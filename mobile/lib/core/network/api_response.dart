/// Typed view over the backend's success envelope.
///
/// Every endpoint answers with:
/// ```json
/// { "success": true, "message": "...", "data": {...}, "meta": {...} }
/// ```
/// `data` is an object on most endpoints and a bare list on the ones built by
/// `Response::paginated`, so both shapes are exposed here.
class ApiResponse {
  const ApiResponse({
    required this.success,
    required this.message,
    this.data,
    this.meta = const {},
    this.statusCode = 200,
    this.fromCache = false,
  });

  factory ApiResponse.fromJson(
    Map<String, dynamic> json, {
    int statusCode = 200,
    bool fromCache = false,
  }) =>
      ApiResponse(
        success: json['success'] == true,
        message: (json['message'] ?? '').toString(),
        data: json['data'],
        meta: _asMap(json['meta']),
        statusCode: statusCode,
        fromCache: fromCache,
      );

  final bool success;
  final String message;
  final dynamic data;
  final Map<String, dynamic> meta;

  /// HTTP status the response arrived with. Mostly irrelevant — the envelope
  /// carries the outcome — but conditional GETs need to spot a 304.
  final int statusCode;

  /// True when the server was unreachable and this is the last good answer
  /// replayed from the device. The data is real, just possibly stale — a
  /// screen can use it and optionally say so.
  final bool fromCache;

  /// True when a conditional request confirmed the caller's cache is current.
  bool get isNotModified => statusCode == 304;

  /// `data` as an object. Empty when the endpoint sent a list or nothing.
  Map<String, dynamic> get dataMap => _asMap(data);

  /// `data` as a list of objects — what `Response::paginated` produces.
  List<Map<String, dynamic>> get dataList => _asList(data);

  /// A named list inside `data`, e.g. `creators`, `conversations`, `days`.
  List<Map<String, dynamic>> listAt(String key) => _asList(dataMap[key]);

  /// A named object inside `data`, e.g. `user`, `creator`, `booking`.
  Map<String, dynamic> mapAt(String key) => _asMap(dataMap[key]);

  /// A named object inside `data`, or null when the key is absent/null.
  Map<String, dynamic>? maybeMapAt(String key) {
    final value = dataMap[key];
    return value is Map ? _asMap(value) : null;
  }

  /// The `meta.pagination` block, or a single-page fallback when absent.
  Pagination get pagination {
    final raw = meta['pagination'];
    return raw is Map
        ? Pagination.fromJson(_asMap(raw))
        : Pagination.singlePage(dataList.length);
  }

  static Map<String, dynamic> _asMap(dynamic value) =>
      value is Map ? Map<String, dynamic>.from(value) : const {};

  static List<Map<String, dynamic>> _asList(dynamic value) => value is List
      ? value.whereType<Map>().map(_asMap).toList(growable: false)
      : const [];
}

/// The `meta.pagination` block emitted by `Response::paginated`.
class Pagination {
  const Pagination({
    required this.total,
    required this.perPage,
    required this.currentPage,
    required this.lastPage,
    required this.hasMore,
  });

  factory Pagination.fromJson(Map<String, dynamic> json) => Pagination(
        total: _int(json['total']),
        perPage: _int(json['per_page'], fallback: 20),
        currentPage: _int(json['current_page'], fallback: 1),
        lastPage: _int(json['last_page'], fallback: 1),
        hasMore: json['has_more'] == true,
      );

  /// Describes an endpoint that returned everything in one shot.
  factory Pagination.singlePage(int total) => Pagination(
        total: total,
        perPage: total == 0 ? 1 : total,
        currentPage: 1,
        lastPage: 1,
        hasMore: false,
      );

  static const empty = Pagination(
    total: 0,
    perPage: 20,
    currentPage: 1,
    lastPage: 1,
    hasMore: false,
  );

  final int total;
  final int perPage;
  final int currentPage;
  final int lastPage;
  final bool hasMore;

  int get nextPage => currentPage + 1;

  static int _int(dynamic value, {int fallback = 0}) {
    if (value is int) return value;
    if (value is num) return value.toInt();
    return int.tryParse('$value') ?? fallback;
  }
}

/// One page of results plus the cursor needed to fetch the next.
///
/// Repositories return this for every list endpoint; screens keep the latest
/// [Paginated] in state and call [merge] when the user scrolls further.
class Paginated<T> {
  const Paginated({required this.items, required this.pagination});

  const Paginated.empty()
      : items = const [],
        pagination = Pagination.empty;

  final List<T> items;
  final Pagination pagination;

  bool get hasMore => pagination.hasMore;
  bool get isEmpty => items.isEmpty;
  bool get isNotEmpty => items.isNotEmpty;
  int get total => pagination.total;
  int get nextPage => pagination.nextPage;

  /// Appends the next page, keeping that page's cursor.
  Paginated<T> merge(Paginated<T> next) => Paginated(
        items: [...items, ...next.items],
        pagination: next.pagination,
      );

  Paginated<R> map<R>(R Function(T item) transform) => Paginated(
        items: items.map(transform).toList(growable: false),
        pagination: pagination,
      );

  /// Replaces matching items in place — used after a favourite/mute toggle so
  /// the list does not have to be refetched.
  Paginated<T> replaceWhere(bool Function(T item) test, T Function(T item) update) =>
      Paginated(
        items: items.map((item) => test(item) ? update(item) : item).toList(growable: false),
        pagination: pagination,
      );

  Paginated<T> removeWhere(bool Function(T item) test) => Paginated(
        items: items.where((item) => !test(item)).toList(growable: false),
        pagination: pagination,
      );
}
