import 'package:flutter/material.dart';

import '../../../../core/fouad/api_client.dart';
import '../../../../core/l10n/resource_ux_strings.dart';
import '../../domain/entities/square.dart';

/// Keeps the draft visible until the server confirms the write.
class SquareFormDialog extends StatefulWidget {
  const SquareFormDialog({
    required this.onSave,
    this.existing,
    this.errorMessage,
    super.key,
  });

  final Square? existing;
  final Future<bool> Function({
    int? id,
    required String name,
    String? description,
  })
  onSave;
  final String? Function()? errorMessage;

  @override
  State<SquareFormDialog> createState() => _SquareFormDialogState();
}

class _SquareFormDialogState extends State<SquareFormDialog> {
  final _form = GlobalKey<FormState>();
  late final _name = TextEditingController(text: widget.existing?.name ?? '');
  late final _description = TextEditingController(
    text: widget.existing?.description ?? '',
  );
  bool _saving = false;
  String? _error;

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (_saving || !(_form.currentState?.validate() ?? false)) return;
    FocusScope.of(context).unfocus();
    setState(() {
      _saving = true;
      _error = null;
    });
    try {
      final saved = await widget.onSave(
        id: widget.existing?.id,
        name: _name.text.trim(),
        description: _description.text.trim().isEmpty
            ? null
            : _description.text.trim(),
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
      scrollable: true,
      title: Text(
        widget.existing == null
            ? ResourceUxStrings.newSquare
            : ResourceUxStrings.editSquare,
      ),
      content: Form(
        key: _form,
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            const Text(ResourceUxStrings.squareGuide),
            const SizedBox(height: 12),
            TextFormField(
              controller: _name,
              enabled: !_saving,
              textInputAction: TextInputAction.next,
              decoration: const InputDecoration(
                labelText: ResourceUxStrings.squareName,
              ),
              maxLength: 100,
              autovalidateMode: AutovalidateMode.onUserInteraction,
              validator: (value) => value == null || value.trim().isEmpty
                  ? ResourceUxStrings.squareNameRequired
                  : null,
            ),
            const SizedBox(height: 8),
            TextFormField(
              controller: _description,
              enabled: !_saving,
              decoration: const InputDecoration(
                labelText: ResourceUxStrings.optionalDescription,
              ),
              maxLines: 3,
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
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.pop(context),
          child: const Text(ResourceUxStrings.cancel),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: Text(
            _saving ? ResourceUxStrings.saving : ResourceUxStrings.save,
          ),
        ),
      ],
    ),
  );
}
