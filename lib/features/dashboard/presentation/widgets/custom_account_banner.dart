import 'package:flutter/material.dart';

import '../../../admin_shared/presentation/theme/admin_palette.dart';

class CustomAccountBanner extends StatelessWidget {
  const CustomAccountBanner({
    required this.userName,
    required this.roleLabel,
    required this.isOnline,
    this.avatarUrl,
    this.pendingSyncCount = 0,
    this.onAddReading,
    this.onAddSubscriber,
    super.key,
  });

  final String userName;
  final String roleLabel;
  final String? avatarUrl;
  final bool isOnline;
  final int pendingSyncCount;
  final VoidCallback? onAddReading;
  final VoidCallback? onAddSubscriber;

  @override
  Widget build(BuildContext context) {
    final connectionLabel = isOnline
        ? 'متصل بالخادم'
        : pendingSyncCount > 0
            ? 'أوفلاين • $pendingSyncCount بانتظار المزامنة'
            : 'أوفلاين';
    return Container(
      padding: const EdgeInsets.all(22),
      decoration: BoxDecoration(
        gradient: const LinearGradient(
          colors: [AdminPalette.primaryDark, AdminPalette.primary],
          begin: Alignment.topRight,
          end: Alignment.bottomLeft,
        ),
        borderRadius: BorderRadius.circular(24),
      ),
      child: LayoutBuilder(
        builder: (context, constraints) {
          final compact = constraints.maxWidth < 560;
          final profile = Row(
            mainAxisSize: MainAxisSize.min,
            children: [
              CircleAvatar(
                radius: 28,
                backgroundColor: Colors.white24,
                foregroundImage: avatarUrl == null ? null : NetworkImage(avatarUrl!),
                child: const Icon(Icons.person_rounded, color: Colors.white, size: 30),
              ),
              const SizedBox(width: 14),
              Flexible(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'مرحباً، $userName',
                      overflow: TextOverflow.ellipsis,
                      style: Theme.of(context).textTheme.headlineSmall?.copyWith(
                            color: Colors.white,
                            fontWeight: FontWeight.w800,
                          ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      roleLabel,
                      style: const TextStyle(color: Colors.white70),
                    ),
                  ],
                ),
              ),
            ],
          );
          final status = Container(
            padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 8),
            decoration: BoxDecoration(
              color: Colors.black26,
              borderRadius: BorderRadius.circular(999),
            ),
            child: Row(
              mainAxisSize: MainAxisSize.min,
              children: [
                Icon(
                  isOnline ? Icons.cloud_done_outlined : Icons.cloud_off_outlined,
                  size: 18,
                  color: isOnline ? Colors.greenAccent : Colors.orangeAccent,
                ),
                const SizedBox(width: 7),
                Flexible(
                  child: Text(
                    connectionLabel,
                    style: const TextStyle(color: Colors.white),
                  ),
                ),
              ],
            ),
          );
          final actions = Wrap(
            spacing: 10,
            runSpacing: 10,
            children: [
              if (onAddReading != null)
                FilledButton.tonalIcon(
                  onPressed: onAddReading,
                  icon: const Icon(Icons.speed_rounded),
                  label: const Text('إضافة قراءة'),
                ),
              if (onAddSubscriber != null)
                OutlinedButton.icon(
                  onPressed: onAddSubscriber,
                  style: OutlinedButton.styleFrom(foregroundColor: Colors.white),
                  icon: const Icon(Icons.person_add_alt_1_rounded),
                  label: const Text('إضافة مشترك'),
                ),
            ],
          );
          return Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              if (compact) ...[
                profile,
                const SizedBox(height: 14),
                Align(alignment: AlignmentDirectional.centerStart, child: status),
              ] else
                Row(
                  children: [
                    Expanded(child: profile),
                    const SizedBox(width: 16),
                    status,
                  ],
                ),
              if (actions.children.isNotEmpty) ...[
                const SizedBox(height: 18),
                actions,
              ],
            ],
          );
        },
      ),
    );
  }
}
