import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/config/app_config.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/setup/server_config_sheet.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/theme/app_theme.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_motion.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_brand.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/ui/app_viz.dart';
import '../../../../core/widgets/app_text_field.dart';
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
              const Positioned.fill(child: AquaBackdrop()),
              PositionedDirectional(
                top: 12,
                start: 12,
                child: SafeArea(
                  child: AppIconButton(
                    icon: Theme.of(context).brightness == Brightness.dark
                        ? Icons.light_mode_rounded
                        : Icons.dark_mode_rounded,
                    color: Colors.white,
                    tooltip: 'تبديل المظهر',
                    onPressed: () => GetIt.I<AppThemeController>()
                        .toggle(current: Theme.of(context).brightness),
                  ),
                ),
              ),
              SafeArea(
                child: LayoutBuilder(
                  builder: (context, constraints) {
                    final wide = constraints.maxWidth >= 980;
                    final form = _LoginFormCard(
                      formKey: _formKey,
                      emailController: _emailController,
                      passwordController: _passwordController,
                      obscurePassword: _obscurePassword,
                      rememberSession: _rememberSession,
                      loading: loading,
                      errorMessage: state.status == AuthStatus.failure &&
                              state.operation == AuthOperation.login
                          ? state.message
                          : null,
                      onToggleObscure: () => setState(() => _obscurePassword = !_obscurePassword),
                      onToggleRemember: () => setState(() => _rememberSession = !_rememberSession),
                      onSubmit: loading ? null : _submit,
                      onOpenServerConfig: loading ? null : _openServerConfig,
                    );

                    if (wide) {
                      return Center(
                        child: SingleChildScrollView(
                          padding: const EdgeInsets.symmetric(horizontal: 40, vertical: 32),
                          child: ConstrainedBox(
                            constraints: const BoxConstraints(maxWidth: 1160),
                            child: Row(
                              crossAxisAlignment: CrossAxisAlignment.center,
                              children: <Widget>[
                                const Expanded(flex: 6, child: _BrandPanel()),
                                const SizedBox(width: 48),
                                Expanded(
                                  flex: 4,
                                  child: Center(
                                    child: ConstrainedBox(
                                      constraints: const BoxConstraints(maxWidth: 440),
                                      child: form,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ),
                      );
                    }

                    return Center(
                      child: SingleChildScrollView(
                        padding: EdgeInsets.symmetric(
                          horizontal: constraints.maxWidth < 380 ? 16 : 22,
                          vertical: 64,
                        ),
                        child: ConstrainedBox(
                          constraints: const BoxConstraints(maxWidth: 460),
                          child: Column(
                            mainAxisSize: MainAxisSize.min,
                            children: <Widget>[
                              const _CompactBrand(),
                              const SizedBox(height: 24),
                              form,
                              const SizedBox(height: 20),
                              _FooterNote(),
                            ],
                          ),
                        ),
                      ),
                    );
                  },
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}

class _LoginFormCard extends StatelessWidget {
  const _LoginFormCard({
    required this.formKey,
    required this.emailController,
    required this.passwordController,
    required this.obscurePassword,
    required this.rememberSession,
    required this.loading,
    required this.errorMessage,
    required this.onToggleObscure,
    required this.onToggleRemember,
    required this.onSubmit,
    required this.onOpenServerConfig,
  });

  final GlobalKey<FormState> formKey;
  final TextEditingController emailController;
  final TextEditingController passwordController;
  final bool obscurePassword;
  final bool rememberSession;
  final bool loading;
  final String? errorMessage;
  final VoidCallback onToggleObscure;
  final VoidCallback onToggleRemember;
  final VoidCallback? onSubmit;
  final VoidCallback? onOpenServerConfig;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppCard(
      padding: const EdgeInsets.all(AppSpacing.xl),
      radius: AppRadius.xxl,
      child: Form(
        key: formKey,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Row(
              children: <Widget>[
                AppIconBubble(icon: Icons.lock_person_rounded, color: AppColors.primary, size: 44),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        'مرحباً بعودتك',
                        style: theme.textTheme.titleLarge?.copyWith(fontWeight: FontWeight.w800),
                      ),
                      const SizedBox(height: 2),
                      Text('سجّل الدخول لمتابعة يومك', style: theme.textTheme.bodySmall),
                    ],
                  ),
                ),
              ],
            ),
            const Gap(AppSpacing.xl),
            AppTextField(
              controller: emailController,
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
                if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(input)) {
                  return 'تحقق من صيغة البريد الإلكتروني.';
                }
                return null;
              },
            ),
            const SizedBox(height: AppSpacing.md),
            AppTextField(
              controller: passwordController,
              label: 'كلمة المرور',
              prefixIcon: Icons.lock_outline_rounded,
              textDirection: TextDirection.ltr,
              textAlign: TextAlign.left,
              obscureText: obscurePassword,
              autofillHints: const <String>[AutofillHints.password],
              suffixIcon: IconButton(
                tooltip: obscurePassword ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور',
                onPressed: onToggleObscure,
                icon: Icon(obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined),
              ),
              validator: (value) => (value == null || value.isEmpty) ? 'أدخل كلمة المرور.' : null,
              onFieldSubmitted: (_) => onSubmit?.call(),
            ),
            const SizedBox(height: AppSpacing.sm),
            InkWell(
              onTap: loading ? null : onToggleRemember,
              borderRadius: BorderRadius.circular(AppRadius.sm),
              child: Padding(
                padding: const EdgeInsets.symmetric(vertical: 4),
                child: Row(
                  children: <Widget>[
                    SizedBox(
                      width: 24,
                      height: 24,
                      child: Checkbox(
                        value: rememberSession,
                        onChanged: loading ? null : (_) => onToggleRemember(),
                      ),
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'الاحتفاظ بالجلسة على هذا الجهاز',
                        style: theme.textTheme.bodySmall,
                      ),
                    ),
                  ],
                ),
              ),
            ),
            if (errorMessage != null) ...<Widget>[
              const Gap(AppSpacing.sm),
              AppNotice(
                title: 'تعذر تسجيل الدخول',
                message: errorMessage!,
                icon: Icons.error_outline_rounded,
                color: AppColors.danger,
              ),
            ],
            const Gap(AppSpacing.lg),
            AppPrimaryButton(
              label: 'تسجيل الدخول',
              icon: Icons.login_rounded,
              loading: loading,
              onPressed: onSubmit,
            ),
            const Gap(AppSpacing.md),
            Row(
              mainAxisAlignment: MainAxisAlignment.center,
              children: <Widget>[
                const Icon(Icons.shield_outlined, size: 15, color: AppColors.success),
                const SizedBox(width: 6),
                Flexible(
                  child: Text(
                    'اتصال محمي عبر رمز دخول آمن',
                    style: theme.textTheme.labelSmall,
                  ),
                ),
              ],
            ),
            Center(
              child: TextButton.icon(
                onPressed: onOpenServerConfig,
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
      ),
    );
  }
}

class _BrandPanel extends StatelessWidget {
  const _BrandPanel();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          children: <Widget>[
            const AppBrandMark(size: 56, glow: false),
            const SizedBox(width: 13),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: <Widget>[
                  Text(
                    AppConfig.appName,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: theme.textTheme.titleLarge?.copyWith(
                      color: Colors.white,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  Text(
                    'منصة إدارة المشروع',
                    style: theme.textTheme.labelSmall?.copyWith(color: Colors.white70),
                  ),
                ],
              ),
            ),
          ],
        ),
        const SizedBox(height: 34),
        Text(
          'إدارة واضحة،\nمن الميدان إلى المكتب.',
          style: theme.textTheme.displaySmall?.copyWith(
            color: Colors.white,
            fontWeight: FontWeight.w800,
            height: 1.28,
          ),
        ),
        const SizedBox(height: 14),
        Text(
          'منظومة واحدة للقراءات والفواتير والتحصيل والمشتركين، مصممة لتبقى معك حتى عندما تضعف الشبكة.',
          style: theme.textTheme.bodyLarge?.copyWith(
            color: Colors.white.withValues(alpha: .8),
            height: 1.7,
          ),
        ),
        const SizedBox(height: 26),
        Wrap(
          spacing: 8,
          runSpacing: 8,
          children: const <Widget>[
            _FeaturePill(icon: Icons.wifi_off_rounded, label: 'جاهز دون اتصال'),
            _FeaturePill(icon: Icons.shield_outlined, label: 'صلاحيات دقيقة'),
            _FeaturePill(icon: Icons.bolt_rounded, label: 'تحصيل فوري'),
          ],
        ),
        const SizedBox(height: 34),
        const _PreviewCard(),
      ],
    );
  }
}

