/// منسّق أرقام الهواتف اليمنية (+967) — وحدة Dev 3.
///
/// يطابق 100% دالة `WhatsAppService::formatPhoneNumber` (سطر 20-36 في
/// `app/Services/WhatsAppService.php`) و`$formatPhone` في `receipt.blade.php`
/// (سطر 348-360) المستخدمة في الطباعة.
class PhoneFormatter {
  PhoneFormatter._();

  /// يطابق `formatPhoneNumber`: ينزع كل غير الأرقام، يزيل الصفر الافتتاحي أو `00`،
  /// ويضيف `967` إذا كان الطول 9 ويبدأ بـ `7` أو `1`.
  /// يرجع `null` عند عدم وجود رقم صالح (وعندها تُخفى أزرار الواتساب).
  static String? toWhatsappDigits(String? phone) {
    if (phone == null) return null;
    var digits = phone.replaceAll(RegExp(r'[^0-9]'), '');
    if (digits.isEmpty) return null;

    if (digits.startsWith('00')) {
      digits = digits.substring(2);
    } else if (digits.startsWith('0')) {
      digits = digits.substring(1);
    }

    if (digits.length == 9 &&
        (digits.startsWith('7') || digits.startsWith('1'))) {
      digits = '967$digits';
    }

    return digits.isEmpty ? null : digits;
  }

  /// تنسيق العرض في السند الرسمي: `967 7XXXXXXXX` (يطابق `$formatPhone` في الـ Blade).
  static String forReceipt(String? phone) {
    if (phone == null || phone.trim().isEmpty || phone.trim() == '-') return '-';
    final cleaned = phone.replaceAll(' ', '');

    if (cleaned.startsWith('+967') && cleaned.length > 4) {
      return '+967 ${cleaned.substring(4)}';
    }
    if (cleaned.startsWith('967') && cleaned.length > 3) {
      return '967 ${cleaned.substring(3)}';
    }
    if (cleaned.startsWith('+') && cleaned.length > 4) {
      return '${cleaned.substring(0, 4)} ${cleaned.substring(4)}';
    }
    return cleaned;
  }

  static String? waMeLink(String? phone, String message) {
    final digits = toWhatsappDigits(phone);
    if (digits == null) return null;
    return 'https://wa.me/$digits?text=${Uri.encodeQueryComponent(message)}';
  }
}
