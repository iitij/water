import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:water_project/core/theme/app_colors.dart';
import 'package:water_project/core/theme/app_theme.dart';

double _contrast(Color a, Color b) {
  final first = a.computeLuminance();
  final second = b.computeLuminance();
  final lighter = first > second ? first : second;
  final darker = first > second ? second : first;
  return (lighter + .05) / (darker + .05);
}

void main() {
  test('dark theme uses the requested green palette with readable pairs', () {
    final theme = AppTheme.dark;
    final scheme = theme.colorScheme;

    expect(theme.scaffoldBackgroundColor, const Color(0xFF0B1513));
    expect(scheme.surface, const Color(0xFF162420));
    expect(scheme.surfaceContainerHighest, const Color(0xFF263A32));
    expect(scheme.primary, const Color(0xFF58CB99));
    expect(scheme.onSurface, const Color(0xFFF2F7F3));
    expect(scheme.brightness, Brightness.dark);
    expect(
      _contrast(scheme.onSurface, scheme.surface),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrast(scheme.onSurfaceVariant, scheme.surfaceContainerHighest),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrast(scheme.onPrimary, scheme.primary),
      greaterThanOrEqualTo(4.5),
    );
  });

  test('light theme has distinct white cards and readable green actions', () {
    final theme = AppTheme.light;
    final scheme = theme.colorScheme;

    expect(scheme.brightness, Brightness.light);
    expect(scheme.surface, Colors.white);
    expect(theme.scaffoldBackgroundColor, AppColors.paper);
    expect(theme.scaffoldBackgroundColor, isNot(scheme.surface));
    expect(
      _contrast(scheme.onSurface, scheme.surface),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrast(scheme.onSurfaceVariant, scheme.surface),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrast(scheme.onPrimary, scheme.primary),
      greaterThanOrEqualTo(4.5),
    );
    expect(
      _contrast(scheme.onPrimaryContainer, scheme.primaryContainer),
      greaterThanOrEqualTo(4.5),
    );
  });
}
