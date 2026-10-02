import '../core/utils/formatters.dart';
import '../core/utils/json.dart';
import 'booking.dart' show StatusTone;

/// A GST-compliant invoice issued against a booking (FR-014).
///
/// Mirrors the `invoices` table. `brand_invoice` bills the brand for a booking;
/// `creator_payout` documents what a creator was paid.
class Invoice {
  const Invoice({
    required this.id,
    required this.number,
    required this.bookingId,
    this.bookingCode,
    this.bookingTitle,
    this.type = 'brand_invoice',
    this.billToName = '',
    this.billToEmail,
    this.billToAddress,
    this.billToGst,
    this.lineItems = const [],
    this.subtotal = 0,
    this.feeAmount = 0,
    this.taxAmount = 0,
    this.taxRate = 0,
    this.discountAmount = 0,
    this.total = 0,
    this.currency = 'INR',
    this.status = 'issued',
    this.issuedAt,
    this.dueAt,
    this.paidAt,
    this.pdfUrl,
  });

  final int id;

  /// e.g. `ZI/2026-27/000123`.
  final String number;
  final int bookingId;
  final String? bookingCode;
  final String? bookingTitle;
  final String type;

  final String billToName;
  final String? billToEmail;
  final String? billToAddress;
  final String? billToGst;

  final List<InvoiceLine> lineItems;
  final double subtotal;
  final double feeAmount;
  final double taxAmount;
  final double taxRate;
  final double discountAmount;
  final double total;
  final String currency;

  /// `draft`, `issued`, `paid`, `cancelled`, `refunded`.
  final String status;
  final DateTime? issuedAt;
  final DateTime? dueAt;
  final DateTime? paidAt;
  final String? pdfUrl;

  factory Invoice.fromJson(Map<String, dynamic> json) => Invoice(
        id: Json.integer(json['id']),
        number: Json.str(json['invoice_number'] ?? json['number']),
        bookingId: Json.integer(json['booking_id']),
        bookingCode: Json.strOrNull(json['booking_code']),
        bookingTitle: Json.strOrNull(json['booking_title']),
        type: Json.str(json['type'], 'brand_invoice'),
        billToName: Json.str(json['bill_to_name']),
        billToEmail: Json.strOrNull(json['bill_to_email']),
        billToAddress: Json.strOrNull(json['bill_to_address']),
        billToGst: Json.strOrNull(json['bill_to_gst']),
        lineItems: Json.list(json['line_items'], InvoiceLine.fromJson),
        subtotal: Json.decimal(json['subtotal']),
        feeAmount: Json.decimal(json['fee_amount']),
        taxAmount: Json.decimal(json['tax_amount']),
        taxRate: Json.decimal(json['tax_rate']),
        discountAmount: Json.decimal(json['discount_amount']),
        total: Json.decimal(json['total']),
        currency: Json.str(json['currency'], 'INR'),
        status: Json.str(json['status'], 'issued'),
        issuedAt: Formatters.parseDate(json['issued_at']),
        dueAt: Formatters.parseDate(json['due_at']),
        paidAt: Formatters.parseDate(json['paid_at']),
        pdfUrl: Json.strOrNull(json['pdf_url'] ?? json['url']),
      );

  String get totalLabel => Formatters.currency(total);
  String get subtotalLabel => Formatters.currency(subtotal);
  String get taxLabel => Formatters.currency(taxAmount);
  String get dateLabel => Formatters.date(issuedAt);

  bool get isPaid => status == 'paid';
  bool get hasGst => billToGst != null && billToGst!.isNotEmpty;

  String get statusLabel =>
      status.isEmpty ? 'Issued' : status[0].toUpperCase() + status.substring(1);

  StatusTone get tone => switch (status) {
        'paid' => StatusTone.success,
        'issued' || 'draft' => StatusTone.warning,
        'refunded' => StatusTone.info,
        'cancelled' => StatusTone.error,
        _ => StatusTone.neutral,
      };
}

/// One billed line on an invoice.
class InvoiceLine {
  const InvoiceLine({
    required this.label,
    this.amount = 0,
    this.quantity = 1,
  });

  final String label;
  final double amount;
  final int quantity;

  factory InvoiceLine.fromJson(Map<String, dynamic> json) => InvoiceLine(
        label: Json.str(json['label'] ?? json['description'], 'Item'),
        amount: Json.decimal(json['amount']),
        quantity: Json.integer(json['quantity'], 1),
      );

  String get amountLabel => Formatters.currency(amount);
}
