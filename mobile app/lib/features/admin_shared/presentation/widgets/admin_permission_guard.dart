import 'package:flutter/widgets.dart';

/// Adapter contract for Dev 1's PermissionGuard/PermissionService.
/// Pass `permissionService.can` (or an equivalent closure) to [can].
typedef AdminPermissionCheck = bool Function(String permission);

class AdminPermissionGuard extends StatelessWidget {
  const AdminPermissionGuard({
    required this.permission,
    required this.can,
    required this.child,
    this.fallback = const SizedBox.shrink(),
    super.key,
  });

  final String permission;
  final AdminPermissionCheck can;
  final Widget child;
  final Widget fallback;

  @override
  Widget build(BuildContext context) => can(permission) ? child : fallback;
}

abstract final class AdminPermissions {
  static const viewDashboard = 'View:CustomAccountWidget';
  static const viewStats = 'View:StatsOverview';
  static const viewRevenue = 'View:MonthlyRevenueChart';
  static const viewLatestInvoices = 'View:LatestInvoicesWidget';
  static const viewOverdueInvoices = 'View:OverdueInvoicesWidget';
  static const createPayment = 'Create:Payment';
  static const viewReadingSettings = 'View:ReadingSettings';
  static const viewInvoiceSettings = 'View:InvoiceSettings';
  static const viewReceiptSettings = 'View:ReceiptSettings';
  static const viewWhatsAppSettings = 'View:WhatsAppSettings';
  static const viewExports = 'View:ExportDataPage';
  static const viewUsers = 'ViewAny:User';
  static const createUsers = 'Create:User';
  static const updateUsers = 'Update:User';
  static const deleteUsers = 'Delete:User';
  static const deleteAnyUsers = 'DeleteAny:User';
  static const viewRoles = 'ViewAny:Role';
  static const createRoles = 'Create:Role';
  static const updateRoles = 'Update:Role';
  static const deleteRoles = 'Delete:Role';
  static const viewSettings = 'ViewAny:Setting';
  static const createSettings = 'Create:Setting';
  static const updateSettings = 'Update:Setting';
  static const deleteSettings = 'Delete:Setting';
}
