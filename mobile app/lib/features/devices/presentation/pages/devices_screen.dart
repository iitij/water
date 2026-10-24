import 'package:flutter/material.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../../core/finance/finance_feedback.dart';
import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/widgets/admin_permission_guard.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/device_record.dart';
import '../../domain/repositories/devices_repository.dart';
import '../cubit/devices_cubit.dart';

/// شاشة «الأجهزة المسجّلة» (S7-07).
///
/// حين يُفقد الجهاز أو يُسرق، يصبح التوكين خطراً مفتوحاً. هذه الشاشة تُبطل
/// الجهاز من أي جهاز آخر بمجرد توفر الشبكة — وتُظهر الجهاز الحالي موسوماً
/// بلا زر إبطال.
class DevicesScreen extends StatelessWidget {
  const DevicesScreen({
    required this.repository,
    this.currentDeviceUuid,
    this.can,
    super.key,
  });

  final DevicesRepository repository;
  final String? currentDeviceUuid;
  final AdminPermissionCheck? can;

  @override
  Widget build(BuildContext context) {
    if (can != null && !can!(AdminPermissions.viewUsers)) {
      return const Scaffold(
        body: AdminErrorView(message: 'إدارة الأجهزة تحتاج صلاحية ViewAny:User.'),
      );
    }
    return BlocProvider(
      create: (_) => DevicesCubit(
        repository: repository,
        currentDeviceUuid: currentDeviceUuid,
      )..load(),
      child: const _DevicesView(),
    );
  }
}

class _DevicesView extends StatelessWidget {
  const _DevicesView();

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<DevicesCubit, DevicesState>(
      listenWhen: (previous, current) =>
          previous.successMessage != current.successMessage ||
          previous.errorMessage != current.errorMessage,
      listener: (context, state) {
        final success = state.successMessage;
        final failure = state.errorMessage;
        if (success != null) {
          AppFeedback.success(context, success);
          context.read<DevicesCubit>().clearMessages();
        } else if (failure != null) {
          AppFeedback.error(context, failure);
          context.read<DevicesCubit>().clearMessages();
        }
      },
      builder: (context, state) {
        return Scaffold(
          appBar: AppBar(
            title: const Text('الأجهزة المسجّلة'),
            actions: <Widget>[
              IconButton(
                tooltip: 'تحديث',
                onPressed: state.status == DevicesStatus.loading
                    ? null
                    : () => context.read<DevicesCubit>().load(),
                icon: const Icon(Icons.refresh_rounded),
              ),
            ],
          ),
          body: switch (state.status) {
            DevicesStatus.initial || DevicesStatus.loading when state.devices.isEmpty =>
              const Center(child: CircularProgressIndicator()),
            DevicesStatus.failure when state.devices.isEmpty => AdminErrorView(
                message: state.errorMessage ?? 'تعذّر تحميل الأجهزة.',
                onRetry: () => context.read<DevicesCubit>().load(),
              ),
            _ => _DevicesBody(state: state),
          },
        );
      },
    );
  }
}

class _DevicesBody extends StatelessWidget {
  const _DevicesBody({required this.state});

  final DevicesState state;

  @override
  Widget build(BuildContext context) {
    final active = state.active;
    final revoked = state.revoked;
    return ListView(
      padding: const EdgeInsets.all(AppSpacing.md),
      children: <Widget>[
        AppCard(
          color: Theme.of(context).colorScheme.surfaceContainerHighest,
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Icon(Icons.shield_outlined, color: Theme.of(context).colorScheme.primary),
              const SizedBox(width: AppSpacing.sm),
              const Expanded(
                child: Text(
                  'كل جهاز يسجّل الدخول يظهر هنا. إبطال جهاز يمنعه من الوصول '
                  'إلى البيانات في أول طلب لاحق — استخدمه فوراً عند فقدان الجهاز.',
                  style: TextStyle(height: 1.5),
                ),
              ),
            ],
          ),
        ),
        const SizedBox(height: AppSpacing.lg),
        Text('نشطة (${active.length})', style: Theme.of(context).textTheme.titleSmall),
        const SizedBox(height: AppSpacing.sm),
        if (active.isEmpty)
          const AppCard(child: Text('لا توجد أجهزة نشطة مسجّلة على هذا الحساب.'))
        else
          for (final device in active)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: _DeviceCard(device: device),
            ),
        if (revoked.isNotEmpty) ...<Widget>[
          const SizedBox(height: AppSpacing.lg),
          Text('مُبطلة (${revoked.length})', style: Theme.of(context).textTheme.titleSmall),
          const SizedBox(height: AppSpacing.sm),
          for (final device in revoked)
            Padding(
              padding: const EdgeInsets.only(bottom: AppSpacing.sm),
              child: _DeviceCard(device: device),
            ),
        ],
      ],
    );
  }
}

