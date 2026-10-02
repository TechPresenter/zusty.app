import '../core/utils/formatters.dart';
import '../core/utils/json.dart';

/// The booking state machine from `Zuste_SRS.md` SRS-FR-15.
enum BookingStatus {
  pending,
  countered,
  accepted,
  rejected,
  awaitingPayment,
  confirmed,
  inProgress,
  delivered,
  completed,
  cancelled,
  expired,
  disputed,
  refunded;

  static BookingStatus fromString(String? value) =>
      switch (value?.toLowerCase()) {
        'countered' => BookingStatus.countered,
        'accepted' => BookingStatus.accepted,
        'rejected' => BookingStatus.rejected,
        'awaiting_payment' => BookingStatus.awaitingPayment,
        'confirmed' => BookingStatus.confirmed,
        'in_progress' => BookingStatus.inProgress,
        'delivered' => BookingStatus.delivered,
        'completed' => BookingStatus.completed,
        'cancelled' => BookingStatus.cancelled,
        'expired' => BookingStatus.expired,
        'disputed' => BookingStatus.disputed,
        'refunded' => BookingStatus.refunded,
        _ => BookingStatus.pending,
      };

  /// Matches `BookingController::statusLabel()`.
  String get label => switch (this) {
        BookingStatus.pending => 'Pending',
        BookingStatus.countered => 'Counter offer',
        BookingStatus.accepted => 'Accepted',
        BookingStatus.rejected => 'Declined',
        BookingStatus.awaitingPayment => 'Awaiting payment',
        BookingStatus.confirmed => 'Confirmed',
        BookingStatus.inProgress => 'In progress',
        BookingStatus.delivered => 'Delivered',
        BookingStatus.completed => 'Completed',
        BookingStatus.cancelled => 'Cancelled',
        BookingStatus.expired => 'Expired',
        BookingStatus.disputed => 'Disputed',
        BookingStatus.refunded => 'Refunded',
      };

  /// Matches `BookingController::statusTone()` — drives the status chip colour.
  StatusTone get tone => switch (this) {
        BookingStatus.pending ||
        BookingStatus.countered ||
        BookingStatus.awaitingPayment =>
          StatusTone.warning,
        BookingStatus.accepted ||
        BookingStatus.confirmed ||
        BookingStatus.inProgress ||
        BookingStatus.delivered =>
          StatusTone.info,
        BookingStatus.completed => StatusTone.success,
        BookingStatus.rejected ||
        BookingStatus.cancelled ||
        BookingStatus.expired ||
        BookingStatus.disputed ||
        BookingStatus.refunded =>
          StatusTone.error,
      };

  /// Whether the booking is still moving through the lifecycle.
  bool get isActive => switch (this) {
        BookingStatus.pending ||
        BookingStatus.countered ||
        BookingStatus.accepted ||
        BookingStatus.awaitingPayment ||
        BookingStatus.confirmed ||
        BookingStatus.inProgress ||
        BookingStatus.delivered =>
          true,
        _ => false,
      };
}

/// Semantic tone for status chips (DESIGN.md § Status Chips).
enum StatusTone {
  success,
  warning,
  info,
  error,
  neutral;

  static StatusTone fromString(String? value) => switch (value?.toLowerCase()) {
        'success' => StatusTone.success,
        'warning' => StatusTone.warning,
        'info' => StatusTone.info,
        'error' || 'danger' => StatusTone.error,
        _ => StatusTone.neutral,
      };
}

/// The counterparty shown on a booking row — brand or creator depending on
/// which side is viewing.
class BookingCounterparty {
  const BookingCounterparty({
    required this.name,
    this.avatarUrl,
    this.handle,
    this.isVerified = false,
  });

  final String name;
  final String? avatarUrl;
  final String? handle;
  final bool isVerified;

  factory BookingCounterparty.fromJson(Map<String, dynamic> json) =>
      BookingCounterparty(
        name: Json.str(json['name'], 'Zusty user'),
        avatarUrl: Json.strOrNull(json['avatar_url']),
        handle: Json.strOrNull(json['handle']),
        isVerified: Json.boolean(json['is_verified']),
      );

  String get initials => Formatters.initials(name);
}

/// A booking row, from `BookingController::presentListItem()`.
class Booking {
  const Booking({
    required this.id,
    required this.code,
    required this.title,
    required this.status,
    required this.counterparty,
    this.campaignType = '',
    this.platform = '',
    this.postsCount = 0,
    this.amount = 0,
    this.amountLabel = '',
    this.currency = 'INR',
    this.statusLabel = '',
    this.tone = StatusTone.neutral,
    this.paymentStatus = '',
    this.requestedAt,
    this.deliveryDueAt,
    this.dateLabel = '',
    this.canReview = false,
  });

