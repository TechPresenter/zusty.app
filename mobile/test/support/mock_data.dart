import 'package:zuste/core/utils/placeholder_image.dart';

/// In-memory fixtures mirroring the API envelope's `data` blocks.
///
/// Test-only, and deliberately so: this file lives under `test/` and is never
/// compiled into the app, so no fabricated content can ship in a release
/// build. `registerTestFixtures()` in `fixtures.dart` hands these to
/// `BaseRepository.fixtures`, which is the only way a repository can reach
/// them.
abstract final class MockData {
  /// Deterministic placeholder imagery — seeded so a given creator always gets
  /// the same portrait everywhere they appear.
  static String photo(String seed, [int w = 600, int h = 750]) =>
      placeholderPhoto(seed, w, h);

  /// Simulated network latency so loading states get exercised in demo mode.
  static Future<T> delay<T>(T value, [int ms = 350]) =>
      Future.delayed(Duration(milliseconds: ms), () => value);

  // ------------------------------------------------------------------
  // Account
  // ------------------------------------------------------------------

  static Map<String, dynamic> get currentUser => {
        'id': 1,
        'name': 'Priya Sharma',
        'company_name': 'Glow Skincare Co.',
        'role': 'brand',
        'email': 'priya@glowskincare.in',
        'phone': '9876543210',
        'phone_country_code': '+91',
        'avatar_url': photo('priya-brand', 200, 200),
        'city': 'Mumbai',
        'country': 'India',
        'bio': 'Marketing Manager building creator-led campaigns for D2C beauty.',
        'is_creator': false,
        'creator_id': null,
        'email_verified': true,
        'phone_verified': true,
        'kyc_status': 'approved',
        'referral_code': 'PRIYA500',
        'wallet_balance': 48250.00,
        'unread_notifications': 4,
        'unread_messages': 3,
        'created_at': '2026-02-14 10:12:00',
        'notification_prefs': {
          'push': true,
          'email': true,
          'sms': false,
          'marketing': true,
        },
      };

  static Map<String, dynamic> get tokens => {
        'access_token': 'mock.access.token',
        'refresh_token': 'mock.refresh.token',
      };

  // ------------------------------------------------------------------
  // Categories
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get categories => [
        _category(1, 'Lifestyle', 'lifestyle', 'home', '#B80049', 1240),
        _category(2, 'Fashion', 'fashion', 'checkroom', '#632CE5', 986),
        _category(3, 'Travel', 'travel', 'flight', '#0284C7', 742),
        _category(4, 'Food', 'food', 'restaurant', '#EA580C', 655),
        _category(5, 'Fitness', 'fitness', 'fitness_center', '#059669', 531),
        _category(6, 'Tech', 'tech', 'devices', '#4338CA', 489),
        _category(7, 'Beauty', 'beauty', 'spa', '#DB2777', 803),
        _category(8, 'Gaming', 'gaming', 'sports_esports', '#7C3AED', 412),
        _category(9, 'Music', 'music', 'music_note', '#DC2626', 298),
        _category(10, 'Education', 'education', 'school', '#0891B2', 265),
        _category(11, 'Photography', 'photography', 'photo_camera', '#475569', 331),
        _category(12, 'Finance', 'finance', 'attach_money', '#166534', 187),
      ];

  static Map<String, dynamic> _category(
    int id,
    String name,
    String slug,
    String icon,
    String color,
    int count,
  ) =>
      {
        'id': id,
        'name': name,
        'slug': slug,
        'icon': icon,
        'color_hex': color,
        'creator_count': count,
        'cover_image': photo('cat-$slug', 400, 400),
        'description': 'Top $name creators ready to collaborate.',
      };

  // ------------------------------------------------------------------
  // Creators
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get creators => [
        _creator(
          id: 1,
          handle: 'ananyasharma',
          name: 'Ananya Sharma',
          headline: 'Lifestyle Blogger',
          category: 'Lifestyle',
          slug: 'lifestyle',
          city: 'Mumbai',
          rating: 4.8,
          ratingCount: 120,
          followers: 56000,
          posts: 325,
          collabs: 120,
          min: 15000,
          max: 50000,
          verified: true,
          featured: true,
        ),
        _creator(
          id: 2,
          handle: 'rohitverma',
          name: 'Rohit Verma',
          headline: 'Travel Creator',
          category: 'Travel',
          slug: 'travel',
          city: 'Manali',
          rating: 4.9,
          ratingCount: 214,
          followers: 128000,
          posts: 512,
          collabs: 186,
          min: 25000,
          max: 90000,
          verified: true,
          featured: true,
        ),
        _creator(
          id: 3,
          handle: 'nehasingh',
          name: 'Neha Singh',
          headline: 'Fashion Influencer',
          category: 'Fashion',
          slug: 'fashion',
          city: 'Delhi',
          rating: 4.7,
          ratingCount: 98,
          followers: 84000,
          posts: 407,
          collabs: 94,
          min: 20000,
          max: 65000,
          verified: true,
        ),
        _creator(
          id: 4,
          handle: 'karanmehta',
          name: 'Karan Mehta',
          headline: 'Tech Enthusiast',
          category: 'Tech',
          slug: 'tech',
          city: 'Bengaluru',
          rating: 4.6,
          ratingCount: 176,
          followers: 210000,
          posts: 289,
          collabs: 142,
          min: 35000,
          max: 120000,
          verified: true,
        ),
        _creator(
          id: 5,
          handle: 'ishitakapoor',
          name: 'Ishita Kapoor',
          headline: 'Beauty & Skincare',
          category: 'Beauty',
          slug: 'beauty',
          city: 'Mumbai',
          rating: 4.9,
          ratingCount: 342,
          followers: 96500,
          posts: 618,
          collabs: 221,
          min: 18000,
          max: 72000,
          verified: true,
          featured: true,
        ),
        _creator(
          id: 6,
          handle: 'devanshrao',
          name: 'Devansh Rao',
          headline: 'Fitness Coach',
          category: 'Fitness',
          slug: 'fitness',
          city: 'Pune',
          rating: 4.5,
          ratingCount: 87,
          followers: 42000,
          posts: 233,
          collabs: 61,
          min: 12000,
          max: 40000,
        ),
        _creator(
          id: 7,
          handle: 'meerajoshi',
          name: 'Meera Joshi',
          headline: 'Food Blogger',
          category: 'Food',
          slug: 'food',
          city: 'Jaipur',
          rating: 4.8,
          ratingCount: 156,
          followers: 71000,
          posts: 494,
          collabs: 118,
          min: 16000,
          max: 55000,
          verified: true,
        ),
        _creator(
          id: 8,
          handle: 'arjunnair',
          name: 'Arjun Nair',
          headline: 'Lifestyle & Fitness',
          category: 'Lifestyle',
          slug: 'lifestyle',
          city: 'Kochi',
          rating: 4.4,
          ratingCount: 63,
          followers: 40000,
          posts: 198,
          collabs: 47,
          min: 10000,
          max: 32000,
        ),
        _creator(
          id: 9,
          handle: 'sanyagupta',
          name: 'Sanya Gupta',
          headline: 'Fashion Stylist',
          category: 'Fashion',
          slug: 'fashion',
          city: 'Hyderabad',
          rating: 4.7,
          ratingCount: 129,
          followers: 63500,
          posts: 356,
          collabs: 88,
          min: 17000,
          max: 58000,
          verified: true,
        ),
        _creator(
          id: 10,
          handle: 'vikrantsingh',
          name: 'Vikrant Singh',
          headline: 'Gaming Streamer',
          category: 'Gaming',
          slug: 'gaming',
          city: 'Chandigarh',
          rating: 4.6,
          ratingCount: 201,
          followers: 154000,
          posts: 731,
          collabs: 96,
          min: 22000,
          max: 80000,
        ),
        _creator(
          id: 11,
          handle: 'tanvishah',
          name: 'Tanvi Shah',
          headline: 'Travel Photographer',
          category: 'Photography',
          slug: 'photography',
          city: 'Goa',
          rating: 4.9,
          ratingCount: 112,
          followers: 58000,
          posts: 402,
          collabs: 73,
          min: 19000,
          max: 68000,
          verified: true,
        ),
        _creator(
          id: 12,
          handle: 'rahulkhanna',
          name: 'Rahul Khanna',
          headline: 'Finance Educator',
          category: 'Finance',
          slug: 'finance',
          city: 'Mumbai',
          rating: 4.8,
          ratingCount: 264,
          followers: 302000,
          posts: 188,
          collabs: 134,
          min: 45000,
          max: 175000,
          verified: true,
          featured: true,
        ),
      ];

