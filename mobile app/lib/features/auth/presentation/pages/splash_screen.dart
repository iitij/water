import 'dart:async';

import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../../../../core/auth/session_service.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/shimmer_skeleton.dart';

class SplashScreen extends StatefulWidget {
  const SplashScreen({super.key});

  @override
  State<SplashScreen> createState() => _SplashScreenState();
}

class _SplashScreenState extends State<SplashScreen> {
  @override
  void initState() {
    super.initState();
    unawaited(GetIt.I<SessionService>().bootstrap());
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Scaffold(
      body: Stack(
        children: <Widget>[
          const Positioned.fill(
            child: DecoratedBox(
              decoration: BoxDecoration(
                gradient: LinearGradient(
                  begin: Alignment.topRight,
                  end: Alignment.bottomLeft,
                  colors: <Color>[Color(0xFF0B2B40), AppColors.primary, Color(0xFF0EA5E9)],
                ),
              ),
            ),
          ),
          Positioned(
            top: -90,
            left: -70,
            child: _GlowCircle(size: 270, color: Colors.white.withOpacity(.08)),
          ),
          Positioned(
            bottom: -150,
            right: -70,
            child: _GlowCircle(size: 330, color: Colors.white.withOpacity(.07)),
          ),
          SafeArea(
            child: Center(
              child: ConstrainedBox(
                constraints: const BoxConstraints(maxWidth: 380),
                child: Padding(
                  padding: const EdgeInsets.all(28),
                  child: Column(
                    mainAxisAlignment: MainAxisAlignment.center,
                    children: <Widget>[
                      Container(
                        width: 92,
                        height: 92,
                        decoration: BoxDecoration(
                          color: Colors.white.withOpacity(.13),
                          borderRadius: BorderRadius.circular(30),
                          border: Border.all(color: Colors.white.withOpacity(.27)),
                        ),
                        child: const Icon(Icons.water_drop_rounded, color: Colors.white, size: 46),
                      ),
                      const SizedBox(height: 24),
                      Text('مياه الوشاح', style: theme.textTheme.headlineLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w800)),
                      const SizedBox(height: 6),
                      Text('نظام إدارة المشتركين والقراءات', style: theme.textTheme.bodyMedium?.copyWith(color: Colors.white.withOpacity(.82))),
                      const SizedBox(height: 34),
                      const SizedBox(width: 26, height: 26, child: CircularProgressIndicator(color: Colors.white, strokeWidth: 2.4)),
                      const SizedBox(height: 14),
                      Text('جارٍ تجهيز الجلسة الآمنة…', style: theme.textTheme.bodySmall?.copyWith(color: Colors.white.withOpacity(.78))),
                      const SizedBox(height: 44),
                      const ShimmerSkeleton(height: 5, width: 120, radius: 99),
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

class _GlowCircle extends StatelessWidget {
  const _GlowCircle({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
      );
}
