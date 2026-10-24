import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../core/auth/session_service.dart';
import '../../../../core/config/app_config.dart';
import '../../../../core/navigation/sub_page.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/setup/server_config_sheet.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../../core/widgets/app_text_field.dart';
import '../../../../core/widgets/confirm_dialog.dart';
import '../cubit/auth_cubit.dart';

class EditProfileScreen extends StatefulWidget {
  const EditProfileScreen({super.key});

  @override
  State<EditProfileScreen> createState() => _EditProfileScreenState();
}

class _EditProfileScreenState extends State<EditProfileScreen> {
  final _formKey = GlobalKey<FormState>();
  final _nameController = TextEditingController();
  final _emailController = TextEditingController();
  final _passwordController = TextEditingController();
  final _passwordConfirmationController = TextEditingController();
  final _picker = ImagePicker();
  File? _avatarFile;
  bool _obscurePassword = true;
  bool _obscureConfirmation = true;
  bool _initialized = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    if (_initialized) return;
    final user = GetIt.I<SessionService>().user ?? <String, dynamic>{};
    _nameController.text = user['name']?.toString() ?? '';
    _emailController.text = user['email']?.toString() ?? '';
    _initialized = true;
  }

  @override
  void dispose() {
    _nameController.dispose();
    _emailController.dispose();
    _passwordController.dispose();
    _passwordConfirmationController.dispose();
    super.dispose();
  }

  Future<void> _pickAvatar() async {
    final picked = await _picker.pickImage(source: ImageSource.gallery, imageQuality: 86, maxWidth: 1200);
    if (picked == null || !mounted) return;
    setState(() => _avatarFile = File(picked.path));
  }

  void _save() {
    if (!(_formKey.currentState?.validate() ?? false)) return;
    FocusManager.instance.primaryFocus?.unfocus();
    context.read<AuthCubit>().updateProfile(
          name: _nameController.text,
          email: _emailController.text,
          password: _passwordController.text.isEmpty ? null : _passwordController.text,
          avatar: _avatarFile,
        );
  }

  Future<void> _logout() async {
    final confirmed = await showConfirmDialog(
      context,
      title: 'تسجيل الخروج',
      message: 'ستنتهي الجلسة على هذا الجهاز. تبقى العمليات غير المتزامنة محفوظة لتكملها بعد تسجيل الدخول.',
      confirmLabel: 'تسجيل الخروج',
      destructive: true,
    );
    if (confirmed && mounted) await context.read<AuthCubit>().logout();
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
    final session = GetIt.I<SessionService>();
    final permissionService = GetIt.I<PermissionService>();
    final roles = permissionService.currentRoles;
    final user = session.user ?? <String, dynamic>{};
    final maxWidth = MediaQuery.sizeOf(context).width >= 840 ? 900.0 : 620.0;

    return BlocListener<AuthCubit, AuthState>(
      listenWhen: (previous, current) => previous.status != current.status || previous.message != current.message,
      listener: (context, state) {
        if (state.operation == AuthOperation.profile && state.status == AuthStatus.success) {
          _passwordController.clear();
          _passwordConfirmationController.clear();
          _avatarFile = null;
          ScaffoldMessenger.of(context).showSnackBar(const SnackBar(content: Text('تم تحديث الملف الشخصي بنجاح.')));
        } else if (state.operation == AuthOperation.profile && state.status == AuthStatus.failure) {
          ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(state.message ?? 'تعذر تحديث الملف الشخصي.')));
        }
      },
      child: BlocBuilder<AuthCubit, AuthState>(
        builder: (context, state) {
          final saving = state.operation == AuthOperation.profile && state.status == AuthStatus.loading;
          final avatarUrl = user['avatar']?.toString();
          return SingleChildScrollView(
            padding: EdgeInsets.fromLTRB(MediaQuery.sizeOf(context).width < 360 ? 14 : 22, 18, MediaQuery.sizeOf(context).width < 360 ? 14 : 22, 32),
            child: Center(
              child: ConstrainedBox(
                constraints: BoxConstraints(maxWidth: maxWidth),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: <Widget>[
                    _ProfileHero(
                      name: _nameController.text.isNotEmpty ? _nameController.text : (user['name']?.toString() ?? 'المستخدم'),
                      email: user['email']?.toString() ?? '',
                      avatarUrl: avatarUrl,
                      avatarFile: _avatarFile,
                      onEditAvatar: _pickAvatar,
                    ),
                    const SizedBox(height: 18),
                    _ProfileCard(
                      icon: Icons.badge_outlined,
                      title: 'المعلومات الشخصية',
                      subtitle: 'تظهر هذه البيانات في ملفك وسندات المعاملات.',
                      child: Form(
                        key: _formKey,
                        child: Column(
                          children: <Widget>[
                            AppTextField(
                              controller: _nameController,
                              label: 'الاسم الكامل',
                              prefixIcon: Icons.person_outline_rounded,
                              maxLength: 255,
                              onChanged: (_) => setState(() {}),
                              validator: (value) => (value == null || value.trim().isEmpty) ? 'الاسم مطلوب.' : null,
                            ),
                            const SizedBox(height: 14),
                            AppTextField(
                              controller: _emailController,
                              label: 'البريد الإلكتروني',
                              prefixIcon: Icons.alternate_email_rounded,
                              keyboardType: TextInputType.emailAddress,
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.left,
                              maxLength: 255,
                              validator: (value) {
                                final input = value?.trim() ?? '';
                                if (input.isEmpty) return 'البريد الإلكتروني مطلوب.';
                                if (!RegExp(r'^[^\s@]+@[^\s@]+\.[^\s@]+$').hasMatch(input)) return 'تحقق من صيغة البريد الإلكتروني.';
                                return null;
                              },
                            ),
                            const SizedBox(height: 18),
                            Align(
                              alignment: AlignmentDirectional.centerStart,
                              child: Text('تغيير كلمة المرور (اختياري)', style: theme.textTheme.titleMedium),
                            ),
                            const SizedBox(height: 10),
                            AppTextField(
                              controller: _passwordController,
                              label: 'كلمة المرور الجديدة',
                              prefixIcon: Icons.lock_outline_rounded,
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.left,
                              obscureText: _obscurePassword,
                              suffixIcon: IconButton(
                                tooltip: _obscurePassword ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور',
                                onPressed: () => setState(() => _obscurePassword = !_obscurePassword),
                                icon: Icon(_obscurePassword ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                              ),
                              validator: (value) {
                                final input = value ?? '';
                                if (input.isNotEmpty && input.length < 6) return 'استخدم 6 أحرف على الأقل.';
                                return null;
                              },
                            ),
                            const SizedBox(height: 14),
                            AppTextField(
                              controller: _passwordConfirmationController,
                              label: 'تأكيد كلمة المرور',
                              prefixIcon: Icons.verified_user_outlined,
                              textDirection: TextDirection.ltr,
                              textAlign: TextAlign.left,
                              obscureText: _obscureConfirmation,
                              suffixIcon: IconButton(
                                tooltip: _obscureConfirmation ? 'إظهار كلمة المرور' : 'إخفاء كلمة المرور',
                                onPressed: () => setState(() => _obscureConfirmation = !_obscureConfirmation),
                                icon: Icon(_obscureConfirmation ? Icons.visibility_outlined : Icons.visibility_off_outlined),
                              ),
                              validator: (value) {
                                if (_passwordController.text.isEmpty) return null;
                                if (value != _passwordController.text) return 'كلمتا المرور غير متطابقتين.';
                                return null;
                              },
                            ),
                            const SizedBox(height: 18),
                            AppPrimaryButton(label: 'حفظ التعديلات', icon: Icons.check_rounded, loading: saving, onPressed: saving ? null : _save),
                          ],
                        ),
                      ),
                    ),
                    const SizedBox(height: 18),
                    _ProfileCard(
                      icon: Icons.admin_panel_settings_outlined,
                      title: 'الأدوار والصلاحيات',
                      subtitle: 'تتحكم هذه الأدوار بالوحدات والإجراءات المتاحة لك.',
                      child: Wrap(
                        spacing: 8,
                        runSpacing: 8,
                        children: roles.isEmpty
                            ? const <Widget>[
                                AppPill(
                                  label: 'موظف',
                                  icon: Icons.person_outline_rounded,
                                  color: AppColors.primary,
                                ),
                              ]
                            : roles
                                .map(
                                  (role) => AppPill(
                                    label: _roleLabel(role),
                                    icon: _roleIcon(role),
                                    color: AppColors.primary,
                                  ),
                                )
                                .toList(growable: false),
                      ),
                    ),
                    const SizedBox(height: 18),
                    AppCard(
                      onTap: () => context.push(RouteNames.devices),
                      child: Row(
                        children: <Widget>[
                          const AppIconBubble(
                            icon: Icons.devices_rounded,
                            color: AppColors.danger,
                            size: 42,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: <Widget>[
                                Text(
                                  'الأجهزة المسجّلة',
                                  style: theme.textTheme.titleMedium?.copyWith(
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  'راجع الأجهزة المرتبطة بحسابك وأبطل المفقود منها',
                                  style: theme.textTheme.bodySmall,
                                ),
                              ],
                            ),
                          ),
                          const Icon(Icons.chevron_left_rounded, size: 20),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),
                    AppCard(
                      onTap: saving ? null : _openServerConfig,
                      child: Row(
                        children: <Widget>[
                          const AppIconBubble(
                            icon: Icons.dns_outlined,
                            color: AppColors.primary,
                            size: 42,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: <Widget>[
                                Text(
                                  'الاتصال بالخادم',
                                  style: theme.textTheme.titleMedium?.copyWith(
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  Uri.tryParse(AppConfig.apiBaseUrl)?.authority ??
                                      AppConfig.apiBaseUrl,
                                  maxLines: 1,
                                  overflow: TextOverflow.ellipsis,
                                  textDirection: TextDirection.ltr,
                                  style: theme.textTheme.bodySmall,
                                ),
                              ],
                            ),
                          ),
                          const Icon(Icons.chevron_left_rounded, size: 20),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),
                    AppCard(
                      onTap: state.operation == AuthOperation.logout &&
                              state.status == AuthStatus.loading
                          ? null
                          : _logout,
                      child: Row(
                        children: <Widget>[
                          const AppIconBubble(
                            icon: Icons.logout_rounded,
                            color: AppColors.danger,
                            size: 42,
                          ),
                          const SizedBox(width: 12),
                          Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: <Widget>[
                                Text(
                                  state.operation == AuthOperation.logout &&
                                          state.status == AuthStatus.loading
                                      ? 'جارٍ تسجيل الخروج…'
                                      : 'تسجيل الخروج',
                                  style: theme.textTheme.titleMedium?.copyWith(
                                    color: AppColors.danger,
                                    fontWeight: FontWeight.w600,
                                  ),
                                ),
                                const SizedBox(height: 2),
                                Text(
                                  'إنهاء الجلسة على هذا الجهاز',
                                  style: theme.textTheme.bodySmall,
                                ),
                              ],
                            ),
                          ),
                          const Icon(
                            Icons.arrow_back_ios_new_rounded,
                            size: 13,
                            color: AppColors.danger,
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
              ),
            ),
          );
        },
      ),
    );
  }

  IconData _roleIcon(String role) => switch (role.toLowerCase()) {
        'super_admin' || 'admin' => Icons.shield_rounded,
        'collector' => Icons.payments_outlined,
        'reader' || 'meter_reader' => Icons.speed_rounded,
        _ => Icons.badge_outlined,
      };

  String _roleLabel(String role) => switch (role.toLowerCase()) {
        'super_admin' => 'مدير النظام الأعلى',
        'admin' => 'مدير النظام',
        'collector' => 'محصل',
        'reader' || 'meter_reader' => 'قارئ عدادات',
        _ => role,
      };
}

class _ProfileHero extends StatelessWidget {
  const _ProfileHero({
    required this.name,
    required this.email,
    required this.avatarUrl,
    required this.avatarFile,
    required this.onEditAvatar,
  });

  final String name;
  final String email;
  final String? avatarUrl;
  final File? avatarFile;
  final VoidCallback onEditAvatar;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return AppHeroPanel(
      padding: const EdgeInsets.all(AppSpacing.lg),
      child: Row(
        children: <Widget>[
          Stack(
            clipBehavior: Clip.none,
            children: <Widget>[
              _HeroAvatar(image: _avatarImage()),
              PositionedDirectional(
                bottom: -2,
                end: -2,
                child: Material(
                  color: AppColors.accentOnPanel,
                  shape: const CircleBorder(),
                  child: InkWell(
                    onTap: onEditAvatar,
                    customBorder: const CircleBorder(),
                    child: const Padding(
                      padding: EdgeInsets.all(7),
                      child: Icon(Icons.edit_rounded, size: 15, color: AppColors.panel),
                    ),
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  name,
                  style: theme.textTheme.headlineSmall?.copyWith(
                    color: Colors.white,
                    fontWeight: FontWeight.w600,
                  ),
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                ),
                const SizedBox(height: 3),
                Directionality(
                  textDirection: TextDirection.ltr,
                  child: Text(
                    email,
                    style: theme.textTheme.bodySmall?.copyWith(color: Colors.white.withValues(alpha: .82)),
                    textAlign: TextAlign.start,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
                const SizedBox(height: 10),
                const HeroMetaChip(icon: Icons.verified_rounded, label: 'حساب موثّق'),
              ],
            ),
          ),
        ],
      ),
    );
  }

  ImageProvider<Object>? _avatarImage() {
    if (avatarFile != null) return FileImage(avatarFile!);
    if (avatarUrl == null || avatarUrl!.isEmpty) return null;
    return NetworkImage(avatarUrl!);
  }
}

class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.icon, required this.title, required this.subtitle, required this.child});

  final IconData icon;
  final String title;
  final String subtitle;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    return AppCard(
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          SectionHeading(title: title, subtitle: subtitle, icon: icon),
          const Gap(AppSpacing.md),
          child,
        ],
      ),
    );
  }
}

/// Avatar plate used on the ink hero: a rounded square, not a circle, so it
/// matches `AppGlyph` and the rest of the interface.
class _HeroAvatar extends StatelessWidget {
  const _HeroAvatar({required this.image});

  final ImageProvider<Object>? image;

  @override
  Widget build(BuildContext context) {
    final image = this.image;
    return Container(
      width: 64,
      height: 64,
      clipBehavior: Clip.antiAlias,
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: .10),
        borderRadius: BorderRadius.circular(AppRadius.xxl),
        border: Border.all(color: Colors.white.withValues(alpha: .22)),
      ),
      child: image == null
          ? const Icon(Icons.person_rounded, color: AppColors.onPanelSoft, size: 30)
          : Image(image: image, fit: BoxFit.cover),
    );
  }
}