class _FeaturePill extends StatelessWidget {
  const _FeaturePill({required this.icon, required this.label});

  final IconData icon;
  final String label;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(AppRadius.pill),
        border: Border.all(color: Colors.white.withValues(alpha: .16)),
      ),
      child: Row(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          Icon(icon, size: 15, color: Colors.white),
          const SizedBox(width: 6),
          Text(
            label,
            style: const TextStyle(color: Colors.white, fontSize: 12, fontWeight: FontWeight.w600),
          ),
        ],
      ),
    );
  }
}

/// A static "product preview" card. It gives the login screen the feel of a
/// real product instead of an empty marketing page.
class _PreviewCard extends StatelessWidget {
  const _PreviewCard();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(18),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .09),
        borderRadius: BorderRadius.circular(AppRadius.xl),
        border: Border.all(color: Colors.white.withValues(alpha: .16)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(
            children: <Widget>[
              Text(
                'ملخص اليوم',
                style: theme.textTheme.titleSmall?.copyWith(color: Colors.white, fontWeight: FontWeight.w800),
              ),
              const Spacer(),
              Container(
                padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                decoration: BoxDecoration(
                  color: AppColors.success.withValues(alpha: .22),
                  borderRadius: BorderRadius.circular(AppRadius.pill),
                ),
                child: const Text(
                  'مباشر',
                  style: TextStyle(color: Color(0xFF6EE7B7), fontSize: 10.5, fontWeight: FontWeight.w700),
                ),
              ),
            ],
          ),
          const SizedBox(height: 14),
          Row(
            children: <Widget>[
              const Expanded(
                child: _PreviewMetric(
                  label: 'محصّل اليوم',
                  value: '12,480',
                  color: Color(0xFF6EE7B7),
                ),
              ),
              const SizedBox(width: 10),
              const Expanded(
                child: _PreviewMetric(
                  label: 'قراءات',
                  value: '86',
                  color: Color(0xFF7DD3FC),
                ),
              ),
            ],
          ),
          const SizedBox(height: 16),
          const SizedBox(
            height: 44,
            child: AppMiniBars(
              values: <double>[18, 26, 21, 34, 30, 42, 38],
              color: Color(0xFF5EEAD4),
              height: 44,
            ),
          ),
        ],
      ),
    );
  }
}

