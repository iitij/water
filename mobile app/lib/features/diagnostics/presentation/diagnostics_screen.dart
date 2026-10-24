import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:go_router/go_router.dart';

import '../../../core/config/app_config.dart';
import '../../../core/diagnostics/api_log_manager.dart';
import '../../../core/diagnostics/error_reporter.dart';
import '../../../core/finance/finance_feedback.dart';
import '../../../core/finance/finance_locator.dart';
import '../../../core/network/device_identity.dart';
import '../../../core/network/network_info.dart';
import '../../../core/router/route_names.dart';
import '../../../core/theme/app_colors.dart';
import '../../../core/ui/app_surfaces.dart';
import '../../../core/ui/app_tokens.dart';
import '../../audit/services/audit_log_service.dart';
import '../../offline_sync/domain/sync_queue_item.dart';
import '../../offline_sync/services/sync_queue_manager.dart';

/// شاشة التشخيص والدعم (بطاقة S5-06).
///
/// هدفها أن يجيب المحصّل أو الدعم في الميدان عن ثلاثة أسئلة بلا مطوّر:
/// 1. هل هناك عمليات لم تُزامَن، وما سبب فشلها؟
/// 2. هل ضاع حدث تدقيق؟ (سجل محلي معلّق)
/// 3. ما آخر الأخطاء التي رآها التطبيق؟
///
/// وزر «نسخ تقرير الدعم» يجمع ذلك كله في نص واحد يُرسل عبر واتساب.
class DiagnosticsScreen extends StatefulWidget {
  const DiagnosticsScreen({super.key});

  @override
  State<DiagnosticsScreen> createState() => _DiagnosticsScreenState();
}

class _DiagnosticsScreenState extends State<DiagnosticsScreen> {
  bool? _online;
  bool _busy = false;

  SyncQueueManager get _queue => sl<SyncQueueManager>();
  AuditLogService get _audit => sl<AuditLogService>();
  ErrorReporter get _errors => sl<ErrorReporter>();
  ApiLogManager get _apiLogs => sl<ApiLogManager>();
  NetworkInfo get _network => sl<NetworkInfo>();

  @override
  void initState() {
    super.initState();
    _refresh();
  }

  Future<void> _refresh() async {
    bool online;
    try {
      online = await _network.isConnected;
    } catch (_) {
      online = false;
    }
    if (!mounted) return;
    setState(() => _online = online);
    await _audit.refreshPendingCount();
  }