  static Map<String, dynamic> _creator({
    required int id,
    required String handle,
    required String name,
    required String headline,
    required String category,
    required String slug,
    required String city,
    required double rating,
    required int ratingCount,
    required int followers,
    required int posts,
    required int collabs,
    required int min,
    required int max,
    bool verified = false,
    bool featured = false,
    String? gender,
    String? ageGroup,
    String? experience,
    String? responseTime,
    double? engagement,
    List<String>? languages,
  }) =>
      {
        'id': id,
        'user_id': 100 + id,
        // Deterministic spread across every facet so the advanced filters have
        // something meaningful to narrow in demo mode.
        'gender': gender ?? _cycle(id, _genders),
        'age_group': ageGroup ?? _cycle(id, _ageGroups),
        'experience': experience ?? _cycle(id, _experiences),
        'response_time': responseTime ?? _cycle(id, _responseTimes),
        'engagement_rate': engagement ?? (2.4 + (id % 7) * 0.9),
        'languages': languages ?? _languagesFor(id),
        'handle': handle,
        'display_name': name,
        'headline': headline,
        'avatar_url': photo(handle, 400, 500),
        'city': city,
        'location': '$city, India',
        'category': category,
        'category_slug': slug,
        'rating': rating,
        'rating_count': ratingCount,
        'followers': followers,
        'followers_label': _compact(followers),
        'posts_count': posts,
        'collabs_count': collabs,
        'price_min': min,
        'price_max': max,
        'price_label': '${_money(min)} – ${_money(max)}',
        'currency': 'INR',
        'is_verified': verified,
        'is_featured': featured,
        'availability': id % 5 == 0 ? 'busy' : 'available',
        'is_favorite': id == 1 || id == 5 || id == 11,
        'is_following': id == 2,
      };

  /// Full profile payload for `GET /creators/{handle}`.
  static Map<String, dynamic> creatorProfile(String handle) {
    final base = creators.firstWhere(
      (c) => c['handle'] == handle || '${c['id']}' == handle,
      orElse: () => creators.first,
    );

    return {
      ...base,
      'bio':
          'Lifestyle content creator sharing fashion, travel, and daily life moments. '
              'Passionate about creating authentic brand stories that resonate with a young, '
              'dynamic audience.',
      'cover_url': photo('cover-${base['handle']}', 900, 500),
      'languages': ['English', 'Hindi', 'Marathi'],
      'is_online': true,
      'is_owner': false,
      'response_time': 'Usually replies within 2 hours',
      'joined_at': '2024-06-11 09:00:00',
      'categories': categories
          .where((c) =>
              c['slug'] == base['category_slug'] ||
              c['slug'] == 'fashion' ||
              c['slug'] == 'travel')
          .take(3)
          .toList(),
      'social_accounts': [
        {
          'platform': 'instagram',
          'handle': '@${base['handle']}',
          'profile_url': 'https://instagram.com/${base['handle']}',
          'followers': base['followers'],
          'engagement_rate': 4.8,
          'avg_views': 32000,
          'is_verified': true,
        },
        {
          'platform': 'youtube',
          'handle': base['display_name'],
          'profile_url': 'https://youtube.com/@${base['handle']}',
          'followers': ((base['followers'] as int) * 0.4).round(),
          'engagement_rate': 6.2,
          'avg_views': 18500,
          'is_verified': false,
        },
      ],
      'packages': [
        {
          'id': 1,
          'title': 'Instagram Reel',
          'description': 'One 30–60s reel with a story cross-post.',
          'platform': 'instagram',
          'deliverable_type': 'reel',
          'quantity': 1,
          'price': base['price_min'],
          'price_formatted': _money(base['price_min'] as int),
          'currency': 'INR',
          'turnaround_days': 5,
          'revisions': 2,
          'includes': ['1 Reel', '2 Story frames', '1 revision round', 'Usage rights 30 days'],
        },
        {
          'id': 2,
          'title': 'Feed Post + Stories',
          'description': 'A static or carousel feed post plus three stories.',
          'platform': 'instagram',
          'deliverable_type': 'post',
          'quantity': 1,
          'price': ((base['price_min'] as int) * 1.6).round(),
          'price_formatted': _money(((base['price_min'] as int) * 1.6).round()),
          'currency': 'INR',
          'turnaround_days': 4,
          'revisions': 1,
          'includes': ['1 Feed post', '3 Story frames', 'Analytics report'],
        },
        {
          'id': 3,
          'title': 'Full Campaign Bundle',
          'description': 'Reel, feed post, and a week of story coverage.',
          'platform': 'instagram',
          'deliverable_type': 'bundle',
          'quantity': 3,
          'price': base['price_max'],
          'price_formatted': _money(base['price_max'] as int),
          'currency': 'INR',
          'turnaround_days': 10,
          'revisions': 3,
          'includes': [
            '1 Reel',
            '1 Feed post',
            '7 Story frames',
            'Exclusive category rights 30 days',
            'Full analytics report',
          ],
        },
      ],
      'portfolio_counts': {'post': 18, 'reel': 12, 'photo': 24, 'video': 9},
      'recent_portfolio': List.generate(
        12,
        (i) => _portfolioItem(i + 1, '${base['handle']}-p$i',
            i.isEven ? 'photo' : 'reel'),
      ),
      'reviews': reviews,
      'rating_breakdown': {'5': 86, '4': 24, '3': 7, '2': 2, '1': 1},
    };
  }

  static Map<String, dynamic> _portfolioItem(int id, String seed, String kind) =>
      {
        'id': id,
        'media_kind': kind,
        'title': kind == 'reel' ? 'Behind the scenes' : 'Campaign shot',
        'caption': 'Shot on location — brand collaboration highlights.',
        'media_url': photo(seed, 800, 1000),
        'thumbnail_url': photo(seed, 400, 500),
        'source_platform': 'instagram',
        'aspect_ratio': kind == 'reel' ? 0.5625 : 0.8,
        'duration_secs': kind == 'reel' ? 42 : 0,
        'views_count': 12000 + id * 1730,
        'likes_count': 800 + id * 210,
        'comments_count': 40 + id * 9,
        'published_at': '2026-07-${(id % 28) + 1} 12:00:00',
      };

