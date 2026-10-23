import 'dart:async';
import 'dart:convert';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../../../../core/ui/app_tokens.dart';
import 'package:flutter_bloc/flutter_bloc.dart';

import '../../../admin_shared/data/admin_json.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';
import '../../../admin_shared/presentation/widgets/admin_widgets.dart';
import '../../domain/entities/settings_entities.dart';
import '../cubit/whatsapp_settings_cubit.dart';

class LocalServerControlPanel extends StatefulWidget {
  const LocalServerControlPanel({super.key});

  @override
  State<LocalServerControlPanel> createState() => _LocalServerControlPanelState();
}

class _LocalServerControlPanelState extends State<LocalServerControlPanel> {
  Timer? _timer;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      if (mounted) context.read<WhatsAppSettingsCubit>().refreshLocalServerStatus();
    });
    _timer = Timer.periodic(const Duration(seconds: 3), (_) {
      if (mounted) context.read<WhatsAppSettingsCubit>().refreshLocalServerStatus();
    });
  }

  @override
  void dispose() {
    _timer?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return BlocConsumer<WhatsAppSettingsCubit, WhatsAppSettingsState>(
      listenWhen: (previous, current) =>
          previous.serverSuccessMessage != current.serverSuccessMessage &&
          current.serverSuccessMessage != null,
      listener: (context, state) {
        if (state.serverSuccessMessage != null) {
          showAdminSnackBar(context, state.serverSuccessMessage!);
        }
      },
      buildWhen: (previous, current) =>
          previous.serverStatus != current.serverStatus ||
          previous.isServerBusy != current.isServerBusy ||
          previous.serverError != current.serverError,
      builder: (context, state) {
        final status = state.serverStatus;
        final isOffline = status == null || status.state == LocalServerState.offline;
        return AdminCard(
          borderColor: AdminPalette.success.withAlpha(45),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              AdminSectionHeader(
                title: 'الخادم المحلي — مراقبة حية',
                subtitle: 'يُحدّث كل 3 ثوانٍ • ${status == null ? 'جارٍ الاتصال' : AdminFormat.dateTime(status.checkedAt)}',
                icon: Icons.dns_outlined,
                trailing: state.isServerBusy
                    ? const SizedBox(
                        width: 22,
                        height: 22,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : null,
              ),
              const SizedBox(height: 16),
              if (state.serverError != null)
                Padding(
                  padding: const EdgeInsets.only(bottom: 12),
                  child: Text(
                    adminErrorMessage(state.serverError!),
                    style: const TextStyle(color: AdminPalette.danger),
                  ),
                ),
              _ServerStatusView(status: status),
              const SizedBox(height: 16),
              Wrap(
                spacing: 10,
                runSpacing: 10,
                children: [
                  if (isOffline)
                    FilledButton.icon(
                      key: const ValueKey('start-local-server'),
                      onPressed: state.isServerBusy
                          ? null
                          : () => context.read<WhatsAppSettingsCubit>().startLocalServer(),
                      icon: const Icon(Icons.play_arrow_rounded),
                      label: const Text('تشغيل الخادم من هنا'),
                    )
                  else ...[
                    FilledButton.tonalIcon(
                      key: const ValueKey('send-whatsapp-test'),
                      onPressed: state.isServerBusy
                          ? null
                          : () => _openSendTestDialog(context),
                      icon: const Icon(Icons.send_rounded),
                      label: const Text('إرسال رسالة تجريبية'),
                    ),
                    OutlinedButton.icon(
                      key: const ValueKey('logout-local-server'),
                      onPressed: state.isServerBusy
                          ? null
                          : () => _confirmLogout(context),
                      style: OutlinedButton.styleFrom(
                        foregroundColor: AdminPalette.warning,
                      ),
                      icon: const Icon(Icons.link_off_rounded),
                      label: const Text('تسجيل خروج / فصل الرقم'),
                    ),
                    FilledButton.tonalIcon(
                      key: const ValueKey('stop-local-server'),
                      onPressed: state.isServerBusy ? null : () => _confirmStop(context),
                      style: FilledButton.styleFrom(foregroundColor: AdminPalette.danger),
                      icon: const Icon(Icons.stop_rounded),
                      label: const Text('إيقاف الخادم نهائياً'),
                    ),
                  ],
                  OutlinedButton.icon(
                    onPressed: state.isServerBusy
                        ? null
                        : () => context.read<WhatsAppSettingsCubit>().refreshLocalServerStatus(),
                    icon: const Icon(Icons.refresh_rounded),
                    label: const Text('فحص الآن'),
                  ),
                ],
              ),
              const SizedBox(height: 18),
              LayoutBuilder(
                builder: (context, constraints) {
                  final whatsapp = _TerminalLog(
                    title: 'سجل خادم الواتساب',
                    lines: status?.whatsappLogs ?? const [],
                    color: AdminPalette.whatsappLog,
                  );
                  final queue = _TerminalLog(
                    title: 'سجل طابور الإرسال',
                    lines: status?.queueLogs ?? const [],
                    color: AdminPalette.queueLog,
                  );
                  if (constraints.maxWidth < 800) {
                    return Column(
                      crossAxisAlignment: CrossAxisAlignment.stretch,
                      children: [whatsapp, const SizedBox(height: 12), queue],
                    );
                  }
                  return Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Expanded(child: whatsapp),
                      const SizedBox(width: 12),
                      Expanded(child: queue),
                    ],
                  );
                },
              ),
            ],
          ),
        );
      },
    );
  }

  Future<void> _confirmStop(BuildContext context) async {
    final cubit = context.read<WhatsAppSettingsCubit>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('إيقاف خادم الواتساب'),
        content: const Text('سيتوقف إرسال الفواتير والسندات عبر الخادم المحلي. هل تريد المتابعة؟'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('إلغاء')),
          FilledButton(
            onPressed: () => Navigator.pop(context, true),
            child: const Text('إيقاف الخادم'),
          ),
        ],
      ),
    );
    if (confirmed ?? false) await cubit.stopLocalServer();
  }

  Future<void> _confirmLogout(BuildContext context) async {
    final cubit = context.read<WhatsAppSettingsCubit>();
    final confirmed = await showDialog<bool>(
      context: context,
      builder: (dialogContext) => AlertDialog(
        title: const Text('فصل رقم الواتساب المرتبط'),
        content: const Text(
          'سيتم تسجيل الخروج من جلسة الواتساب الحالية وإصدار كود QR جديد لربط رقم آخر. هل تريد المتابعة؟',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('إلغاء'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            child: const Text('تسجيل الخروج'),
          ),
        ],
      ),
    );
    if (confirmed ?? false) await cubit.logoutLocalServer();
  }

  Future<void> _openSendTestDialog(BuildContext context) async {
    final cubit = context.read<WhatsAppSettingsCubit>();
    final formKey = GlobalKey<FormState>();
    final phoneCtrl = TextEditingController();
    final msgCtrl = TextEditingController(
      text: 'رسالة تجريبية من نظام إدارة المياه للتأكد من جاهزية الربط عبر الواتساب ✅',
    );
    var sending = false;

    await showDialog<void>(
      context: context,
      builder: (dialogContext) => StatefulBuilder(
        builder: (dialogContext, setDialogState) {
          Future<void> send() async {
            if (!(formKey.currentState?.validate() ?? false)) return;
            setDialogState(() => sending = true);
            final ok = await cubit.sendTestMessage(
              phone: phoneCtrl.text.trim(),
              message: msgCtrl.text.trim(),
            );
            if (!dialogContext.mounted) return;
            setDialogState(() => sending = false);
            if (ok) Navigator.pop(dialogContext);
          }

          return AlertDialog(
            title: const Text('إرسال رسالة واتساب تجريبية'),
            content: SizedBox(
              width: 480,
              child: Form(
                key: formKey,
                child: Column(
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    Directionality(
                      textDirection: TextDirection.ltr,
                      child: TextFormField(
                        controller: phoneCtrl,
                        keyboardType: TextInputType.phone,
                        decoration: const InputDecoration(
                          labelText: 'رقم الجوال (مع مفتاح الدولة، مثل 96777xxxxxxx)',
                          prefixIcon: Icon(Icons.phone_outlined),
                        ),
                        validator: (val) => val == null || val.trim().length < 7
                            ? 'أدخل رقم هاتف صالح'
                            : null,
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextFormField(
                      controller: msgCtrl,
                      maxLines: 4,
                      decoration: const InputDecoration(
                        labelText: 'نص الرسالة التجريبية',
                      ),
                      validator: (val) => val == null || val.trim().isEmpty
                          ? 'أدخل نص الرسالة'
                          : null,
                    ),
                  ],
                ),
              ),
            ),
            actions: [
              TextButton(
                onPressed: sending ? null : () => Navigator.pop(dialogContext),
                child: const Text('إلغاء'),
              ),
              FilledButton.icon(
                onPressed: sending ? null : send,
                icon: sending
                    ? const SizedBox(
                        width: 16,
                        height: 16,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      )
                    : const Icon(Icons.send_rounded),
                label: Text(sending ? 'جارٍ الإرسال...' : 'إرسال الآن'),
              ),
            ],
          );
        },
      ),
    );
    phoneCtrl.dispose();
    msgCtrl.dispose();
  }
}

