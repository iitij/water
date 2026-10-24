import 'dart:convert';
import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../../core/di/injection_container.dart';
import '../../../core/diagnostics/api_log_manager.dart';
import '../../../core/diagnostics/models/api_log_entry.dart';
import '../../../core/finance/finance_feedback.dart';
import '../../../core/theme/app_colors.dart';

/// شاشة فحص ومراقبة سجلات الـ API والشبكة بين التطبيق والخادم.
///
/// تمكّن من معرفة:
/// 1. أي طلب فشل أو أرجع خطأ (422, 500, 401, 403, إلخ).
/// 2. أي شاشة لم تجلب بيانات أو استغرقت وقتاً طويلاً (لاق / بطء).
/// 3. مطابقة سجلات التطبيق المحلية مع سجلات الخادم (Backend Logs).
/// 4. نسخ أمر cURL أو تقرير الخطأ بنقرة واحدة لتشخيصه فوراً.
class ApiLogsScreen extends StatefulWidget {
  const ApiLogsScreen({super.key});

  @override
  State<ApiLogsScreen> createState() => _ApiLogsScreenState();
}

class _ApiLogsScreenState extends State<ApiLogsScreen> {
  final ApiLogManager _logManager = getIt<ApiLogManager>();
  final TextEditingController _searchController = TextEditingController();

  String _selectedFilter = 'all'; // all, errors, slow, success
  int _activeSourceTab = 0; // 0: Local App Logs, 1: Backend Server Logs
  bool _loadingBackend = false;
  List<ApiLogEntry> _backendLogs = <ApiLogEntry>[];
  String? _backendError;

  @override
  void initState() {
    super.initState();
    _logManager.addListener(_onManagerUpdated);
    _searchController.addListener(_onSearchChanged);
  }

  @override
  void dispose() {
    _logManager.removeListener(_onManagerUpdated);
    _searchController.removeListener(_onSearchChanged);
    _searchController.dispose();
    super.dispose();
  }

  void _onManagerUpdated() {
    if (mounted) setState(() {});
  }

  void _onSearchChanged() {
    if (mounted) setState(() {});
  }

  Future<void> _fetchBackendLogs() async {
    setState(() {
      _loadingBackend = true;
      _backendError = null;
    });

    try {
      final dio = getIt<Dio>();
      final filterParam = _selectedFilter == 'errors'
          ? 'errors'
          : (_selectedFilter == 'slow' ? 'slow' : null);

      final query = <String, dynamic>{
        if (filterParam != null) 'filter': filterParam,
        if (_searchController.text.trim().isNotEmpty)
          'search': _searchController.text.trim(),
        'limit': 100,
      };

      final response = await dio.get<Map<String, dynamic>>(
        // بلا شرطة بادئة حتى يحافظ Dio على /api/ في baseUrl (انظر api_endpoints.dart).
        'system/api-logs',
        queryParameters: query,
      );

      final data = response.data;
      final rawList = data?['logs'];
      final parsed = <ApiLogEntry>[];
      if (rawList is List) {
        parsed.addAll(rawList.map((item) {
          if (item is Map) {
            return ApiLogEntry(
              id: item['id']?.toString() ?? '',
              timestamp: item['timestamp'] != null
                  ? DateTime.tryParse(item['timestamp'].toString()) ?? DateTime.now()
                  : DateTime.now(),
              method: item['method']?.toString() ?? 'GET',
              url: item['full_url']?.toString() ?? item['path']?.toString() ?? '',
              endpoint: item['path']?.toString() ?? '',
              queryParams: item['query'] is Map ? Map<String, dynamic>.from(item['query'] as Map) : null,
              requestHeaders: item['headers'] is Map ? Map<String, dynamic>.from(item['headers'] as Map) : null,
              requestBody: item['payload'],
              statusCode: item['status'] is int ? item['status'] as int : null,
              responseBody: item['response_preview'],
              durationMs: item['duration_ms'] is int ? item['duration_ms'] as int : 0,
              isSlow: item['is_slow'] == true,
              isError: item['is_error'] == true,
              errorMessage: item['error']?.toString(),
              requestId: item['id']?.toString(),
              context: 'backend',
            );
          }
          return null;
        }).whereType<ApiLogEntry>().toList());
      }

      // تقارير أعطال التطبيق المرفوعة إلى الخادم (system/error-reports) — تظهر
      // في نفس القائمة بوسم واضح ليتمكن الدعم من رؤية أعطال الأجهزة.
      final rawReports = data?['client_reports'];
      if (rawReports is List) {
        parsed.addAll(rawReports.map((item) {
          if (item is Map) {
            return ApiLogEntry(
              id: item['id']?.toString() ?? '',
              timestamp: item['occurred_at'] != null
                  ? DateTime.tryParse(item['occurred_at'].toString()) ?? DateTime.now()
                  : DateTime.now(),
              method: 'APP-CRASH',
              url: item['message']?.toString() ?? '',
              endpoint: 'تقرير عطل من الجهاز',
              requestBody: <String, dynamic>{
                if (item['stack'] != null) 'stack': item['stack'],
                if (item['context'] is Map) 'context': item['context'],
                if (item['app_version'] != null) 'app_version': item['app_version'],
                if (item['device_id'] != null) 'device_id': item['device_id'],
              },
              statusCode: 500,
              responseBody: null,
              durationMs: 0,
              isSlow: false,
              isError: true,
              errorMessage: item['message']?.toString(),
              requestId: item['id']?.toString(),
              context: 'client-report',
            );
          }
          return null;
        }).whereType<ApiLogEntry>().toList());
      }

      parsed.sort((a, b) => b.timestamp.compareTo(a.timestamp));

      if (mounted) {
        setState(() {
          _backendLogs = parsed;
          _loadingBackend = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _backendError = 'تعذر جلب سجلات الخادم: $e';
          _loadingBackend = false;
        });
      }
    }
  }