  final int id;
  final String code;
  final String title;
  final BookingStatus status;
  final BookingCounterparty counterparty;
  final String campaignType;
  final String platform;
  final int postsCount;
  final double amount;

  /// Server-formatted, e.g. `₹25,000`.
  final String amountLabel;
  final String currency;
  final String statusLabel;
  final StatusTone tone;
  final String paymentStatus;
  final DateTime? requestedAt;
  final DateTime? deliveryDueAt;
  final String dateLabel;
  final bool canReview;

  factory Booking.fromJson(Map<String, dynamic> json) {
    final status = BookingStatus.fromString(Json.strOrNull(json['status']));
    return Booking(
      id: Json.integer(json['id']),
      code: Json.str(json['code']),
      title: Json.str(json['title'], 'Campaign'),
      status: status,
      counterparty:
          BookingCounterparty.fromJson(Json.map(json['counterparty'])),
      campaignType: Json.str(json['campaign_type']),
      platform: Json.str(json['platform']),
      postsCount: Json.integer(json['posts_count']),
      amount: Json.decimal(json['amount']),
      amountLabel: Json.str(
        json['amount_label'],
        Formatters.currency(Json.decimal(json['amount'])),
      ),
      currency: Json.str(json['currency'], 'INR'),
      statusLabel: Json.str(json['status_label'], status.label),
      tone: json['status_tone'] != null
          ? StatusTone.fromString(Json.strOrNull(json['status_tone']))
          : status.tone,
      paymentStatus: Json.str(json['payment_status']),
      requestedAt: Formatters.parseDate(json['requested_at']),
      deliveryDueAt: Formatters.parseDate(json['delivery_due_at']),
      dateLabel: Json.str(json['date_label']),
      canReview: Json.boolean(json['can_review']),
    );
  }
}

/// What the viewer may do right now — the backend computes this so the action
/// bar rules live in exactly one place.
class BookingActions {
  const BookingActions({
    this.canAccept = false,
    this.canReject = false,
    this.canCounter = false,
    this.canAcceptOffer = false,
    this.canPay = false,
    this.canDeliver = false,
    this.canComplete = false,
    this.canCancel = false,
    this.canReview = false,
    this.canMessage = true,
    this.canViewInvoice = false,
  });

  final bool canAccept;
  final bool canReject;
  final bool canCounter;
  final bool canAcceptOffer;
  final bool canPay;
  final bool canDeliver;
  final bool canComplete;
  final bool canCancel;
  final bool canReview;
  final bool canMessage;
  final bool canViewInvoice;

  factory BookingActions.fromJson(Map<String, dynamic> json) => BookingActions(
        canAccept: Json.boolean(json['can_accept']),
        canReject: Json.boolean(json['can_reject']),
        canCounter: Json.boolean(json['can_counter']),
        canAcceptOffer: Json.boolean(json['can_accept_offer']),
        canPay: Json.boolean(json['can_pay']),
        canDeliver: Json.boolean(json['can_deliver']),
        canComplete: Json.boolean(json['can_complete']),
        canCancel: Json.boolean(json['can_cancel']),
        canReview: Json.boolean(json['can_review']),
        canMessage: Json.boolean(json['can_message'], true),
        canViewInvoice: Json.boolean(json['can_view_invoice']),
      );
}

/// A money breakdown line rendered on the booking and payment screens.
class BookingAmounts {
  const BookingAmounts({
    this.amount = '',
    this.platformFee = '',
    this.tax = '',
    this.discount = '',
    this.total = '',
    this.earning = '',
    this.commission = '',
  });

  final String amount;
  final String platformFee;
  final String tax;

  /// Already signed by the server (`-₹500.00`), because a bare amount beside
  /// "Coupon" in an order summary reads as a charge rather than a saving.
  final String discount;
  final String total;
  final String earning;

  /// The creator's commission. Empty from servers that predate the field, in
  /// which case the creator breakdown falls back to [platformFee].
  final String commission;

  factory BookingAmounts.fromJson(Map<String, dynamic> json) => BookingAmounts(
        amount: Json.str(json['amount']),
        platformFee: Json.str(json['platform_fee']),
        tax: Json.str(json['tax']),
        discount: Json.str(json['discount']),
        total: Json.str(json['total']),
        earning: Json.str(json['earning']),
        commission: Json.str(json['commission']),
      );
}

