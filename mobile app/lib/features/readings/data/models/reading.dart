import '../../../../core/models/square_label.dart';
class Reading {
  const Reading({
    this.id,
    this.localId,
    required this.meterId,
    required this.readingDate,
    required this.periodStart,
    required this.periodEnd,
    required this.previousReading,
    required this.currentReading,
    required this.consumptionUnits,
    this.actualReadingDate,
    this.isInvoiced = false,
    this.invoiceId,
    this.meterCode,
    this.pathNumber,
    this.squareId,
    this.squareName,
    this.subscriberName,
    this.imagePath,
    this.imageUrl,
    this.isSynced = true,
    this.syncError,
    this.createdAt,
  });

  /// Server-side primary key. Null for a locally queued reading.
  final int? id;
  final String? localId;
  final int meterId;
  final DateTime readingDate;
  final DateTime? actualReadingDate;
  final DateTime periodStart;
  final DateTime periodEnd;
  final double previousReading;
  final double currentReading;
  final double consumptionUnits;
  final bool isInvoiced;
  final int? invoiceId;
  final String? meterCode;
  final int? pathNumber;
  final int? squareId;
  final String? squareName;
  final String? subscriberName;
  final String? imagePath;
  final String? imageUrl;
  final bool isSynced;
  final String? syncError;
  final DateTime? createdAt;

  bool get hasPhoto =>
      (imageUrl != null && imageUrl!.trim().isNotEmpty) ||
      (imagePath != null && imagePath!.trim().isNotEmpty);

  String get selectionKey =>
      id == null ? 'local:${localId ?? ''}' : 'server:$id';

  factory Reading.fromJson(Map<String, dynamic> json) {
    final meter = _map(json['meter']);
    final subscriber = _map(meter?['subscriber']);
    final square = _map(subscriber?['square'] ?? meter?['square']);
    final periodStart =
        _date(json['period_start'] ?? json['reading_date']) ?? DateTime.now();
    final periodEnd =
        _date(json['period_end'] ?? json['reading_date']) ?? periodStart;
    final readingId = _int(json['id'] ?? json['server_id']);
    return Reading(
      id: readingId,
      createdAt: _date(json['created_at']),
      localId: _string(json['local_id'] ?? json['client_action_id']),
      meterId: _int(json['meter_id'] ?? meter?['id']) ?? 0,
      readingDate:
          _date(json['reading_date'] ?? json['actual_reading_date']) ??
          periodEnd,
      actualReadingDate: _date(json['actual_reading_date']),
      periodStart: periodStart,
      periodEnd: periodEnd,
      previousReading: _double(json['previous_reading']) ?? 0,
      currentReading: _double(json['current_reading']) ?? 0,
      consumptionUnits:
          _double(json['consumption_units']) ??
          ((_double(json['current_reading']) ?? 0) -
              (_double(json['previous_reading']) ?? 0)),
      isInvoiced: json['is_invoiced'] == true || json['is_invoiced'] == 1,
      invoiceId: _int(json['invoice_id']),
      meterCode: _string(json['meter_code'] ?? meter?['unique_code']),
      pathNumber: _int(json['path_number'] ?? meter?['path_number']),
      squareId: _int(
        json['square_id'] ?? subscriber?['square_id'] ?? square?['id'],
      ),
      squareName: squareLabelOrNull(json['square_name'] ?? square?['name'], json['square_code'] ?? square?['code']),
      subscriberName: _string(json['subscriber_name'] ?? subscriber?['name']),
      imagePath: _string(json['image_path']),
      imageUrl: _string(json['image_url']),
      isSynced: json['is_synced'] != false,
      syncError: _string(json['sync_error']),
    );
  }

