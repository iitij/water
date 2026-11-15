/// حالة الفاتورة — مطابقة لـ `enum('draft','issued','partially_paid','paid','overdue')`
/// في `2024_01_01_000006_create_invoices_table.php` وتسميات `InvoiceResource::table`.
enum InvoiceStatus {
  draft('draft', 'مسودة'),
  issued('issued', 'صادرة'),
  partiallyPaid('partially_paid', 'مسددة جزئياً'),
  paid('paid', 'مسددة'),
  overdue('overdue', 'متأخرة'),
  cancelled('cancelled', 'ملغاة');

  const InvoiceStatus(this.wire, this.label);

  final String wire;
  final String label;

  /// اسم الحالة بالعربية (اسم مستعار لـ [label]).
  String get labelAr => label;

  static InvoiceStatus parse(String? value) => InvoiceStatus.values.firstWhere(
        (s) => s.wire == value,
        orElse: () => InvoiceStatus.issued,
      );

  /// حالات التحصيل النشطة: `whereIn('status', ['issued','partially_paid','overdue'])`
  /// في `InvoiceController::unpaidInvoices` و`PaymentService::distributePayment`.
  bool get isOpenForCollection =>
      this == issued || this == partiallyPaid || this == overdue;

  bool get isSettled => this == paid;

  String get colorKey => switch (this) {
        InvoiceStatus.paid => 'paid',
        InvoiceStatus.partiallyPaid => 'partially_paid',
        InvoiceStatus.overdue => 'overdue',
        InvoiceStatus.issued => 'issued',
        InvoiceStatus.draft => 'draft',
        InvoiceStatus.cancelled => 'cancelled',
      };
}

/// نوع الفاتورة (محركا الإصدار).
enum InvoiceType {
  meter('meter', 'عداد'),
  subscription('subscription', 'اشتراك');

  const InvoiceType(this.wire, this.label);

  final String wire;
  final String label;

  static InvoiceType parse(String? value) => InvoiceType.values.firstWhere(
        (t) => t.wire == value,
        orElse: () => InvoiceType.meter,
      );
}

/// نوع بند الفاتورة (`invoice_items.type`).
enum InvoiceItemType {
  consumption('consumption', 'استهلاك مياه'),
  previousBalance('previous_balance', 'متأخرات سابقة'),
  subscription('subscription', 'رسوم اشتراك');

  const InvoiceItemType(this.wire, this.label);

  final String wire;
  final String label;

  static InvoiceItemType parse(String? value) => InvoiceItemType.values.firstWhere(
        (t) => t.wire == value,
        orElse: () => InvoiceItemType.consumption,
      );
}

/// طريقة الدفع — `enum('cash','bank_transfer','check')` في جدول `payments`.
enum PaymentMethod {
  cash('cash', 'نقدي'),
  bankTransfer('bank_transfer', 'تحويل بنكي'),
  check('check', 'شيك');

  const PaymentMethod(this.wire, this.label);

  final String wire;
  final String label;

  /// اسم طريقة الدفع بالعربية (اسم مستعار لـ [label]).
  String get labelAr => label;

  static PaymentMethod parse(String? value) => PaymentMethod.values.firstWhere(
        (m) => m.wire == value,
        orElse: () => PaymentMethod.cash,
      );

  /// القاعدة الشرطية في `InvoiceResource#L402-405`: `reference_number` يظهر ويُلزم
  /// فقط مع `bank_transfer` أو `check`.
  bool get requiresReference => this == bankTransfer || this == check;
}