/// A coupon the customer could use on a specific booking.
///
/// Ineligible offers are still listed, carrying [reason], because "needs a
/// booking of at least ₹5,000" tells the customer something they can act on;
/// hiding the code entirely does not.
class CouponOffer {
  const CouponOffer({
    required this.id,
    required this.code,
    this.name = '',
    this.description,
    this.discountLabel = '',
    this.savingLabel,
    this.discount = 0,
    this.eligible = false,
    this.isApplied = false,
    this.reason,
  });

  final int id;
  final String code;
  final String name;
  final String? description;

  /// What the coupon offers in the abstract — "10% off".
  final String discountLabel;

  /// What it is worth on THIS booking — "You save ₹500.00".
  final String? savingLabel;
  final double discount;
  final bool eligible;
  final bool isApplied;
  final String? reason;

  factory CouponOffer.fromJson(Map<String, dynamic> json) => CouponOffer(
        id: Json.integer(json['id']),
        code: Json.str(json['code']),
        name: Json.str(json['name']),
        description: Json.strOrNull(json['description']),
        discountLabel: Json.str(json['discount_label']),
        savingLabel: Json.strOrNull(json['saving_label']),
        discount: Json.decimal(json['discount']),
        eligible: Json.boolean(json['eligible']),
        isApplied: Json.boolean(json['is_applied']),
        reason: Json.strOrNull(json['reason']),
      );
}

/// One entry in the booking status timeline (`booking_status_history`).
class BookingTimelineEntry {
  const BookingTimelineEntry({
    required this.toStatus,
    this.label = '',
    this.note,
    this.actorName,
    this.actorType,
    this.createdAt,
  });

  final String toStatus;
  final String label;
  final String? note;
  final String? actorName;

  /// `brand`, `creator`, `admin` or `system` — who moved the booking on. The
  /// live API sends this rather than a name, so the timeline can still say
  /// "by the creator" when [actorName] is absent.
  final String? actorType;
  final DateTime? createdAt;

  factory BookingTimelineEntry.fromJson(Map<String, dynamic> json) =>
      BookingTimelineEntry(
        toStatus: Json.str(json['to_status']),
        label: Json.str(
          json['label'],
          BookingStatus.fromString(Json.strOrNull(json['to_status'])).label,
        ),
        note: Json.strOrNull(json['note']),
        actorName: Json.strOrNull(json['actor_name']),
        actorType: Json.strOrNull(json['changed_by_type'] ?? json['actor_type']),
        createdAt: Formatters.parseDate(json['created_at']),
      );
}

/// A single deliverable inside a campaign brief.
class Deliverable {
  const Deliverable({
    required this.id,
    this.type = '',
    this.platform = '',
    this.quantity = 1,
    this.description = '',
    this.status = 'pending',
    this.mediaUrl,
    this.liveUrl,
    this.metrics = const {},
    this.submittedAt,
    this.title = '',
    this.thumbnailUrl,
    this.remarks,
    this.revisionCount = 0,
  });

  final int id;
  final String type;
  final String platform;
  final int quantity;
  final String description;
  final String status;
  final String? mediaUrl;
  final String? liveUrl;
  final Map<String, dynamic> metrics;
  final DateTime? submittedAt;

  /// What the creator called the item ("Reel 1"). `booking_deliverables` has
  /// a title and no type, so this is often the only name a live row carries.
  final String title;
  final String? thumbnailUrl;

  /// The brand's feedback when changes were requested.
  final String? remarks;
  final int revisionCount;

  /// The best short name for the item: its title, else its type.
  String get displayName => title.isNotEmpty
      ? title
      : (type.isEmpty ? 'Deliverable' : type[0].toUpperCase() + type.substring(1));

  factory Deliverable.fromJson(Map<String, dynamic> json) => Deliverable(
        id: Json.integer(json['id']),
        type: Json.str(json['type'] ?? json['deliverable_type']),
        platform: Json.str(json['platform']),
        quantity: Json.integer(json['quantity'], 1),
        description: Json.str(json['description']),
        status: Json.str(json['status'], 'pending'),
        mediaUrl: Json.strOrNull(json['media_url']),
        // The table stores the published link as `content_url`.
        liveUrl: Json.strOrNull(json['live_url'] ?? json['content_url']),
        metrics: Json.map(json['metrics']),
        submittedAt: Formatters.parseDate(json['submitted_at']),
        title: Json.str(json['title']),
        thumbnailUrl: Json.strOrNull(json['thumbnail_url']),
        remarks: Json.strOrNull(json['remarks']),
        revisionCount: Json.integer(json['revision_count']),
      );
}