  Future<void> _clearLogs() async {
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('مسح السجلات'),
        content: Text(
          _activeSourceTab == 0
              ? 'هل تريد مسح جميع سجلات الـ API المحلية المحفوظة في التطبيق؟'
              : 'هل تريد مسح سجلات الـ API من الخادم أيضاً؟',
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('مسح الآن'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    if (_activeSourceTab == 0) {
      _logManager.clear();
      AppFeedback.success(context, 'تم مسح السجلات المحلية بنجاح.');
    } else {
      try {
        final dio = getIt<Dio>();
        await dio.delete<void>('system/api-logs');
        await _fetchBackendLogs();
        if (mounted) AppFeedback.success(context, 'تم مسح سجلات الخادم بنجاح.');
      } catch (e) {
        if (mounted) AppFeedback.error(context, 'تعذر مسح سجلات الخادم: $e');
      }
    }
  }

  Future<void> _copyAllLogs() async {
    final text = _activeSourceTab == 0
        ? _logManager.exportAsText()
        : _backendLogs.map((e) => e.toDetailedReport()).join('\n----------------------------------------\n');

    await Clipboard.setData(ClipboardData(text: text));
    if (mounted) {
      AppFeedback.success(context, 'تم نسخ تقرير السجلات بالكامل إلى الحافظة.');
    }
  }

  @override
  Widget build(BuildContext context) {
    final currentLogs = _activeSourceTab == 0
        ? _logManager.filter(
            query: _searchController.text,
            type: _selectedFilter == 'all' ? null : _selectedFilter,
          )
        : _filterBackendLogs();

    return Scaffold(
      appBar: AppBar(
        title: const Text('مراقب وسجلات API'),
        actions: <Widget>[
          IconButton(
            tooltip: 'تحديث',
            icon: const Icon(Icons.refresh_rounded),
            onPressed: _activeSourceTab == 0 ? () => setState(() {}) : _fetchBackendLogs,
          ),
          IconButton(
            tooltip: 'نسخ كل السجلات',
            icon: const Icon(Icons.copy_all_rounded),
            onPressed: currentLogs.isEmpty ? null : _copyAllLogs,
          ),
          IconButton(
            tooltip: 'مسح السجلات',
            icon: const Icon(Icons.delete_sweep_outlined),
            onPressed: _clearLogs,
          ),
        ],
      ),
      body: Column(
        children: <Widget>[
          // بطاقة الإحصائيات العلوية
          _buildMetricsBar(),

          // مبدل المصدر: سجلات التطبيق / سجلات الخادم
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
            child: SegmentedButton<int>(
              segments: const <ButtonSegment<int>>[
                ButtonSegment<int>(
                  value: 0,
                  label: Text('سجلات التطبيق المحلية'),
                  icon: Icon(Icons.phone_android_rounded, size: 18),
                ),
                ButtonSegment<int>(
                  value: 1,
                  label: Text('سجلات الخادم (Backend)'),
                  icon: Icon(Icons.cloud_outlined, size: 18),
                ),
              ],
              selected: <int>{_activeSourceTab},
              onSelectionChanged: (selected) {
                setState(() => _activeSourceTab = selected.first);
                if (selected.first == 1 && _backendLogs.isEmpty && !_loadingBackend) {
                  _fetchBackendLogs();
                }
              },
            ),
          ),

          // شريط البحث وفلاتر التصفية
          _buildSearchAndFilters(),

          // القائمة الفعلية للسجلات
          Expanded(
            child: _activeSourceTab == 1 && _loadingBackend
                ? const Center(child: CircularProgressIndicator())
                : _activeSourceTab == 1 && _backendError != null
                    ? _buildBackendErrorState()
                    : currentLogs.isEmpty
                        ? _buildEmptyState()
                        : ListView.separated(
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                            itemCount: currentLogs.length,
                            separatorBuilder: (_, __) => const SizedBox(height: 8),
                            itemBuilder: (context, index) {
                              final entry = currentLogs[index];
                              return _ApiLogCard(
                                entry: entry,
                                onTap: () => _showDetailSheet(entry),
                              );
                            },
                          ),
          ),
        ],
      ),
    );
  }

  List<ApiLogEntry> _filterBackendLogs() {
    Iterable<ApiLogEntry> list = _backendLogs;
    if (_selectedFilter == 'errors') {
      list = list.where((e) => e.isError);
    } else if (_selectedFilter == 'slow') {
      list = list.where((e) => e.isSlow);
    } else if (_selectedFilter == 'success') {
      list = list.where((e) => e.isSuccess);
    }

    if (_searchController.text.trim().isNotEmpty) {
      final q = _searchController.text.trim().toLowerCase();
      list = list.where((e) {
        if (e.endpoint.toLowerCase().contains(q)) return true;
        if (e.method.toLowerCase().contains(q)) return true;
        if (e.statusCode?.toString().contains(q) == true) return true;
        if (e.errorMessage?.toLowerCase().contains(q) == true) return true;
        return false;
      });
    }

    return list.toList(growable: false);
  }

  Widget _buildMetricsBar() {
    final total = _activeSourceTab == 0 ? _logManager.totalCount : _backendLogs.length;
    final errors = _activeSourceTab == 0 ? _logManager.errorCount : _backendLogs.where((e) => e.isError).length;
    final slow = _activeSourceTab == 0 ? _logManager.slowCount : _backendLogs.where((e) => e.isSlow).length;
    final avg = _activeSourceTab == 0 ? _logManager.avgDurationMs : _calcAvgBackendDuration();

    return Container(
      margin: const EdgeInsets.fromLTRB(16, 8, 16, 4),
      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 10),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: AppColors.border),
      ),
      child: Row(
        mainAxisAlignment: MainAxisAlignment.spaceAround,
        children: <Widget>[
          _MetricBadge(label: 'الإجمالي', value: '$total', color: AppColors.info),
          _MetricBadge(label: 'أخطاء', value: '$errors', color: errors > 0 ? AppColors.danger : AppColors.muted),
          _MetricBadge(label: 'بطء / لاق', value: '$slow', color: slow > 0 ? AppColors.warning : AppColors.muted),
          _MetricBadge(label: 'متوسط الزمن', value: '${avg}ms', color: avg > 1000 ? AppColors.warning : AppColors.success),
        ],
      ),
    );
  }

  int _calcAvgBackendDuration() {
    if (_backendLogs.isEmpty) return 0;
    final sum = _backendLogs.fold<int>(0, (prev, e) => prev + e.durationMs);
    return (sum / _backendLogs.length).round();
  }

  Widget _buildSearchAndFilters() {
    return Padding(
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 6),
      child: Column(
        children: <Widget>[
          TextField(
            controller: _searchController,
            decoration: InputDecoration(
              hintText: 'ابحث بالمسار، الحالة، أو نص الخطأ...',
              prefixIcon: const Icon(Icons.search_rounded, size: 20),
              suffixIcon: _searchController.text.isNotEmpty
                  ? IconButton(
                      icon: const Icon(Icons.clear_rounded, size: 18),
                      onPressed: () => _searchController.clear(),
                    )
                  : null,
              isDense: true,
              contentPadding: const EdgeInsets.symmetric(vertical: 10, horizontal: 12),
              border: OutlineInputBorder(borderRadius: BorderRadius.circular(10)),
            ),
          ),
          const SizedBox(height: 8),
          SingleChildScrollView(
            scrollDirection: Axis.horizontal,
            child: Row(
              children: <Widget>[
                _FilterChipItem(
                  label: 'الكل',
                  selected: _selectedFilter == 'all',
                  onSelected: () => setState(() => _selectedFilter = 'all'),
                ),
                const SizedBox(width: 6),
                _FilterChipItem(
                  label: 'الأخطاء فقط ❌',
                  selected: _selectedFilter == 'errors',
                  onSelected: () => setState(() => _selectedFilter = 'errors'),
                ),
                const SizedBox(width: 6),
                _FilterChipItem(
                  label: 'البطيء / لاق ⚠️',
                  selected: _selectedFilter == 'slow',
                  onSelected: () => setState(() => _selectedFilter = 'slow'),
                ),
                const SizedBox(width: 6),
                _FilterChipItem(
                  label: 'الناجحة ✅',
                  selected: _selectedFilter == 'success',
                  onSelected: () => setState(() => _selectedFilter = 'success'),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildEmptyState() {
    return Center(
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(Icons.checklist_rtl_rounded, size: 54, color: AppColors.muted.withOpacity(0.5)),
          const SizedBox(height: 12),
          Text(
            _searchController.text.isNotEmpty || _selectedFilter != 'all'
                ? 'لا توجد سجلات مطابقة للفلتر المحدد'
                : 'لا توجد عمليات مسجلة حتى الآن.\nقم بفتح الشاشات لتسجيل ومتابعة طلبات الـ API.',
            textAlign: TextAlign.center,
            style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: AppColors.muted),
          ),
        ],
      ),
    );
  }

  Widget _buildBackendErrorState() {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            const Icon(Icons.cloud_off_rounded, size: 48, color: AppColors.danger),
            const SizedBox(height: 12),
            Text(
              _backendError ?? '',
              textAlign: TextAlign.center,
              style: const TextStyle(color: AppColors.danger),
            ),
            const SizedBox(height: 16),
            FilledButton.icon(
              onPressed: _fetchBackendLogs,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('إعادة المحاولة'),
            ),
          ],
        ),
      ),
    );
  }

  void _showDetailSheet(ApiLogEntry entry) {
    showModalBottomSheet<void>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Theme.of(context).scaffoldBackgroundColor,
      shape: const RoundedRectangleBorder(
        borderRadius: BorderRadius.vertical(top: Radius.circular(20)),
      ),
      builder: (ctx) => _ApiLogDetailSheet(entry: entry),
    );
  }
}