  static List<Map<String, dynamic>> portfolio(String kind) => List.generate(
        18,
        (i) => _portfolioItem(i + 1, '$kind-item-$i', kind),
      );

  static List<Map<String, dynamic>> get reviews => [
        {
          'id': 1,
          'rating': 5,
          'title': 'Outstanding collaboration',
          'comment':
              'Delivered ahead of schedule and the content quality exceeded our brief. '
                  'Engagement was 2x our benchmark.',
          'reviewer_name': 'Glow Skincare Co.',
          'reviewer_avatar': photo('brand-glow', 200, 200),
          'booking_code': 'ZB-2026-0142',
          'created_at': '2026-07-18 14:20:00',
          'reply': 'Thank you so much — loved working on this campaign!',
          'replied_at': '2026-07-19 09:00:00',
        },
        {
          'id': 2,
          'rating': 5,
          'title': 'Highly professional',
          'comment':
              'Clear communication throughout and handled the revision round without fuss.',
          'reviewer_name': 'Urban Threads',
          'reviewer_avatar': photo('brand-urban', 200, 200),
          'booking_code': 'ZB-2026-0119',
          'created_at': '2026-06-29 11:05:00',
        },
        {
          'id': 3,
          'rating': 4,
          'title': 'Great content, minor delay',
          'comment':
              'Content was excellent. Delivery slipped by a day but they kept us informed.',
          'reviewer_name': 'FitFuel India',
          'reviewer_avatar': photo('brand-fitfuel', 200, 200),
          'booking_code': 'ZB-2026-0087',
          'created_at': '2026-06-02 16:45:00',
        },
      ];

  // ------------------------------------------------------------------
  // Home
  // ------------------------------------------------------------------

  static Map<String, dynamic> get home => {
        'banners': [
          {
            'id': 1,
            'title': 'Find the Right Creator\nfor Your Next Campaign',
            'subtitle': 'Verified creators. Escrow-protected payments.',
            'image_url': photo('hero-campaign', 900, 500),
            'cta_label': 'Explore Now',
            'action_url': '/explore',
          },
          {
            'id': 2,
            'title': 'Book 15 creators\nin under 2 weeks',
            'subtitle': 'Structured briefs, one dashboard, zero spreadsheets.',
            'image_url': photo('hero-seeding', 900, 500),
            'cta_label': 'Start a campaign',
            'action_url': '/search',
          },
        ],
        'categories': categories.take(8).toList(),
        'top_creators': creators.take(4).toList(),
        'trending': creators.skip(4).take(6).toList(),
        'new_creators': creators.reversed.take(6).toList(),
        'active_bookings': bookings.take(2).toList(),
        'badges': {'unread_notifications': 4, 'unread_messages': 3},
      };

  static Map<String, dynamic> get explore => {
        'top_creators': creators.take(8).toList(),
        'trending': creators.reversed.take(8).toList(),
        'categories': categories,
      };

  static List<String> get searchSuggestions => [
        'Lifestyle creators in Mumbai',
        'Fashion influencers under ₹25,000',
        'Travel creators with 100K+ followers',
        'Verified beauty creators',
        'Tech reviewers on YouTube',
      ];

  static List<String> get recentSearches => [
        'Skincare creators',
        'Ananya Sharma',
        'Fitness Mumbai',
        'Reels under 20k',
      ];

  // ------------------------------------------------------------------
  // Bookings
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get bookings => [
        _booking(
          id: 1,
          code: 'ZB-2026-0187',
          title: 'Summer Glow Product Launch',
          status: 'in_progress',
          creatorIndex: 0,
          amount: 32500,
          date: '28 Jul 2026',
          due: '2026-08-08 18:00:00',
        ),
        _booking(
          id: 2,
          code: 'ZB-2026-0184',
          title: 'Monsoon Travel Series',
          status: 'pending',
          creatorIndex: 1,
          amount: 68000,
          date: '26 Jul 2026',
          due: '2026-08-15 18:00:00',
        ),
        _booking(
          id: 3,
          code: 'ZB-2026-0176',
          title: 'Festive Collection Reel',
          status: 'confirmed',
          creatorIndex: 2,
          amount: 45000,
          date: '21 Jul 2026',
          due: '2026-08-05 18:00:00',
        ),
        _booking(
          id: 4,
          code: 'ZB-2026-0165',
          title: 'Smartphone Unboxing Review',
          status: 'delivered',
          creatorIndex: 3,
          amount: 95000,
          date: '14 Jul 2026',
          due: '2026-07-30 18:00:00',
        ),
        _booking(
          id: 5,
          code: 'ZB-2026-0142',
          title: 'Skincare Routine Collaboration',
          status: 'completed',
          creatorIndex: 4,
          amount: 28000,
          date: '02 Jul 2026',
          due: '2026-07-16 18:00:00',
        ),
        _booking(
          id: 6,
          code: 'ZB-2026-0131',
          title: 'Gym Wear Campaign',
          status: 'countered',
          creatorIndex: 5,
          amount: 24000,
          date: '27 Jun 2026',
          due: '2026-07-12 18:00:00',
        ),
        _booking(
          id: 7,
          code: 'ZB-2026-0119',
          title: 'Recipe Series — Regional Flavours',
          status: 'completed',
          creatorIndex: 6,
          amount: 38000,
          date: '18 Jun 2026',
          due: '2026-07-02 18:00:00',
        ),
        _booking(
          id: 8,
          code: 'ZB-2026-0104',
          title: 'Athleisure Lookbook',
          status: 'cancelled',
          creatorIndex: 8,
          amount: 41000,
          date: '05 Jun 2026',
          due: '2026-06-20 18:00:00',
        ),
      ];

  static Map<String, dynamic> _booking({
    required int id,
    required String code,
    required String title,
    required String status,
    required int creatorIndex,
    required int amount,
    required String date,
    required String due,
  }) {
    final creator = creators[creatorIndex];
    return {
      'id': id,
      'code': code,
      'title': title,
      'campaign_type': 'Sponsored content',
      'platform': 'instagram',
      'posts_count': 3,
      'counterparty': {
        'name': creator['display_name'],
        'avatar_url': creator['avatar_url'],
        'handle': creator['handle'],
        'is_verified': creator['is_verified'],
      },
      'creator_id': creator['id'],
      'creator_handle': creator['handle'],
      'conversation_id': creatorIndex + 1,
      'amount': amount,
      'amount_label': _money(amount),
      'currency': 'INR',
      'status': status,
      'status_label': _statusLabel(status),
      'status_tone': _statusTone(status),
      'payment_status': status == 'pending' || status == 'countered'
          ? 'unpaid'
          : (status == 'completed' ? 'released' : 'escrow'),
      'requested_at': '2026-07-20 10:00:00',
      'delivery_due_at': due,
      'date_label': date,
      'can_review': status == 'completed' && id != 5,
    };
  }