/// Full booking detail from `GET /bookings/{id}`.
class BookingDetail {
  const BookingDetail({
    required this.booking,
    required this.actions,
    required this.amounts,
    this.brief = '',
    this.viewerRole = 'brand',
    this.creatorId,
    this.creatorHandle,
    this.conversationId,
    this.platformFee = 0,
    this.taxAmount = 0,
    this.totalAmount = 0,
    this.creatorEarning = 0,
    this.discountAmount = 0,
    this.couponCode,
    this.startDate,
    this.endDate,
    this.timeline = const [],
    this.deliverables = const [],
    this.offers = const [],
    this.invoiceUrl,
    this.escrowStatus = '',
    this.counterpartyHeadline,
    this.expiresAt,
    this.acceptedAt,
    this.paidAt,
    this.deliveredAt,
    this.completedAt,
    this.cancelledAt,
    this.cancellationReason,
    this.rejectionReason,
    this.taxRate,
  });

  final Booking booking;
  final BookingActions actions;
  final BookingAmounts amounts;
  final String brief;

  /// `brand` or `creator` — which side the signed-in user is on.
  final String viewerRole;
  final int? creatorId;
  final String? creatorHandle;
  final int? conversationId;
  final double platformFee;
  final double taxAmount;
  final double totalAmount;
  final double creatorEarning;
  final double discountAmount;

  /// The coupon currently on the booking, if any. Comes from the server rather
  /// than the screen's own state, so a reload still shows what was applied.
  final String? couponCode;
  final DateTime? startDate;
  final DateTime? endDate;
  final List<BookingTimelineEntry> timeline;
  final List<Deliverable> deliverables;
  final List<CounterOffer> offers;
  final String? invoiceUrl;

  /// `none`, `held`, `released` or `refunded` — where the money physically
  /// sits. `payment_status` only says `paid`, which is true both while the
  /// money is held and after it has gone to the creator.
  final String escrowStatus;

  /// The creator's one-line headline, shown under their name to a brand.
  final String? counterpartyHeadline;

  // Milestones, straight from the booking row. Each is null until it happens.
  final DateTime? expiresAt;
  final DateTime? acceptedAt;
  final DateTime? paidAt;
  final DateTime? deliveredAt;
  final DateTime? completedAt;
  final DateTime? cancelledAt;
  final String? cancellationReason;
  final String? rejectionReason;

  /// GST rate applied to this booking, e.g. 18. Null when not sent.
  final double? taxRate;

  bool get isBrandView => viewerRole == 'brand';

  /// Whether the brand has paid — read from both fields, because older rows
  /// recorded escrow in `payment_status` and current ones say `paid` there and
  /// keep the escrow state in `escrow_status`.
  bool get isPaid =>
      const {'paid', 'escrow', 'released', 'refunded'}
          .contains(booking.paymentStatus) ||
      const {'held', 'released', 'refunded'}.contains(escrowStatus);

  factory BookingDetail.fromJson(Map<String, dynamic> json) => BookingDetail(
        booking: Booking.fromJson(json),
        actions: BookingActions.fromJson(Json.map(json['actions'])),
        amounts: BookingAmounts.fromJson(Json.map(json['formatted'])),
        brief: Json.str(json['brief'] ?? json['description']),
        viewerRole: Json.str(json['viewer_role'], 'brand'),
        creatorId: Json.intOrNull(json['creator_id']),
        creatorHandle: Json.strOrNull(json['creator_handle']),
        conversationId: Json.intOrNull(json['conversation_id']),
        platformFee: Json.decimal(json['platform_fee']),
        taxAmount: Json.decimal(json['tax_amount']),
        totalAmount: Json.decimal(json['total_amount']),
        creatorEarning: Json.decimal(json['creator_earning']),
        discountAmount: Json.decimal(json['discount_amount']),
        couponCode: Json.strOrNull(Json.map(json['coupon'])['code']),
        startDate: Formatters.parseDate(json['start_date']),
        endDate: Formatters.parseDate(json['end_date']),
        timeline: Json.list(json['timeline'], BookingTimelineEntry.fromJson),
        deliverables: Json.list(json['deliverables'], Deliverable.fromJson),
        offers: Json.list(json['offers'], CounterOffer.fromJson),
        invoiceUrl: Json.strOrNull(Json.map(json['invoice'])['url']),
        escrowStatus: Json.str(json['escrow_status']),
        counterpartyHeadline: Json.strOrNull(json['creator_headline']),
        expiresAt: Formatters.parseDate(json['expires_at']),
        acceptedAt: Formatters.parseDate(json['accepted_at']),
        paidAt: Formatters.parseDate(json['paid_at']),
        deliveredAt: Formatters.parseDate(json['delivered_at']),
        completedAt: Formatters.parseDate(json['completed_at']),
        cancelledAt: Formatters.parseDate(json['cancelled_at']),
        cancellationReason: Json.strOrNull(json['cancellation_reason']),
        rejectionReason: Json.strOrNull(json['rejection_reason']),
        taxRate: Json.doubleOrNull(json['tax_rate']),
      );
}

