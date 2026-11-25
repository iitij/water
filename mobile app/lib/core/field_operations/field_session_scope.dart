import 'dart:convert';

import 'package:crypto/crypto.dart';

/// Immutable account + server ownership for field records. Old, unowned keys
/// are deliberately not claimed, copied, deleted or uploaded.
class FieldSessionScope {
  FieldSessionScope({
    required this.server,
    required this.userId,
    required this.generation,
    required bool Function() isCurrent,
  }) : _isCurrent = isCurrent {
    final uri = Uri.tryParse(server);
    if (uri == null || !uri.hasAuthority ||
        (uri.scheme != 'https' && uri.scheme != 'http') ||
        userId.isEmpty || generation < 0) {
      throw StateError('Invalid field session owner.');
    }
  }

  final String server;
  final String userId;
  final int generation;
  final bool Function() _isCurrent;

  late final String namespace =
      sha256.convert(utf8.encode(jsonEncode([server, userId]))).toString();

  String get prefix => 'field.v2.$namespace.';

  void ensureCurrent() {
    if (!_isCurrent()) throw StateError('Field session changed.');
  }
}
