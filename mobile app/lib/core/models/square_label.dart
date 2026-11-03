/// A square's user-entered identifier, displayed alongside its unchanged name.
String squareDisplayName(String name, String? code) {
  final identifier = code?.trim();
  if (identifier == null || identifier.isEmpty) return name;
  return name.isEmpty ? identifier : '$identifier — $name';
}

String? squareLabelOrNull(Object? name, Object? code) {
  if (name == null && code == null) return null;
  return squareDisplayName(name?.toString() ?? '', code?.toString());
}
