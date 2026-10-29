import 'dart:async';

import 'package:dio/dio.dart';
import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';
import 'package:go_router/go_router.dart';

import '../../../../core/config/app_config.dart';
import '../../../../core/diagnostics/api_log_manager.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../../../core/router/route_names.dart';
import '../../../../core/security/developer_access_service.dart';
import '../../../../core/setup/server_config_sheet.dart';
import '../../../settings/domain/repositories/settings_repository.dart';
import '../../../settings/presentation/pages/whatsapp_settings_screen.dart';

/// أدوات الدعم المتقدمة. يمنح الخادم صلاحية قصيرة العمر بعد التحقق من PIN.
class DeveloperModeSheet extends StatefulWidget {
  const DeveloperModeSheet({super.key, required this.pageContext});

  final BuildContext pageContext;

  static Future<void> show(BuildContext context) => showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    useSafeArea: true,
    showDragHandle: true,
    builder: (_) => DeveloperModeSheet(pageContext: context),
  );

  @override
  State<DeveloperModeSheet> createState() => _DeveloperModeSheetState();
}

class _DeveloperModeSheetState extends State<DeveloperModeSheet> {
  final _pin = TextEditingController();
  final _access = GetIt.I<DeveloperAccessService>();
  bool _busy = false;
  String? _error;
  String? _notice;

  @override
  void initState() {
    super.initState();
    _access.addListener(_refresh);
    if (_access.isUnlocked) {
      unawaited(_access.refreshConfig());
    }
  }

