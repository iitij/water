import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/config/app_config.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/setup/server_config_sheet.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/widgets/app_button.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/aqua_glass_card.dart';
import '../cubit/auth_cubit.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  bool _obscurePassword = true;
  bool _rememberSession = true;

  @override
  void dispose() {
    _emailController.dispose();
    _passwordController.dispose();
    super.dispose();
  }

  void _submit() {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    FocusManager.instance.primaryFocus?.unfocus();
    context.read<AuthCubit>().login(
          email: _emailController.text,
          password: _passwordController.text,
          rememberSession: _rememberSession,
        );
  }

  Future<void> _openServerConfig() async {
    final updated = await ServerConfigSheet.show(context);
    if (!mounted || updated == null) return;
    setState(() {});
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(content: Text('تم تحديث عنوان الخادم إلى: $updated')),
    );
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return BlocConsumer<AuthCubit, AuthState>(
      listener: (context, state) {
        if (state.operation == AuthOperation.login && state.status == AuthStatus.success) {
          context.go(RouteNames.home);
        }
      },
      builder: (context, state) {
        final loading = state.operation == AuthOperation.login && state.status == AuthStatus.loading;
        return Scaffold(
          body: Stack(
            children: <Widget>[
              const Positioned.fill(child: _AquaBackdrop()),
              Positioned(
                top: 15,
                left: 14,
                child: SafeArea(
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      IconButton.filledTonal(
                        tooltip: 'تبديل المظهر',
                        onPressed: GetIt.I<AppThemeController>().toggle,
                        icon: Icon(theme.brightness == Brightness.dark ? Icons.light_mode_rounded : Icons.dark_mode_rounded),
                      ),
                      const SizedBox(width: 8),
                      IconButton.filledTonal(
                        tooltip: 'تغيير عنوان الخادم',
                        onPressed: loading ? null : _openServerConfig,
                        icon: const Icon(Icons.dns_outlined),
                      ),
                    ],
                  ),
                ),
              ),
              SafeArea(
                child: LayoutBuilder(builder: (context, constraints) {
                  final isTablet = constraints.maxWidth >= 840;
                  final horizontalPadding = constraints.maxWidth < 360 ? 16.0 : 28.0;
                  final loginCard = ConstrainedBox(
                    constraints: const BoxConstraints(maxWidth: 460),
                    child: AquaGlassCard(
                      padding: EdgeInsets.all(constraints.maxWidth < 360 ? 20 : 30),
                      child: _loginForm(context, theme, state, loading),
                    ),
                  );

                  if (isTablet) {
                    return SingleChildScrollView(
                      child: ConstrainedBox(
                        constraints: BoxConstraints(minHeight: constraints.maxHeight),
                        child: Center(
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 1160),
                            child: Padding(
                              padding: const EdgeInsets.all(28),
                              child: Row(
                                children: <Widget>[
                                  const Expanded(flex: 5, child: _BrandPanel()),
                                  const SizedBox(width: 30),
                                  Expanded(flex: 5, child: Center(child: loginCard)),
                                ],
                              ),
                            ),
                          ),
                        ),
                      ),
                    );
                  }

                  return Center(
                    child: SingleChildScrollView(
                      padding: EdgeInsets.symmetric(horizontal: horizontalPadding, vertical: 78),
                      child: Column(
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          const _CompactBrand(),
                          const SizedBox(height: 28),
                          loginCard,
                          const SizedBox(height: 22),
                          Text('آمن • سريع • جاهز للعمل الميداني', style: theme.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: .82))),
                        ],
                      ),
                    ),
                  );
                }),
              ),
            ],
          ),
        );
      },
    );
  }

  Widget _loginForm(BuildContext context, ThemeData theme, AuthState state, bool loading) {
    return Form(
      key: _formKey,
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Text('مرحباً بعودتك', style: theme.textTheme.headlineMedium?.copyWith(fontWeight: FontWeight.w800)),
          const SizedBox(height: 5),
          Text('سجّل الدخول إلى حساب مشروع المياه', style: theme.textTheme.bodyMedium?.copyWith(color: theme.hintColor)),
          const SizedBox(height: 26),
          AppTextField(
            controller: _emailController,
            label: 'البريد الإلكتروني',
            hint: 'name@example.com',
            prefixIcon: Icons.alternate_email_rounded,
            keyboardType: TextInputType.emailAddress,
            textDirection: TextDirection.ltr,
            textAlign: TextAlign.left,
            autofillHints: const <String>[AutofillHints.username, AutofillHints.email],
            validator: (value) {
              final input = value?.trim() ?? '';
              if (input.isEmpty) return 'أدخل البريد الإلكتروني.';
              if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(input)) return 'تحقق من صيغة البريد الإلكتروني.';
              return null;
            },
          ),
          const SizedBox(height: 15),
          AppTextField(
            controller: _passwordController,
            label: 'كلمة المرور',
            prefixIcon: Icons.lock_outline_rounded,
            textDirection: TextDirection.ltr,
            textAlign: TextAlign.left,
            obscureText: _obscurePassword,
            autofillHints: const <String>[AutofillHints.password],
            suffixIcon: IconButton(
              tooltip: _obscurePassword ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور',
              onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
              icon: Icon(_obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined),
            ),
            validator: (value) => (value == null || value.isEmpty) ? 'أدخل كلمة المرور.' : null,
            onFieldSubmitted: (_) => _submit(),
          ),
          const SizedBox(height: 8),
          CheckboxListTile.adaptive(
            contentPadding: EdgeInsets.zero,
            controlAffinity: ListTileControlAffinity.leading,
            value: _rememberSession,
            onChanged: loading ? null : (value) => setState(() => _rememberSession = value ?? true),
            title: const Text('الاحتفاظ بالجلسة على هذا الجهاز'),
            dense: true,
          ),
          if (state.status == AuthStatus.failure && state.operation == AuthOperation.login && state.message != null) ...<Widget>[
            const SizedBox(height: 6),
            _ErrorBanner(message: state.message!),
          ],
          const SizedBox(height: 18),
          AppButton(
            label: 'تسجيل الدخول',
            icon: Icons.arrow_back_rounded,
            loading: loading,
            onPressed: loading ? null : _submit,
          ),
          const SizedBox(height: 17),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: <Widget>[
              const Icon(Icons.shield_outlined, size: 16, color: AppColors.success),
              const SizedBox(width: 6),
              Text('اتصال محمي عبر رمز دخول آمن', style: theme.textTheme.bodySmall),
            ],
          ),
          const SizedBox(height: 10),
          Center(
            child: TextButton.icon(
              onPressed: loading ? null : _openServerConfig,
              icon: const Icon(Icons.dns_outlined, size: 16),
              label: Text(
                AppConfig.currentApiBaseUrl == null
                    ? 'إعدادات الاتصال بالخادم'
                    : 'الخادم: ${Uri.tryParse(AppConfig.currentApiBaseUrl!)?.authority ?? AppConfig.currentApiBaseUrl} (تغيير)',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

class _AquaBackdrop extends StatelessWidget {
  const _AquaBackdrop();

  @override
  Widget build(BuildContext context) => const DecoratedBox(
        decoration: BoxDecoration(
          gradient: LinearGradient(
            begin: Alignment.topRight,
            end: Alignment.bottomLeft,
            colors: <Color>[Color(0xFF062235), Color(0xFF075D73), Color(0xFF0E7490)],
            stops: <double>[0, .58, 1],
          ),
        ),
        child: Stack(
          children: <Widget>[
            Positioned(top: -120, right: -60, child: _BlurCircle(size: 340, color: Color(0x1FFFFFFF))),
            Positioned(bottom: -150, left: -70, child: _BlurCircle(size: 390, color: Color(0x170EA5E9))),
            Positioned(top: 140, left: 40, child: _BlurCircle(size: 120, color: Color(0x12FFFFFF))),
          ],
        ),
      );
}

class _BlurCircle extends StatelessWidget {
  const _BlurCircle({required this.size, required this.color});
  final double size;
  final Color color;

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: color, shape: BoxShape.circle),
      );
}

class _CompactBrand extends StatelessWidget {
  const _CompactBrand();

  @override
  Widget build(BuildContext context) => Column(
        children: <Widget>[
          Container(
            width: 64,
            height: 64,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(22), border: Border.all(color: Colors.white.withValues(alpha: .25))),
            child: const Icon(Icons.water_drop_rounded, color: Colors.white, size: 34),
          ),
          const SizedBox(height: 12),
          Text('مياه الوشاح', style: Theme.of(context).textTheme.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w800)),
        ],
      );
}