class _MetricBadge extends StatelessWidget {
  const _MetricBadge({required this.label, required this.value, required this.color});

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Column(
      mainAxisSize: MainAxisSize.min,
      children: <Widget>[
        Text(value, style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold, color: color)),
        const SizedBox(height: 2),
        Text(label, style: Theme.of(context).textTheme.bodySmall?.copyWith(color: AppColors.muted)),
      ],
    );
  }
}

class _FilterChipItem extends StatelessWidget {
  const _FilterChipItem({
    required this.label,
    required this.selected,
    required this.onSelected,
  });

  final String label;
  final bool selected;
  final VoidCallback onSelected;

  @override
  Widget build(BuildContext context) {
    return ChoiceChip(
      label: Text(label),
      selected: selected,
      onSelected: (_) => onSelected(),
      labelStyle: TextStyle(
        fontSize: 12,
        fontWeight: selected ? FontWeight.bold : FontWeight.normal,
      ),
    );
  }
}

class _ApiLogCard extends StatelessWidget {
  const _ApiLogCard({required this.entry, required this.onTap});

  final ApiLogEntry entry;
  final VoidCallback onTap;

  Color _methodColor(String method) {
    switch (method.toUpperCase()) {
      case 'GET':
        return Colors.blue.shade700;
      case 'POST':
        return Colors.green.shade700;
      case 'PUT':
      case 'PATCH':
        return Colors.orange.shade800;
      case 'DELETE':
        return Colors.red.shade700;
      case 'APP':
        return Colors.purple.shade700;
      default:
        return AppColors.muted;
    }
  }

