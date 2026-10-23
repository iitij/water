import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';
import 'package:get_it/get_it.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../core/auth/session_service.dart';
import '../../../../core/config/app_config.dart';
import '../../../../core/permissions/permission_service.dart';
import '../../../../core/setup/server_config_sheet.dart';
import '../../../../core/theme/app_colors.dart';
import '../../../../core/widgets/app_button.dart';
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
                            AppButton(label: 'حفظ التعديلات', icon: Icons.check_rounded, loading: saving, onPressed: saving ? null : _save),
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
                            ? <Widget>[const Chip(avatar: Icon(Icons.person_outline, size: 17), label: Text('موظف'))]
                            : roles.map((role) => Chip(
                                  avatar: Icon(_roleIcon(role), size: 17, color: AppColors.primary),
                                  label: Text(_roleLabel(role)),
                                  backgroundColor: theme.colorScheme.primary.withValues(alpha: .06),
                                  side: BorderSide(color: theme.dividerColor.withValues(alpha: .65)),
                                )).toList(growable: false),
                      ),
                    ),
                    const SizedBox(height: 18),
                    _ProfileCard(
                      icon: Icons.dns_outlined,
                      title: 'الاتصال بالخادم',
                      subtitle: 'عنوان خادم الـ API المرتبط بهذا الجهاز حالياً.',
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.stretch,
                        children: <Widget>[
                          Directionality(
                            textDirection: TextDirection.ltr,
                            child: Text(
                              AppConfig.currentApiBaseUrl ?? 'غير مضبوط',
                              style: theme.textTheme.bodyMedium?.copyWith(fontWeight: FontWeight.w600),
                            ),
                          ),
                          const SizedBox(height: 12),
                          OutlinedButton.icon(
                            onPressed: saving
                                ? null
                                : () async {
                                    final updated = await ServerConfigSheet.show(context);
                                    if (!mounted || updated == null) return;
                                    setState(() {});
                                    ScaffoldMessenger.of(this.context).showSnackBar(
                                      SnackBar(content: Text('تم تحديث عنوان الخادم إلى: $updated')),
                                    );
                                  },
                            icon: const Icon(Icons.swap_horiz_rounded),
                            label: const Text('تغيير عنوان الخادم'),
                          ),
                        ],
                      ),
                    ),
                    const SizedBox(height: 18),
                    OutlinedButton.icon(
                      onPressed: state.operation == AuthOperation.logout && state.status == AuthStatus.loading ? null : _logout,
                      icon: const Icon(Icons.logout_rounded),
                      label: Text(state.operation == AuthOperation.logout && state.status == AuthStatus.loading ? 'جارٍ تسجيل الخروج…' : 'تسجيل الخروج'),
                      style: OutlinedButton.styleFrom(foregroundColor: AppColors.danger, minimumSize: const Size.fromHeight(50), side: const BorderSide(color: Color(0xFFFECACA)), shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16))),
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
  const _ProfileHero({required this.name, required this.email, required this.avatarUrl, required this.avatarFile, required this.onEditAvatar});

  final String name;
  final String email;
  final String? avatarUrl;
  final File? avatarFile;
  final VoidCallback onEditAvatar;

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(24),
        gradient: const LinearGradient(begin: AlignmentDirectional.topStart, end: AlignmentDirectional.bottomEnd, colors: <Color>[Color(0xFF075D73), Color(0xFF0EA5E9)]),
        boxShadow: <BoxShadow>[BoxShadow(color: AppColors.primary.withValues(alpha: .16), blurRadius: 22, offset: const Offset(0, 11))],
      ),
      child: Row(
        children: <Widget>[
          Stack(
            clipBehavior: Clip.none,
            children: <Widget>[
              CircleAvatar(radius: 35, backgroundColor: Colors.white.withValues(alpha: .22), foregroundImage: _avatarImage(), child: const Icon(Icons.person_rounded, color: Colors.white, size: 38)),
              PositionedDirectional(
                bottom: -2,
                end: -2,
                child: Material(
                  color: Colors.white,
                  shape: const CircleBorder(),
                  child: InkWell(onTap: onEditAvatar, customBorder: const CircleBorder(), child: const Padding(padding: EdgeInsets.all(7), child: Icon(Icons.edit_rounded, size: 15, color: AppColors.primary))),
                ),
              ),
            ],
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(name, style: theme.textTheme.titleLarge?.copyWith(color: Colors.white, fontWeight: FontWeight.w800), maxLines: 1, overflow: TextOverflow.ellipsis),
                const SizedBox(height: 3),
                Directionality(textDirection: TextDirection.ltr, child: Text(email, style: theme.textTheme.bodyMedium?.copyWith(color: Colors.white.withValues(alpha: .8)), textAlign: TextAlign.right, maxLines: 1, overflow: TextOverflow.ellipsis)),
                const SizedBox(height: 10),
                Container(padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 4), decoration: BoxDecoration(color: Colors.white.withValues(alpha: .14), borderRadius: BorderRadius.circular(99)), child: const Text('حساب موثّق', style: TextStyle(color: Colors.white, fontSize: 11, fontWeight: FontWeight.w700))),
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
    final theme = Theme.of(context);
    return Container(
      padding: const EdgeInsets.all(20),
      decoration: BoxDecoration(color: theme.colorScheme.surface, borderRadius: BorderRadius.circular(22), border: Border.all(color: theme.dividerColor.withValues(alpha: .75))),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: <Widget>[
          Row(children: <Widget>[
            Container(width: 38, height: 38, decoration: BoxDecoration(color: theme.colorScheme.primary.withValues(alpha: .08), borderRadius: BorderRadius.circular(13)), child: Icon(icon, color: theme.colorScheme.primary, size: 20)),
            const SizedBox(width: 11),
            Expanded(child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: <Widget>[Text(title, style: theme.textTheme.titleMedium), Text(subtitle, style: theme.textTheme.bodySmall)])),
          ]),
          const SizedBox(height: 18),
          child,
        ],
      ),
    );
  }
}
