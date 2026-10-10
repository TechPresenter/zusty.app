import 'package:dio/dio.dart';

/// Every failure the data layer can surface, already translated into copy a
/// user can read.
///
/// Screens never see a [DioException] — the client converts one of these
/// instead. Match on the subtype when the reaction differs:
///
/// ```dart
/// try {
///   await ref.read(bookingRepositoryProvider).accept(id);
/// } on ValidationFailure catch (failure) {
///   setState(() => _fieldErrors = failure.errors);
/// } on ApiFailure catch (failure) {
///   ZSnack.error(context, failure.message);
/// }
/// ```
sealed class ApiFailure implements Exception {
  const ApiFailure({
    required this.message,
    this.statusCode,
    this.code = 'error',
  });

  /// Human-readable, already localised through `AppStrings` where the message
  /// is generated on the client. Server messages are passed through as-is.
  final String message;

  /// HTTP status, when the failure came from a response.
  final int? statusCode;

  /// Machine-readable code from the envelope, e.g. `validation_failed`,
  /// `token_expired`, `handle_taken`, `invalid_status_transition`.
  final String code;

  /// True when retrying the same request could plausibly succeed.
  bool get isRetryable => switch (this) {
        OfflineFailure() => true,
        TimeoutFailure() => true,
        ServerFailure() => true,
        RateLimitFailure() => true,
        MaintenanceFailure() => true,
        _ => false,
      };

  /// Field errors, empty unless this is a [ValidationFailure].
  Map<String, List<String>> get errors => const {};

  /// First message for [field], or null when the server did not flag it.
  String? errorFor(String field) {
    final messages = errors[field];
    return (messages == null || messages.isEmpty) ? null : messages.first;
  }

  @override
  String toString() => '$runtimeType($statusCode, $code): $message';
}

/// The device has no usable connection, or the host could not be reached.
final class OfflineFailure extends ApiFailure {
  const OfflineFailure({
    super.message = 'You are offline. Check your connection and try again.',
    super.code = 'offline',
  });
}

/// The request was sent but the server did not answer in time.
final class TimeoutFailure extends ApiFailure {
  const TimeoutFailure({
    super.message = 'The request took too long. Please try again.',
    super.code = 'timeout',
  });
}

/// 401 — the session is missing, expired beyond refresh, or was revoked.
final class UnauthorisedFailure extends ApiFailure {
  const UnauthorisedFailure({
    super.message = 'Your session has ended. Please sign in again.',
    super.statusCode = 401,
    super.code = 'unauthenticated',
  });
}

/// 403 — authenticated, but not allowed to do this.
final class ForbiddenFailure extends ApiFailure {
  const ForbiddenFailure({
    super.message = 'You do not have permission to do that.',
    super.statusCode = 403,
    super.code = 'forbidden',
  });
}

/// 404 — the resource is gone or never existed.
final class NotFoundFailure extends ApiFailure {
  const NotFoundFailure({
    super.message = 'We could not find what you were looking for.',
    super.statusCode = 404,
    super.code = 'not_found',
  });
}

/// 409 — the request conflicts with the current state, e.g. an illegal
/// booking status transition or a duplicate review.
final class ConflictFailure extends ApiFailure {
  const ConflictFailure({
    required super.message,
    super.statusCode = 409,
    super.code = 'conflict',
  });
}

/// 422 — the payload failed validation. [errors] is keyed by field name.
final class ValidationFailure extends ApiFailure {
  const ValidationFailure({
    required super.message,
    Map<String, List<String>> errors = const {},
    super.statusCode = 422,
    super.code = 'validation_failed',
  }) : _errors = errors;

  final Map<String, List<String>> _errors;

  @override
  Map<String, List<String>> get errors => _errors;
}

/// 429 — throttled. [retryAfter] mirrors the `Retry-After` header when sent.
final class RateLimitFailure extends ApiFailure {
  const RateLimitFailure({
    super.message = 'Too many attempts. Please wait a moment and try again.',
    this.retryAfter,
    super.statusCode = 429,
    super.code = 'rate_limited',
  });

  final Duration? retryAfter;
}

/// 503 with `code: maintenance` — the platform is deliberately down.
final class MaintenanceFailure extends ApiFailure {
  const MaintenanceFailure({
    super.message = 'Zusty is briefly down for maintenance. Try again soon.',
    super.statusCode = 503,
    super.code = 'maintenance',
  });
}

/// 5xx — the backend broke.
final class ServerFailure extends ApiFailure {
  const ServerFailure({
    super.message = 'Something went wrong on our side. Please try again.',
    super.statusCode,
    super.code = 'server_error',
  });
}

/// Anything else, including malformed responses.
final class UnknownFailure extends ApiFailure {
  const UnknownFailure({
    super.message = 'Something went wrong. Please try again.',
    super.statusCode,
    super.code = 'unknown',
  });
}

/// Translates transport and envelope errors into an [ApiFailure].
abstract final class ApiFailureMapper {
  /// Maps a [DioException] that never produced a usable envelope.
  static ApiFailure fromDio(DioException error) => switch (error.type) {
        DioExceptionType.connectionTimeout ||
        DioExceptionType.sendTimeout ||
        DioExceptionType.receiveTimeout ||
        DioExceptionType.transformTimeout =>
          const TimeoutFailure(),
        DioExceptionType.connectionError ||
        DioExceptionType.badCertificate =>
          const OfflineFailure(),
        DioExceptionType.cancel => const UnknownFailure(
            message: 'The request was cancelled.',
            code: 'cancelled',
          ),
        DioExceptionType.badResponse ||
        DioExceptionType.unknown =>
          const UnknownFailure(),
      };

  /// Maps a parsed `{success: false, ...}` envelope.
  static ApiFailure fromEnvelope({
    required int statusCode,
    required String message,
    required String code,
    Map<String, List<String>> errors = const {},
    Duration? retryAfter,
  }) {
    final safeMessage = message.trim().isEmpty
        ? 'Something went wrong. Please try again.'
        : message.trim();

    // Only the platform's own maintenance answer. Another 503, such as a
    // verification code the mail or SMS provider could not deliver, falls
    // through to ServerFailure below, keeping its code and staying retryable.
    if (code == 'maintenance') {
      return MaintenanceFailure(message: safeMessage);
    }

    return switch (statusCode) {
      401 => UnauthorisedFailure(message: safeMessage, code: code),
      403 => ForbiddenFailure(message: safeMessage, code: code),
      404 => NotFoundFailure(message: safeMessage, code: code),
      409 => ConflictFailure(message: safeMessage, code: code),
      422 => ValidationFailure(
          message: safeMessage,
          errors: errors,
          code: code,
        ),
      429 => RateLimitFailure(
          message: safeMessage,
          retryAfter: retryAfter,
          code: code,
        ),
      >= 500 => ServerFailure(
          message: safeMessage,
          statusCode: statusCode,
          code: code,
        ),
      _ => UnknownFailure(
          message: safeMessage,
          statusCode: statusCode,
          code: code,
        ),
    };
  }

  /// Last-resort wrapper so a repository can never leak a raw error.
  static ApiFailure fromObject(Object error) => switch (error) {
        ApiFailure() => error,
        DioException() => fromDio(error),
        _ => const UnknownFailure(),
      };
}
