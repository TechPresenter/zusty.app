import '../../core/network/api_endpoints.dart';
import '../../core/network/api_response.dart';
import '../../models/booking.dart';
import 'base_repository.dart';

/// The booking lifecycle (`/bookings/*`).
class BookingRepository extends BaseRepository {
  BookingRepository(super.client);

  /// Map the picker's free-text labels onto the server's `deliverable_type`
  /// enum (post, reel, story, video, short, live, ugc, blog, review, bundle).
  ///
  /// The picker is driven by an admin-managed taxonomy, so its labels are
  /// whatever the operator typed — matching on a keyword is deliberate, and
  /// anything unrecognised falls back to `post` rather than sending a value the
  /// server would reject.
  static String _deliverableType(List<String> deliverables) {
    if (deliverables.length > 1) return 'bundle';

    final label = deliverables.first.toLowerCase();

    for (final type in const [
      'reel', 'story', 'short', 'live', 'ugc', 'blog', 'review', 'video', 'post',
    ]) {
      if (label.contains(type)) return type;
    }

    return 'post';
  }

  /// Keep what the server has no column for.
  ///
  /// The API models one delivery deadline and one deliverable type, but the
  /// form collects a date range and a list. Rather than drop the difference —
  /// which is what used to happen — it is appended to the brief so the creator
  /// still sees exactly what was asked for.
  static String _briefWithDetails(
    String brief,
    List<String> deliverables,
    DateTime? startDate,
  ) {
    final extras = <String>[];

    if (deliverables.length > 1) {
      extras.add('Deliverables: ${deliverables.join(', ')}');
    }

    if (startDate != null) {
      extras.add('Requested start: ${startDate.toIso8601String().split('T').first}');
    }

    if (extras.isEmpty) return brief;

    return brief.trim().isEmpty
        ? extras.join('\n')
        : '${brief.trim()}\n\n${extras.join('\n')}';
  }