  /// Detail payload for `GET /bookings/{id}`.
  /// Offers as `GET /bookings/{id}/coupons` returns them: already priced
  /// against the booking, best saving first, ineligible codes last and
  /// carrying the reason rather than being hidden.
  static const List<Map<String, dynamic>> couponOffers = [
    {
      'id': 1,
      'code': 'FESTIVE25',
      'name': 'Festive season',
      'discount_label': '25% off',
      'saving_label': 'You save ₹2,500.00',
      'discount': 2500.0,
      'eligible': true,
      'is_applied': false,
      'reason': null,
    },
    {
      'id': 2,
      'code': 'WELCOME200',
      'name': 'Welcome offer',
      'discount_label': '₹200.00 off',
      'saving_label': 'You save ₹200.00',
      'discount': 200.0,
      'eligible': true,
      'is_applied': false,
      'reason': null,
    },
    {
      'id': 3,
      'code': 'SCALE20',
      'name': 'Scale-up discount',
      'discount_label': '20% off',
      'saving_label': null,
      'discount': 0.0,
      'eligible': false,
      'is_applied': false,
      'reason': 'This coupon needs a booking of at least ₹50,000.00.',
    },
  ];

  static Map<String, dynamic> bookingDetail(int id) {
    final base = bookings.firstWhere(
      (b) => b['id'] == id,
      orElse: () => bookings.first,
    );
    final status = base['status'] as String;
    final amount = (base['amount'] as num).toDouble();
    final fee = amount * 0.15;
    final tax = amount * 0.18;

    return {
      ...base,
      'viewer_role': 'brand',
      'brief':
          'We are launching our new Vitamin C serum and need authentic, daylight-lit '
              'content that shows the product in a real morning routine. Please highlight '
              'the texture, the pump applicator, and the "glass skin" finish.\n\n'
              'Tone: warm, conversational, no hard sell. Please tag @glowskincare and use '
              '#GlowFromWithin.',
      'platform_fee': fee,
      'tax_amount': tax,
      'total_amount': amount + tax,
      'creator_earning': amount - fee,
      'start_date': '2026-08-01 00:00:00',
      'end_date': '2026-08-14 00:00:00',
      'formatted': {
        'amount': _money(amount.round()),
        'platform_fee': _money(fee.round()),
        'tax': _money(tax.round()),
        'total': _money((amount + tax).round()),
        'earning': _money((amount - fee).round()),
      },
      'actions': {
        'can_accept': false,
        'can_reject': false,
        'can_counter': status == 'pending' || status == 'countered',
        'can_accept_offer': status == 'countered',
        'can_pay': status == 'accepted' || status == 'awaiting_payment',
        'can_deliver': false,
        'can_complete': status == 'delivered',
        'can_cancel': status != 'completed' &&
            status != 'cancelled' &&
            status != 'rejected',
        'can_review': status == 'completed',
        'can_message': true,
        'can_view_invoice': status == 'completed',
      },
      'invoice': status == 'completed'
          ? {'url': 'https://zuste.com/invoices/${base['code']}.pdf'}
          : null,
      'deliverables': [
        {
          'id': 1,
          'type': 'reel',
          'platform': 'instagram',
          'quantity': 1,
          'description': '30–60s reel featuring the morning routine',
          'status': status == 'delivered' || status == 'completed'
              ? 'submitted'
              : 'pending',
          'live_url': status == 'completed'
              ? 'https://instagram.com/reel/abc123'
              : null,
          'metrics': status == 'completed'
              ? {'views': 84200, 'likes': 6120, 'comments': 318}
              : <String, dynamic>{},
          'submitted_at': status == 'completed' ? '2026-07-14 11:00:00' : null,
        },
        {
          'id': 2,
          'type': 'post',
          'platform': 'instagram',
          'quantity': 1,
          'description': 'Carousel feed post — 3 frames',
          'status': status == 'completed' ? 'submitted' : 'pending',
          'metrics': <String, dynamic>{},
        },
        {
          'id': 3,
          'type': 'story',
          'platform': 'instagram',
          'quantity': 3,
          'description': '3 story frames with product link sticker',
          'status': 'pending',
          'metrics': <String, dynamic>{},
        },
      ],
      'timeline': [
        {
          'to_status': 'pending',
          'note': 'Campaign request sent to creator.',
          'actor_name': 'Priya Sharma',
          'created_at': '2026-07-20 10:00:00',
        },
        {
          'to_status': 'accepted',
          'note': 'Creator accepted the brief and budget.',
          'actor_name': 'Ananya Sharma',
          'created_at': '2026-07-21 09:15:00',
        },
        {
          'to_status': 'confirmed',
          'note': 'Payment of ${_money((amount + tax).round())} moved to escrow.',
          'actor_name': 'Zusty',
          'created_at': '2026-07-21 11:40:00',
        },
        {
          'to_status': 'in_progress',
          'note': 'Creator started production.',
          'actor_name': 'Ananya Sharma',
          'created_at': '2026-07-23 08:00:00',
        },
      ],
      'offers': status == 'countered'
          ? [
              {
                'id': 1,
                'amount': 24000,
                'amount_label': _money(24000),
                'by_role': 'brand',
                'note': 'Our budget for this deliverable set.',
                'status': 'superseded',
                'created_at': '2026-06-27 10:00:00',
              },
              {
                'id': 2,
                'amount': 29500,
                'amount_label': _money(29500),
                'by_role': 'creator',
                'note': 'Happy to do it at this rate including one revision.',
                'status': 'pending',
                'created_at': '2026-06-28 15:30:00',
              },
            ]
          : <Map<String, dynamic>>[],
    };
  }

  // -- Facet spreads for the advanced filters ------------------------------

  static const _genders = ['female', 'male', 'female', 'non_binary'];
  static const _ageGroups = ['25-34', '18-24', '35-44', '25-34', '45+'];
  static const _experiences = [
    'experienced',
    'intermediate',
    'expert',
    'new',
    'experienced',
  ];
  static const _responseTimes = [
    'within_1h',
    'within_6h',
    'within_24h',
    'within_6h',
    'within_48h',
  ];

  static const _languagePool = [
    ['English', 'Hindi'],
    ['English', 'Hindi', 'Marathi'],
    ['English', 'Tamil'],
    ['English', 'Hindi', 'Punjabi'],
    ['English', 'Telugu', 'Hindi'],
    ['English', 'Bengali'],
    ['English', 'Hindi', 'Gujarati'],
    ['English', 'Kannada'],
    ['English', 'Malayalam', 'Hindi'],
  ];

  static String _cycle(int id, List<String> options) =>
      options[id % options.length];

  static List<String> _languagesFor(int id) =>
      _languagePool[id % _languagePool.length];

