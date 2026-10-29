import 'dart:io';

import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../auth/session_service.dart';
import '../config/app_config.dart';
import '../database/app_database.dart';
import '../finance/finance_api_client.dart';
import '../l10n/app_strings.dart';
import '../network/dio_client.dart';
import '../security/developer_access_service.dart';
import '../theme/app_colors.dart';
import '../ui/app_tokens.dart';
import '../widgets/app_button.dart';
import '../widgets/app_text_field.dart';

/// Safely validates and switches the active backend API base URL at runtime
/// while strictly blocking server changes whenever unsynced offline actions exist.
class ServerConnectionService {
  const ServerConnectionService._();

  static String? validateInput(String? rawValue, {bool? isAndroid}) {
    final checkAndroid = isAndroid ?? Platform.isAndroid;
    final raw = (rawValue ?? '').trim();
    if (!AppConfig.isInsecureHttpAllowed &&
        raw.toLowerCase().startsWith('http://') &&
        !AppConfig.isHttpAllowedForHost(Uri.tryParse(raw)?.host ?? '')) {
      return AppStringsDefaults.serverPublicHttpsRequired;
    }
    try {
      final normalized = AppConfig.normalizeApiBaseUrl(rawValue ?? '');
      final host = Uri.parse(normalized).host.toLowerCase();
      if (checkAndroid &&
          const <String>[
            'localhost',
            '127.0.0.1',
            '::1',
            '[::1]',
          ].contains(host)) {
        return 'استخدم عنوان الكمبيوتر على الشبكة (مثل 192.168.1.20) أو 10.0.2.2 للمحاكي بدلاً من localhost.';
      }
      return null;
    } on FormatException {
      return AppStringsDefaults.serverUrlInvalid;
    }
  }

  static Future<int> pendingOfflineOperationsCount() async {
    final getIt = GetIt.I;
    final fromManager = getIt.isRegistered<SyncQueueManager>()
        ? getIt<SyncQueueManager>().pendingCount
        : 0;
    final fromDb = getIt.isRegistered<AppDatabase>()
        ? await getIt<AppDatabase>().getPendingActionCount()
        : 0;
    return fromManager > fromDb ? fromManager : fromDb;
  }

  static Future<String> switchServerUrl(
    String rawUrl, {
    bool? isAndroid,
  }) async {
    final validationError = validateInput(rawUrl, isAndroid: isAndroid);
    if (validationError != null) {
      throw FormatException(validationError);
    }

    final normalized = AppConfig.normalizeApiBaseUrl(rawUrl);
    final current = AppConfig.currentApiBaseUrl;
    if (current == normalized) {
      return normalized;
    }

    final pendingCount = await pendingOfflineOperationsCount();
    if (pendingCount > 0) {
      throw StateError(
        'لا يمكن تغيير عنوان الخادم حالياً لوجود $pendingCount عملية أوفلاين معلقة في الطابور. '
        'يرجى مزامنتها أولاً قبل التبديل لحماية البيانات المالية من الاختلاط.',
      );
    }

    final getIt = GetIt.I;
    if (getIt.isRegistered<DeveloperAccessService>()) {
      getIt<DeveloperAccessService>().lock();
    }
    if (getIt.isRegistered<SessionService>()) {
      final session = getIt<SessionService>();
      if (session.isAuthenticated) {
        await session.logout();
      }
    }

    if (getIt.isRegistered<AppDatabase>()) {
      await getIt<AppDatabase>().clearCachedServerData();
    }

    final preferences = await SharedPreferences.getInstance();
    for (final key in preferences.getKeys().toList(growable: false)) {
      if (key.startsWith('dev5.') || key.startsWith('finance.')) {
        await preferences.remove(key);
      }
    }

    if (!await preferences.setString(
      AppConfig.serverUrlPreferenceKey,
      normalized,
    )) {
      throw StateError('تعذر حفظ عنوان الخادم الجديد على الجهاز.');
    }

    if (getIt.isRegistered<DioClient>()) {
      getIt<DioClient>().updateBaseUrl(normalized);
    } else {
      AppConfig.configureServer(normalized);
    }
    financeApiBaseUrl = normalized;

    return normalized;
  }
}

class ServerConfigSheet extends StatefulWidget {
  const ServerConfigSheet({super.key});

  static Future<String?> show(BuildContext context) {
    return showModalBottomSheet<String>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      showDragHandle: true,
      builder: (_) => const ServerConfigSheet(),
    );
  }

  @override
  State<ServerConfigSheet> createState() => _ServerConfigSheetState();
}

