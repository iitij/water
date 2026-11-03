import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/database/offline_pack_downloader.dart';
import '../../../../core/l10n/laravel_screen_strings.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/info_notice.dart';
import '../../../meters/data/models/meter.dart';

class OfflinePackDialog extends StatefulWidget {
  const OfflinePackDialog({
    required this.downloader,
    required this.squares,
    this.initialSquareId,
    super.key,
  });

  final OfflinePackDownloader downloader;
  final List<SquareOption> squares;
  final int? initialSquareId;

  static Future<OfflinePackResult?> show(
    BuildContext context, {
    OfflinePackDownloader? downloader,
    required List<SquareOption> squares,
    int? initialSquareId,
  }) {
    final resolved = downloader ??
        (GetIt.I.isRegistered<OfflinePackDownloader>()
            ? GetIt.I<OfflinePackDownloader>()
            : null);
    if (resolved == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('خدمة حزمة الأوفلاين غير مهيأة حالياً.')),
      );
      return Future.value(null);
    }
    return showDialog<OfflinePackResult>(
      context: context,
      barrierDismissible: false,
      builder: (_) => OfflinePackDialog(
        downloader: resolved,
        squares: squares,
        initialSquareId: initialSquareId,
      ),
    );
  }

  @override
  State<OfflinePackDialog> createState() => _OfflinePackDialogState();
}

class _OfflinePackDialogState extends State<OfflinePackDialog> {
  int? _selectedSquareId;
  bool _isDownloading = false;
  double _progress = 0;
  String _stage = '';
  String? _error;
  OfflinePackResult? _result;

  @override
  void initState() {
    super.initState();
    _selectedSquareId = widget.initialSquareId;
  }

  Future<void> _startDownload() async {
    setState(() {
      _isDownloading = true;
      _error = null;
      _progress = 0.02;
      _stage = 'بدء التحميل…';
    });
    try {
      final res = await widget.downloader.download(
        squareId: _selectedSquareId,
        onProgress: (stage, progress) {
          if (!mounted) return;
          setState(() {
            _stage = stage;
            _progress = progress.clamp(0.0, 1.0);
          });
        },
      );
      if (!mounted) return;
      setState(() {
        _isDownloading = false;
        _result = res;
      });
    } catch (error) {
      if (!mounted) return;
      String message = 'تعذر تنزيل حزمة العمل الأوفلاين. تحقق من اتصال الإنترنت.';
      if (error is DioException) {
        final data = error.response?.data;
        if (data is Map && data['message'] != null) {
          message = data['message'].toString();
        }
      }
      setState(() {
        _isDownloading = false;
        _error = message;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final scheme = theme.colorScheme;
    final result = _result;
    return Directionality(
      textDirection: TextDirection.rtl,
      child: AlertDialog(
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(AppRadius.xl)),
        titlePadding: const EdgeInsetsDirectional.fromSTEB(18, 18, 18, 0),
        contentPadding: const EdgeInsetsDirectional.fromSTEB(18, 14, 18, 0),
        actionsPadding: const EdgeInsetsDirectional.fromSTEB(16, 10, 16, 16),
        title: Row(
          children: [
            AppGlyph(
              icon: Icons.cloud_download_outlined,
              tone: scheme.primary,
              size: 38,
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Text(
                'تنزيل حزمة العمل أوفلاين',
                style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w600),
              ),
            ),
          ],
        ),
        content: ConstrainedBox(
          constraints: const BoxConstraints(maxWidth: 420),
          child: result != null
              ? Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Row(
                      children: [
                        const AppGlyph(
                          icon: Icons.check_circle_rounded,
                          tone: AppColors.success,
                          size: 42,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Text(
                            'اكتمل حفظ البيانات للعمل الميداني دون اتصال!',
                            style: theme.textTheme.titleSmall?.copyWith(
                              fontWeight: FontWeight.w600,
                            ),
                          ),
                        ),
                      ],
                    ),
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 4),
                      decoration: BoxDecoration(
                        color: scheme.surfaceContainerHighest.withValues(alpha: .45),
                        borderRadius: BorderRadius.circular(AppRadius.lg),
                      ),
                      child: Column(
                        children: [
                          _statRow('المربعات المخزنة', '${result.squareCount}'),
                          _statRow('المشتركون', '${result.subscriberCount}'),
                          _statRow('العدادات', '${result.meterCount}'),
                          _statRow(LaravelScreenStrings.readings, '${result.readingCount}'),
                          _statRow('فترات العدادات الجاهزة', '${result.cachedPeriodCount}'),
                        ],
                      ),
                    ),
                  ],
                )
              : Column(
                  mainAxisSize: MainAxisSize.min,
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const Text(
                      'قم بتنزيل بيانات المربع السكني (المشتركين، العدادات، آخر القراءات، وفترات الفوترة) للعمل في الميدان بدون إنترنت.',
                    ),
                    const SizedBox(height: 14),
                    DropdownButtonFormField<int?>(
                      initialValue: widget.squares.any((s) => s.id == _selectedSquareId)
                          ? _selectedSquareId
                          : null,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'نطاق الحزمة',
                        prefixIcon: Icon(Icons.location_city_outlined),
                      ),
                      items: [
                        const DropdownMenuItem<int?>(
                          value: null,
                          child: Text('كل المربعات السكنية'),
                        ),
                        ...widget.squares.map(
                          (sq) => DropdownMenuItem<int?>(
                            value: sq.id,
                            child: Text(sq.displayName, overflow: TextOverflow.ellipsis),
                          ),
                        ),
                      ],
                      onChanged: _isDownloading
                          ? null
                          : (val) => setState(() => _selectedSquareId = val),
                    ),
                    if (_isDownloading) ...[
                      const SizedBox(height: 16),
                      LinearProgressIndicator(value: _progress, minHeight: 4),
                      const SizedBox(height: 8),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              _stage,
                              style: theme.textTheme.bodySmall,
                            ),
                          ),
                          Text(
                            '${(_progress * 100).round()}%',
                            style: theme.textTheme.bodySmall?.copyWith(
                              fontWeight: FontWeight.w600,
                              fontFeatures: const <FontFeature>[
                                FontFeature.tabularFigures()
                              ],
                            ),
                          ),
                        ],
                      ),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      InfoNotice(
                        text: _error!,
                        icon: Icons.error_outline_rounded,
                        color: scheme.error,
                      ),
                    ],
                  ],
                ),
        ),
        actions: [
          if (result != null)
            AppPrimaryButton(
              label: 'تم',
              onPressed: () => Navigator.pop(context, result),
              expand: false,
            )
          else ...[
            AppSecondaryButton(
              label: 'إلغاء',
              onPressed: _isDownloading ? null : () => Navigator.pop(context),
              expand: false,
            ),
            const SizedBox(width: 10),
            AppPrimaryButton(
              label: _isDownloading ? 'جارٍ التنزيل…' : 'بدء التنزيل',
              icon: Icons.download_outlined,
              loading: _isDownloading,
              onPressed: _isDownloading ? null : _startDownload,
              expand: false,
            ),
          ],
        ],
      ),
    );
  }

  Widget _statRow(String label, String value) {
    final theme = Theme.of(context);
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 5),
      child: Row(
        children: [
          Expanded(child: Text(label, style: theme.textTheme.bodySmall)),
          Text(
            value,
            style: theme.textTheme.bodyMedium?.copyWith(
              fontWeight: FontWeight.w600,
              fontFeatures: const <FontFeature>[FontFeature.tabularFigures()],
            ),
          ),
        ],
      ),
    );
  }
}