  // ------------------------------------------------------------------
  // Brand campaigns
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get campaigns => [
        _campaign(
          id: 1,
          code: 'ZC100045',
          title: 'Festive Glow — Diwali Collection',
          status: 'open',
          category: 'Beauty',
          platform: 'instagram',
          deliverable: 'reel',
          posts: 2,
          min: 25000,
          max: 60000,
          applications: 14,
          hired: 3,
          isPublic: true,
          requirements: {
            'min_followers': 50000,
            'cities': ['Mumbai', 'Delhi', 'Bengaluru'],
            'gender': 'any',
            'min_rating': 4.5,
          },
        ),
        _campaign(
          id: 2,
          code: 'ZC100038',
          title: 'Monsoon Travel Series',
          status: 'in_progress',
          category: 'Travel',
          platform: 'youtube',
          deliverable: 'video',
          posts: 1,
          min: 60000,
          max: 120000,
          applications: 9,
          hired: 2,
          isPublic: true,
          requirements: {
            'min_followers': 100000,
            'cities': ['Manali', 'Goa'],
            'min_rating': 4.0,
          },
        ),
        _campaign(
          id: 3,
          code: 'ZC100031',
          title: 'Everyday Skincare Routine — UGC',
          status: 'draft',
          category: 'Beauty',
          platform: 'instagram',
          deliverable: 'ugc',
          posts: 3,
          min: 8000,
          max: 20000,
          applications: 0,
          hired: 0,
          isPublic: false,
          requirements: {'min_followers': 10000},
        ),
        _campaign(
          id: 4,
          code: 'ZC100022',
          title: 'Fitness Challenge Launch',
          status: 'completed',
          category: 'Fitness',
          platform: 'instagram',
          deliverable: 'post',
          posts: 4,
          min: 15000,
          max: 45000,
          applications: 22,
          hired: 6,
          isPublic: true,
          requirements: {
            'min_followers': 25000,
            'cities': ['Pune', 'Mumbai'],
          },
        ),
      ];

  static Map<String, dynamic> _campaign({
    required int id,
    required String code,
    required String title,
    required String status,
    required String category,
    required String platform,
    required String deliverable,
    required int posts,
    required int min,
    required int max,
    required int applications,
    required int hired,
    required bool isPublic,
    Map<String, dynamic> requirements = const {},
  }) =>
      {
        'id': id,
        'code': code,
        'title': title,
        'description':
            'We are looking for authentic, daylight-lit content that shows the '
                'product in a real routine. Please highlight texture and finish, '
                'keep the tone conversational, and avoid hard-sell language.\n\n'
                'Tag @glowskincare and use #GlowFromWithin.',
        'category_name': category,
        'platform': platform,
        'deliverable_type': deliverable,
        'posts_count': posts,
        'budget_min': min,
        'budget_max': max,
        'currency': 'INR',
        'reference_urls': [
          'https://instagram.com/p/reference1',
          'https://instagram.com/p/reference2',
        ],
        'requirements': requirements,
        'starts_on': '2026-08-05',
        'ends_on': '2026-08-28',
        'applications_count': applications,
        'hired_count': hired,
        'status': status,
        'is_public': isPublic,
        'created_at': '2026-07-${(id * 4) % 28 + 1} 10:00:00',
      };

  /// Campaign detail with the applicant pipeline.
  static Map<String, dynamic> campaignDetail(int id) {
    final base = campaigns.firstWhere(
      (c) => c['id'] == id,
      orElse: () => campaigns.first,
    );

    const pipeline = [
      'hired',
      'shortlisted',
      'applied',
      'applied',
      'invited',
      'declined',
    ];

    return {
      ...base,
      'recipients': [
        for (var i = 0; i < pipeline.length; i++)
          {
            'id': i + 1,
            'creator': creators[i],
            'status': pipeline[i],
            'quoted_amount':
                (base['budget_min'] as int) + (i * 4200),
            'pitch': i.isEven
                ? 'I have worked with three skincare brands this year and my '
                    'audience skews 70% female, 22–34, mostly metro India.'
                : null,
            'booking_id': pipeline[i] == 'hired' ? i + 1 : null,
            'responded_at': '2026-07-2${i % 9} 14:00:00',
          },
      ],
    };
  }

  // ------------------------------------------------------------------
  // Invoices
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get invoices => [
        _invoice(1, 'ZI/2026-27/000123', 5, 'ZB-2026-0142',
            'Skincare Routine Collaboration', 28000, 'paid'),
        _invoice(2, 'ZI/2026-27/000119', 3, 'ZB-2026-0176',
            'Festive Collection Reel', 45000, 'paid'),
        _invoice(3, 'ZI/2026-27/000104', 8, 'ZB-2026-0104',
            'Athleisure Lookbook', 41000, 'refunded'),
        _invoice(4, 'ZI/2026-27/000098', 7, 'ZB-2026-0119',
            'Recipe Series — Regional Flavours', 38000, 'paid'),
      ];

  static Map<String, dynamic> _invoice(
    int id,
    String number,
    int bookingId,
    String bookingCode,
    String bookingTitle,
    int subtotal,
    String status,
  ) {
    final tax = (subtotal * 0.18).round();
    return {
      'id': id,
      'invoice_number': number,
      'booking_id': bookingId,
      'booking_code': bookingCode,
      'booking_title': bookingTitle,
      'type': 'brand_invoice',
      'bill_to_name': 'Glow Skincare Co.',
      'bill_to_email': 'priya@glowskincare.in',
      'bill_to_address': '4th Floor, Trade Centre, Bandra Kurla Complex, Mumbai 400051',
      'bill_to_gst': '27AABCG1234M1Z5',
      'line_items': [
        {'label': bookingTitle, 'amount': subtotal},
      ],
      'subtotal': subtotal,
      'fee_amount': 0,
      'tax_amount': tax,
      'tax_rate': 18.0,
      'discount_amount': 0,
      'total': subtotal + tax,
      'currency': 'INR',
      'status': status,
      'issued_at': '2026-07-${(id * 5) % 28 + 1} 12:00:00',
      'paid_at': status == 'paid'
          ? '2026-07-${(id * 5) % 28 + 1} 12:05:00'
          : null,
    };
  }

  // ------------------------------------------------------------------
  // Availability calendar
  // ------------------------------------------------------------------

  /// A month of booking availability for `GET /creators/{id}/availability`.
  ///
  /// Weekends are blocked, a handful of weekdays are fully booked, and the rest
  /// are open — enough variety to exercise every cell state in the calendar.
  static Map<String, dynamic> availability(String month) {
    final parts = month.split('-');
    final year = int.tryParse(parts.first) ?? 2026;
    final monthNo = parts.length > 1 ? (int.tryParse(parts[1]) ?? 8) : 8;
    final daysInMonth = DateTime(year, monthNo + 1, 0).day;

    final days = <Map<String, dynamic>>[];

    for (var day = 1; day <= daysInMonth; day++) {
      final date = DateTime(year, monthNo, day);
      final isWeekend = date.weekday == DateTime.saturday ||
          date.weekday == DateTime.sunday;

      // A deterministic sprinkle of fully-booked weekdays.
      final isBooked = !isWeekend && day % 7 == 3;

      days.add({
        'available_on':
            '$year-${monthNo.toString().padLeft(2, '0')}-${day.toString().padLeft(2, '0')}',
        'is_available': !isWeekend,
        'max_bookings': 2,
        'booked_count': isBooked ? 2 : (day % 5 == 0 ? 1 : 0),
        'slots': isWeekend ? <String>[] : ['09:00-13:00', '15:00-19:00'],
      });
    }

    return {'month': month, 'days': days};
  }

  // ------------------------------------------------------------------
  // Wallet and payments
  // ------------------------------------------------------------------

  static Map<String, dynamic> get wallet => {
        'balance': 48250.00,
        'pending': 12800.00,
        'escrow': 145500.00,
        'lifetime_earnings': 862400.00,
        'withdrawn': 814150.00,
        'monthly_earnings': 96300.00,
        'currency': 'INR',
        'can_withdraw': true,
        'min_withdrawal': 500,
      };

