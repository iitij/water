import 'package:flutter/foundation.dart';

import '../../../core/error/failures.dart';
import '../data/cash_session_api.dart';
import '../domain/cash_session.dart';

/// يُرمى عند محاولة تحصيل نقدي دون صندوق مفتوح.
///
/// الرسالة عربية جاهزة للعرض؛ هذا هو الحاجز الذي يمنع «سندات بلا صندوق»
/// وهي أكبر ثغرة في v1 (خطة 2.0 — الفصل 5.2).
class CashSessionRequiredException implements Exception {
  const CashSessionRequiredException([
    this.message = 'افتح صندوق المحصّل أولاً قبل تسجيل أي تحصيل.',
  ]);

  final String message;

  @override
  String toString() => message;
}

/// حالة صندوق المحصّل في التطبيق: آخر صندوق معروف، وهل يجوز تسجيل تحصيل.
///
/// القرار يعتمد على صندوق مفتوح **معروف محلياً** حتى لا يمنع انقطاع الشبكة
/// المحصّل من العمل؛ وعند غياب المعرفة (لم يُفتح صندوق قط) يُسمح بالتحصيل
/// الأوفلاين لأن الخادم سيرفضه لاحقاً إن كان غير صحيح، مع تنبيه واضح.
class CashSessionService extends ChangeNotifier {
  CashSessionService({required CashSessionApi api}) : _api = api;

  static const String noSessionMessage =
      'افتح صندوق المحصّل أولاً قبل تسجيل أي تحصيل.';

  final CashSessionApi _api;

  CashSession? _current;
  bool _loaded = false;
  bool _loading = false;
  AppFailure? _lastError;

  CashSession? get current => _current;
  bool get isOpen => _current?.isOpen ?? false;
  bool get hasLoaded => _loaded;
  bool get isLoading => _loading;
  AppFailure? get lastError => _lastError;

  /// هل يجوز تسجيل تحصيل الآن؟
  ///
  /// * صندوق مفتوح معروف → نعم.
  /// * لا صندوق معروف (لم ننجح في الجلب بعد) → نعم مؤقتاً، لأن المنع الكامل
  ///   عند أول تشغيل بلا شبكة يعطّل الميدان بلا فائدة.
  bool get canCollect => _current == null || _current!.isOpen;

  /// يرمي استثناءً عربياً إن كان الصندوق مغلقاً/بانتظار الاعتماد.
  void ensureCanCollect() {
    if (!canCollect) throw const CashSessionRequiredException();
  }

  /// يُستدعى عند فتح التطبيق أو بعد تسجيل الدخول.
  Future<CashSession?> refresh() async {
    if (_loading) return _current;
    _loading = true;
    notifyListeners();
    try {
      _current = await _api.current();
      _lastError = null;
      _loaded = true;
      return _current;
    } on AppFailure catch (failure) {
      _lastError = failure;
      // فشل الشبكة لا يمحو آخر صندوق معروف؛ رفض المصادقة يمحوه.
      if (failure is UnauthorizedFailure || failure is ForbiddenFailure) {
        _current = null;
      }
      return _current;
    } finally {
      _loading = false;
      notifyListeners();
    }
  }

  Future<CashSession> open({
    double openingAmount = 0,
    int? squareId,
    String? notes,
  }) async {
    final session = await _api.open(
      openingAmount: openingAmount,
      squareId: squareId,
      notes: notes,
    );
    _current = session;
    _loaded = true;
    _lastError = null;
    notifyListeners();
    return session;
  }

  Future<CashSession> close({
    required double countedAmount,
    String? notes,
  }) async {
    final session = _current;
    if (session == null) {
      throw const CashSessionRequiredException('لا يوجد صندوق مفتوح لإغلاقه.');
    }
    final closed = await _api.close(
      session.id,
      countedAmount: countedAmount,
      notes: notes,
    );
    _current = closed.isOpen ? closed : null;
    notifyListeners();
    return closed;
  }

  Future<CashSession> approve(int id, {required bool approve, String? notes}) =>
      _api.approve(id, approve: approve, notes: notes);

  /// يُستدعى عند تسجيل الخروج حتى لا يرى مستخدمٌ صندوق مستخدم آخر.
  void reset() {
    _current = null;
    _loaded = false;
    _lastError = null;
    notifyListeners();
  }
}