class _ServerStatusView extends StatelessWidget {
  const _ServerStatusView({required this.status});

  final LocalServerStatus? status;

  @override
  Widget build(BuildContext context) {
    if (status == null || status!.state == LocalServerState.waiting) {
      return const _StatusMessage(
        icon: Icons.hourglass_top_rounded,
        color: AdminPalette.warning,
        title: 'جاري تجهيز الخادم وطلب كود QR من واتساب...',
        showProgress: true,
      );
    }
    switch (status!.state) {
      case LocalServerState.connected:
        return const _StatusMessage(
          icon: Icons.check_circle_outline_rounded,
          color: AdminPalette.success,
          title: 'الخادم يعمل وجاهز لاستقبال طلبات إرسال الفواتير بصيغة PDF آلياً.',
        );
      case LocalServerState.offline:
        return const _StatusMessage(
          icon: Icons.cancel_outlined,
          color: AdminPalette.danger,
          title: 'الخادم لا يعمل حالياً. شغّله من هنا أو شغّله يدوياً على جهاز الخادم.',
        );
      case LocalServerState.qr:
        return Column(
          children: [
            const _StatusMessage(
              icon: Icons.qr_code_2_rounded,
              color: AdminPalette.warning,
              title: 'امسح هذا الكود من واتساب → الأجهزة المرتبطة → ربط جهاز.',
            ),
            const SizedBox(height: 12),
            _QrImage(payload: status!.qrCode),
            const SizedBox(height: 8),
            const Text('يتحدث الكود تلقائياً كل بضع ثوانٍ.'),
          ],
        );
      case LocalServerState.waiting:
        return const SizedBox.shrink();
    }
  }
}