  factory Reading.fromLocalRow(Map<String, Object?> row) => Reading(
    id: _int(row['server_id']),
    localId: _string(row['local_id']),
    meterId: _int(row['meter_id']) ?? 0,
    readingDate: _date(row['reading_date']) ?? DateTime.now(),
    actualReadingDate: _date(row['actual_reading_date']),
    periodStart: _date(row['period_start']) ?? DateTime.now(),
    periodEnd: _date(row['period_end']) ?? DateTime.now(),
    previousReading: _double(row['previous_reading']) ?? 0,
    currentReading: _double(row['current_reading']) ?? 0,
    consumptionUnits: _double(row['consumption_units']) ?? 0,
    isInvoiced: row['is_invoiced'] == 1 || row['is_invoiced'] == true,
    invoiceId: _int(row['invoice_id']),
    meterCode: _string(row['unique_code'] ?? row['meter_code']),
    pathNumber: _int(row['path_number']),
    squareId: _int(row['square_id']),
    squareName: _string(row['square_name']),
    subscriberName: _string(row['subscriber_name']),
    imagePath: _string(row['image_path']),
    isSynced: row['server_id'] != null,
    syncError: _string(row['sync_error']),
    createdAt: _date(row['created_at']),
  );

  Map<String, dynamic> toSyncJson({String? imageBase64}) => {
    'client_action_id': localId,
    'sync_uuid': localId,
    'meter_id': meterId,
    'reading_date': _dateOnly(readingDate),
    'actual_reading_date': _dateOnly(actualReadingDate ?? readingDate),
    'period_start': _dateOnly(periodStart),
    'period_end': _dateOnly(periodEnd),
    'previous_reading': previousReading,
    'current_reading': currentReading,
    'consumption_units': consumptionUnits,
    if (imageBase64 != null) 'image_base64': imageBase64,
    if (imagePath != null) 'image_filename': imagePath!.split('/').last,
  };
}

class MeterPeriodResponse {
  const MeterPeriodResponse({
    required this.lastReading,
    required this.periodStart,
    required this.periodEnd,
    this.autoIssueInvoice = false,
    this.isOfflineEstimate = false,
  });

  final double lastReading;
  final DateTime periodStart;
  final DateTime periodEnd;
  final bool autoIssueInvoice;
  final bool isOfflineEstimate;

  factory MeterPeriodResponse.fromJson(Map<String, dynamic> json) {
    return MeterPeriodResponse(
      lastReading: _double(json['last_reading']) ?? 0,
      periodStart: _date(json['period_start']) ?? DateTime.now(),
      periodEnd: _date(json['period_end']) ?? DateTime.now(),
      autoIssueInvoice: _bool(json['auto_issue_invoice']),
    );
  }

  MeterPeriodResponse asOfflineEstimate() => MeterPeriodResponse(
    lastReading: lastReading,
    periodStart: periodStart,
    periodEnd: periodEnd,
    autoIssueInvoice: autoIssueInvoice,
    isOfflineEstimate: true,
  );

  Map<String, dynamic> toJson() => {
    'last_reading': lastReading,
    'period_start': _dateOnly(periodStart),
    'period_end': _dateOnly(periodEnd),
    'auto_issue_invoice': autoIssueInvoice,
  };
}

class ReadingSubmission {
  const ReadingSubmission({
    required this.meterId,
    required this.readingDate,
    required this.periodStart,
    required this.periodEnd,
    required this.previousReading,
    required this.currentReading,
    this.imagePath,
    this.autoIssueInvoice = false,
  });

  final int meterId;
  final DateTime readingDate;
  final DateTime periodStart;
  final DateTime periodEnd;
  final double previousReading;
  final double currentReading;
  final String? imagePath;
  final bool autoIssueInvoice;

  double get consumption => ((currentReading * 100).round() - (previousReading * 100).round()) / 100;

