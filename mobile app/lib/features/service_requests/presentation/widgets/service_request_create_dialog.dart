import 'package:flutter/material.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/l10n/resource_ux_strings.dart';
import '../../../../core/ui/app_tiles.dart';
import '../../../../shared/widgets/subscriber_meter_selector.dart';
import '../../../subscribers/domain/entities/subscriber.dart';
import '../../../subscribers/domain/repositories/subscribers_repository.dart';
import '../../domain/entities/service_request.dart';

/// Validation and saving stay in the dialog so a failure cannot discard a draft.
class ServiceRequestCreateDialog extends StatefulWidget {
  const ServiceRequestCreateDialog({
    required this.repository,
    required this.onSave,
    this.errorMessage,
    super.key,
  });

  final SubscribersRepository repository;
  final Future<bool> Function(ServiceRequest draft) onSave;
  final String? Function()? errorMessage;

  @override
  State<ServiceRequestCreateDialog> createState() =>
      _ServiceRequestCreateDialogState();
}

class _ServiceRequestCreateDialogState
    extends State<ServiceRequestCreateDialog> {
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController(text: '5000');
  final _notes = TextEditingController();
  Subscriber? _subscriber;
  DateTime _date = DateTime.now();
  TankerStatus _status = TankerStatus.completed;
  bool _saving = false;
  String? _error;

  static double? _parseAmount(String value) => double.tryParse(
    normalizeDigits(
      value,
    ).replaceAll('٫', '.').replaceAll('٬', '').replaceAll(',', '').trim(),
  );

  @override
  void dispose() {
    _amount.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _select(Subscriber subscriber) async {
    setState(() {
      _subscriber = subscriber;
      _error = null;
    });
    final previousInput = _amount.text;
    try {
      final last = await widget.repository.lastTankerAmount(subscriber.id);
      if (!mounted ||
          _saving ||
          _subscriber?.id != subscriber.id ||
          _amount.text != previousInput)
        return;
      if (last != null) _amount.text = last.toStringAsFixed(0);
    } catch (_) {
      // The optional previous-price suggestion must not block a new request.
    }
  }

  Future<void> _save() async {
    if (_saving) return;
    final validAmount = _form.currentState?.validate() ?? false;
    final subscriber = _subscriber;
    if (subscriber == null)
      setState(() => _error = ResourceUxStrings.subscriberRequired);
    if (!validAmount || subscriber == null) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final saved = await widget.onSave(
        ServiceRequest(
          id: 0,
          subscriberId: subscriber.id,
          amount: _parseAmount(_amount.text)!,
          serviceDate: _date.toIso8601String().substring(0, 10),
          status: _status,
          notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
        ),
      );
      if (!mounted) return;
      if (saved) {
        Navigator.of(context).pop(true);
        return;
      }
      setState(
        () => _error =
            widget.errorMessage?.call() ?? ResourceUxStrings.saveFailed,
      );
    } catch (error) {
      if (mounted) setState(() => _error = friendlyError(error));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) => PopScope(
    canPop: !_saving,
    child: AlertDialog(
      title: const Text(ResourceUxStrings.newRequest),
      scrollable: true,
      content: SizedBox(
        width: 440,
        child: Form(
          key: _form,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            crossAxisAlignment: CrossAxisAlignment.stretch,
            children: [
              const Text(ResourceUxStrings.requestGuide),
              const SizedBox(height: 12),
              IgnorePointer(
                ignoring: _saving,
                child: SizedBox(
                  height: 200,
                  child: SubscriberMeterSelectorWidget(
                    repository: widget.repository,
                    showMeterPicker: false,
                    onSelected: (subscriber, _) => _select(subscriber),
                  ),
                ),
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _amount,
                enabled: !_saving,
                keyboardType: const TextInputType.numberWithOptions(
                  decimal: true,
                ),
                decoration: const InputDecoration(
                  labelText: ResourceUxStrings.tankerAmount,
                ),
                validator: (value) {
                  final amount = _parseAmount(value ?? '');
                  return amount == null || !amount.isFinite || amount <= 0
                      ? ResourceUxStrings.positiveAmount
                      : null;
                },
              ),
              const SizedBox(height: 10),
              AppSettingRow(
                icon: Icons.calendar_month_rounded,
                color: Theme.of(context).colorScheme.primary,
                title: ResourceUxStrings.serviceDate,
                subtitle: _date.toIso8601String().substring(0, 10),
                onTap: _saving
                    ? null
                    : () async {
                        final picked = await showDatePicker(
                          context: context,
                          initialDate: _date,
                          firstDate: DateTime(2020),
                          lastDate: DateTime(2100),
                        );
                        if (picked != null && mounted)
                          setState(() => _date = picked);
                      },
              ),
              DropdownButtonFormField<TankerStatus>(
                initialValue: _status,
                isExpanded: true,
                decoration: const InputDecoration(
                  labelText: ResourceUxStrings.status,
                ),
                items: [
                  for (final status in TankerStatus.values)
                    DropdownMenuItem(value: status, child: Text(status.label)),
                ],
                onChanged: _saving
                    ? null
                    : (value) => setState(() => _status = value ?? _status),
              ),
              const SizedBox(height: 10),
              TextFormField(
                controller: _notes,
                enabled: !_saving,
                decoration: const InputDecoration(
                  labelText: ResourceUxStrings.optionalNotes,
                ),
                maxLines: 2,
              ),
              if (_error != null) ...[
                const SizedBox(height: 12),
                Text(
                  _error!,
                  style: TextStyle(color: Theme.of(context).colorScheme.error),
                ),
              ],
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.pop(context),
          child: const Text(ResourceUxStrings.cancel),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: Text(
            _saving ? ResourceUxStrings.saving : ResourceUxStrings.addRequest,
          ),
        ),
      ],
    ),
  );
}