class _StatusMessage extends StatelessWidget {
  const _StatusMessage({
    required this.icon,
    required this.color,
    required this.title,
    this.showProgress = false,
  });

  final IconData icon;
  final Color color;
  final String title;
  final bool showProgress;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: color.withAlpha(15),
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: color.withAlpha(45)),
      ),
      child: Row(
        children: [
          Icon(icon, color: color),
          const SizedBox(width: 10),
          Expanded(child: Text(title, style: TextStyle(color: color, fontWeight: FontWeight.w600))),
          if (showProgress) ...[
            const SizedBox(width: 10),
            const SizedBox(
              width: 20,
              height: 20,
              child: CircularProgressIndicator(strokeWidth: 2),
            ),
          ],
        ],
      ),
    );
  }
}

class _QrImage extends StatelessWidget {
  const _QrImage({required this.payload});

  final String? payload;

  @override
  Widget build(BuildContext context) {
    if (payload == null || payload!.isEmpty) {
      return const AdminEmptyView(title: 'بانتظار صورة QR من الخادم', icon: Icons.qr_code_2);
    }
    final raw = payload!.trim();
    final uri = Uri.tryParse(raw);
    final isNetwork = uri != null && (uri.scheme == 'http' || uri.scheme == 'https');
    final bytes = isNetwork ? null : _decodeImage(raw);
    return Container(
      width: 280,
      height: 280,
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(AppRadius.lg),
        border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
      ),
      child: isNetwork
          ? Image.network(raw, fit: BoxFit.contain)
          : bytes == null
              ? const Center(
                  child: Text('صورة QR غير صالحة. سيُعاد تحميلها تلقائياً.'),
                )
              : Image.memory(
                  bytes,
                  key: const ValueKey('whatsapp-qr-image'),
                  fit: BoxFit.contain,
                  errorBuilder: (context, error, stackTrace) => const Center(
                    child: Text('صورة QR غير صالحة. سيُعاد تحميلها تلقائياً.'),
                  ),
                ),
    );
  }

  static Uint8List? _decodeImage(String raw) {
    try {
      return base64Decode(adminBase64Payload(raw));
    } on FormatException {
      return null;
    }
  }
}

class _TerminalLog extends StatelessWidget {
  const _TerminalLog({required this.title, required this.lines, required this.color});

  final String title;
  final List<String> lines;
  final Color color;

  @override
  Widget build(BuildContext context) {
    final text = lines.isEmpty ? 'لا توجد سجلات بعد...' : lines.join('\n');
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Text(title, style: const TextStyle(fontWeight: FontWeight.w600)),
        const SizedBox(height: 6),
        Container(
          height: 220,
          padding: const EdgeInsets.all(12),
          decoration: BoxDecoration(
            color: AdminPalette.terminal,
            borderRadius: BorderRadius.circular(AppRadius.md),
          ),
          child: SingleChildScrollView(
            reverse: true,
            child: Directionality(
              textDirection: TextDirection.ltr,
              child: Align(
                alignment: Alignment.topLeft,
                child: SelectableText(
                  text,
                  style: TextStyle(color: color, fontFamily: 'monospace', fontSize: 12, height: 1.45),
                ),
              ),
            ),
          ),
        ),
      ],
    );
  }
}
