import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../../app.dart';
import '../../bootstrap.dart';
import '../config/app_config.dart';
import 'server_config_sheet.dart';

/// Server selection precedes all authenticated clients and offline workers.
/// Once saved, changing servers requires clearing app data to avoid moving
/// credentials or pending offline operations between unrelated backends.
class ServerSetupApp extends StatefulWidget {
  const ServerSetupApp({super.key});

  static const preferenceKey = 'water.server_url';

  @override
  State<ServerSetupApp> createState() => _ServerSetupAppState();
}

class _ServerSetupAppState extends State<ServerSetupApp> {
  final _form = GlobalKey<FormState>();
  late final _url = TextEditingController(
    text: AppConfig.configuredApiBaseUrl.isNotEmpty
        ? AppConfig.configuredApiBaseUrl
        : Platform.isWindows ? 'http://localhost:8000/api' : '',
  );
  bool _loading = false;
  bool _initializationFailed = false;
  String? _error;

  @override
  void dispose() {
    _url.dispose();
    super.dispose();
  }

  Future<void> _start() async {
    if (!(_form.currentState?.validate() ?? false)) return;
    setState(() { _loading = true; _error = null; });
    try {
      final url = AppConfig.normalizeApiBaseUrl(_url.text);
      AppConfig.configureServer(url);
      await bootstrap();
      final preferences = await SharedPreferences.getInstance();
      if (!await preferences.setString(ServerSetupApp.preferenceKey, url)) {
        throw StateError('Could not save server configuration');
      }
      runApp(const WaterApp());
    } catch (_) {
      if (mounted) {
        setState(() {
          _loading = false;
          _initializationFailed = true;
          _error = 'تعذر تهيئة التطبيق. أغلق التطبيق وأعد المحاولة.';
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) => MaterialApp(
    debugShowCheckedModeBanner: false,
    locale: const Locale('ar'),
    supportedLocales: const [Locale('ar')],
    localizationsDelegates: GlobalMaterialLocalizations.delegates,
    theme: ThemeData(colorSchemeSeed: const Color(0xFF075D73), fontFamily: 'IBMPlexSansArabic'),
    home: Scaffold(
      appBar: AppBar(title: const Text('إعداد خادم مشروع المياه')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Form(
              key: _form,
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  const Icon(Icons.water_drop, size: 64, color: Color(0xFF075D73)),
                  const SizedBox(height: 24),
                  const Text('نسخة اختبار — أدخل عنوان API لخادم Laravel.'),
                  const SizedBox(height: 12),
                  const Text('على Windows يمكن استخدام localhost. على الهاتف أدخل عنوان الكمبيوتر داخل الشبكة، واتصل بنفس شبكة Wi-Fi. localhost على الهاتف لا يشير إلى الكمبيوتر.'),
                  const SizedBox(height: 24),
                  TextFormField(
                    controller: _url,
                    enabled: !_loading && !_initializationFailed,
                    textDirection: TextDirection.ltr,
                    keyboardType: TextInputType.url,
                    autocorrect: false,
                    decoration: const InputDecoration(
                      labelText: 'API URL',
                      hintText: 'http://192.168.1.20:8000/api',
                    ),
                    validator: ServerConnectionService.validateInput,
                  ),
                  const SizedBox(height: 16),
                  const Text('HTTP للاختبار على شبكة موثوقة فقط؛ استخدم HTTPS للإنتاج. يمكنك تغيير عنوان الخادم لاحقاً من شاشة تسجيل الدخول أو الملف الشخصي بشرط عدم وجود عمليات أوفلاين معلّقة.'),
                  if (_error != null) Padding(
                    padding: const EdgeInsets.only(top: 16),
                    child: Text(_error!, style: const TextStyle(color: Colors.red)),
                  ),
                  const SizedBox(height: 24),
                  FilledButton(
                    onPressed: _loading || _initializationFailed ? null : _start,
                    child: Text(_loading ? 'جارٍ التهيئة…' : 'حفظ ومتابعة'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    ),
  );
}