class _PreviewMetric extends StatelessWidget {
  const _PreviewMetric({required this.label, required this.value, required this.color});

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 10),
      decoration: BoxDecoration(
        color: Colors.black.withValues(alpha: .14),
        borderRadius: BorderRadius.circular(AppRadius.sm),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            label,
            style: TextStyle(color: Colors.white.withValues(alpha: .7), fontSize: 11),
          ),
          const SizedBox(height: 3),
          Directionality(
            textDirection: TextDirection.ltr,
            child: Text(
              value,
              style: TextStyle(color: color, fontSize: 20, fontWeight: FontWeight.w800, height: 1.1),
            ),
          ),
        ],
      ),
    );
  }
}

class _CompactBrand extends StatelessWidget {
  const _CompactBrand();

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Column(
      children: <Widget>[
        const AppBrandMark(size: 68),
        const SizedBox(height: 12),
        Text(
          AppConfig.appName,
          textAlign: TextAlign.center,
          style: theme.textTheme.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w800),
        ),
        const SizedBox(height: 2),
        Text(
          'منصة إدارة المشتركين والتحصيل',
          style: theme.textTheme.labelSmall?.copyWith(color: Colors.white70),
        ),
      ],
    );
  }
}

class _FooterNote extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final host = Uri.tryParse(AppConfig.apiBaseUrl)?.host ?? '';
    return Column(
      children: <Widget>[
        Text(
          host.isEmpty ? 'جاهز للعمل الميداني' : host,
          textDirection: TextDirection.ltr,
          style: const TextStyle(color: Colors.white70, fontSize: 11.5),
        ),
        const SizedBox(height: 4),
        const Text(
          'الإصدار 1.0.0 • جلسة مشفرة',
          style: TextStyle(color: Colors.white54, fontSize: 11),
        ),
      ],
    );
  }
}
