import 'package:flutter/material.dart';
import 'package:get_it/get_it.dart';

import '../permissions/permission_service.dart';

class PermissionGuard extends StatelessWidget {
  const PermissionGuard({
    super.key,
    required this.permission,
    required this.child,
    this.fallback,
  });

  final String permission;
  final Widget child;
  final Widget? fallback;

  @override
  Widget build(BuildContext context) {
    final allowed = GetIt.I<PermissionService>().hasPermission(permission);
    if (allowed) return child;
    return fallback ?? const SizedBox.shrink();
  }
}
