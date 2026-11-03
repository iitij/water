import 'package:flutter/material.dart';
import '../l10n/list_options_strings.dart';
import '../models/record_location.dart';

class RecordLocationLabel extends StatelessWidget {
  const RecordLocationLabel({super.key, required this.location});
  final RecordLocation location;
  @override
  Widget build(BuildContext context) => Text(
    '${location.squareLabel ?? (location.squareId == null ? ListOptionsStrings.noSquare : '${ListOptionsStrings.square} ${location.squareId}')} · '
    '${location.paths.isEmpty ? ListOptionsStrings.noPath : '${ListOptionsStrings.path}: ${location.paths.join('، ')}'}',
    style: Theme.of(context).textTheme.bodySmall,
  );
}