class _ServerConfigSheetState extends State<ServerConfigSheet> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _urlController = TextEditingController(
    text: AppConfig.currentApiBaseUrl ?? AppConfig.defaultApiBaseUrl,
  );

  int _pendingCount = 0;
  bool _loadingGuard = true;
  bool _saving = false;
  String? _error;

  @override
  void initState() {
    super.initState();
    _loadPendingGuard();
  }

  Future<void> _loadPendingGuard() async {
    final count = await ServerConnectionService.pendingOfflineOperationsCount();
    if (!mounted) return;
    setState(() {
      _pendingCount = count;
      _loadingGuard = false;
    });
  }

  @override
  void dispose() {
    _urlController.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final updated = await ServerConnectionService.switchServerUrl(
        _urlController.text,
      );
      if (!mounted) return;
      Navigator.of(context).pop(updated);
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _saving = false;
        _error = error
            .toString()
            .replaceFirst('FormatException: ', '')
            .replaceFirst('Bad state: ', '');
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final bottomInset = MediaQuery.viewInsetsOf(context).bottom;
    final blocked = !_loadingGuard && _pendingCount > 0;
    final isAuthenticated =
        GetIt.I.isRegistered<SessionService>() &&
        GetIt.I<SessionService>().isAuthenticated;

    return Padding(
      padding: EdgeInsets.fromLTRB(22, 4, 22, bottomInset + 24),
      child: SingleChildScrollView(
        child: Form(
          key: _formKey,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Row(
                children: <Widget>[
                  Container(
                    width: 42,
                    height: 42,
                    decoration: BoxDecoration(
                      color: AppColors.primary.withValues(alpha: .1),
                      borderRadius: BorderRadius.circular(AppRadius.lg),
                    ),
                    child: const Icon(
                      Icons.dns_outlined,
                      color: AppColors.primary,
                    ),
                  ),
                  const SizedBox(width: 12),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: <Widget>[
                        Text(
                          'إعدادات الاتصال بالخادم',
                          style: theme.textTheme.titleLarge,
                        ),
                        Text(
                          'تغيير رابط خادم الـ API دون مسح بيانات التطبيق',
                          style: theme.textTheme.bodySmall,
                        ),
                      ],
                    ),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              if (blocked) ...<Widget>[
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: AppColors.danger.withValues(alpha: .08),
                    borderRadius: BorderRadius.circular(AppRadius.xl),
                    border: Border.all(
                      color: AppColors.danger.withValues(alpha: .28),
                    ),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      const Icon(
                        Icons.warning_amber_rounded,
                        color: AppColors.danger,
                        size: 22,
                      ),
                      const SizedBox(width: 10),
                      Expanded(
                        child: Text(
                          'يوجد $_pendingCount عملية أوفلاين معلّقة في طابور المزامنة. '
                          'تم قفل تغيير الخادم مؤقتاً حتى تتم مزامنة العمليات مع الخادم الحالي.',
                          style: theme.textTheme.bodySmall?.copyWith(
                            color: AppColors.danger,
                            fontWeight: FontWeight.w600,
                            height: 1.5,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
              ] else if (isAuthenticated) ...<Widget>[
                Container(
                  padding: const EdgeInsets.all(12),
                  decoration: BoxDecoration(
                    color: AppColors.warning.withValues(alpha: .09),
                    borderRadius: BorderRadius.circular(AppRadius.lg),
                    border: Border.all(
                      color: AppColors.warning.withValues(alpha: .28),
                    ),
                  ),
                  child: Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      const Icon(
                        Icons.info_outline_rounded,
                        color: AppColors.warning,
                        size: 20,
                      ),
                      const SizedBox(width: 9),
                      Expanded(
                        child: Text(
                          'عند حفظ خادم مختلف سيتم تسجيل الخروج من الجلسة الحالية وتنظيف الكاش المؤقت بأمان.',
                          style: theme.textTheme.bodySmall?.copyWith(
                            height: 1.45,
                          ),
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
              ],
              AppTextField(
                controller: _urlController,
                enabled: !_saving && !blocked && !_loadingGuard,
                label: 'رابط الـ API للخادم',
                hint: 'https://water.example.com/api',
                prefixIcon: Icons.link_rounded,
                keyboardType: TextInputType.url,
                textDirection: TextDirection.ltr,
                textAlign: TextAlign.left,
                validator: ServerConnectionService.validateInput,
              ),
              const SizedBox(height: 10),
              Text(
                'أمثلة: على شبكة محلية http://192.168.1.20:8000/api • على محاكي أندرويد http://10.0.2.2:8000/api • للإنتاج استخدم https://',
                style: theme.textTheme.bodySmall,
              ),
              if (_error != null) ...<Widget>[
                const SizedBox(height: 12),
                Text(
                  _error!,
                  style: theme.textTheme.bodySmall?.copyWith(
                    color: AppColors.danger,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
              const SizedBox(height: 20),
              AppButton(
                label: _saving ? 'جارٍ الحفظ…' : 'حفظ عنوان الخادم',
                icon: _saving ? null : Icons.check_rounded,
                loading: _saving,
                onPressed: _saving || blocked || _loadingGuard ? null : _save,
              ),
            ],
          ),
        ),
      ),
    );
  }
}
