/// عقد توفير توكن Sanctum لطبقة الشبكة.
///
/// التنفيذ الفعلي في `core/auth/session_store.dart` (يملكه Dev 1). وجود العقد هنا
/// يمنع دورة الاستيراد بين الشبكة والمصادقة.
abstract interface class AuthTokenProvider {
  String? get bearerToken;
}
