import 'package:flutter/widgets.dart';
import 'package:image_picker/image_picker.dart';

import '../../domain/entities/settings_entities.dart';
import '../../domain/repositories/settings_repository.dart';
import 'template_settings_editor.dart';

class ReceiptSettingsStudioScreen extends StatelessWidget {
  const ReceiptSettingsStudioScreen({
    required this.repository,
    this.embedded = false,
    this.mediaBaseUrl,
    this.imagePicker,
    super.key,
  });

  final SettingsRepository repository;
  final bool embedded;
  final String? mediaBaseUrl;
  final ImagePicker? imagePicker;

  @override
  Widget build(BuildContext context) => TemplateSettingsEditor(
        repository: repository,
        kind: TemplateKind.receipt,
        embedded: embedded,
        mediaBaseUrl: mediaBaseUrl,
        imagePicker: imagePicker,
      );
}
