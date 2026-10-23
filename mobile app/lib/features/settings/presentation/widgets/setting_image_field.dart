import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../core/ui/app_tokens.dart';
import '../../../admin_shared/presentation/theme/admin_palette.dart';

class SettingImageField extends StatelessWidget {
  const SettingImageField({
    required this.label,
    required this.settingKey,
    required this.path,
    required this.isUploading,
    required this.onSelected,
    this.helperText,
    this.mediaBaseUrl,
    this.picker,
    super.key,
  });

  final String label;
  final String settingKey;
  final String path;
  final bool isUploading;
  final String? helperText;
  final String? mediaBaseUrl;
  final ImagePicker? picker;
  final void Function(XFile file) onSelected;

  @override
  Widget build(BuildContext context) {
    final imageUrl = _resolveUrl(path, mediaBaseUrl);
    return Container(
      margin: const EdgeInsets.symmetric(vertical: 6),
      padding: const EdgeInsets.all(12),
      decoration: BoxDecoration(
        border: Border.all(color: Theme.of(context).colorScheme.outlineVariant),
        borderRadius: BorderRadius.circular(AppRadius.lg),
      ),
      child: Row(
        children: [
          Container(
            width: 64,
            height: 64,
            clipBehavior: Clip.antiAlias,
            decoration: BoxDecoration(
              color: AdminPalette.primary.withAlpha(15),
              borderRadius: BorderRadius.circular(AppRadius.md),
            ),
            child: imageUrl == null
                ? const Icon(Icons.image_outlined, color: AdminPalette.primary)
                : Image.network(
                    imageUrl,
                    fit: BoxFit.contain,
                    errorBuilder: (context, error, stackTrace) =>
                        const Icon(Icons.broken_image_outlined),
                  ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(label, style: const TextStyle(fontWeight: FontWeight.w600)),
                if (helperText != null)
                  Text(helperText!, style: Theme.of(context).textTheme.bodySmall),
                if (path.isNotEmpty)
                  Text(
                    path,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    textDirection: TextDirection.ltr,
                    style: Theme.of(context).textTheme.bodySmall,
                  ),
              ],
            ),
          ),
          const SizedBox(width: 8),
          IconButton.filledTonal(
            key: ValueKey('upload-$settingKey'),
            tooltip: 'اختيار ورفع صورة',
            onPressed: isUploading
                ? null
                : () async {
                    final file = await (picker ?? ImagePicker()).pickImage(
                      source: ImageSource.gallery,
                      maxWidth: 2048,
                      maxHeight: 2048,
                      imageQuality: 92,
                    );
                    if (file != null) onSelected(file);
                  },
            icon: isUploading
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  )
                : const Icon(Icons.upload_rounded),
          ),
        ],
      ),
    );
  }

  static String? _resolveUrl(String path, String? baseUrl) {
    if (path.isEmpty) return null;
    final uri = Uri.tryParse(path);
    if (uri != null && uri.hasScheme) return path;
    if (baseUrl == null || baseUrl.isEmpty) return null;
    return '${baseUrl.replaceFirst(RegExp(r'/$'), '')}/${path.replaceFirst(RegExp(r'^/'), '')}';
  }
}