  Future<void> _run(Future<void> Function() action, String success) async {
    if (_busy) return;
    setState(() => _busy = true);
    try {
      await action();
      if (!mounted) return;
      await _refresh();
      if (mounted) AppFeedback.success(context, success);
    } catch (error) {
      if (mounted) AppFeedback.error(context, 'تعذّر إتمام العملية: $error');
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  List<SyncQueueItem> get _waiting => _queue.items
      .where((item) => item.nextAttemptAt != null)
      .toList(growable: false);

  List<SyncQueueItem> get _attention => _queue.items
      .where((item) => item.status == SyncQueueStatus.needsAttention)
      .toList(growable: false);

  String _supportReport() {
    final buffer = StringBuffer()
      ..writeln('تقرير دعم — نظام إدارة المياه')
      ..writeln('إصدار التطبيق: ${AppConfig.appVersion}')
      ..writeln('منصّة الجهاز: ${DeviceIdentity.platform}')
      ..writeln('الخادم: ${AppConfig.apiBaseUrl}')
      ..writeln('الوقت: ${DateTime.now().toIso8601String()}')
      ..writeln('الاتصال: ${_online == true ? 'متصل' : 'غير متصل'}')
      ..writeln('طلبات API المسجلة: ${_apiLogs.totalCount}')
      ..writeln('أخطاء API: ${_apiLogs.errorCount}')
      ..writeln('عمليات بطيئة (لاق): ${_apiLogs.slowCount}')
      ..writeln('متوسط زمن الاستجابة: ${_apiLogs.avgDurationMs}ms')
      ..writeln('عمليات في الطابور: ${_queue.pendingCount}')
      ..writeln('فشل دائم: ${_queue.failedCount}')
      ..writeln('بانتظار تهدئة: ${_waiting.length}')
      ..writeln('تحتاج تدخلاً: ${_attention.length}')
      ..writeln('أحداث تدقيق معلّقة: ${_audit.pendingCount}')
      ..writeln('آخر رفع تدقيق: ${_audit.lastFlushedAt ?? 'لا يوجد'}');

    if (_apiLogs.errorLogs.isNotEmpty) {
      buffer.writeln('أحدث أخطاء الـ API:');
      for (final entry in _apiLogs.errorLogs.take(5)) {
        buffer.writeln(
          '- [${entry.timestamp.toIso8601String()}] ${entry.method} ${entry.endpoint} '
          '-> ${entry.displayStatus} (${entry.durationLabel}): ${entry.errorMessage ?? 'بلا رسالة'}',
        );
      }
    }

    for (final item in _attention.take(5)) {
      buffer.writeln(
        '- ${item.type.name} (${item.id}): ${item.lastError ?? 'بلا رسالة'}'
        '${item.lastStatusCode == null ? '' : ' [HTTP ${item.lastStatusCode}]'}',
      );
    }

    buffer.writeln('آخر الأخطاء:');
    for (final report in _errors.recent.take(8)) {
      buffer.writeln(
        '- ${report.occurredAt.toIso8601String()} ${report.context ?? ''} '
        '${report.statusCode == null ? '' : 'HTTP ${report.statusCode} '}'
        '${report.message}',
      );
    }

    return buffer.toString();
  }

  Future<void> _copyReport() async {
    await Clipboard.setData(ClipboardData(text: _supportReport()));
    if (mounted) {
      AppFeedback.success(context, 'نُسخ تقرير الدعم — أرسله للمشرف أو فريق الصيانة.');
    }
  }

  @override
  Widget build(BuildContext context) {
    return RefreshIndicator(
      onRefresh: _refresh,
      child: ListenableBuilder(
        listenable: Listenable.merge(<Listenable>[_queue, _audit, _apiLogs]),
        builder: (context, _) => AppPageBody(
          topPadding: 16,
          bottomPadding: 32,
          children: <Widget>[
            AppHeroPanel(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: <Widget>[
                  Text('التشخيص والدعم', style: Theme.of(context).textTheme.titleLarge),
                  const Gap(6),
                  Text(
                    _online == null
                        ? 'جارٍ فحص الاتصال…'
                        : _online!
                            ? 'التطبيق متصل بالخادم.'
                            : 'لا يوجد اتصال الآن؛ العمليات تُحفظ محلياً وتُرسل تلقائياً.',
                    style: Theme.of(context).textTheme.bodyMedium,
                  ),
                ],
              ),
            ),
            const Gap(AppSpacing.md),
            _Section(
              title: 'سجل مكالمات API والشبكة',
              rows: <Widget>[
                _MetricRow(label: 'إجمالي طلبات الـ API المسجلة', value: _apiLogs.totalCount),
                _MetricRow(
                  label: 'العمليات الفاشلة / أخطاء',
                  value: _apiLogs.errorCount,
                  valueLabel: _apiLogs.errorCount == 0 ? 'لا توجد أخطاء' : '${_apiLogs.errorCount} خطأ',
                ),
                _MetricRow(
                  label: 'عمليات بطيئة / لاق (>1200ms)',
                  value: _apiLogs.slowCount,
                  valueLabel: _apiLogs.slowCount == 0 ? 'طبيعي' : '${_apiLogs.slowCount} بطيئة',
                ),
                _MetricRow(
                  label: 'متوسط سرعة الاستجابة',
                  value: _apiLogs.avgDurationMs,
                  valueLabel: '${_apiLogs.avgDurationMs}ms',
                ),
              ],
              actions: <Widget>[
                FilledButton.icon(
                  onPressed: () => context.push(RouteNames.apiLogs),
                  icon: const Icon(Icons.sync_alt_rounded),
                  label: const Text('فتح سجل الـ API ومراقبة الأخطاء'),
                ),
                OutlinedButton.icon(
                  onPressed: () => setState(() => _apiLogs.toggleFloatingPill()),
                  icon: Icon(_apiLogs.showFloatingPill ? Icons.visibility_off_rounded : Icons.visibility_rounded),
                  label: Text(_apiLogs.showFloatingPill ? 'إخفاء الزر العائم' : 'إظهار الزر العائم'),
                ),
              ],
            ),
            const Gap(AppSpacing.md),
            _Section(
              title: 'إصدار التطبيق',
              rows: <Widget>[
                const _MetricRow(
                  label: 'الإصدار الحالي',
                  value: 0,
                  valueLabel: AppConfig.appVersion,
                ),
                _MetricRow(
                  label: 'الخادم',
                  value: 0,
                  valueLabel: Uri.tryParse(AppConfig.apiBaseUrl)?.host ?? AppConfig.apiBaseUrl,
                ),
              ],
            ),
            const Gap(AppSpacing.md),
            _Section(
              title: 'طابور المزامنة',
              rows: <Widget>[
                _MetricRow(label: 'عمليات بانتظار الإرسال', value: _queue.pendingCount),
                _MetricRow(label: 'فشل نهائي', value: _queue.failedCount),
                _MetricRow(label: 'بانتظار انتهاء التهدئة', value: _waiting.length),
                _MetricRow(label: 'تحتاج تدخلاً بشرياً', value: _attention.length),
              ],
              actions: <Widget>[
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _run(_queue.retryFailed, 'أُعيدت محاولة العمليات الفاشلة.'),
                  icon: const Icon(Icons.refresh_rounded),
                  label: const Text('إعادة محاولة الفاشل'),
                ),
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _run(_queue.syncPending, 'بدأت المزامنة الآن.'),
                  icon: const Icon(Icons.sync_rounded),
                  label: const Text('مزامنة الآن'),
                ),
              ],
            ),
            const Gap(AppSpacing.md),
            _Section(
              title: 'سجل التدقيق المحلي',
              rows: <Widget>[
                _MetricRow(label: 'أحداث لم تُرفع بعد', value: _audit.pendingCount),
                _MetricRow(
                  label: 'حالة الرفع',
                  value: _audit.lastError == null ? 0 : 1,
                  valueLabel: _audit.lastError == null ? 'سليم' : 'فشل',
                ),
              ],
              actions: <Widget>[
                OutlinedButton.icon(
                  onPressed: _busy ? null : () => _run(_audit.flush, 'رُفعت أحداث التدقيق المعلّقة.'),
                  icon: const Icon(Icons.cloud_upload_outlined),
                  label: const Text('رفع الأحداث الآن'),
                ),
              ],
            ),
            if (_attention.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.md),
              _AttentionList(items: _attention),
            ],
            if (_errors.recent.isNotEmpty) ...<Widget>[
              const Gap(AppSpacing.md),
              _RecentErrors(errors: _errors.recent.take(8).toList(growable: false)),
            ],
            const Gap(AppSpacing.md),
            FilledButton.icon(
              onPressed: _copyReport,
              icon: const Icon(Icons.copy_all_rounded),
              label: const Text('نسخ تقرير الدعم'),
            ),
          ],
        ),
      ),
    );
  }
}

