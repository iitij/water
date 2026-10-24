import 'dart:async';
import 'dart:collection';
import 'dart:convert';
import 'package:flutter/foundation.dart';

import 'models/api_log_entry.dart';

/// مدير سجلات الـ API والشبكة على مستوى التطبيق بالكامل.
///
/// يقوم بالآتي:
/// 1. حفظ سجل تفصيلي لآخر 250 عملية (طلبات API، استجابات، أخطاء، بطء/لاق).
/// 2. توفير إحصائيات فورية (عدد الأخطاء، زمن الاستجابة، العمليات البطيئة).
/// 3. إشعار الواجهات في الوقت الفعلي عند حدوث أي طلب أو خطأ جديد.
/// 4. تجميع أخطاء التطبيق العامة وأخطاء الشاشات وربطها مع طلبات الشبكة.
class ApiLogManager extends ChangeNotifier {
  ApiLogManager({this.maxEntries = 250});

  static final ApiLogManager instance = ApiLogManager();

  final int maxEntries;
  final List<ApiLogEntry> _entries = <ApiLogEntry>[];
  final StreamController<ApiLogEntry> _streamController =
      StreamController<ApiLogEntry>.broadcast();

  bool _showFloatingPill = true;

  /// هل يظهر الزر العائم لسجلات الـ API فوق الشاشات للمتابعة اللحظية؟
  bool get showFloatingPill => _showFloatingPill;

  set showFloatingPill(bool value) {
    if (_showFloatingPill != value) {
      _showFloatingPill = value;
      notifyListeners();
    }
  }

  void toggleFloatingPill() {
    _showFloatingPill = !_showFloatingPill;
    notifyListeners();
  }

  Stream<ApiLogEntry> get onNewEntry => _streamController.stream;

  List<ApiLogEntry> get logs => UnmodifiableListView<ApiLogEntry>(_entries);

  List<ApiLogEntry> get errorLogs =>
      _entries.where((e) => e.isError).toList(growable: false);

  List<ApiLogEntry> get slowLogs =>
      _entries.where((e) => e.isSlow).toList(growable: false);

  List<ApiLogEntry> get successLogs =>
      _entries.where((e) => e.isSuccess).toList(growable: false);

  int get totalCount => _entries.length;

  int get errorCount => _entries.where((e) => e.isError).length;

  int get slowCount => _entries.where((e) => e.isSlow).length;

  int get avgDurationMs {
    if (_entries.isEmpty) return 0;
    final sum = _entries.fold<int>(0, (prev, e) => prev + e.durationMs);
    return (sum / _entries.length).round();
  }

  /// تصفية السجلات حسب نص البحث والنوع
  List<ApiLogEntry> filter({String? query, String? type}) {
    Iterable<ApiLogEntry> list = _entries;

    if (type == 'errors') {
      list = list.where((e) => e.isError);
    } else if (type == 'slow') {
      list = list.where((e) => e.isSlow);
    } else if (type == 'success') {
      list = list.where((e) => e.isSuccess);
    }

    if (query != null && query.trim().isNotEmpty) {
      final q = query.trim().toLowerCase();
      list = list.where((e) {
        if (e.endpoint.toLowerCase().contains(q)) return true;
        if (e.method.toLowerCase().contains(q)) return true;
        if (e.url.toLowerCase().contains(q)) return true;
        if (e.statusCode?.toString().contains(q) == true) return true;
        if (e.errorMessage?.toLowerCase().contains(q) == true) return true;
        if (e.errorType?.toLowerCase().contains(q) == true) return true;
        return false;
      });
    }

    return list.toList(growable: false);
  }

  /// تسجيل طلب/استجابة API
  void addEntry(ApiLogEntry entry) {
    _entries.insert(0, entry);
    while (_entries.length > maxEntries) {
      _entries.removeLast();
    }
    if (!_streamController.isClosed) {
      _streamController.add(entry);
    }
    notifyListeners();
  }

  /// تسجيل خطأ غير متوقع من أي شاشة أو Cubit في التطبيق
  void logAppError(
    String context,
    Object error, {
    StackTrace? stackTrace,
    String? endpoint,
  }) {
    final entry = ApiLogEntry(
      id: 'err-${DateTime.now().microsecondsSinceEpoch}',
      timestamp: DateTime.now(),
      method: 'APP',
      url: endpoint ?? 'local://$context',
      endpoint: endpoint ?? '/app/$context',
      isError: true,
      errorType: error.runtimeType.toString(),
      errorMessage: error.toString(),
      stackTrace: stackTrace?.toString(),
      context: context,
    );
    addEntry(entry);
  }

  /// مسح جميع السجلات المحفوظة
  void clear() {
    _entries.clear();
    notifyListeners();
  }

  /// تصدير السجلات كنص تقرير شامل
  String exportAsText() {
    final buffer = StringBuffer()
      ..writeln('========================================')
      ..writeln('تقرير سجلات الـ API والشبكة لنظام المياه')
      ..writeln('التاريخ: ${DateTime.now().toIso8601String()}')
      ..writeln('إجمالي الطلبات: $totalCount')
      ..writeln('العمليات الفاشلة: $errorCount')
      ..writeln('العمليات البطيئة/لاق: $slowCount')
      ..writeln('متوسط زمن الاستجابة: ${avgDurationMs}ms')
      ..writeln('========================================\n');

    for (final entry in _entries) {
      buffer
        ..writeln(
          '[${entry.timestamp.toIso8601String()}] ${entry.method} ${entry.endpoint} '
          '-> ${entry.displayStatus} (${entry.durationLabel})'
          '${entry.isSlow ? ' [لاق/بطء]' : ''}',
        );
      if (entry.errorMessage != null) {
        buffer.writeln('   الخطأ: ${entry.errorMessage}');
      }
      if (entry.requestId != null) {
        buffer.writeln('   معرف الطلب: ${entry.requestId}');
      }
      buffer.writeln('----------------------------------------');
    }

    return buffer.toString();
  }

  /// تصدير السجلات بصيغة JSON
  String exportAsJson() {
    final list = _entries.map((e) => e.toJson()).toList();
    return const JsonEncoder.withIndent('  ').convert(list);
  }

  @override
  void dispose() {
    _streamController.close();
    super.dispose();
  }
}
