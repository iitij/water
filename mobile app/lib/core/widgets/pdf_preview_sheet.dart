import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:printing/printing.dart';

import '../error/failures.dart';
import '../finance/finance_feedback.dart';
import '../services/pdf_print_service.dart';
import '../theme/app_colors.dart';

/// نافذة معاينة ملف PDF الرسمي (الفاتورة/السند) مع أزرار الطباعة والمشاركة.
///
/// تُستخدم في `SCR-14`/`SCR-15`/`SCR-17` وعند نجاح السند في `SCR-18`.
class PdfPreviewSheet extends StatelessWidget {
  const PdfPreviewSheet({
    super.key,
    required this.title,
    required this.bytes,
    this.fileName = 'document.pdf',
  });

  final String title;
  final Uint8List bytes;
  final String fileName;

  static Future<void> open(
    BuildContext context, {
    required String title,
    required PdfPrintService service,
    required Future<Uint8List> Function() loader,
    String fileName = 'document.pdf',
  }) async {
    try {
      final bytes = await loader();
      if (!context.mounted) return;
      await showModalBottomSheet<void>(
        context: context,
        isScrollControlled: true,
        useSafeArea: true,
        showDragHandle: true,
        builder: (_) => PdfPreviewSheet(
          title: title,
          bytes: bytes,
          fileName: fileName,
        ),
      );
    } on AppFailure catch (failure) {
      if (context.mounted) AppFeedback.error(context, failure.message);
    } catch (error) {
      if (context.mounted) {
        AppFeedback.error(context, 'تعذّر تحميل ملف PDF: $error');
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      expand: false,
      initialChildSize: 0.92,
      minChildSize: 0.5,
      builder: (context, scrollController) => Column(
        children: <Widget>[
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: Row(
              children: <Widget>[
                const Icon(Icons.picture_as_pdf_outlined, color: AppColors.primary),
                const SizedBox(width: 8),
                Expanded(
                  child: Text(
                    title,
                    style: Theme.of(context)
                        .textTheme
                        .titleMedium
                        ?.copyWith(fontWeight: FontWeight.w600),
                  ),
                ),
                IconButton(
                  tooltip: 'طباعة',
                  onPressed: () => _print(context),
                  icon: const Icon(Icons.print_outlined),
                ),
                IconButton(
                  tooltip: 'مشاركة',
                  onPressed: () => _share(context),
                  icon: const Icon(Icons.ios_share),
                ),
              ],
            ),
          ),
          const Divider(height: 1),
          Expanded(
            child: PdfPreview(
              build: (_) async => bytes,
              canChangePageFormat: false,
              canChangeOrientation: false,
              canDebug: false,
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 12),
              child: Row(
                children: <Widget>[
                  Expanded(
                    child: OutlinedButton.icon(
                      onPressed: () => _share(context),
                      icon: const Icon(Icons.share_outlined),
                      label: const Text('مشاركة الملف'),
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: FilledButton.icon(
                      onPressed: () => _print(context),
                      icon: const Icon(Icons.print_outlined),
                      label: const Text('طباعة مباشرة'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _print(BuildContext context) async {
    try {
      await Printing.layoutPdf(
        name: fileName,
        onLayout: (_) async => bytes,
      );
    } catch (error) {
      if (context.mounted) AppFeedback.error(context, 'تعذّرت الطباعة: $error');
    }
  }

  Future<void> _share(BuildContext context) async {
    try {
      await Printing.sharePdf(bytes: bytes, filename: fileName);
    } catch (error) {
      if (context.mounted) {
        AppFeedback.error(context, 'تعذّرت المشاركة: $error');
      }
    }
  }
}