  Color _statusColor(int? code, bool isError) {
    if (code == null || isError) return AppColors.danger;
    if (code >= 200 && code < 300) return AppColors.success;
    if (code >= 300 && code < 400) return Colors.indigo;
    if (code >= 400 && code < 500) return AppColors.warning;
    return AppColors.danger;
  }

  @override
  Widget build(BuildContext context) {
    final statusColor = _statusColor(entry.statusCode, entry.isError);
    final timeStr = '${entry.timestamp.hour.toString().padLeft(2, '0')}:'
        '${entry.timestamp.minute.toString().padLeft(2, '0')}:'
        '${entry.timestamp.second.toString().padLeft(2, '0')}';

    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(10),
      child: Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(
          color: Theme.of(context).cardColor,
          borderRadius: BorderRadius.circular(10),
          border: Border.all(
            color: entry.isError
                ? AppColors.danger.withOpacity(0.5)
                : (entry.isSlow ? AppColors.warning.withOpacity(0.5) : AppColors.border),
          ),
        ),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: <Widget>[
            Row(
              children: <Widget>[
                // شارة نوع الطلب Method
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                  decoration: BoxDecoration(
                    color: _methodColor(entry.method).withOpacity(0.12),
                    borderRadius: BorderRadius.circular(6),
                    border: Border.all(color: _methodColor(entry.method).withOpacity(0.4)),
                  ),
                  child: Text(
                    entry.method,
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: _methodColor(entry.method),
                    ),
                  ),
                ),
                const SizedBox(width: 8),

                // شارة الحالة HTTP Status
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                  decoration: BoxDecoration(
                    color: statusColor.withOpacity(0.12),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Text(
                    entry.displayStatus,
                    style: TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.bold,
                      color: statusColor,
                    ),
                  ),
                ),
                const SizedBox(width: 8),

                // شارة الزمن واللاق
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 7, vertical: 3),
                  decoration: BoxDecoration(
                    color: entry.isSlow
                        ? AppColors.warning.withOpacity(0.15)
                        : Colors.grey.withOpacity(0.1),
                    borderRadius: BorderRadius.circular(6),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      if (entry.isSlow) ...<Widget>[
                        const Text('⚠️ ', style: TextStyle(fontSize: 10)),
                      ],
                      Text(
                        entry.durationLabel,
                        style: TextStyle(
                          fontSize: 11,
                          fontWeight: entry.isSlow ? FontWeight.bold : FontWeight.normal,
                          color: entry.isSlow ? AppColors.warning : AppColors.muted,
                        ),
                      ),
                    ],
                  ),
                ),

                const Spacer(),

                // وقت الطلب
                Text(
                  timeStr,
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: AppColors.muted),
                ),
              ],
            ),
            const SizedBox(height: 8),

            // رابط ومسار الطلب
            Text(
              entry.endpoint.isNotEmpty ? entry.endpoint : entry.url,
              style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
            ),

            // معاينة الخطأ في حال وجوده
            if (entry.errorMessage != null) ...<Widget>[
              const SizedBox(height: 6),
              Container(
                padding: const EdgeInsets.all(8),
                decoration: BoxDecoration(
                  color: AppColors.danger.withOpacity(0.08),
                  borderRadius: BorderRadius.circular(6),
                ),
                child: Text(
                  entry.errorMessage!,
                  style: const TextStyle(fontSize: 11, color: AppColors.danger),
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ],
        ),
      ),
    );
  }
}

