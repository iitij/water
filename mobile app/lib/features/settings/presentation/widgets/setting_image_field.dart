import 'package:flutter/material.dart';
import 'package:image_picker/image_picker.dart';

import '../../../../core/ui/app_controls.dart';
import '../../../../core/ui/app_surfaces.dart';
import '../../../../core/ui/app_tokens.dart';

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
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 6),
      child: AppCard(
        padding: const EdgeInsets.all(12),
        child: Row(
        children: [
          SizedBox(
            width: 64,
            height: 64,
            child: imageUrl == null
                ? const AppGlyph(icon: Icons.image_outlined, size: 64, neutral: true)
                : Container(
                    clipBehavior: Clip.antiAlias,
                    decoration: BoxDecoration(
                      color: Theme.of(context).colorScheme.surfaceContainerHighest,
                      borderRadius: BorderRadius.circular(AppRadius.md),
                    ),
                    child: Image.network(
                      imageUrl,
                      fit: BoxFit.contain,
                      errorBuilder: (context, error, stackTrace) =>
                          const Icon(Icons.broken_image_outlined),
                    ),
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
          if (isUploading)
            const SizedBox.square(
              dimension: 40,
              child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
            )
          else
            AppIconButton(
              key: ValueKey('upload-$settingKey'),
              tooltip: 'اختيار ورفع صورة',
              onPressed: () async {
                final file = await (picker ?? ImagePicker()).pickImage(
                  source: ImageSource.gallery,
                  maxWidth: 2048,
                  maxHeight: 2048,
                  imageQuality: 92,
                );
                if (file != null) onSelected(file);
              },
              icon: Icons.upload_rounded,
            ),
        ],
        ),
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
