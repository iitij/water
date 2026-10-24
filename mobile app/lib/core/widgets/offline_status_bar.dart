import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/material.dart';
import 'package:go_router/go_router.dart';

import '../../features/offline_sync/domain/sync_queue_item.dart';
import '../../features/offline_sync/services/sync_queue_manager.dart';
import '../finance/finance_locator.dart';
import '../network/network_info.dart';
import '../router/route_names.dart';
import '../theme/app_colors.dart';

/// نص الشريط كما يُحسب من الحالة — دالة نقية كي تُختبر بلا ودجات.
///
/// ترتيب الأولوية: انقطاع الشبكة أهم من عدّاد المزامنة، لأن المستخدم يحتاج أن
/// يعرف أولاً أنه يعمل محلياً، ثم كم عملية تنتظر.
({String text, bool isWarning}) offlineStatusLabel({
  required bool online,
  required int pending,
  required int needsAttention,
}) {
  if (!online) {
    return (
      text: pending > 0
          ? 'أنت غير متصل — $pending عملية محفوظة محلياً وستُرسل تلقائياً'
          : 'أنت غير متصل — العمل مستمر محلياً',
      isWarning: true,
    );
  }
  if (needsAttention > 0) {
    return (
      text: '$needsAttention عملية تحتاج تدخلاً بشرياً',
      isWarning: true,
    );
  }
  if (pending > 0) {
    return (text: 'جارٍ إرسال $pending عملية معلّقة…', isWarning: false);
  }
  return (text: '', isWarning: false);
}

/// شريط الحالة الأوفلاين (بطاقة S3-05).
///
/// يظهر في كل صفحة فرعية فقط عند الحاجة (بلا شبكة، أو عمليات معلّقة/تحتاج
/// تدخلاً)، ويختفي تماماً عندما يكون كل شيء متزامناً — لأن الصمت هنا نجاح.
class OfflineStatusBar extends StatefulWidget {
  const OfflineStatusBar({super.key});

  @override
  State<OfflineStatusBar> createState() => _OfflineStatusBarState();
}

class _OfflineStatusBarState extends State<OfflineStatusBar> {
  StreamSubscription<List<ConnectivityResult>>? _connectivity;
  SyncQueueManager? _queue;
  bool _online = true;

  @override
  void initState() {
    super.initState();
    _wire();
  }

  void _wire() {
    final network = trySl<NetworkInfo>();
    if (network != null) {
      unawaited(_check(network));
      _connectivity = network.onConnectivityChanged.listen((results) {
        final online = results.any((result) => result != ConnectivityResult.none);
        if (mounted && online != _online) setState(() => _online = online);
      });
    }

    final queue = trySl<SyncQueueManager>();
    _queue = queue;
    queue?.addListener(_onQueueChanged);
  }

  Future<void> _check(NetworkInfo network) async {
    try {
      final online = await network.isConnected;
      if (mounted) setState(() => _online = online);
    } catch (_) {
      // فشل الفحص لا يعني الانقطاع؛ نترك الحالة كما هي.
    }
  }

  void _onQueueChanged() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    _connectivity?.cancel();
    _queue?.removeListener(_onQueueChanged);
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final queue = _queue;
    final pending = queue?.pendingCount ?? 0;
    final attention = queue == null
        ? 0
        : queue.items
            .where((item) => item.status == SyncQueueStatus.needsAttention)
            .length;

    final label = offlineStatusLabel(
      online: _online,
      pending: pending,
      needsAttention: attention,
    );
    if (label.text.isEmpty) return const SizedBox.shrink();

    final color = label.isWarning ? AppColors.danger : AppColors.info;
    return Material(
      color: color.withValues(alpha: 0.10),
      child: InkWell(
        onTap: () => context.push(RouteNames.offlineSync),
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 7),
          child: Row(
            children: <Widget>[
              Icon(
                label.isWarning ? Icons.cloud_off_rounded : Icons.sync_rounded,
                size: 15,
                color: color,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  label.text,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: Theme.of(context).textTheme.labelSmall?.copyWith(
                        color: color,
                        fontWeight: FontWeight.w600,
                      ),
                ),
              ),
              Icon(Icons.chevron_left_rounded, size: 16, color: color),
            ],
          ),
        ),
      ),
    );
  }
}
