/// إعدادات الاتصال لوحدات فؤاد (Dev 4) — تُضبط وقت البناء:
/// flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api --dart-define=DEMO=false
/// الوضع الافتراضي بعد التوحيد DEMO=false لاستخدام عميل Dio الحقيقي المصادق عليه.
class AppConfig {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );
  static const bool demoMode = bool.fromEnvironment('DEMO', defaultValue: false);
  static const String authToken = String.fromEnvironment('API_TOKEN');
}