  @override
  void dispose() {
    _access.removeListener(_refresh);
    _pin.dispose();
    super.dispose();
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  Future<void> _unlock() async {
    if (_busy) return;
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    final result = await _access.unlock(_pin.text.trim());
    _pin.clear();
    if (!mounted) return;
    setState(() {
      _busy = false;
      _error = switch (result) {
        DeveloperUnlockResult.success => null,
        DeveloperUnlockResult.wrongPin => DeveloperModeStrings.wrongPin,
        DeveloperUnlockResult.rateLimited => DeveloperModeStrings.rateLimited,
        DeveloperUnlockResult.unavailable => DeveloperModeStrings.unavailable,
      };
    });
  }

  Future<void> _changeServer() async {
    if (!await _requireFreshGrant()) return;
    if (!mounted) return;
    final updated = await ServerConfigSheet.show(context);
    if (!mounted || updated == null) return;
    _access.lock();
    setState(() => _notice = DeveloperModeStrings.serverSaved);
  }

  Future<bool> _requireFreshGrant() async {
    if (_busy || !_access.isUnlocked) return false;
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    final refreshed = await _access.refreshConfig();
    if (!mounted) return false;
    setState(() {
      _busy = false;
      if (!refreshed) _error = DeveloperModeStrings.refreshFailed;
    });
    return refreshed;
  }

  Future<void> _uploadLogs() async {
    if (_busy ||
        !_access.isUnlocked ||
        !_access.canOpenFeature('mobile_logs')) {
      return;
    }
    final entries = ApiLogManager.instance.logs
        .take(50)
        .toList(growable: false);
    if (entries.isEmpty) {
      setState(() => _notice = DeveloperModeStrings.noLogs);
      return;
    }
    setState(() {
      _busy = true;
      _error = null;
      _notice = null;
    });
    try {
      // نرسل بيانات تشخيصية فقط؛ لا نرسل الرابط الكامل أو الاستعلام أو
      // الترويسات أو جسم الطلب والاستجابة أو نص الخطأ أو التتبع.
      final logs = entries
          .map(
            (entry) => <String, String>{
              'level': entry.isError
                  ? 'error'
                  : entry.isSlow
                  ? 'warning'
                  : 'info',
              'source': 'api',
              'occurred_at': entry.timestamp.toUtc().toIso8601String(),
              'message':
                  '${entry.method} '
                  'status=${entry.statusCode ?? '-'} '
                  'duration_ms=${entry.durationMs} '
                  'type=${_safeType(entry.errorType)}',
            },
          )
          .toList(growable: false);
      final response = await GetIt.I<Dio>().post<Map<String, dynamic>>(
        'developer/mobile-logs',
        data: <String, dynamic>{'logs': logs},
        options: _access.grantOptions,
      );
      if (!mounted) return;
      final count = response.data?['received_count'] ?? logs.length;
      setState(
        () => _notice = DeveloperModeStrings.uploadedCount(count.toString()),
      );
    } on DioException catch (error) {
      if (!mounted) return;
      setState(() {
        _error = error.response?.statusCode == 403
            ? DeveloperModeStrings.grantExpired
            : DeveloperModeStrings.uploadFailed;
      });
      if (error.response?.statusCode == 403) _access.lock();
    } catch (_) {
      if (mounted) {
        setState(() => _error = DeveloperModeStrings.uploadFailedShort);
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  static String _safeType(String? value) =>
      value != null && RegExp(r'^[A-Za-z][A-Za-z0-9_]{0,40}$').hasMatch(value)
      ? value
      : '-';

  void _openTool(String feature, String route) {
    if (!_access.canOpenFeature(feature)) return;
    Navigator.of(context).pop();
    if (widget.pageContext.mounted) {
      widget.pageContext.push(route);
    }
  }

  Future<void> _openWhatsAppSettings() async {
    if (!_access.canOpenFeature('whatsapp_settings') ||
        !await _requireFreshGrant()) {
      return;
    }
    if (!mounted) return;
    final access = _access;
    Navigator.of(context).pop();
    if (widget.pageContext.mounted) {
      unawaited(
        Navigator.of(widget.pageContext).push(
          MaterialPageRoute<void>(
            builder: (routeContext) => ListenableBuilder(
              listenable: access,
              builder: (routeContext, _) {
                if (!access.canOpenFeature('whatsapp_settings')) {
                  WidgetsBinding.instance.addPostFrameCallback((_) {
                    if (routeContext.mounted &&
                        ModalRoute.of(routeContext)?.isCurrent == true) {
                      Navigator.of(routeContext).pop();
                    }
                  });
                  return const SizedBox.shrink();
                }
                return WhatsAppSettingsScreen(
                  repository: GetIt.I<SettingsRepository>(),
                );
              },
            ),
          ),
        ),
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    final theme = Theme.of(context);
    final unlocked = _access.isUnlocked;
    final features = _access.features;
    return FractionallySizedBox(
      heightFactor: .88,
      child: Padding(
        padding: EdgeInsets.fromLTRB(
          20,
          8,
          20,
          MediaQuery.viewInsetsOf(context).bottom + 20,
        ),
        child: SingleChildScrollView(
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: <Widget>[
              Text(
                DeveloperModeStrings.title,
                style: theme.textTheme.headlineSmall,
              ),
              const SizedBox(height: 6),
              Text(
                unlocked
                    ? DeveloperModeStrings.unlockedDescription
                    : DeveloperModeStrings.lockedDescription,
                style: theme.textTheme.bodyMedium,
              ),
              if (_error != null) ...<Widget>[
                const SizedBox(height: 12),
                Text(_error!, style: TextStyle(color: theme.colorScheme.error)),
              ],
              if (_notice != null) ...<Widget>[
                const SizedBox(height: 12),
                Text(
                  _notice!,
                  style: TextStyle(color: theme.colorScheme.primary),
                ),
              ],
              const SizedBox(height: 20),
              if (!unlocked) ...<Widget>[
                TextField(
                  controller: _pin,
                  obscureText: true,
                  keyboardType: TextInputType.number,
                  maxLength: 12,
                  onSubmitted: (_) => _unlock(),
                  decoration: const InputDecoration(
                    labelText: DeveloperModeStrings.pinLabel,
                    prefixIcon: Icon(Icons.pin_outlined),
                  ),
                ),
                FilledButton.icon(
                  onPressed: _busy ? null : _unlock,
                  icon: const Icon(Icons.lock_open_rounded),
                  label: Text(
                    _busy
                        ? DeveloperModeStrings.verifying
                        : DeveloperModeStrings.entryTitle,
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  DeveloperModeStrings.recoveryHint,
                  style: theme.textTheme.bodySmall,
                ),
              ] else ...<Widget>[
                Card(
                  child: Column(
                    children: <Widget>[
                      ListTile(
                        leading: const Icon(Icons.dns_outlined),
                        title: const Text(DeveloperModeStrings.changeServer),
                        subtitle: Text(
                          AppConfig.apiBaseUrl,
                          maxLines: 1,
                          overflow: TextOverflow.ellipsis,
                        ),
                        onTap: _changeServer,
                      ),
                      if (_access.canOpenFeature('whatsapp_settings'))
                        ListTile(
                          leading: const Icon(Icons.chat_rounded),
                          title: const Text(
                            DeveloperModeStrings.whatsappSettings,
                          ),
                          subtitle: const Text(
                            DeveloperModeStrings.visibleOnDevice,
                          ),
                          onTap: _openWhatsAppSettings,
                        ),
                      if (_access.canOpenFeature('mobile_logs'))
                        ListTile(
                          leading: const Icon(Icons.upload_file_rounded),
                          title: const Text(DeveloperModeStrings.sendLogs),
                          subtitle: const Text(
                            DeveloperModeStrings.logsPrivacy,
                          ),
                          onTap: _busy ? null : _uploadLogs,
                        ),
                      if (_access.canOpenFeature('diagnostics'))
                        ListTile(
                          leading: const Icon(Icons.health_and_safety_outlined),
                          title: const Text(DeveloperModeStrings.diagnostics),
                          onTap: () =>
                              _openTool('diagnostics', RouteNames.diagnostics),
                        ),
                      if (_access.canOpenFeature('api_logs'))
                        ListTile(
                          leading: const Icon(Icons.receipt_long_rounded),
                          title: const Text(DeveloperModeStrings.openLocalLogs),
                          onTap: () =>
                              _openTool('api_logs', RouteNames.apiLogs),
                        ),
                    ],
                  ),
                ),
                const SizedBox(height: 16),
                Text(
                  DeveloperModeStrings.managedFeatures,
                  style: theme.textTheme.titleMedium,
                ),
                const SizedBox(height: 8),
                ...features.entries.map(
                  (feature) => SwitchListTile(
                    title: Text(switch (feature.key) {
                      'whatsapp_settings' =>
                        DeveloperModeStrings.whatsappSettings,
                      'mobile_logs' => DeveloperModeStrings.sendLogsFeature,
                      'diagnostics' => DeveloperModeStrings.diagnostics,
                      'api_logs' => DeveloperModeStrings.apiLogs,
                      _ => feature.key,
                    }),
                    subtitle: const Text(DeveloperModeStrings.savedOnDevice),
                    value: feature.value,
                    onChanged: _busy
                        ? null
                        : (enabled) async {
                            try {
                              await _access.setFeatureEnabled(
                                feature.key,
                                enabled,
                              );
                            } catch (_) {
                              if (mounted) {
                                setState(
                                  () => _error =
                                      DeveloperModeStrings.saveVisibilityFailed,
                                );
                              }
                            }
                          },
                  ),
                ),
                const SizedBox(height: 12),
                OutlinedButton.icon(
                  onPressed: () {
                    _access.lock();
                    setState(() => _notice = DeveloperModeStrings.closed);
                  },
                  icon: const Icon(Icons.lock_rounded),
                  label: const Text(DeveloperModeStrings.close),
                ),
              ],
            ],
          ),
        ),
      ),
    );
  }
}