class _ApiLogDetailSheet extends StatelessWidget {
  const _ApiLogDetailSheet({required this.entry});

  final ApiLogEntry entry;

  Future<void> _copyText(BuildContext context, String text, String message) async {
    await Clipboard.setData(ClipboardData(text: text));
    if (context.mounted) AppFeedback.success(context, message);
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.85,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      expand: false,
      builder: (context, scrollController) {
        return Container(
          decoration: BoxDecoration(
            color: Theme.of(context).scaffoldBackgroundColor,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(20)),
          ),
          child: Column(
            children: <Widget>[
              // المقبض العلوي
              Center(
                child: Container(
                  margin: const EdgeInsets.symmetric(vertical: 10),
                  width: 40,
                  height: 4,
                  decoration: BoxDecoration(
                    color: AppColors.muted.withOpacity(0.3),
                    borderRadius: BorderRadius.circular(2),
                  ),
                ),
              ),

              // العنوان وأزرار النسخ السريعة
              Padding(
                padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                child: Row(
                  children: <Widget>[
                    Expanded(
                      child: Text(
                        'تفاصيل الطلب: ${entry.method} ${entry.endpoint}',
                        style: Theme.of(context).textTheme.titleMedium?.copyWith(
                              fontWeight: FontWeight.bold,
                            ),
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                      ),
                    ),
                    IconButton(
                      tooltip: 'نسخ cURL',
                      icon: const Icon(Icons.terminal_rounded),
                      onPressed: () => _copyText(context, entry.toCurl(), 'تم نسخ أمر cURL'),
                    ),
                    IconButton(
                      tooltip: 'نسخ تقرير العملية بالكامل',
                      icon: const Icon(Icons.copy_rounded),
                      onPressed: () => _copyText(context, entry.toDetailedReport(), 'تم نسخ التقرير كاملاً'),
                    ),
                  ],
                ),
              ),
              const Divider(),

              Expanded(
                child: ListView(
                  controller: scrollController,
                  padding: const EdgeInsets.all(16),
                  children: <Widget>[
                    // بطاقة المعلومات العامة
                    _buildSectionCard(
                      context,
                      title: 'المعلومات العامة (Overview)',
                      children: <Widget>[
                        _buildRow('الرابط الكامل', entry.url),
                        _buildRow('طريقة الطلب', entry.method),
                        _buildRow('رمز الحالة', entry.displayStatus),
                        _buildRow(
                          'زمن المعالجة الكلي',
                          '${entry.durationMs}ms ${entry.isSlow ? '(⚠️ لاق وبطء شديد)' : ''}',
                        ),
                        if (entry.serverDurationMs != null)
                          _buildRow('زمن استجابة الخادم الداخلي', '${entry.serverDurationMs}ms'),
                        if (entry.requestId != null)
                          _buildRow('معرف الطلب (X-Request-Id)', entry.requestId!),
                        _buildRow('التاريخ والوقت', entry.timestamp.toIso8601String()),
                      ],
                    ),
                    const SizedBox(height: 12),

                    // في حال وجود خطأ
                    if (entry.isError || entry.errorMessage != null) ...<Widget>[
                      _buildSectionCard(
                        context,
                        title: 'تفاصيل الخطأ (Error)',
                        borderColor: AppColors.danger,
                        children: <Widget>[
                          if (entry.errorType != null)
                            _buildRow('نوع الخطأ', entry.errorType!),
                          if (entry.errorMessage != null)
                            _buildRow('رسالة الخطأ', entry.errorMessage!),
                          if (entry.stackTrace != null) ...<Widget>[
                            const SizedBox(height: 6),
                            const Text(
                              'تتبع الاستثناء (Stack Trace):',
                              style: TextStyle(fontWeight: FontWeight.bold, fontSize: 12),
                            ),
                            const SizedBox(height: 4),
                            Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: Colors.black.withOpacity(0.05),
                                borderRadius: BorderRadius.circular(6),
                              ),
                              child: SelectableText(
                                entry.stackTrace!,
                                style: const TextStyle(fontSize: 10, fontFamily: 'monospace'),
                              ),
                            ),
                          ],
                        ],
                      ),
                      const SizedBox(height: 12),
                    ],

                    // متغيرات الاستعلام Query Parameters
                    if (entry.queryParams != null && entry.queryParams!.isNotEmpty) ...<Widget>[
                      _buildSectionCard(
                        context,
                        title: 'متغيرات الاستعلام (Query Parameters)',
                        children: <Widget>[
                          _buildJsonView(entry.queryParams),
                        ],
                      ),
                      const SizedBox(height: 12),
                    ],

                    // بيانات الطلب المُرسلة Request Body
                    if (entry.requestBody != null) ...<Widget>[
                      _buildSectionCard(
                        context,
                        title: 'بيانات الطلب المُرسلة (Request Payload)',
                        children: <Widget>[
                          _buildJsonView(entry.requestBody),
                        ],
                      ),
                      const SizedBox(height: 12),
                    ],

                    // استجابة الخادم Response Body
                    if (entry.responseBody != null) ...<Widget>[
                      _buildSectionCard(
                        context,
                        title: 'استجابة الخادم (Response Body)',
                        children: <Widget>[
                          _buildJsonView(entry.responseBody),
                        ],
                      ),
                      const SizedBox(height: 12),
                    ],

                    // ترويسات الطلب Request Headers
                    if (entry.requestHeaders != null && entry.requestHeaders!.isNotEmpty) ...<Widget>[
                      _buildSectionCard(
                        context,
                        title: 'ترويسات الطلب (Headers)',
                        children: <Widget>[
                          _buildJsonView(entry.requestHeaders),
                        ],
                      ),
                      const SizedBox(height: 12),
                    ],

                    // زر نسخ cURL في الأسفل
                    FilledButton.icon(
                      onPressed: () => _copyText(context, entry.toCurl(), 'تم نسخ أمر cURL'),
                      icon: const Icon(Icons.terminal_rounded),
                      label: const Text('نسخ أمر cURL لتجربة الطلب'),
                    ),
                    const SizedBox(height: 24),
                  ],
                ),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _buildSectionCard(
    BuildContext context, {
    required String title,
    required List<Widget> children,
    Color? borderColor,
  }) {
    return Container(
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Theme.of(context).cardColor,
        borderRadius: BorderRadius.circular(10),
        border: Border.all(color: borderColor ?? AppColors.border),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Text(
            title,
            style: TextStyle(
              fontWeight: FontWeight.bold,
              fontSize: 13,
              color: borderColor ?? AppColors.accent,
            ),
          ),
          const SizedBox(height: 8),
          ...children,
        ],
      ),
    );
  }

  Widget _buildRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 3),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          SizedBox(
            width: 130,
            child: Text(
              label,
              style: const TextStyle(fontSize: 12, color: AppColors.muted),
            ),
          ),
          Expanded(
            child: SelectableText(
              value,
              style: const TextStyle(fontSize: 12, fontWeight: FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }

  Widget _buildJsonView(dynamic data) {
    String formatted;
    try {
      if (data is String) {
        final decoded = jsonDecode(data);
        formatted = const JsonEncoder.withIndent('  ').convert(decoded);
      } else {
        formatted = const JsonEncoder.withIndent('  ').convert(data);
      }
    } catch (_) {
      formatted = data.toString();
    }

    return Container(
      padding: const EdgeInsets.all(10),
      decoration: BoxDecoration(
        color: Colors.black.withOpacity(0.04),
        borderRadius: BorderRadius.circular(8),
      ),
      child: SelectableText(
        formatted,
        style: const TextStyle(fontSize: 11, fontFamily: 'monospace'),
      ),
    );
  }
}
