import 'dart:typed_data';

import 'package:print_bluetooth_thermal/print_bluetooth_thermal.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'esc_pos/esc_pos_buffer.dart';
import 'esc_pos/thermal_raster_renderer.dart';
import 'esc_pos/thermal_ticket.dart';
import '../settings/finance_config.dart';

enum ThermalPrintStatus { printed, notConnected, failed }

class ThermalPrintResult {
  const ThermalPrintResult(this.status, {this.message, this.deviceAddress});

  final ThermalPrintStatus status;
  final String? message;
  final String? deviceAddress;

  bool get isSuccess => status == ThermalPrintStatus.printed;
}

/// قناة الاتصال بالطابعة — فصلها يسمح بالاختبار بدون بلوتوث وبتجربة «معاينة البايتات».
abstract interface class ThermalPrinterChannel {
  Future<bool> isConnected();
  Future<bool> connect(String address);
  Future<void> writeBytes(Uint8List bytes);
  Future<void> disconnect();
}

/// تنفيذ الطابعات الحرارية عبر البلوتوث (Android) — `print_bluetooth_thermal`.
class BluetoothThermalChannel implements ThermalPrinterChannel {
  const BluetoothThermalChannel();

  @override
  Future<bool> isConnected() async {
    try {
      return await PrintBluetoothThermal.isConnected();
    } catch (_) {
      return false;
    }
  }

  @override
  Future<bool> connect(String address) async {
    try {
      return await PrintBluetoothThermal.connectBlt(address);
    } catch (_) {
      return false;
    }
  }

  @override
  Future<void> writeBytes(Uint8List bytes) =>
      PrintBluetoothThermal.writeBytes(bytes: bytes);

  @override
  Future<void> disconnect() async {
    try {
      await PrintBluetoothThermal.disconnect();
    } catch (_) {
      // تجاهل: انقطاع الاتصال ليس خطأ المستخدم.
    }
  }
}

/// قناة صامتة تُستخدم على الحوامل غير المدعومة (iOS/Desk) أو في الاختبارات.
class NullThermalChannel implements ThermalPrinterChannel {
  const NullThermalChannel();

  @override
  Future<bool> isConnected() async => false;
  @override
  Future<bool> connect(String address) async => false;
  @override
  Future<void> writeBytes(Uint8List bytes) async {}
  @override
  Future<void> disconnect() async {}
}

/// محرّك الطباعة الحرارية الثانية (58mm / 80mm) للفواتير وسندات القبض.
///
/// يطابق محتوى `receipt.blade.php` (الحقول التي يراها المشترك) ويعرض المبلغ
/// بالتفقيط العربي عبر `ArabicNumberSpellout`.
class ThermalPrinterService {
  ThermalPrinterService({
    required ThermalPrinterChannel channel,
    required FinanceConfigRepository configRepository,
    required SharedPreferences prefs,
  })  : _channel = channel,
        _config = configRepository,
        _prefs = prefs;

  static const String _addressKey = 'thermal.printer.address';

  final ThermalPrinterChannel _channel;
  final FinanceConfigRepository _config;
  final SharedPreferences _prefs;

  String? get savedAddress => _prefs.getString(_addressKey);

  Future<void> saveAddress(String address) =>
      _prefs.setString(_addressKey, address.trim());

  Future<void> clearAddress() => _prefs.remove(_addressKey);

  int get paperWidthMm => _config.cached.thermalPaperWidthMm;

  /// عدد النقاط الصالحة للسطر: 384 @58mm، 576 @80mm (203dpi).
  int get widthDots => paperWidthMm >= 80 ? 576 : 384;

  /// يولّد بايتات ESC/POS (تُستخدم في الاختبار وفي «معاينة قبل الطباعة»).
  Future<Uint8List> buildBytes(ThermalTicket ticket, {int? widthDots}) async {
    final raster = await ThermalRasterRenderer.render(
      ticket: ticket,
      widthDots: widthDots ?? this.widthDots,
    );

    final buffer = EscPosBuffer()
      ..initialize()
      ..align(1) // توسيط المحتوى أفقياً داخل عرض الورق.
      ..lineSpacing(28)
      ..rasterImage(
        widthDots: raster.widthDots,
        bytesPerRow: raster.bytesPerRow,
        bitmapRows: raster.rows,
      )
      ..align(0)
      ..feedLines(3)
      ..partialCut();

    return buffer.bytes;
  }

  /// مسار الطباعة الكامل: اتصال → إرسال → قطع.
  Future<ThermalPrintResult> printTicket(ThermalTicket ticket) async {
    final address = savedAddress;
    if (address == null || address.isEmpty) {
      return const ThermalPrintResult(
        ThermalPrintStatus.notConnected,
        message: 'لم يتم اختيار طابعة حرارية. افتح إعدادات الطابعة واختر الجهاز.',
      );
    }

    try {
      if (!await _channel.isConnected()) {
        final connected = await _channel.connect(address);
        if (!connected) {
          return ThermalPrintResult(
            ThermalPrintStatus.notConnected,
            message: 'تعذّر الاتصال بالطابعة $address — تأكد من إقلاعها وقربها.',
            deviceAddress: address,
          );
        }
      }

      await _channel.writeBytes(await buildBytes(ticket));
      return ThermalPrintResult(
        ThermalPrintStatus.printed,
        message: 'تمت الطباعة على $address',
        deviceAddress: address,
      );
    } catch (error) {
      return ThermalPrintResult(
        ThermalPrintStatus.failed,
        message: 'فشل الإرسال إلى الطابعة: $error',
        deviceAddress: address,
      );
    }
  }

  /// اختبار الاتصال بسطر قصير (يُستخدم في شاشة إعدادات الطابعة).
  Future<ThermalPrintResult> testPrint() => printTicket(
        ThermalTicket(
          title: 'اختبار الطابعة',
          lines: <ThermalLine>[
            ThermalSyntax.heading('طابعة المشروع الحرارية'),
            ThermalSyntax.field('الحالة', 'تعمل'),
            ThermalSyntax.divider,
            ThermalSyntax.big('Water Project'),
          ],
        ),
      );
}