  static List<Map<String, dynamic>> get transactions => [
        _txn(1, 'credit', 'booking_earning', 27625, 'Payout — Skincare Routine Collaboration', '18 Jul 2026', 'payments'),
        _txn(2, 'debit', 'withdrawal', 50000, 'Withdrawal to HDFC •••• 4821', '16 Jul 2026', 'account_balance'),
        _txn(3, 'credit', 'booking_earning', 32300, 'Payout — Recipe Series', '12 Jul 2026', 'payments'),
        _txn(4, 'debit', 'commission', 5737, 'Platform commission (15%)', '12 Jul 2026', 'percent'),
        _txn(5, 'credit', 'referral', 500, 'Referral bonus — Meera J.', '09 Jul 2026', 'card_giftcard'),
        _txn(6, 'credit', 'deposit', 25000, 'Wallet top-up via UPI', '04 Jul 2026', 'arrow_downward'),
        _txn(7, 'credit', 'refund', 41000, 'Refund — Athleisure Lookbook cancelled', '28 Jun 2026', 'refresh'),
        _txn(8, 'debit', 'escrow_hold', 45000, 'Escrow hold — Festive Collection Reel', '21 Jun 2026', 'payments'),
      ];

  static Map<String, dynamic> _txn(
    int id,
    String direction,
    String category,
    int amount,
    String description,
    String date,
    String icon,
  ) =>
      {
        'id': id,
        'reference': 'TXN${100000 + id}',
        'direction': direction,
        'category': category,
        'amount': amount,
        'amount_label':
            '${direction == 'credit' ? '+' : '-'} ${_money(amount)}',
        'balance_after': 48250 + id * 1200,
        'description': description,
        'status': 'completed',
        'date_label': date,
        'icon': icon,
        'created_at': '2026-07-${(id % 28) + 1} 10:00:00',
      };

  static List<Map<String, dynamic>> get payments => [
        {
          'id': 1,
          'reference': 'PAY-2026-8841',
          'booking_id': 5,
          'booking_code': 'ZB-2026-0142',
          'booking_title': 'Skincare Routine Collaboration',
          'purpose': 'booking',
          'gateway': 'razorpay',
          'method': 'upi',
          'amount': 33040,
          'amount_label': _money(33040),
          'refunded': 0,
          'currency': 'INR',
          'status': 'paid',
          'card_last4': null,
          'paid_at': '2026-07-02 12:14:00',
          'created_at': '2026-07-02 12:12:00',
        },
        {
          'id': 2,
          'reference': 'PAY-2026-8790',
          'booking_id': 3,
          'booking_code': 'ZB-2026-0176',
          'booking_title': 'Festive Collection Reel',
          'purpose': 'booking',
          'gateway': 'razorpay',
          'method': 'card',
          'amount': 53100,
          'amount_label': _money(53100),
          'refunded': 0,
          'currency': 'INR',
          'status': 'paid',
          'card_last4': '4242',
          'paid_at': '2026-07-21 11:40:00',
          'created_at': '2026-07-21 11:38:00',
        },
        {
          'id': 3,
          'reference': 'PAY-2026-8612',
          'booking_id': 8,
          'booking_code': 'ZB-2026-0104',
          'booking_title': 'Athleisure Lookbook',
          'purpose': 'booking',
          'gateway': 'stripe',
          'method': 'netbanking',
          'amount': 48380,
          'amount_label': _money(48380),
          'refunded': 48380,
          'currency': 'INR',
          'status': 'refunded',
          'card_last4': null,
          'paid_at': '2026-06-05 09:20:00',
          'created_at': '2026-06-05 09:18:00',
        },
      ];

  static List<Map<String, dynamic>> get withdrawals => [
        {
          'id': 1,
          'reference': 'WD-2026-0331',
          'amount': 50000,
          'amount_label': _money(50000),
          'fee': 0,
          'net_amount': 50000,
          'net_label': _money(50000),
          'method': 'bank',
          'destination': 'HDFC Bank •••• 4821',
          'status': 'paid',
          'payout_reference': 'UTR8891234',
          'can_cancel': false,
          'created_at': '2026-07-16 10:00:00',
          'processed_at': '2026-07-17 14:30:00',
        },
        {
          'id': 2,
          'reference': 'WD-2026-0298',
          'amount': 20000,
          'amount_label': _money(20000),
          'fee': 0,
          'net_amount': 20000,
          'net_label': _money(20000),
          'method': 'upi',
          'destination': 'priya@okhdfcbank',
          'status': 'pending',
          'can_cancel': true,
          'created_at': '2026-07-29 09:12:00',
        },
      ];

  static List<Map<String, dynamic>> get bankAccounts => [
        {
          'id': 1,
          'account_holder': 'Priya Sharma',
          'bank_name': 'HDFC Bank',
          'account_last4': '4821',
          'ifsc': 'HDFC0001234',
          'method': 'bank',
          'is_primary': true,
          'is_verified': true,
        },
        {
          'id': 2,
          'account_holder': 'Priya Sharma',
          'bank_name': '',
          'account_last4': '',
          'ifsc': '',
          'upi_id': 'priya@okhdfcbank',
          'method': 'upi',
          'is_primary': false,
          'is_verified': true,
        },
      ];

  static List<Map<String, dynamic>> get paymentMethods => [
        {
          'key': 'upi',
          'label': 'UPI',
          'description': 'GPay, PhonePe, Paytm, BHIM',
          'gateway': 'razorpay',
          'is_enabled': true,
        },
        {
          'key': 'card',
          'label': 'Credit / Debit Card',
          'description': 'Visa, Mastercard, RuPay, Amex',
          'gateway': 'razorpay',
          'is_enabled': true,
        },
        {
          'key': 'netbanking',
          'label': 'Net Banking',
          'description': 'All major Indian banks',
          'gateway': 'razorpay',
          'is_enabled': true,
        },
        {
          'key': 'wallet',
          'label': 'Zusty Wallet',
          'description': 'Balance ${_money(48250)}',
          'gateway': 'wallet',
          'is_enabled': true,
        },
      ];

  // ------------------------------------------------------------------
  // Messaging
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get conversations => List.generate(
        7,
        (i) {
          final creator = creators[i];
          return {
            'id': i + 1,
            'other_user': {
              'id': creator['user_id'],
              'name': creator['display_name'],
              'avatar_url': creator['avatar_url'],
              'handle': creator['handle'],
              'is_online': i < 3,
              'is_verified': creator['is_verified'],
            },
            'last_message_text': _lastMessages[i % _lastMessages.length],
            'last_message_at': '2026-08-01 ${(9 + i).toString().padLeft(2, '0')}:${(12 + i * 5) % 60}:00',
            'unread_count': i == 0 ? 2 : (i == 2 ? 1 : 0),
            'is_muted': i == 5,
            'booking_id': i < 4 ? i + 1 : null,
            'booking_code': i < 4 ? bookings[i]['code'] : null,
            'booking_title': i < 4 ? bookings[i]['title'] : null,
          };
        },
      );