class _Section extends StatelessWidget {
  const _Section({required this.title, required this.rows, this.actions = const <Widget>[]});

  final String title;
  final List<Widget> rows;
  final List<Widget> actions;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(title, style: Theme.of(context).textTheme.titleMedium),
          const Gap(AppSpacing.sm),
          ...rows,
          if (actions.isNotEmpty) ...<Widget>[
            const Gap(AppSpacing.sm),
            Wrap(spacing: 8, runSpacing: 8, children: actions),
          ],
        ],
      ),
    );
  }
}

class _MetricRow extends StatelessWidget {
  const _MetricRow({required this.label, required this.value, this.valueLabel});

  final String label;
  final int value;
  final String? valueLabel;

  @override
  Widget build(BuildContext context) {
    final danger = value > 0 && valueLabel == null;
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        children: <Widget>[
          Expanded(child: Text(label, style: Theme.of(context).textTheme.bodyMedium)),
          Text(
            valueLabel ?? '$value',
            style: Theme.of(context).textTheme.titleSmall?.copyWith(
                  color: danger ? AppColors.danger : null,
                ),
          ),
        ],
      ),
    );
  }
}

class _AttentionList extends StatelessWidget {
  const _AttentionList({required this.items});

  final List<SyncQueueItem> items;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      borderColor: AppColors.danger,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('عمليات تحتاج تدخلاً', style: Theme.of(context).textTheme.titleMedium),
          const Gap(AppSpacing.sm),
          ...items.take(5).map(
                (item) => Padding(
                  padding: const EdgeInsets.only(bottom: 6),
                  child: Text(
                    '• ${item.type.name} — ${item.lastError ?? 'بلا رسالة'}',
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
                ),
              ),
        ],
      ),
    );
  }
}

class _RecentErrors extends StatelessWidget {
  const _RecentErrors({required this.errors});

  final List<ErrorReport> errors;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text('آخر الأخطاء', style: Theme.of(context).textTheme.titleMedium),
          const Gap(AppSpacing.sm),
          ...errors.map(
            (report) => Padding(
              padding: const EdgeInsets.only(bottom: 6),
              child: Text(
                '• ${report.message}'
                '${report.endpoint == null ? '' : ' — ${report.endpoint}'}',
                style: Theme.of(context).textTheme.bodySmall,
              ),
            ),
          ),
        ],
      ),
    );
  }
}
