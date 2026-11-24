import 'package:flutter/material.dart';
import 'package:flutter/scheduler.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/di/injection_container.dart';
import '../../../../core/diagnostics/api_log_manager.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/security/developer_access_service.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_tokens.dart';

/// زر عائم قابل للسحب يظهر فوق الشاشات لمراقبة طلبات الـ API والأخطاء في الوقت الفعلي.
///
/// عند النقر عليه، يفتح شاشة سجلات الـ API مباشرة من أي مكان في التطبيق.
class FloatingApiLogOverlay extends StatefulWidget {
  const FloatingApiLogOverlay({super.key, required this.child});

  final Widget child;

  @override
  State<FloatingApiLogOverlay> createState() => _FloatingApiLogOverlayState();
}

class _FloatingApiLogOverlayState extends State<FloatingApiLogOverlay> {
  final ApiLogManager _logManager = getIt<ApiLogManager>();
  final DeveloperAccessService? _access =
      getIt.isRegistered<DeveloperAccessService>()
      ? getIt<DeveloperAccessService>()
      : null;

  bool get _canShow =>
      _logManager.showFloatingPill &&
      _access?.canOpenFeature('api_logs') == true;

  Offset _position = const Offset(16, 120);
  late bool _visible;
  bool _updateScheduled = false;

  @override
  void initState() {
    super.initState();
    _visible = _canShow;
    _logManager.addListener(_onUpdated);
    _access?.addListener(_onUpdated);
  }

  @override
  void dispose() {
    _logManager.removeListener(_onUpdated);
    _access?.removeListener(_onUpdated);
    super.dispose();
  }

  void _onUpdated() {
    if (!mounted) return;
    final visible = _canShow;
    if (!visible && !_visible) return;
    _visible = visible;
    if (SchedulerBinding.instance.schedulerPhase ==
        SchedulerPhase.persistentCallbacks) {
      if (_updateScheduled) return;
      _updateScheduled = true;
      WidgetsBinding.instance.addPostFrameCallback((_) {
        _updateScheduled = false;
        if (mounted) setState(() {});
      });
    } else {
      setState(() {});
    }
  }

  @override
  Widget build(BuildContext context) {
    if (!_canShow) {
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
              if (_access?.canOpenFeature('api_logs') == true) {
                context.push(RouteNames.apiLogs);
              }
            },
            child: Material(
              color: Colors.transparent,
              child: Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 10,
                  vertical: 6,
                ),
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
                          : (hasSlow
                                ? Icons.speed_rounded
                                : Icons.sync_alt_rounded),
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
                        padding: const EdgeInsets.symmetric(
                          horizontal: 4,
                          vertical: 1,
                        ),
                        decoration: BoxDecoration(
                          color: AppColors.surface,
                          borderRadius: BorderRadius.circular(AppRadius.xs),
                        ),
                        child: Text(
                          '$errors خطأ',
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
