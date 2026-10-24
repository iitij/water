# قواعد R8/ProGuard لنسخة 2.0.
#
# Flutter نفسها تُوفّر قواعدها تلقائياً؛ ما نحتاجه هنا هو ما يقرأه المحرّك
# بالانعكاس (reflection) من الحزم المساعدة.

# Drift / sqlite3
-keep class io.flutter.plugins.** { *; }
-keep class com.waterproject.** { *; }

# الحفاظ على أسماء الأنواع المستخدمة في JSON عبر حزم التسلسل
-keepattributes Signature
-keepattributes *Annotation*
-keepattributes EnclosingMethod
-dontwarn org.slf4j.**