  static const _lastMessages = [
    'Sure, I can shoot that by Friday 👍',
    'Sending the first cut for review now.',
    'Thanks! Could you share the product brief PDF?',
    'The reel is live — link attached.',
    'Perfect, let\'s lock the dates.',
    'I\'ll get back to you after checking my calendar.',
    'Payment received, thank you!',
  ];

  static List<Map<String, dynamic>> messages(int conversationId) {
    final creator = creators[(conversationId - 1).clamp(0, creators.length - 1)];
    final otherId = creator['user_id'] as int;

    return [
      {
        'id': 1,
        'type': 'system',
        'body': 'Booking ${bookings.first['code']} created for this conversation.',
        'created_at': '2026-07-31 09:00:00',
      },
      {
        'id': 2,
        'type': 'text',
        'sender_id': 1,
        'body':
            'Hi ${(creator['display_name'] as String).split(' ').first}! We loved your recent skincare content. '
                'Would you be open to a collaboration for our new serum launch?',
        'is_mine': true,
        'is_read': true,
        'created_at': '2026-07-31 09:02:00',
      },
      {
        'id': 3,
        'type': 'text',
        'sender_id': otherId,
        'sender_name': creator['display_name'],
        'sender_avatar': creator['avatar_url'],
        'body': 'Hi Priya! Thank you — I\'d love to hear more. What are you thinking?',
        'is_mine': false,
        'is_read': true,
        'created_at': '2026-07-31 09:20:00',
      },
      {
        'id': 4,
        'type': 'text',
        'sender_id': 1,
        'body':
            'One reel plus three stories, shot in natural light. Budget is ₹32,500 with a 10-day timeline.',
        'is_mine': true,
        'is_read': true,
        'created_at': '2026-07-31 09:25:00',
      },
      {
        'id': 5,
        'type': 'file',
        'sender_id': 1,
        'body': 'Campaign brief',
        'file_name': 'GlowSerum_Brief_Aug2026.pdf',
        'file_size': 1458000,
        'media_url': 'https://zuste.com/files/brief.pdf',
        'is_mine': true,
        'is_read': true,
        'created_at': '2026-07-31 09:26:00',
      },
      {
        'id': 6,
        'type': 'text',
        'sender_id': otherId,
        'sender_name': creator['display_name'],
        'sender_avatar': creator['avatar_url'],
        'body': 'Got it, reading through now. The timeline works for me.',
        'is_mine': false,
        'is_read': true,
        'created_at': '2026-08-01 08:10:00',
      },
      {
        'id': 7,
        'type': 'image',
        'sender_id': otherId,
        'sender_name': creator['display_name'],
        'sender_avatar': creator['avatar_url'],
        'body': 'Here\'s a mood board reference for the shoot.',
        'media_url': photo('moodboard-$conversationId', 700, 500),
        'is_mine': false,
        'is_read': false,
        'created_at': '2026-08-01 08:12:00',
      },
      {
        'id': 8,
        'type': 'text',
        'sender_id': otherId,
        'sender_name': creator['display_name'],
        'sender_avatar': creator['avatar_url'],
        'body': 'Sure, I can shoot that by Friday 👍',
        'is_mine': false,
        'is_read': false,
        'created_at': '2026-08-01 08:14:00',
      },
    ];
  }

  // ------------------------------------------------------------------
  // Notifications
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get notifications => [
        _notification(1, 'booking_accepted', 'Booking accepted',
            'Ananya Sharma accepted your campaign request for Summer Glow Product Launch.',
            false, '/bookings/1'),
        _notification(2, 'payment_released', 'Payment released',
            '₹27,625 has been credited to your wallet for ZB-2026-0142.',
            false, '/wallet'),
        _notification(3, 'message_received', 'New message',
            'Rohit Verma sent you a message about Monsoon Travel Series.',
            false, '/messages/2'),
        _notification(4, 'booking_delivered', 'Deliverables submitted',
            'Karan Mehta submitted deliverables for Smartphone Unboxing Review. Review and approve.',
            false, '/bookings/4'),
        _notification(5, 'kyc_approved', 'Verification approved',
            'Your KYC documents were approved. Your profile is now live.',
            true, '/profile'),
        _notification(6, 'review_received', 'New review',
            'Glow Skincare Co. left you a 5-star review.',
            true, '/profile'),
        _notification(7, 'campaign_reminder', 'Delivery due soon',
            'Festive Collection Reel is due in 3 days.',
            true, '/bookings/3'),
        _notification(8, 'promo_offer', 'Zero commission week',
            'Book any creator before 10 Aug and pay 0% platform fee.',
            true, '/explore'),
      ];

  static Map<String, dynamic> _notification(
    int id,
    String type,
    String title,
    String body,
    bool isRead,
    String actionUrl,
  ) =>
      {
        'id': id,
        'type': type,
        'title': title,
        'body': body,
        'is_read': isRead,
        'action_url': actionUrl,
        'created_at': '2026-08-01 ${(14 - id).toString().padLeft(2, '0')}:30:00',
      };

  // ------------------------------------------------------------------
  // Support and CMS
  // ------------------------------------------------------------------

  static List<Map<String, dynamic>> get tickets => [
        {
          'id': 1,
          'reference': 'TK-2026-0451',
          'subject': 'Payout not received for ZB-2026-0142',
          'category': 'payment',
          'status': 'in_progress',
          'priority': 'high',
          'last_message': 'We have escalated this to our finance team.',
          'messages_count': 4,
          'booking_id': 5,
          'created_at': '2026-07-28 11:00:00',
          'updated_at': '2026-07-30 16:20:00',
        },
        {
          'id': 2,
          'reference': 'TK-2026-0392',
          'subject': 'How do I change my registered email?',
          'category': 'account',
          'status': 'resolved',
          'priority': 'normal',
          'last_message': 'Glad that worked — closing this ticket.',
          'messages_count': 3,
          'created_at': '2026-07-12 09:30:00',
          'updated_at': '2026-07-12 15:10:00',
        },
      ];

  static Map<String, dynamic> ticketDetail(int id) {
    final base = tickets.firstWhere((t) => t['id'] == id,
        orElse: () => tickets.first);
    return {
      ...base,
      'messages': [
        {
          'id': 1,
          'body':
              'Hi, the payout for booking ZB-2026-0142 was approved five days ago '
                  'but has not reached my bank account yet.',
          'author_name': 'Priya Sharma',
          'author_avatar': photo('priya-brand', 200, 200),
          'is_staff': false,
          'created_at': '2026-07-28 11:00:00',
        },
        {
          'id': 2,
          'body':
              'Thanks for reaching out. Let me check the payout reference with our '
                  'partner bank — I\'ll update you within 24 hours.',
          'author_name': 'Zusty Support',
          'is_staff': true,
          'created_at': '2026-07-28 13:45:00',
        },
        {
          'id': 3,
          'body': 'Any update on this? It has been two more days.',
          'author_name': 'Priya Sharma',
          'author_avatar': photo('priya-brand', 200, 200),
          'is_staff': false,
          'created_at': '2026-07-30 10:05:00',
        },
        {
          'id': 4,
          'body':
              'We have escalated this to our finance team. The UTR shows the transfer '
                  'was initiated — your bank should credit it within 48 hours.',
          'author_name': 'Zusty Support',
          'is_staff': true,
          'created_at': '2026-07-30 16:20:00',
        },
      ],
    };
  }