class _BrandPanel extends StatelessWidget {
  const _BrandPanel();

  @override
  Widget build(BuildContext context) {
    final style = Theme.of(context).textTheme;
    return Padding(
      padding: const EdgeInsetsDirectional.only(start: 24, end: 34),
      child: Column(
        mainAxisAlignment: MainAxisAlignment.center,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Container(
            width: 76,
            height: 76,
            decoration: BoxDecoration(color: Colors.white.withValues(alpha: .13), borderRadius: BorderRadius.circular(25), border: Border.all(color: Colors.white.withValues(alpha: .26))),
            child: const Icon(Icons.water_drop_rounded, color: Colors.white, size: 40),
          ),
          const SizedBox(height: 28),
          Text('إدارة أكثر وضوحاً،\nمن الميدان إلى المكتب', style: style.displayMedium?.copyWith(color: Colors.white, fontWeight: FontWeight.w800, height: 1.25)),
          const SizedBox(height: 14),
          Text('منظومة موحدة للقراءات والفواتير والتحصيل، مصممة لتعمل معك حتى عندما تضعف الشبكة.', style: style.titleMedium?.copyWith(color: Colors.white.withValues(alpha: .8), height: 1.7)),
          const SizedBox(height: 32),
          const _FeaturePill(icon: Icons.wifi_off_rounded, label: 'جاهز للعمل دون اتصال'),
          const SizedBox(height: 10),
          const _FeaturePill(icon: Icons.shield_outlined, label: 'صلاحيات وجلسات آمنة'),
        ],
      ),
    );
  }
}

class _FeaturePill extends StatelessWidget {
  const _FeaturePill({required this.icon, required this.label});
  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.symmetric(horizontal: 13, vertical: 9),
        decoration: BoxDecoration(color: Colors.white.withValues(alpha: .1), borderRadius: BorderRadius.circular(99), border: Border.all(color: Colors.white.withValues(alpha: .13))),
        child: Row(mainAxisSize: MainAxisSize.min, children: <Widget>[
          Icon(icon, size: 17, color: Colors.white),
          const SizedBox(width: 8),
          Text(label, style: Theme.of(context).textTheme.bodyMedium?.copyWith(color: Colors.white)),
        ]),
      );
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});
  final String message;

  @override
  Widget build(BuildContext context) => Container(
        padding: const EdgeInsets.all(12),
        decoration: BoxDecoration(color: const Color(0xFFFFF1F2), borderRadius: BorderRadius.circular(14), border: Border.all(color: const Color(0xFFFECACA))),
        child: Row(children: <Widget>[
          const Icon(Icons.error_outline_rounded, color: AppColors.danger, size: 19),
          const SizedBox(width: 9),
          Expanded(child: Text(message, style: const TextStyle(color: Color(0xFF991B1B), fontSize: 13, height: 1.45))),
        ]),
      );
}
