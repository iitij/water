import 'package:flutter/material.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/fouad/currency_formatter.dart';
import '../../../../core/l10n/app_strings.dart';
import '../../domain/repositories/subscription_fees_repository.dart';

/// Final step of subscriber → persisted meter → subscription fee creation.
class SetupFeeDialog extends StatefulWidget {
  const SetupFeeDialog({
    super.key,
    required this.repository,
    required this.subscriberId,
    required this.meterId,
  });
  final SubscriptionFeesRepository repository;
  final int subscriberId, meterId;
  @override
  State<SetupFeeDialog> createState() => _SetupFeeDialogState();
}

class _SetupFeeDialogState extends State<SetupFeeDialog> {
  final _form = GlobalKey<FormState>();
  final _amount = TextEditingController();
  final _notes = TextEditingController();
  bool _saving = false;
  String? _error;
  @override
  void dispose() {
    _amount.dispose();
    _notes.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !_form.currentState!.validate()) return;
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      await widget.repository.createFee(
        subscriberId: widget.subscriberId,
        meterId: widget.meterId,
        amount: double.parse(normalizeDigits(_amount.text.trim())),
        notes: _notes.text.trim().isEmpty ? null : _notes.text.trim(),
      );
      if (mounted) Navigator.pop(context, true);
    } catch (error) {
      if (mounted) {
        setState(() {
          _saving = false;
          _error = friendlyError(error);
        });
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppStrings.of(context);
    return PopScope(
      canPop: !_saving,
      child: AlertDialog(
        title: Text(strings.setupAddFee),
        content: Form(
          key: _form,
          child: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextFormField(
                  controller: _amount,
                  keyboardType: const TextInputType.numberWithOptions(
                    decimal: true,
                  ),
                  enabled: !_saving,
                  decoration: InputDecoration(
                    labelText: strings.setupFeeAmount,
                  ),
                  validator: (value) {
                    final amount = double.tryParse(
                      normalizeDigits(value?.trim() ?? ''),
                    );
                    return amount == null || !amount.isFinite || amount <= 0
                        ? strings.setupAmountInvalid
                        : null;
                  },
                ),
                TextFormField(
                  controller: _notes,
                  enabled: !_saving,
                  decoration: InputDecoration(labelText: strings.setupFeeNotes),
                ),
                if (_error != null)
                  Text(
                    _error!,
                    style: TextStyle(
                      color: Theme.of(context).colorScheme.error,
                    ),
                  ),
                if (_saving) const LinearProgressIndicator(),
              ],
            ),
          ),
        ),
        actions: [
          TextButton(
            onPressed: _saving ? null : () => Navigator.pop(context, false),
            child: Text(strings.setupSkipFee),
          ),
          FilledButton(
            onPressed: _saving ? null : _save,
            child: Text(strings.save),
          ),
        ],
      ),
    );
  }
}
