import 'dart:io';

import 'package:intl/intl.dart';
import 'package:path/path.dart' as path;
import 'package:path_provider/path_provider.dart';
import 'package:share_plus/share_plus.dart';

import '../../data/models/payment_model.dart';

/// نتيجة تصدير سندات اليوم.
class DailyReceiptsExport {
  const DailyReceiptsExport({
    required this.file,
    required this.rowCount,
    required this.totalAmount,
    required this.day,
  });

  final File file;
  final int rowCount;
  final double totalAmount;
  final DateTime day;
}

/// تصدير سندات اليوم **من الجهاز** إلى CSV (خطة 2.0 — الفصل 6.2).
///
/// لماذا من الجهاز لا من الخادم؟ لأن الغرض مطابقة نقد المحصّل في نهاية اليوم
/// وقد يكون بلا شبكة أصلاً. الملف يُبنى من القائمة التي على الشاشة، ويُحفظ في
/// مجلد المستندات ويُشارَك عبر واتساب/البريد أو يُفتح في Excel.
///
/// التفاصيل المهمة:
/// * ترميز UTF-8 مع BOM حتى لا تظهر العربية مشوّشة في Excel على Windows.
/// * الفاصلة `,` هي الفاصل، وأي فاصلة/سطر داخل القيمة يُغلَّف بعلامات اقتباس.
/// * السندات الملغاة تُصدَّر بعمود «الحالة» ولا تُحذف — المطابقة تحتاج معرفة
///   ما أُلغي أيضاً.
/// * المستندات المحذوفة تُستثنى لأنها لا تمثّل نقداً مستلماً.
class DailyReceiptsExporter {
  const DailyReceiptsExporter({DateTime Function()? now})
      : _now = now ?? DateTime.now;

  final DateTime Function() _now;

  static final DateFormat _date = DateFormat('yyyy-MM-dd');
  static final DateFormat _stamp = DateFormat('yyyy-MM-dd HH:mm');

  static const List<String> headers = <String>[
    'رقم السند',
    'التاريخ',
    'المشترك/المستأجر',
    'العداد',
    'المبلغ',
    'طريقة الدفع',
    'الفاتورة',
    'المحصّل',
    'الصندوق',
    'واتساب',
    'الحالة',
    'السبب',
  ];

  /// يبني نص CSV كاملاً — دالة نقية قابلة للاختبار بلا ملفات.
  static String buildCsv(List<PaymentModel> payments, {DateTime? day}) {
    final buffer = StringBuffer()..write('\uFEFF');
    buffer.writeln(headers.map(_escape).join(','));

    final sorted = List<PaymentModel>.from(payments)
      ..sort((a, b) => a.receiptNumber.compareTo(b.receiptNumber));

    var total = 0.0;
    for (final payment in sorted) {
      if (payment.isDeleted) continue;
      total += payment.amount;
      buffer.writeln(
        <String>[
          payment.receiptNumber,
          payment.paymentDate == null ? '' : _date.format(payment.paymentDate!),
          payment.displayName,
          payment.meterLabel ?? '',
          payment.amount.toStringAsFixed(2),
          payment.method.label,
          payment.invoiceBillNumber ?? '',
          payment.collectorName ?? '',
          payment.cashSessionId?.toString() ?? '—',
          payment.whatsappSent ? 'نعم' : 'لا',
          payment.isVoided ? 'ملغى' : 'ساري',
          payment.voidReason ?? '',
        ].map(_escape).join(','),
      );
    }

    buffer.writeln(
      <String>['', '', 'الإجمالي', '', total.toStringAsFixed(2), '', '', '', '', '', '', '']
          .map(_escape)
          .join(','),
    );
    return buffer.toString();
  }

  /// إجمالي ما سيُصدَّر (بلا المحذوف) — يُعرض في الرسالة قبل التصدير.
  static double totalOf(List<PaymentModel> payments) => payments
      .where((payment) => !payment.isDeleted)
      .fold<double>(0, (sum, payment) => sum + payment.amount);

  /// يحفظ الملف ويعيده. لا يُشارك تلقائياً حتى لا يفتح تطبيق آخر بلا طلب.
  Future<DailyReceiptsExport> save(List<PaymentModel> payments) async {
    final day = _now();
    final directory = await getApplicationDocumentsDirectory();
    final folder = Directory(path.join(directory.path, 'exports'));
    await folder.create(recursive: true);

    final fileName = 'سندات-${_date.format(day)}.csv';
    final file = File(path.join(folder.path, fileName));
    await file.writeAsString(buildCsv(payments, day: day), flush: true);

    return DailyReceiptsExport(
      file: file,
      rowCount: payments.where((payment) => !payment.isDeleted).length,
      totalAmount: totalOf(payments),
      day: day,
    );
  }

  Future<void> share(DailyReceiptsExport export) async {
    await Share.shareXFiles(
      <XFile>[XFile(export.file.path, mimeType: 'text/csv')],
      text: 'سندات يوم ${_stamp.format(export.day)} — ${export.rowCount} سنداً',
    );
  }

  /// `2026-10-24 18:05` — للعرض في الرسالة.
  static String stamp(DateTime value) => _stamp.format(value);

  static String _escape(String value) {
    final needsQuotes =
        value.contains(',') || value.contains('"') || value.contains('\n') || value.contains('\r');
    final escaped = value.replaceAll('"', '""');
    return needsQuotes ? '"$escaped"' : escaped;
  }
}