class _DeviceCard extends StatelessWidget {
  const _DeviceCard({required this.device});

  final DeviceRecord device;

  Future<void> _confirmRevoke(BuildContext context) async {
    final cubit = context.read<DevicesCubit>();
    if (!cubit.canRevoke(device)) {
      await cubit.revoke(device); // يُصدر الرسالة المناسبة (جهاز حالي/مُبطل).
      return;
    }
    final confirmed = await ConfirmDialog.show(
      context,
      title: 'إبطال الجهاز؟',
      message: 'سيفقد «${device.displayName}» الوصول فوراً، وسيُطلب تسجيل الدخول '
          'من جديد على ذلك الجهاز. لا يمكن التراجع.',
      confirmLabel: 'إبطال',
      destructive: true,
    );
    if (confirmed) await cubit.revoke(device);
  }

  @override
  Widget build(BuildContext context) {
    final scheme = Theme.of(context).colorScheme;
    final revoked = device.isRevoked;
    return AppCard(
      borderColor: device.isCurrent ? scheme.primary : null,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Icon(
            _iconFor(device.platform),
            color: revoked ? scheme.outline : scheme.primary,
          ),
          const SizedBox(width: AppSpacing.sm),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Wrap(
                  spacing: AppSpacing.xs,
                  runSpacing: AppSpacing.xs,
                  crossAxisAlignment: WrapCrossAlignment.center,
                  children: <Widget>[
                    Text(device.displayName, style: Theme.of(context).textTheme.titleSmall),
                    if (device.isCurrent)
                      const AppPill(label: 'هذا الجهاز', dense: true, filled: true),
                    if (revoked)
                      AppPill(label: 'مُبطل', icon: Icons.block_rounded, dense: true, color: scheme.error),
                  ],
                ),
                const SizedBox(height: 2),
                Text(
                  _subtitle(device),
                  style: Theme.of(context).textTheme.bodySmall?.copyWith(color: scheme.onSurfaceVariant),
                ),
              ],
            ),
          ),
          if (!revoked && !device.isCurrent)
            IconButton(
              tooltip: 'إبطال الجهاز',
              onPressed: () => _confirmRevoke(context),
              icon: const Icon(Icons.link_off_rounded),
              color: scheme.error,
            ),
        ],
      ),
    );
  }

  static IconData _iconFor(String? platform) {
    switch (platform) {
      case 'android':
      case 'ios':
        return Icons.smartphone_rounded;
      case 'windows':
      case 'macos':
      case 'linux':
        return Icons.desktop_windows_rounded;
      default:
        return Icons.devices_other_rounded;
    }
  }

  static String _subtitle(DeviceRecord device) {
    final parts = <String>[];
    if (device.appVersion != null && device.appVersion!.trim().isNotEmpty) {
      parts.add('الإصدار ${device.appVersion!.trim()}');
    }
    final seen = device.lastSeenAt;
    if (seen != null) parts.add('آخر ظهور ${_format(seen)}');
    if (device.isRevoked) {
      parts.add('أُبطل ${_format(device.revokedAt!)}');
    }
    return parts.isEmpty ? DeviceRecord.platformLabelOf(device.platform) : parts.join(' • ');
  }

  static String _format(DateTime value) {
    final local = value.toLocal();
    String two(int n) => n.toString().padLeft(2, '0');
    return '${local.year}/${two(local.month)}/${two(local.day)} ${two(local.hour)}:${two(local.minute)}';
  }
}
