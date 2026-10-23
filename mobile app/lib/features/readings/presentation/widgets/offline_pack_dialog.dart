import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/database/offline_pack_downloader.dart';
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
    final result = _result;
    return Directionality(
      textDirection: TextDirection.rtl,
      child: AlertDialog(
        title: Row(
          children: [
            Icon(Icons.cloud_download_outlined, color: Theme.of(context).colorScheme.primary),
            const SizedBox(width: 8),
            const Expanded(child: Text('تنزيل حزمة العمل أوفلاين')),
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
                        const Icon(Icons.check_circle, color: Colors.green),
                        const SizedBox(width: 8),
                        Expanded(
                          child: Text(
                            'اكتمل حفظ البيانات للعمل الميداني دون اتصال!',
                            style: Theme.of(context).textTheme.titleSmall?.copyWith(fontWeight: FontWeight.bold),
                          ),
                        ),
                      ],
                    ),
                    const Divider(height: 20),
                    _statRow('المربعات المخزنة', '${result.squareCount}'),
                    _statRow('المشتركون', '${result.subscriberCount}'),
                    _statRow('العدادات', '${result.meterCount}'),
                    _statRow('سجل القراءات', '${result.readingCount}'),
                    _statRow('فترات العدادات الجاهزة', '${result.cachedPeriodCount}'),
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
                      value: widget.squares.any((s) => s.id == _selectedSquareId)
                          ? _selectedSquareId
                          : null,
                      isExpanded: true,
                      decoration: const InputDecoration(
                        labelText: 'نطاق الحزمة',
                        prefixIcon: Icon(Icons.location_city_outlined),
                        border: OutlineInputBorder(),
                      ),
                      items: [
                        const DropdownMenuItem<int?>(
                          value: null,
                          child: Text('كل المربعات السكنية'),
                        ),
                        ...widget.squares.map(
                          (sq) => DropdownMenuItem<int?>(
                            value: sq.id,
                            child: Text(sq.name, overflow: TextOverflow.ellipsis),
                          ),
                        ),
                      ],
                      onChanged: _isDownloading
                          ? null
                          : (val) => setState(() => _selectedSquareId = val),
                    ),
                    if (_isDownloading) ...[
                      const SizedBox(height: 16),
                      LinearProgressIndicator(value: _progress),
                      const SizedBox(height: 8),
                      Row(
                        mainAxisAlignment: MainAxisAlignment.spaceBetween,
                        children: [
                          Expanded(
                            child: Text(
                              _stage,
                              style: Theme.of(context).textTheme.bodySmall,
                            ),
                          ),
                          Text(
                            '${(_progress * 100).round()}%',
                            style: Theme.of(context)
                                .textTheme
                                .bodySmall
                                ?.copyWith(fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
                    ],
                    if (_error != null) ...[
                      const SizedBox(height: 12),
                      Text(_error!, style: const TextStyle(color: Colors.red)),
                    ],
                  ],
                ),
        ),
        actions: [
          if (result != null)
            FilledButton(
              onPressed: () => Navigator.pop(context, result),
              child: const Text('تم'),
            )
          else ...[
            TextButton(
              onPressed: _isDownloading ? null : () => Navigator.pop(context),
              child: const Text('إلغاء'),
            ),
            FilledButton.icon(
              onPressed: _isDownloading ? null : _startDownload,
              icon: _isDownloading
                  ? const SizedBox.square(
                      dimension: 16,
                      child: CircularProgressIndicator(strokeWidth: 2),
                    )
                  : const Icon(Icons.download_outlined),
              label: Text(_isDownloading ? 'جارٍ التنزيل…' : 'بدء التنزيل'),
            ),
          ],
        ],
      ),
    );
  }

  Widget _statRow(String label, String value) => Padding(
        padding: const EdgeInsets.symmetric(vertical: 4),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.spaceBetween,
          children: [
            Text(label),
            Text(value, style: const TextStyle(fontWeight: FontWeight.bold)),
          ],
        ),
      );
}
