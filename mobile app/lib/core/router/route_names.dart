class RouteNames {
  const RouteNames._();

  // Dev 1: Auth, Home & Sync
  static const splash = '/splash';
  static const login = '/login';
  static const home = '/home';
  static const services = '/services';
  static const globalSearch = '/search';
  static const profile = '/profile';
  static const offlineSync = '/sync';
  static const diagnostics = '/diagnostics';
  static const apiLogs = '/api-logs';
  static const routeMode = '/route-mode';
  static const appLock = '/app-lock';

  // Shell destinations (five-tab navigation)
  static const fieldHub = '/field';
  static const billingHub = '/billing';
  static const crmHub = '/crm';
  static const moreHub = '/more';

  // Dev 2: Field Operations, Meters & Readings
  static const meters = '/meters';
  static const meterNew = '/meters/new';
  static const fieldReader = '/field-reader';
  static const readings = '/readings';

  // Dev 3: Finance, Invoices, Payments & Tariffs
  static const quickCollector = '/collector';
  static const cashSession = '/cash-session';
  static const cashSessionReview = '/cash-sessions/review';
  static const auditEvents = '/audit-events';
  static const invoices = '/invoices';
  static const invoiceIssue = '/issue-invoice';
  static const invoiceCreate = '/invoices/create';
  static const payments = '/payments';
  static const paymentCreate = '/payments/create';
  static const tariffs = '/tariffs';

  // Dev 4: Squares, Subscribers 360°, Subscription Fees, Tankers & Ledger
  static const squares = '/squares';
  static const subscribers = '/subscribers';
  static const subscriberNew = '/subscribers/new';
  static const subscriptionFees = '/subscription-fees';
  static const serviceRequests = '/service-requests';
  static const transactions = '/transactions';

  // Dev 5: Dashboard, Reports, Settings Studio & Exports
  static const dashboard = '/dashboard';
  static const reports = '/reports';
  static const settings = '/settings';
  static const devices = '/devices';
  static const dataQuality = '/data-quality';
  static const backup = '/backup';
  static const reminders = '/reminders';
  static const exports = '/exports';
}