  static List<Map<String, dynamic>> get faqs => [
        _faq(1, 'getting_started', 'How do I get started on Zusty?',
            'Create an account, pick whether you are a brand or a creator, and complete your profile. '
                'Brands can search and book creators immediately; creators need to pass KYC verification first.'),
        _faq(2, 'getting_started', 'Is Zusty free to join?',
            'Yes. Creating an account and browsing creators is free. Zusty charges a platform commission '
                'of 10–20% only when a booking completes successfully.'),
        _faq(3, 'bookings', 'How does the booking process work?',
            'Send a campaign request with your brief, deliverables, budget, and timeline. The creator can accept, '
                'reject, or send a counter-offer. Once both sides agree, you confirm and pay into escrow.'),
        _faq(4, 'bookings', 'Can I negotiate the price?',
            'Yes. Either side can send a counter-offer during the Negotiating stage. Every round is recorded '
                'on the booking so there is a full audit trail.'),
        _faq(5, 'payments', 'What is escrow and why does Zusty use it?',
            'Your payment is held safely by Zusty until you approve the delivered work. The creator knows the money '
                'exists; you know work will be delivered before it is released.'),
        _faq(6, 'payments', 'When does the creator get paid?',
            'As soon as you approve the delivered work. Zusty deducts the platform commission and credits the '
                'remainder to the creator wallet — typically within five minutes.'),
        _faq(7, 'payments', 'Which payment methods are supported?',
            'UPI, credit and debit cards, net banking, and your Zusty wallet balance, processed through Razorpay and Stripe.'),
        _faq(8, 'payouts', 'How do I withdraw my earnings?',
            'Open your Wallet, tap Withdraw, and choose a verified bank account or UPI ID. Withdrawals are reviewed '
                'by our team and usually paid out within 48 hours.'),
        _faq(9, 'payouts', 'Is there a minimum withdrawal amount?',
            'Yes, the minimum withdrawal is ₹500.'),
        _faq(10, 'verification', 'Why do creators need KYC verification?',
            'KYC keeps the marketplace trustworthy. It confirms a creator is a real person who controls the social '
                'accounts they list, which protects brands from fraud and fake followers.'),
        _faq(11, 'verification', 'How long does verification take?',
            'Most submissions are reviewed within 24–48 hours. You will be notified as soon as a decision is made.'),
        _faq(12, 'disputes', 'What if the delivered work is not what I briefed?',
            'You can request changes or raise a dispute from the booking screen. Our support team reviews the brief, '
                'chat history, and deliverables, then decides whether to release, refund, or split the escrowed amount.'),
      ];

  static Map<String, dynamic> _faq(
    int id,
    String category,
    String question,
    String answer,
  ) =>
      {
        'id': id,
        'category': category,
        'question': question,
        'answer': answer,
        'helpful_yes': 40 + id * 3,
        'helpful_no': id,
      };

  static Map<String, dynamic> get referrals => {
        'code': 'PRIYA500',
        'share_url': 'https://zuste.com/join?ref=PRIYA500',
        'total_invited': 12,
        'total_joined': 7,
        'total_earned': 3500,
        'reward_per_referral': 500,
        'referrals': [
          {
            'name': 'Meera Joshi',
            'avatar_url': photo('meerajoshi', 200, 200),
            'status': 'rewarded',
            'reward': 500,
            'joined_at': '2026-07-09 10:00:00',
          },
          {
            'name': 'Devansh Rao',
            'avatar_url': photo('devanshrao', 200, 200),
            'status': 'rewarded',
            'reward': 500,
            'joined_at': '2026-06-22 10:00:00',
          },
          {
            'name': 'Sanya Gupta',
            'avatar_url': photo('sanyagupta', 200, 200),
            'status': 'joined',
            'reward': 0,
            'joined_at': '2026-07-25 10:00:00',
          },
          {
            'name': 'Amit Trivedi',
            'status': 'pending',
            'reward': 0,
            'joined_at': '2026-07-30 10:00:00',
          },
        ],
      };

  static Map<String, dynamic> page(String slug) => {
        'slug': slug,
        'title': switch (slug) {
          'terms' => 'Terms of Service',
          'privacy' => 'Privacy Policy',
          'about' => 'About Zusty',
          _ => 'Zusty',
        },
        'subtitle': 'Last updated 1 August 2026',
        'content':
            'Zusty is the transaction and relationship layer for influencer marketing. '
                'This page is served from the CMS module of the admin panel and is fully '
                'editable by the platform operations team.',
        'updated_at': '2026-08-01 00:00:00',
      };

  // ------------------------------------------------------------------
  // Helpers
  // ------------------------------------------------------------------

  static String _money(num value) {
    final digits = value.round().toString();
    // Indian grouping: last three digits, then pairs.
    if (digits.length <= 3) return '₹$digits';
    final last3 = digits.substring(digits.length - 3);
    var rest = digits.substring(0, digits.length - 3);
    final buffer = StringBuffer();
    while (rest.length > 2) {
      buffer.write(',${rest.substring(rest.length - 2)}');
      rest = rest.substring(0, rest.length - 2);
    }
    final head = rest.isEmpty ? '' : rest;
    final grouped = buffer.toString().split(',').reversed.where((e) => e.isNotEmpty).join(',');
    return '₹${[if (head.isNotEmpty) head, if (grouped.isNotEmpty) grouped].join(',')},$last3';
  }

  static String _compact(int value) {
    if (value >= 10000000) return '${(value / 10000000).toStringAsFixed(1)}Cr';
    if (value >= 100000) return '${(value / 100000).toStringAsFixed(1)}L';
    if (value >= 1000) {
      final k = value / 1000;
      return '${k % 1 == 0 ? k.toStringAsFixed(0) : k.toStringAsFixed(1)}K';
    }
    return '$value';
  }

  // -- AI drafts and matching (POST /ai/*) -----------------------------------

  static const aiBioText =
      'I create honest, everyday skincare content for people who want '
      'routines that fit real life.';

  static Map<String, dynamic> get aiBio =>
      {'text': aiBioText, 'remaining_today': 19};

  static Map<String, dynamic> get aiBrief => {
        'text': 'Objective\nLaunch the Glow Serum with honest reviews.\n\n'
            'Deliverables\n- 2 reels per creator',
        'remaining_today': 18,
      };

  static Map<String, dynamic> get aiMatches => {
        'matches': [
          for (final (index, creator) in creators.take(3).indexed)
            {
              ...creator,
              'match_score': 92 - index * 6,
              'match_reason': 'Strong ${creator['category']} audience.',
            },
        ],
        'remaining_today': 17,
      };

  static String _statusLabel(String status) => switch (status) {
        'pending' => 'Pending',
        'countered' => 'Counter offer',
        'accepted' => 'Accepted',
        'rejected' => 'Declined',
        'awaiting_payment' => 'Awaiting payment',
        'confirmed' => 'Confirmed',
        'in_progress' => 'In progress',
        'delivered' => 'Delivered',
        'completed' => 'Completed',
        'cancelled' => 'Cancelled',
        _ => status,
      };

  static String _statusTone(String status) => switch (status) {
        'pending' || 'countered' || 'awaiting_payment' => 'warning',
        'accepted' || 'confirmed' || 'in_progress' || 'delivered' => 'info',
        'completed' => 'success',
        _ => 'error',
      };
}