  Map<String, dynamic> toJson({String? localId}) => {
    if (localId != null) 'client_action_id': localId,
    if (localId != null) 'sync_uuid': localId,
    'meter_id': meterId,
    'reading_date': _dateOnly(readingDate),
    'actual_reading_date': _dateOnly(readingDate),
    'period_start': _dateOnly(periodStart),
    'period_end': _dateOnly(periodEnd),
    'previous_reading': previousReading,
    'current_reading': currentReading,
    // Kept in the local queue payload so offline rows show the real
    // consumption before the server recalculates it on sync.
    'consumption_units': consumption,
    'auto_issue_invoice': autoIssueInvoice,
  };
}

class InvoiceSummary {
  const InvoiceSummary({
    this.id,
    this.billNumber,
    this.billDate,
    required this.totalAmount,
    this.subscriberName,
    this.subscriberPhone,
    this.meterCode,
    this.squareName,
    this.pdfUrl,
    this.periodStart,
    this.periodEnd,
    this.previousReading,
    this.currentReading,
    this.consumptionUnits,
    this.unitPrice,
    this.consumptionFee,
    this.previousBalance,
    this.creditApplied,
  });

  final int? id;
  final String? billNumber;
  final DateTime? billDate;
  final double totalAmount;
  final String? subscriberName;
  final String? subscriberPhone;
  final String? meterCode;
  final String? squareName;
  final String? pdfUrl;
  final DateTime? periodStart;
  final DateTime? periodEnd;
  final double? previousReading;
  final double? currentReading;
  final double? consumptionUnits;
  final double? unitPrice;
  final double? consumptionFee;
  final double? previousBalance;
  final double? creditApplied;

  factory InvoiceSummary.fromJson(Map<String, dynamic> json) => InvoiceSummary(
    id: _int(json['id']),
    billNumber: _string(json['bill_number']),
    billDate: _date(json['bill_date']),
    totalAmount: _double(json['total_amount']) ?? 0,
    subscriberName: _string(json['subscriber_name']),
    subscriberPhone: _string(json['subscriber_phone']),
    meterCode: _string(json['meter_code']),
    squareName: _string(json['square']),
    pdfUrl: _string(json['pdf_url']),
    periodStart: _date(json['period_start']),
    periodEnd: _date(json['period_end']),
    previousReading: _double(json['previous_reading']),
    currentReading: _double(json['current_reading']),
    consumptionUnits: _double(json['consumption_units']),
    unitPrice: _double(json['unit_price']),
    consumptionFee: _double(json['consumption_fee']),
    previousBalance: _double(json['previous_balance']),
    creditApplied: _double(json['credit_applied']),
  );
}

class ReadingSaveResult {
  const ReadingSaveResult({
    required this.reading,
    required this.isPendingSync,
    this.invoiceIssued = false,
    this.invoice,
    this.message,
  });

  final Reading reading;
  final bool isPendingSync;
  final bool invoiceIssued;
  final InvoiceSummary? invoice;
  final String? message;
}

class BulkOperationResult {
  const BulkOperationResult({
    required this.succeededCount,
    this.skippedCount = 0,
    this.failedCount = 0,
    this.message,
  });

  final int succeededCount;
  final int skippedCount;
  final int failedCount;
  final String? message;
}

Map<String, dynamic>? _map(Object? value) {
  if (value is Map<String, dynamic>) return value;
  if (value is Map) return Map<String, dynamic>.from(value);
  return null;
}

String? _string(Object? value) => value?.toString();

int? _int(Object? value) {
  if (value is int) return value;
  if (value is num) return value.toInt();
  return int.tryParse(value?.toString() ?? '');
}

double? _double(Object? value) {
  if (value is num) return value.toDouble();
  return double.tryParse(value?.toString() ?? '');
}

bool _bool(Object? value) =>
    value == true || value == 1 || value?.toString().toLowerCase() == 'true';

DateTime? _date(Object? value) =>
    value == null ? null : DateTime.tryParse(value.toString());

String _dateOnly(DateTime value) =>
    '${value.year.toString().padLeft(4, '0')}-'
    '${value.month.toString().padLeft(2, '0')}-'
    '${value.day.toString().padLeft(2, '0')}';