  /// [status] accepts `active`, `completed`, `cancelled`, or a specific state.
  ///
  /// The endpoint names the facet `tab` and knows all / pending / confirmed /
  /// completed / cancelled. Sending `status` narrowed nothing, so every tab
  /// listed every booking. `active` spans two of the server's tabs, so those
  /// rows are narrowed here by the same rule the app uses everywhere else.
  Future<Paginated<Booking>> list({String? status, int page = 1}) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.bookings,
            query: {
              'tab': _serverTab(status),
              'page': page,
              'per_page': 20,
            },
          );
          final rows = response.dataList.isNotEmpty
              ? response.dataList
              : response.listAt('bookings');

          var items = rows.map(Booking.fromJson).toList();
          if (status == 'active') {
            items = items.where((b) => b.status.isActive).toList();
          }

          final tabs = response.meta['tabs'];

          return BookingPage(
            items: items,
            pagination: response.pagination,
            counts: tabs is Map
                ? BookingTabCounts.fromJson(Map<String, dynamic>.from(tabs))
                : null,
          );
        },
        'bookings',
        (json) {
          var rows = asRows(json).map(Booking.fromJson).toList();
          if (status != null && status != 'all') {
            rows = rows.where((b) => switch (status) {
                  'active' => b.status.isActive,
                  'completed' => b.status == BookingStatus.completed,
                  'cancelled' => b.status == BookingStatus.cancelled ||
                      b.status == BookingStatus.rejected ||
                      b.status == BookingStatus.expired,
                  _ => b.status.name == status,
                }).toList();
          }
          return Paginated(
            items: rows,
            pagination: Pagination.singlePage(rows.length),
          );
        },
      );

  /// Maps the app's tab onto the endpoint's `tab` values.
  ///
  /// `active` is pending ∪ confirmed. The server now has a tab for exactly
  /// that set; a server without one treats an unknown tab as `all`, and the
  /// page is still narrowed above, so either way only active rows show.
  static String _serverTab(String? status) => switch (status) {
        null || '' || 'all' => 'all',
        _ => status,
      };

  Future<BookingDetail> detail(int id) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.booking(id));
          final data = response.maybeMapAt('booking') ?? response.dataMap;
          return BookingDetail.fromJson(data);
        },
        'booking.detail:$id',
        (json) => BookingDetail.fromJson(asMap(json)),
      );

  /// Public offers priced against this booking — each carries the saving it
  /// would give, so the customer picks rather than guesses codes.
  Future<List<CouponOffer>> coupons(int bookingId) => readThrough(
        () async {
          final response = await client.get(ApiEndpoints.bookingCoupons(bookingId));
          return response.listAt('coupons').map(CouponOffer.fromJson).toList();
        },
        'booking.coupons',
        (json) => asRows(json).map(CouponOffer.fromJson).toList(),
      );

  /// Applies a discount code and returns the repriced booking.
  ///
  /// The server does the arithmetic and sends back the whole booking, so the
  /// total on screen is the total that will be charged — the app never
  /// computes a discount of its own.
  Future<BookingDetail> applyCoupon(int bookingId, String code) => writeThrough(
        () async {
          final response = await client.post(
            ApiEndpoints.bookingCoupon(bookingId),
            body: {'code': code},
          );
          final data = response.maybeMapAt('booking') ?? response.dataMap;
          return BookingDetail.fromJson(data);
        },
        'booking.detail:$bookingId',
        (json) => BookingDetail.fromJson(asMap(json)),
      );

  /// Drops the code and restores the full price.
  Future<BookingDetail> removeCoupon(int bookingId) => writeThrough(
        () async {
          final response = await client.delete(ApiEndpoints.bookingCoupon(bookingId));
          final data = response.maybeMapAt('booking') ?? response.dataMap;
          return BookingDetail.fromJson(data);
        },
        'booking.detail:$bookingId',
        (json) => BookingDetail.fromJson(asMap(json)),
      );

  /// Live price breakdown while the brand fills in the request form.
  Future<BookingQuote> quote({
    required int creatorId,
    required double amount,
    int? packageId,
  }) =>
      readThrough(
        () async {
          final response = await client.get(
            ApiEndpoints.bookingQuote,
            query: {
              'creator_id': creatorId,
              'amount': amount,
              'package_id': ?packageId,
            },
          );
          return BookingQuote.fromJson(
            response.maybeMapAt('quote') ?? response.dataMap,
          );
        },
        // Priced by the same local formula the form uses while typing, so
        // there is nothing for a fixture to supply.
        'noop',
        (_) => BookingQuote.estimate(amount),
      );

  Future<BookingDetail> create({
    required int creatorId,
    required String title,
    required String brief,
    required double budget,
    required String campaignType,
    required String platform,
    int postsCount = 1,
    int? packageId,
    DateTime? startDate,
    DateTime? endDate,
    List<String> deliverables = const [],
  }) =>
      writeThrough(
        () async {
          final response = await client.post(
            ApiEndpoints.bookings,
            // Field names must match BookingController::store()'s validator
            // exactly — Validator::validated() returns only whitelisted keys,
            // so anything named differently is dropped in silence. `brief`,
            // `start_date`, `end_date` and `deliverables` were all being
            // discarded, and every booking was created with a null description
            // and a default due date seven days out.
            body: {
              'creator_id': creatorId,
              'title': title,
              'description': _briefWithDetails(brief, deliverables, startDate),
              'amount': budget,
              'campaign_type': campaignType,
              'platform': platform,
              'posts_count': postsCount,
              'package_id': ?packageId,
              // The server models one delivery deadline, not a range, so the
              // end date is the deadline. The start date is carried in the
              // description by _briefWithDetails rather than being dropped.
              if (endDate != null)
                'delivery_due_at':
                    endDate.toIso8601String().split('T').first
              else if (startDate != null)
                'delivery_due_at':
                    startDate.toIso8601String().split('T').first,
              if (deliverables.isNotEmpty)
                'deliverable_type': _deliverableType(deliverables),
            },
          );
          final data = response.maybeMapAt('booking') ?? response.dataMap;
          return BookingDetail.fromJson(data);
        },
        'booking.detail:1',
        (json) => BookingDetail.fromJson({
          ...asMap(json),
          'title': title,
          'brief': brief,
          'amount': budget,
          'status': 'pending',
          'status_label': 'Pending',
          'status_tone': 'warning',
        }),
      );

  Future<void> accept(int id) => _action(ApiEndpoints.acceptBooking(id));

  Future<void> reject(int id, {String? reason}) =>
      _action(ApiEndpoints.rejectBooking(id), body: {'reason': reason});

  /// The endpoint stores the note under `message`; sending `note` had it
  /// dropped by the validator and every counter offer arrived blank.
  Future<void> counterOffer(int id, {required double amount, String? note}) =>
      _action(
        ApiEndpoints.counterOffer(id),
        body: {'amount': amount, 'message': note},
      );

  Future<void> acceptOffer(int id) => _action(ApiEndpoints.acceptOffer(id));

  Future<void> deliver(int id, {String? note, List<String> urls = const []}) =>
      _action(
        ApiEndpoints.deliverBooking(id),
        body: {'note': note, 'urls': urls},
      );

  /// Brand approves the delivered work — releases escrow.
  Future<void> complete(int id) => _action(ApiEndpoints.completeBooking(id));

  /// Sends the work back for changes (Approval Workflow, "Request Changes").
  ///
  /// The booking returns to `in_progress`; escrow stays held.
  Future<void> requestRevision(int id, {required String note}) =>
      _action(ApiEndpoints.requestRevision(id), body: {'note': note});

  /// Escalates to support, freezing escrow until an admin rules
  /// (Refund Workflow).
  Future<void> dispute(int id, {required String reason, String? detail}) =>
      _action(
        ApiEndpoints.disputeBooking(id),
        body: {'reason': reason, 'detail': detail},
      );

  Future<void> cancel(int id, {String? reason}) =>
      _action(ApiEndpoints.cancelBooking(id), body: {'reason': reason});

  /// What cancelling would cost right now, in the server's own words —
  /// "You will receive a full refund of ₹30,680.00", or the late-fee terms.
  ///
  /// Asked before the cancel dialog opens so the person sees the refund they
  /// will get at the point of deciding. Live only: there is nothing sensible
  /// to replay from a cache for money that depends on today's date, so the
  /// caller treats any failure as "no preview" and asks anyway.
  Future<String?> cancellationNotice(int id) async {
    final response = await client.get(ApiEndpoints.cancellationPreview(id));
    final message = response.dataMap['message'];
    return message is String && message.trim().isNotEmpty ? message : null;
  }

  Future<void> review(
    int id, {
    required double rating,
    required String comment,
    String? title,
  }) =>
      _action(
        ApiEndpoints.reviewBooking(id),
        body: {'rating': rating, 'comment': comment, 'title': title},
      );

  /// The booking's GST invoice as the server renders it.
  ///
  /// Fetched through the authenticated client rather than opened as a link:
  /// the endpoint needs the bearer token, which a browser handed the URL does
  /// not have, so a link only ever reached a 401.
  Future<String> invoiceHtml(int bookingId) async {
    final response = await client.get(
      ApiEndpoints.invoice(bookingId),
      query: {'format': 'html'},
    );
    return response.data.toString();
  }

  Future<void> _action(String path, {Map<String, dynamic>? body}) =>
      writeThrough(
        () async {
          await client.post(path, body: body);
        },
        'noop',
        (_) {},
      );
}

/// A page of bookings plus the per-tab totals the list endpoint reports
/// alongside it, so My Bookings can badge every tab from one request.
class BookingPage extends Paginated<Booking> {
  const BookingPage({
    required super.items,
    required super.pagination,
    this.counts,
  });

  final BookingTabCounts? counts;
}