/// How many bookings sit behind each My Bookings tab, from the list
/// endpoint's `meta.tabs`. Null counts mean the server did not say.
class BookingTabCounts {
  const BookingTabCounts({this.active, this.completed, this.cancelled});

  final int? active;
  final int? completed;
  final int? cancelled;

  /// Servers without an `active` bucket still send `pending` and `confirmed`,
  /// which together are the same set of statuses.
  factory BookingTabCounts.fromJson(Map<String, dynamic> json) {
    final pending = Json.intOrNull(json['pending']);
    final confirmed = Json.intOrNull(json['confirmed']);

    return BookingTabCounts(
      active: Json.intOrNull(json['active']) ??
          (pending == null && confirmed == null
              ? null
              : (pending ?? 0) + (confirmed ?? 0)),
      completed: Json.intOrNull(json['completed']),
      cancelled: Json.intOrNull(json['cancelled']),
    );
  }
}

/// A negotiation round (`booking_offers`).
class CounterOffer {
  const CounterOffer({
    required this.id,
    required this.amount,
    this.amountLabel = '',
    this.byRole = '',
    this.note,
    this.status = 'pending',
    this.createdAt,
    this.deliveryDays,
  });

  final int id;
  final double amount;
  final String amountLabel;

  /// `brand` or `creator` — who proposed this figure.
  final String byRole;
  final String? note;
  final String status;
  final DateTime? createdAt;

  /// Turnaround the offer was made against, when the proposer set one.
  final int? deliveryDays;

  factory CounterOffer.fromJson(Map<String, dynamic> json) => CounterOffer(
        id: Json.integer(json['id']),
        amount: Json.decimal(json['amount']),
        amountLabel: Json.str(
          json['amount_label'],
          Formatters.currency(Json.decimal(json['amount'])),
        ),
        byRole: Json.str(json['by_role'] ?? json['offered_by']),
        // `booking_offers` stores the covering note as `message`; reading only
        // `note` dropped every negotiation message the live API sent.
        note: Json.strOrNull(json['note'] ?? json['message']),
        status: Json.str(json['status'], 'pending'),
        createdAt: Formatters.parseDate(json['created_at']),
        deliveryDays: Json.intOrNull(json['delivery_days']),
      );
}

/// The live price breakdown from `GET /bookings/quote`, shown while the brand
/// is still filling in the request form.
class BookingQuote {
  const BookingQuote({
    this.amount = 0,
    this.platformFee = 0,
    this.tax = 0,
    this.total = 0,
    this.creatorEarning = 0,
    this.commissionRate = 0,
    this.taxRate = 0,
    this.currency = 'INR',
  });

  final double amount;
  final double platformFee;
  final double tax;
  final double total;
  final double creatorEarning;
  final double commissionRate;
  final double taxRate;
  final String currency;

  factory BookingQuote.fromJson(Map<String, dynamic> json) => BookingQuote(
        amount: Json.decimal(json['amount']),
        platformFee: Json.decimal(json['platform_fee']),
        tax: Json.decimal(json['tax'] ?? json['tax_amount']),
        total: Json.decimal(json['total'] ?? json['total_amount']),
        creatorEarning: Json.decimal(json['creator_earning']),
        commissionRate: Json.decimal(json['commission_rate']),
        taxRate: Json.decimal(json['tax_rate']),
        currency: Json.str(json['currency'], 'INR'),
      );

  /// Local recomputation used by the mock backend and for optimistic updates
  /// while the quote request is still in flight.
  factory BookingQuote.estimate(
    double amount, {
    double commissionRate = 15,
    double taxRate = 18,
  }) {
    final fee = amount * commissionRate / 100;
    final tax = amount * taxRate / 100;
    return BookingQuote(
      amount: amount,
      platformFee: fee,
      tax: tax,
      total: amount + tax,
      creatorEarning: amount - fee,
      commissionRate: commissionRate,
      taxRate: taxRate,
    );
  }
}
