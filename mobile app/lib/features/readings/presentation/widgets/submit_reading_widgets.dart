part of '../pages/submit_reading_screen.dart';

class _NoticeBanner extends StatelessWidget {
  const _NoticeBanner({
    required this.text,
    required this.color,
    required this.icon,
  });

  final String text;
  final Color color;
  final IconData icon;

  @override
  Widget build(BuildContext context) =>
      InfoNotice(text: text, icon: icon, color: color);
}

class RecordReadingSheet {
  const RecordReadingSheet._();

  static Future<ReadingSaveResult?> show(
    BuildContext context, {
    required Meter meter,
    required ReadingsRepository readingsRepository,
    ReadingInvoiceActionsService? invoiceActions,
    Future<Meter?> Function(Meter currentMeter)? onNextPath,
  }) {
    return showModalBottomSheet<ReadingSaveResult>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      backgroundColor: Colors.transparent,
      builder: (context) => FractionallySizedBox(
        heightFactor: .96,
        child: ClipRRect(
          borderRadius: const BorderRadius.vertical(top: Radius.circular(22)),
          child: SubmitReadingScreen(
            meter: meter,
            readingsRepository: readingsRepository,
            invoiceActions: invoiceActions,
            onNextPath: onNextPath,
          ),
        ),
      ),
    );
  }
}
