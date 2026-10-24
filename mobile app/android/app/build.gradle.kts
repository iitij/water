import java.util.Properties
import java.io.FileInputStream

plugins {
    id("com.android.application")
    id("org.jetbrains.kotlin.android")
    // The Flutter Gradle Plugin must be applied after the Android and Kotlin Gradle plugins.
    id("dev.flutter.flutter-gradle-plugin")
}

// مفتاح التوقيع الإنتاجي (خطة 2.0 — الفصل 9.4).
//
// تُقرأ بيانات المفتاح من `android/key.properties` وهو **خارج Git**:
//   storeFile=/absolute/path/to/upload-keystore.jks
//   storePassword=…
//   keyAlias=upload
//   keyPassword=…
//
// عند غياب الملف (بناء محلي/CI بلا أسرار) يعود البناء إلى مفتاح debug حتى لا
// تتوقف دورة التطوير، ويُطبع تحذير واضح في سجل البناء.
val keystorePropertiesFile = rootProject.file("key.properties")
val keystoreProperties = Properties()
val hasReleaseKeystore = keystorePropertiesFile.exists()
if (hasReleaseKeystore) {
    FileInputStream(keystorePropertiesFile).use { keystoreProperties.load(it) }
}

android {
    namespace = "com.waterproject.app.water_project"
    compileSdk = flutter.compileSdkVersion
    ndkVersion = flutter.ndkVersion

    compileOptions {
        sourceCompatibility = JavaVersion.VERSION_17
        targetCompatibility = JavaVersion.VERSION_17
        // مطلوب لـflutter_local_notifications (S7-09): يستخدم java.time الذي
        // لا يوجد أصلاً في أندرويد أقدم من API 26.
        isCoreLibraryDesugaringEnabled = true
    }

    defaultConfig {
        applicationId = "com.waterproject.app.water_project"
        minSdk = flutter.minSdkVersion
        targetSdk = flutter.targetSdkVersion
        versionCode = flutter.versionCode
        versionName = flutter.versionName
        // نسخة 2.0 تعتمد https فقط؛ القيمة تُقرأ أيضاً في Dart (AppConfig).
        manifestPlaceholders["usesCleartextTraffic"] = "false"
    }

    signingConfigs {
        if (hasReleaseKeystore) {
            create("release") {
                storeFile = file(keystoreProperties.getProperty("storeFile"))
                storePassword = keystoreProperties.getProperty("storePassword")
                keyAlias = keystoreProperties.getProperty("keyAlias")
                keyPassword = keystoreProperties.getProperty("keyPassword")
            }
        }
    }

    buildTypes {
        release {
            isMinifyEnabled = true
            isShrinkResources = true
            proguardFiles(
                getDefaultProguardFile("proguard-android-optimize.txt"),
                "proguard-rules.pro",
            )
            signingConfig = if (hasReleaseKeystore) {
                signingConfigs.getByName("release")
            } else {
                logger.warn(
                    "تحذير: android/key.properties غير موجود — سيُوقَّع بناء الإصدار " +
                        "بمفتاح debug. لا تنشر هذا الملف في المتجر.",
                )
                signingConfigs.getByName("debug")
            }
        }
        debug {
            applicationIdSuffix = ".debug"
            versionNameSuffix = "-debug"
        }
    }
}

dependencies {
    coreLibraryDesugaring("com.android.tools:desugar_jdk_libs:2.1.4")
}

kotlin {
    compilerOptions {
        jvmTarget = org.jetbrains.kotlin.gradle.dsl.JvmTarget.JVM_17
    }
}

flutter {
    source = "../.."
}
