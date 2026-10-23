/// إعدادات الاتصال — تُضبط وقت البناء:
/// flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api --dart-define=DEMO=false
/// الوضع الافتراضي DEMO=true لعرض الوحدات ببيانات تجريبية دون خادم (عقد اليوم الأول).
class AppConfig {
  static const String baseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'http://10.0.2.2:8000/api',
  );
  static const bool demoMode = bool.fromEnvironment('DEMO', defaultValue: true);
  static const String authToken = String.fromEnvironment('API_TOKEN');
}
