import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/di/injection_container.dart';
import '../../../../core/diagnostics/api_log_manager.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_tokens.dart';

/// زر عائم قابل للسحب يظهر فوق الشاشات لمراقبة طلبات الـ API والأخطاء في الوقت الفعلي.
///
/// عند النقر عليه، يفتح شاشة سجلات الـ API مباشرة من أي مكان في التطبيق.
class FloatingApiLogOverlay extends StatefulWidget {
  const FloatingApiLogOverlay({
    super.key,
    required this.child,
  });

  final Widget child;

  @override
  State<FloatingApiLogOverlay> createState() => _FloatingApiLogOverlayState();
}

class _FloatingApiLogOverlayState extends State<FloatingApiLogOverlay> {
  final ApiLogManager _logManager = getIt<ApiLogManager>();

  Offset _position = const Offset(16, 120);

  @override
  void initState() {
    super.initState();
    _logManager.addListener(_onUpdated);
  }

  @override
  void dispose() {
    _logManager.removeListener(_onUpdated);
    super.dispose();
  }

  void _onUpdated() {
    if (mounted) setState(() {});
  }

  @override
  Widget build(BuildContext context) {
    if (!_logManager.showFloatingPill) {
      return widget.child;
    }

    final total = _logManager.totalCount;
    final errors = _logManager.errorCount;
    final hasErrors = errors > 0;
    final hasSlow = _logManager.slowCount > 0;

    return Stack(
      children: <Widget>[
        widget.child,
        Positioned(
          left: _position.dx,
          top: _position.dy,
          child: GestureDetector(
            onPanUpdate: (details) {
              setState(() {
                _position += details.delta;
              });
            },
            onTap: () {
              context.push(RouteNames.apiLogs);
            },
            child: Material(
              color: Colors.transparent,
              child: Container(
                padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
                decoration: BoxDecoration(
                  color: hasErrors
                      ? AppColors.danger
                      : (hasSlow ? AppColors.warning : AppColors.panel),
                  borderRadius: BorderRadius.circular(AppRadius.xl),
                  border: Border.all(color: AppColors.panelLine),
                  boxShadow: AppShadows.floating(context),
                ),
                child: Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    Icon(
                      hasErrors
                          ? Icons.error_outline_rounded
                          : (hasSlow ? Icons.speed_rounded : Icons.sync_alt_rounded),
                      size: 14,
                      color: AppColors.onPanel,
                    ),
                    const SizedBox(width: 5),
                    Text(
                      'API: $total',
                      style: const TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: AppColors.onPanel,
                      ),
                    ),
                    if (hasErrors) ...<Widget>[
                      const SizedBox(width: 4),
                      Container(
                        padding: const EdgeInsets.symmetric(horizontal: 4, vertical: 1),
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(AppRadius.xs),
                        ),
                        child: Text(
                          '$errors ❌',
                          style: const TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.bold,
                            color: AppColors.danger,
                          ),
                        ),
                      ),
                    ],
                  ],
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
