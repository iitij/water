import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/auth/session_service.dart';
import '../../../../core/config/app_config.dart';
import '../../../../core/ui/app_brand.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_tokens.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> with SingleTickerProviderStateMixin {
  late final AnimationController _pulse = AnimationController(
    vsync: this,
    duration: const Duration(milliseconds: 1800),
  )..repeat();

  @override
  void initState() {
    super.initState();
    unawaited(GetIt.I<SessionService>().bootstrap());
  }

  @override
  void dispose() {
    _pulse.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: Stack(
        children: <Widget>[
          const Positioned.fill(child: AquaBackdrop()),
          SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 400),
                child: Padding(
                  padding: const EdgeInsets.all(28),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      SizedBox.square(
                        dimension: 190,
                        child: AnimatedBuilder(
                          animation: _pulse,
                          builder: (context, child) {
                            final t = Curves.easeOut.transform(_pulse.value);
                            return Stack(
                              alignment: Alignment.center,
                              children: <Widget>[
                                _Ring(size: 130 + 60 * t, opacity: (1 - t) * .28),
                                _Ring(size: 110 + 30 * t, opacity: (1 - t) * .18),
                                child!,
                              ],
                            );
                          },
                          child: const AppBrandMark(size: 96),
                        ),
                      ),
                      const SizedBox(height: 22),
                      Text(
                        AppConfig.appName,
                        textAlign: TextAlign.center,
                        style: theme.textTheme.headlineLarge?.copyWith(
                          color: Colors.white,
                          fontWeight: FontWeight.w600,
                        ),
                      ),
                      const SizedBox(height: 5),
                      Text(
                        'منصة إدارة المشتركين والقراءات والتحصيل',
                        textAlign: TextAlign.center,
                        style: theme.textTheme.bodyMedium?.copyWith(
                          color: Colors.white.withValues(alpha: .8),
                        ),
                      ),
                      const SizedBox(height: 40),
                      SizedBox(
                        width: 132,
                        child: ClipRRect(
                          borderRadius: BorderRadius.circular(AppRadius.pill),
                          child: const LinearProgressIndicator(
                            minHeight: 4,
                            backgroundColor: Color(0x33FFFFFF),
                            valueColor: AlwaysStoppedAnimation<Color>(Colors.white),
                          ),
                        ),
                      ),
                      const SizedBox(height: 14),
                      Text(
                        'جارٍ تجهيز الجلسة الآمنة…',
                        style: theme.textTheme.labelSmall?.copyWith(color: Colors.white70),
                      ),
                      const SizedBox(height: 46),
                      Text(
                        'الإصدار 1.0.0',
                        style: theme.textTheme.labelSmall?.copyWith(color: Colors.white38),
                      ),
                    ],
                  ),
                ),
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _Ring extends StatelessWidget {
  const _Ring({required this.size, required this.opacity});

  final double size;
  final double opacity;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        shape: BoxShape.circle,
        border: Border.all(
          color: Colors.white.withValues(alpha: opacity),
          width: 1.4,
        ),
        color: Colors.white.withValues(alpha: opacity * .12),
      ),
    );
  }
}
