import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../core/network/api_response.dart';
import '../data/repositories/booking_repository.dart';
import '../models/booking.dart';
import 'core_providers.dart';

/// The tabs on My Bookings.
enum BookingTab {
  active,
  completed,
  cancelled;

  String get label => switch (this) {
        BookingTab.active => 'Active',
        BookingTab.completed => 'Completed',
        BookingTab.cancelled => 'Cancelled',
      };

  String get query => name;
}

/// Bookings for one tab.
final bookingsProvider = FutureProvider.autoDispose
    .family<Paginated<Booking>, BookingTab>((ref, tab) {
  return ref.watch(bookingRepositoryProvider).list(status: tab.query);
});

/// Per-tab totals for the My Bookings badges.
///
/// Read off the Active page the screen loads first rather than fetched on
/// their own: the list endpoint already reports every tab's total, so this
/// costs no extra request. Null until that page arrives, or when the server
/// sends no totals — the tabs then simply show no badge.
final bookingTabCountsProvider = Provider.autoDispose<BookingTabCounts?>((ref) {
  final page = ref.watch(bookingsProvider(BookingTab.active)).valueOrNull;
  return page is BookingPage ? page.counts : null;
});

/// Full booking detail, including the action flags the app renders its bar from.
final bookingDetailProvider =
    FutureProvider.autoDispose.family<BookingDetail, int>((ref, id) {
  return ref.watch(bookingRepositoryProvider).detail(id);
});

/// Offers the brand could use on this booking, each already priced against it.
final bookingCouponsProvider =
    FutureProvider.autoDispose.family<List<CouponOffer>, int>((ref, id) {
  return ref.watch(bookingRepositoryProvider).coupons(id);
});

/// Live price breakdown while the brand fills in the request form.
typedef QuoteRequest = ({int creatorId, double amount, int? packageId});

final bookingQuoteProvider =
    FutureProvider.autoDispose.family<BookingQuote, QuoteRequest>((ref, req) {
  if (req.amount <= 0) return Future.value(const BookingQuote());
  return ref.watch(bookingRepositoryProvider).quote(
        creatorId: req.creatorId,
        amount: req.amount,
        packageId: req.packageId,
      );
});

/// Mutations that need to invalidate booking lists afterwards.
///
/// Screens call these instead of touching the repository directly, so the
/// My Bookings tabs and the detail screen always refresh together.
class BookingActionsController {
  BookingActionsController(this._ref);

  final Ref _ref;

  Future<void> accept(int id) => _run(id, (repo) => repo.accept(id));

  Future<void> reject(int id, {String? reason}) =>
      _run(id, (repo) => repo.reject(id, reason: reason));

  Future<void> counterOffer(int id, {required double amount, String? note}) =>
      _run(id, (repo) => repo.counterOffer(id, amount: amount, note: note));

  Future<void> acceptOffer(int id) => _run(id, (repo) => repo.acceptOffer(id));

  Future<void> deliver(int id, {String? note, List<String> urls = const []}) =>
      _run(id, (repo) => repo.deliver(id, note: note, urls: urls));

  Future<void> complete(int id) => _run(id, (repo) => repo.complete(id));

  Future<void> requestRevision(int id, {required String note}) =>
      _run(id, (repo) => repo.requestRevision(id, note: note));

  Future<void> dispute(int id, {required String reason, String? detail}) =>
      _run(id, (repo) => repo.dispute(id, reason: reason, detail: detail));

  Future<void> cancel(int id, {String? reason}) =>
      _run(id, (repo) => repo.cancel(id, reason: reason));

  Future<void> review(
    int id, {
    required double rating,
    required String comment,
    String? title,
  }) =>
      _run(
        id,
        (repo) => repo.review(
          id,
          rating: rating,
          comment: comment,
          title: title,
        ),
      );

  /// Applying a coupon reprices the booking server-side, so the detail is
  /// invalidated exactly as any other state change would be — the new total
  /// then comes from the server rather than from arithmetic on this side.
  Future<void> applyCoupon(int id, String code) =>
      _run(id, (repo) => repo.applyCoupon(id, code));

  Future<void> removeCoupon(int id) =>
      _run(id, (repo) => repo.removeCoupon(id));

  Future<void> _run(
    int id,
    Future<void> Function(BookingRepository repo) action,
  ) async {
    await action(_ref.read(bookingRepositoryProvider));
    _ref.invalidate(bookingDetailProvider(id));
    for (final tab in BookingTab.values) {
      _ref.invalidate(bookingsProvider(tab));
    }
  }
}

final bookingActionsProvider = Provider<BookingActionsController>((ref) {
  return BookingActionsController(ref);
});
