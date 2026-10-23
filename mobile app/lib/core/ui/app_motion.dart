import 'package:flutter/material.dart';

import '../theme/app_colors.dart';
import 'app_tokens.dart';

/// Small staggered entrance animation. Used on page sections so the layout
/// settles in instead of snapping into place.
class AppReveal extends StatefulWidget {
  const AppReveal({
    super.key,
    required this.child,
    this.delay = Duration.zero,
    this.offset = const Offset(0, .05),
  });

  final Widget child;
  final Duration delay;
  final Offset offset;

  @override
  State<AppReveal> createState() => _AppRevealState();
}

class _AppRevealState extends State<AppReveal> with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: AppMotion.slow,
  );
  late final Animation<double> _curve = CurvedAnimation(
    parent: _controller,
    curve: Curves.easeOutCubic,
  );

  @override
  void initState() {
    super.initState();
    if (widget.delay == Duration.zero) {
      _controller.forward();
      return;
    }
    Future<void>.delayed(widget.delay, () {
      if (mounted) _controller.forward();
    });
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return FadeTransition(
      opacity: _curve,
      child: SlideTransition(
        position: Tween<Offset>(begin: widget.offset, end: Offset.zero).animate(_curve),
        child: widget.child,
      ),
    );
  }
}

/// Subtle press feedback for custom tappable surfaces.
class PressableScale extends StatefulWidget {
  const PressableScale({super.key, required this.child, this.onTap, this.scale = .97});

  final Widget child;
  final VoidCallback? onTap;
  final double scale;

  @override
  State<PressableScale> createState() => _PressableScaleState();
}

class _PressableScaleState extends State<PressableScale> {
  bool _pressed = false;

  void _setPressed(bool value) {
    if (_pressed == value) return;
    setState(() => _pressed = value);
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTap: widget.onTap,
      onTapDown: widget.onTap == null ? null : (_) => _setPressed(true),
      onTapUp: widget.onTap == null ? null : (_) => _setPressed(false),
      onTapCancel: widget.onTap == null ? null : () => _setPressed(false),
      child: AnimatedScale(
        scale: _pressed ? widget.scale : 1,
        duration: AppMotion.press,
        curve: Curves.easeOut,
        child: widget.child,
      ),
    );
  }
}

/// A soft animated background wash used behind hero sections.
class AquaBackdrop extends StatelessWidget {
  const AquaBackdrop({
    super.key,
    this.colors = const <Color>[
      Color(0xFF04161F),
      Color(0xFF073B49),
      Color(0xFF0E7C86),
    ],
    this.child,
  });

  final List<Color> colors;
  final Widget? child;

  @override
  Widget build(BuildContext context) {
    return DecoratedBox(
      decoration: BoxDecoration(
        gradient: LinearGradient(
          begin: AlignmentDirectional.topStart,
          end: AlignmentDirectional.bottomEnd,
          colors: colors,
          stops: const <double>[0, .55, 1],
        ),
      ),
      child: Stack(
        children: <Widget>[
          PositionedDirectional(
            top: -140,
            end: -80,
            child: _Orb(size: 340, color: Colors.white.withValues(alpha: .1)),
          ),
          PositionedDirectional(
            bottom: -170,
            start: -80,
            child: _Orb(size: 380, color: AppColors.secondary.withValues(alpha: .16)),
          ),
          PositionedDirectional(
            top: 160,
            start: 30,
            child: _Orb(size: 120, color: Colors.white.withValues(alpha: .05)),
          ),
          if (child != null) Positioned.fill(child: child!),
        ],
      ),
    );
  }
}

class _Orb extends StatelessWidget {
  const _Orb({required this.size, required this.color});

  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(
          shape: BoxShape.circle,
          gradient: RadialGradient(colors: <Color>[color, color.withValues(alpha: 0)]),
        ),
      );
}
