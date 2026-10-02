import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:zuste/core/storage/key_value_cache.dart';
import 'package:zuste/core/theme/app_theme.dart';
import 'package:zuste/data/repositories/base_repository.dart';
import 'package:zuste/models/city.dart';
import 'package:zuste/models/menu.dart';
import 'package:zuste/models/onboarding_slide.dart';
import 'package:zuste/models/taxonomy.dart';
import 'package:zuste/models/user.dart';
import 'package:zuste/providers/auth_provider.dart';
// Re-exports core/providers/core_providers.dart, so this one import covers
// both the infrastructure providers and the app-level ones.
import 'package:zuste/providers/core_providers.dart';
import 'package:zuste/providers/onboarding_provider.dart';
import 'package:zuste/shared/widgets/voice_input_button.dart';

import 'package:zuste/features/auth/login_screen.dart';
import 'package:zuste/features/auth/otp_screen.dart';
import 'package:zuste/features/auth/signup_screen.dart';
import 'package:zuste/features/booking/booking_details_screen.dart';
import 'package:zuste/features/booking/checkout_success_screen.dart';
import 'package:zuste/features/calendar/availability_calendar_screen.dart';
import 'package:zuste/features/calendar/booking_calendar_sheet.dart';
import 'package:zuste/features/billing/invoices_screen.dart';
import 'package:zuste/features/booking/deliverable_review_screen.dart';
import 'package:zuste/features/campaigns/campaign_detail_screen.dart';
import 'package:zuste/features/campaigns/campaign_form_screen.dart';
import 'package:zuste/features/campaigns/campaigns_screen.dart';
import 'package:zuste/features/dashboard/brand_dashboard_screen.dart';
import 'package:zuste/features/discovery/compare_creators_screen.dart';
import 'package:zuste/features/location/city_selector_sheet.dart';
import 'package:zuste/features/profile/business_profile_screen.dart';
import 'package:zuste/features/search/filter_sheet.dart';
import 'package:zuste/features/wallet/transaction_detail_screen.dart';
import 'package:zuste/shared/widgets/z_calendar.dart';
import 'package:zuste/features/booking/my_bookings_screen.dart';
import 'package:zuste/features/booking/new_booking_screen.dart';
import 'package:zuste/features/booking/payment_screen.dart';
import 'package:zuste/features/chat/chat_screen.dart';
import 'package:zuste/features/chat/messages_screen.dart';
import 'package:zuste/features/creator/creator_gallery_screen.dart';
import 'package:zuste/features/creator/creator_profile_screen.dart';
import 'package:zuste/features/discovery/categories_screen.dart';
import 'package:zuste/features/discovery/explore_screen.dart';
import 'package:zuste/features/discovery/saved_creators_screen.dart';
import 'package:zuste/features/home/home_screen.dart';
import 'package:zuste/features/kyc/kyc_details_screen.dart';
import 'package:zuste/features/kyc/kyc_status_screen.dart';
import 'package:zuste/features/notifications/notifications_screen.dart';
import 'package:zuste/features/onboarding/onboarding_screen.dart';
import 'package:zuste/features/profile/edit_profile_screen.dart';
import 'package:zuste/features/profile/menu_screen.dart';
import 'package:zuste/features/referral/invite_earn_screen.dart';
import 'package:zuste/features/search/search_results_screen.dart';
import 'package:zuste/features/search/search_screen.dart';
import 'package:zuste/features/support/faqs_screen.dart';
import 'package:zuste/features/support/support_screen.dart';
import 'package:zuste/features/support/ticket_chat_screen.dart';
import 'package:zuste/features/wallet/wallet_screen.dart';

import 'support/fixtures.dart';
import 'support/mock_data.dart';

/// Smoke tests that every screen in `stitch_ui_screen_replication` builds,
/// resolves its providers, and paints its key copy.
///
/// Repositories run in demo mode with the [MockData] fixtures registered, so
/// they stand in for the Core PHP backend. The fixtures live here rather than
/// in `lib/`, and `BaseRepository.fixtures` is the only door into them.
void main() {
  TestWidgetsFlutterBinding.ensureInitialized();

  late KeyValueCache cache;

  setUp(() async {
    SharedPreferences.setMockInitialValues({});
    cache = await KeyValueCache.open();
    BaseRepository.demoMode = true;
    registerTestFixtures();

    // Cities and onboarding copy are admin-managed, so the test supplies them
    // the same way the taxonomy bundle does at runtime — through
    // `City.fromTerm`, which keeps the metadata mapping under test.
    Cities.hydrate(_cityTerms.map(City.fromTerm).toList());
  });

  /// Pumps [screen] inside the app theme with the demo providers wired up.
  ///
  /// The canvas is phone-width (390dp) so the real mobile layout is exercised,
  /// but very tall — lazy lists only build what fits the viewport, and a tall
  /// canvas lets the whole screen build so its content can be asserted.
  Future<void> pumpScreen(
    WidgetTester tester,
    Widget screen, {
    List<Override> overrides = const [],
  }) async {
    tester.view.physicalSize = const Size(1170, 7200);
    tester.view.devicePixelRatio = 3.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(
      ProviderScope(
        overrides: [
          keyValueCacheProvider.overrideWithValue(cache),
          currentUserProvider.overrideWithValue(
            User.fromJson(MockData.currentUser),
          ),
          onboardingSlidesProvider.overrideWith((ref) async => _slides),
          ...overrides,
        ],
        child: MaterialApp(theme: AppTheme.light, home: screen),
      ),
    );

    // Settle the FutureProviders without waiting on the chat poll timer.
    await tester.pump();
    await tester.pump(const Duration(milliseconds: 400));
  }

  /// Pumps a bottom sheet.
  ///
  /// Sheets are always presented through `showModalBottomSheet`, which supplies
  /// the Material ancestor their inputs need, so the test has to provide one
  /// too rather than pumping them bare.
  Future<void> pumpSheet(WidgetTester tester, Widget sheet) =>
      pumpScreen(tester, Scaffold(body: sheet));

  /// Asserts the screen built without throwing and shows [copy].
  void expectsRendered(WidgetTester tester, String copy) {
    expect(tester.takeException(), isNull);
    expect(find.text(copy), findsWidgets);
  }

  group('Onboarding and auth', () {
    testWidgets('01-03 Onboarding', (tester) async {
      await pumpScreen(tester, const OnboardingScreen());
      expectsRendered(tester, 'Next');
      // The dot row and CTA follow the admin-managed slide count.
      expect(find.text(_slides.first.title), findsOneWidget);
    });

    testWidgets('04 Welcome / Login', (tester) async {
      await pumpScreen(tester, const LoginScreen());
      expectsRendered(tester, 'Welcome Back!');
    });

    testWidgets('05 Sign Up', (tester) async {
      await pumpScreen(tester, const SignUpScreen());
      expectsRendered(tester, 'Create Your Account');
    });

    testWidgets('OTP sign-in', (tester) async {
      await pumpScreen(tester, const OtpScreen());
      expectsRendered(tester, 'Sign in with OTP');
    });
  });

  group('Discovery', () {
    testWidgets('06 Home', (tester) async {
      await pumpScreen(tester, const HomeScreen());
      expectsRendered(tester, 'Recommended Creators');
    });

    testWidgets('07 Search', (tester) async {
      await pumpScreen(tester, const SearchScreen());
      expectsRendered(tester, 'Browse categories');
    });

    testWidgets('Voice input hides where speech is unavailable', (tester) async {
      // A test host has no speech platform, which is the same situation as a
      // phone with no recogniser installed, a permission refused for good, or
      // most desktop browsers. The button must render NOTHING there — a
      // microphone that does nothing when tapped is worse than no microphone,
      // and it must not throw while finding that out.
      await pumpScreen(tester, const SearchScreen());

      expect(tester.takeException(), isNull);
      expect(find.byType(VoiceInputButton), findsOneWidget);
      expect(find.byIcon(Icons.mic_none_rounded), findsNothing);
      expect(find.byIcon(Icons.mic_rounded), findsNothing);

      // And the field it lives in is still perfectly usable.
      await tester.enterText(find.byType(TextField).first, 'travel creators');
      await tester.pump();

      expect(find.text('travel creators'), findsOneWidget);
    });

    testWidgets('Every search screen carries the voice control', (tester) async {
      // ZSearchField is the single shared search widget, so voice input is
      // added once rather than eight times. This asserts the wiring actually
      // reaches the screens rather than only the widget.
      for (final screen in <Widget>[
        const SearchScreen(),
        const CategoriesScreen(),
        const FaqsScreen(),
      ]) {
        await pumpScreen(tester, screen);

        expect(
          find.byType(VoiceInputButton),
          findsWidgets,
          reason: '${screen.runtimeType} has a search box with no voice input',
        );
      }
    });

    testWidgets('31 Search Results', (tester) async {
      await pumpScreen(tester, const SearchResultsScreen());
      expect(tester.takeException(), isNull);
      expect(find.textContaining('creators found'), findsOneWidget);
    });

    testWidgets('08 Categories', (tester) async {
      await pumpScreen(tester, const CategoriesScreen());
      expectsRendered(tester, 'Categories');
    });

    testWidgets('23 Explore Creators', (tester) async {
      await pumpScreen(tester, const ExploreScreen());
      expectsRendered(tester, 'Explore Creators');
    });

    testWidgets('25 Saved Creators', (tester) async {
      await pumpScreen(tester, const SavedCreatorsScreen());
      expectsRendered(tester, 'Saved Creators');
    });
  });

  group('Creator', () {
    testWidgets('09 Creator Profile', (tester) async {
      await pumpScreen(
        tester,
        const CreatorProfileScreen(handle: 'ananyasharma'),
      );
      expectsRendered(tester, 'Ananya Sharma');
    });

    testWidgets('10 Creator Gallery', (tester) async {
      await pumpScreen(
        tester,
        const CreatorGalleryScreen(handle: 'ananyasharma'),
      );
      // Tabs carry their item counts, e.g. "Photos (24)".
      expect(tester.takeException(), isNull);
      expect(find.textContaining('Photos'), findsWidgets);
    });

    testWidgets('21 Creator Reels', (tester) async {
      await pumpScreen(
        tester,
        const CreatorGalleryScreen(
          handle: 'ananyasharma',
          initialKind: 'reel',
          title: 'Reels',
        ),
      );
      expect(tester.takeException(), isNull);
      expect(find.textContaining('Reels'), findsWidgets);
    });

    testWidgets('22 Creator Videos', (tester) async {
      await pumpScreen(
        tester,
        const CreatorGalleryScreen(
          handle: 'ananyasharma',
          initialKind: 'video',
          title: 'Videos',
        ),
      );
      expect(tester.takeException(), isNull);
      expect(find.textContaining('Videos'), findsWidgets);
    });
  });

  group('Booking and payment', () {
    testWidgets('11 New Booking Request', (tester) async {
      await pumpScreen(tester, const NewBookingScreen(handle: 'ananyasharma'));
      expectsRendered(tester, 'Campaign details');
    });

    testWidgets('12 Booking Details', (tester) async {
      await pumpScreen(tester, const BookingDetailsScreen(bookingId: 1));
      expectsRendered(tester, 'Payment summary');
    });

    testWidgets('14 My Bookings', (tester) async {
      await pumpScreen(tester, const MyBookingsScreen());
      expectsRendered(tester, 'Active');
    });

    testWidgets('15 Payment Details', (tester) async {
      await pumpScreen(tester, const PaymentScreen(bookingId: 1));
      expectsRendered(tester, 'Order summary');
    });

    testWidgets('Payment offers sheet prices each code', (tester) async {
      await pumpScreen(tester, const PaymentScreen(bookingId: 1));

      await tester.tap(find.text('See offers'));
      await tester.pumpAndSettle();

      // Eligible codes show what they are worth on THIS booking, not just
      // what the coupon says in the abstract.
      expect(find.text('FESTIVE25'), findsOneWidget);
      expect(find.text('You save ₹2,500.00'), findsOneWidget);

      // An ineligible code stays visible and explains itself, because
      // "needs a booking of at least ₹50,000" is actionable and a missing
      // row is not.
      expect(find.text('SCALE20'), findsOneWidget);
      expect(
        find.text('This coupon needs a booking of at least ₹50,000.00.'),
        findsOneWidget,
      );
    });

    testWidgets('16 Wallet', (tester) async {
      await pumpScreen(tester, const WalletScreen());
      expectsRendered(tester, 'Available balance');
    });

    testWidgets('Checkout success', (tester) async {
      await pumpScreen(tester, const CheckoutSuccessScreen(bookingId: 1));
      expectsRendered(tester, 'Payment secured in escrow');
    });

    testWidgets('Transaction detail', (tester) async {
      await pumpScreen(tester, const TransactionDetailScreen(transactionId: 1));
      expectsRendered(tester, 'Balance after');
    });
  });

  group('Header, theming, and filters', () {
    testWidgets('City selector lists metros and offers GPS', (tester) async {
      await pumpSheet(tester, const CitySelectorSheet());
      expectsRendered(tester, 'Use my current location');
      expect(find.text('Mumbai'), findsWidgets);
      expect(find.text('Bengaluru'), findsWidgets);
    });

    testWidgets('Filter sheet exposes every advanced facet', (tester) async {
      await pumpSheet(tester, const FilterSheet());
      expect(tester.takeException(), isNull);

      // The thirteen chip/slider groups render up front.
      for (final section in [
        'Sort by',
        'Location',
        'Category',
        'Price range',
        'Platform',
        'Followers',
        'Engagement rate',
        'Rating',
        'Language',
        'Gender',
        'Age group',
        'Experience',
        'Response time',
      ]) {
        expect(find.text(section), findsWidgets, reason: 'missing "$section"');
      }

      // The two boolean toggles sit at the foot of the sheet, so scroll to them.
      for (final toggle in ['Verified creators only', 'Available now']) {
        await tester.scrollUntilVisible(
          find.text(toggle),
          300,
          scrollable: find.byType(Scrollable).first,
        );
        expect(find.text(toggle), findsWidgets, reason: 'missing "$toggle"');
      }
    });

    testWidgets('Dark palette resolves to dark surfaces', (tester) async {
      AppTheme.of(Brightness.dark);
      expect(AppColors.isDark, isTrue);
      // Dark surface must actually be dark, and text light enough to read.
      expect(AppColors.surface.computeLuminance(), lessThan(0.1));
      expect(AppColors.onSurface.computeLuminance(), greaterThan(0.6));

      AppTheme.of(Brightness.light);
      expect(AppColors.isDark, isFalse);
      expect(AppColors.surface.computeLuminance(), greaterThan(0.8));
    });
  });

  group('Brand', () {
    testWidgets('Dashboard surfaces spend and campaigns', (tester) async {
      await pumpScreen(tester, const BrandDashboardScreen());
      expectsRendered(tester, 'Committed to campaigns');
      expect(find.text('Quick actions'), findsWidgets);
    });

    testWidgets('Business profile captures GST and company', (tester) async {
      await pumpScreen(tester, const BusinessProfileScreen());
      expectsRendered(tester, 'Billing & tax');
      expect(find.text('Company'), findsWidgets);
    });

    testWidgets('Campaigns list', (tester) async {
      await pumpScreen(tester, const CampaignsScreen());
      expectsRendered(tester, 'Festive Glow — Diwali Collection');
    });

    testWidgets('Campaign create form', (tester) async {
      await pumpScreen(tester, const CampaignFormScreen());
      expectsRendered(tester, 'The brief');
      // The form is a step-by-step wizard: budget is on a later step, built
      // but offstage until the brand gets there.
      expect(find.text('Step 1 of 4'), findsWidgets);
      expect(
        find.text('Budget per creator', skipOffstage: false),
        findsWidgets,
      );
    });

    testWidgets('Campaign detail shows applicant pipeline', (tester) async {
      await pumpScreen(tester, const CampaignDetailScreen(campaignId: 1));
      expectsRendered(tester, 'Applicants');
      // Pipeline stages are rendered as counted filter chips.
      expect(find.textContaining('Shortlisted'), findsWidgets);
      expect(find.textContaining('Hired'), findsWidgets);
    });

    testWidgets('Deliverable review offers all three branches',
        (tester) async {
      await pumpScreen(tester, const DeliverableReviewScreen(bookingId: 4));
      expect(tester.takeException(), isNull);
      expect(find.textContaining('held in escrow'), findsWidgets);
      expect(find.text('Approve & release'), findsWidgets);
      expect(find.text('Request changes'), findsWidgets);
      expect(
        find.textContaining('raise a dispute'),
        findsWidgets,
        reason: 'dispute branch from Workflow section 5 must be reachable',
      );
    });

    testWidgets('Invoices list with GST totals', (tester) async {
      await pumpScreen(tester, const InvoicesScreen());
      expectsRendered(tester, 'Total billed');
      expect(find.text('GST paid'), findsWidgets);
    });

    testWidgets('Invoice detail', (tester) async {
      await pumpScreen(tester, const InvoiceDetailScreen(invoiceId: 1));
      expectsRendered(tester, 'Billed to');
    });

    testWidgets('Compare creators', (tester) async {
      await pumpScreen(tester, const CompareCreatorsScreen());
      expectsRendered(tester, 'Pick up to 4');
    });
  });

  group('Calendar', () {
    testWidgets('Availability calendar', (tester) async {
      await pumpScreen(tester, const AvailabilityCalendarScreen());
      expectsRendered(tester, 'Quick actions');
    });

    testWidgets('Availability calendar paints day states', (tester) async {
      await pumpScreen(tester, const AvailabilityCalendarScreen());
      // The month grid renders, and the legend explains its tones.
      expect(find.byType(ZCalendar), findsOneWidget);
      expect(find.text('Open'), findsWidgets);
      expect(find.text('Blocked'), findsWidgets);
    });

    testWidgets('Booking calendar sheet', (tester) async {
      await pumpScreen(
        tester,
        const BookingCalendarSheet(creatorId: 1, creatorName: 'Ananya Sharma'),
      );
      expectsRendered(tester, 'Choose campaign dates');
    });
  });

  group('Messaging and notifications', () {
    testWidgets('13 Messages', (tester) async {
      await pumpScreen(tester, const MessagesScreen());
      expectsRendered(tester, 'Messages');
    });

    testWidgets('Chat thread', (tester) async {
      await pumpScreen(tester, const ChatScreen(conversationId: 1));
      expect(tester.takeException(), isNull);
      expect(find.byType(MessageBubble), findsWidgets);
    });

    testWidgets('17 Notifications', (tester) async {
      await pumpScreen(tester, const NotificationsScreen());
      expectsRendered(tester, 'Booking accepted');
    });

    testWidgets('24 Notification Details', (tester) async {
      await pumpScreen(
        tester,
        const NotificationDetailsScreen(notificationId: 1),
      );
      expectsRendered(tester, 'Booking accepted');
    });
  });

  group('Support', () {
    testWidgets('18 Support & Help', (tester) async {
      await pumpScreen(tester, const SupportScreen());
      expectsRendered(tester, 'Browse by topic');
    });

    testWidgets('27 Help & Support Chat', (tester) async {
      await pumpScreen(tester, const TicketChatScreen(ticketId: 1));
      expectsRendered(tester, 'Zusty Support');
    });

    testWidgets('28 FAQs', (tester) async {
      await pumpScreen(tester, const FaqsScreen());
      expectsRendered(tester, 'FAQs');
    });
  });

  group('Verification', () {
    testWidgets('KYC status shows the checklist and privacy notice',
        (tester) async {
      await pumpScreen(tester, const KycStatusScreen());
      expectsRendered(tester, 'Get verified');
      expect(find.textContaining('encrypted at rest'), findsOneWidget);
    });

    testWidgets('KYC details renders the brand field set', (tester) async {
      await pumpScreen(tester, const KycDetailsScreen());
      expect(tester.takeException(), isNull);

      // Section titles come from the field spec, which mirrors the API's
      // validation rules.
      for (final section in [
        'Company',
        'Tax & registration',
        'Addresses',
        'Primary contact',
      ]) {
        expect(find.text(section), findsOneWidget, reason: section);
      }
    });
  });

  group('Profile', () {
    testWidgets('19 Edit Profile', (tester) async {
      await pumpScreen(tester, const EditProfileScreen());
      expectsRendered(tester, 'Your details');
    });

    testWidgets('20 Menu / More', (tester) async {
      await pumpScreen(tester, const MenuScreen());
      expectsRendered(tester, 'Marketplace');

      // No admin menu configured — the section must simply not appear, rather
      // than rendering an empty card with a heading over it.
      expect(find.text('More'), findsNothing);
    });

    testWidgets('Menu renders the admin-built drawer', (tester) async {
      await pumpScreen(
        tester,
        const MenuScreen(),
        overrides: [
          appDrawerMenuProvider.overrideWithValue(const [
            MenuEntry(
              id: 1,
              label: 'Refer a brand',
              icon: 'card_giftcard',
              linkType: 'screen',
              linkValue: '/invite',
              badgeText: 'New',
            ),
            MenuEntry(
              id: 2,
              label: 'Creator resources',
              icon: 'article',
              children: [
                MenuEntry(
                  id: 3,
                  label: 'Rate card guide',
                  icon: 'description',
                  linkType: 'page',
                  linkValue: 'rate-card-guide',
                ),
              ],
            ),
          ]),
        ],
      );

      expect(tester.takeException(), isNull);

      // A flat entry lands in the generic section, with its admin-set badge.
      expect(find.text('More'), findsOneWidget);
      expect(find.text('Refer a brand'), findsOneWidget);
      expect(find.text('New'), findsOneWidget);

      // An entry with children becomes a section titled with the parent,
      // which is what nesting in the builder is for.
      expect(find.text('Creator resources'), findsOneWidget);
      expect(find.text('Rate card guide'), findsOneWidget);
    });

    testWidgets('Menu refuses a link this build has no screen for',
        (tester) async {
      await pumpScreen(
        tester,
        const MenuScreen(),
        overrides: [
          appDrawerMenuProvider.overrideWithValue(const [
            // '/settings' shipped in the seeded drawer for months and is not a
            // route in this build. Tapping it must say so, not throw.
            MenuEntry(
              id: 9,
              label: 'Settings',
              icon: 'settings',
              linkType: 'screen',
              linkValue: '/favorites',
            ),
          ]),
        ],
      );

      await tester.tap(find.text('Settings'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(tester.takeException(), isNull);
      expect(
        find.text('That link needs a newer version of the app.'),
        findsOneWidget,
      );
    });

    testWidgets('26 Invite & Earn', (tester) async {
      await pumpScreen(tester, const InviteEarnScreen());
      expectsRendered(tester, 'How it works');
    });

    testWidgets('CMS page', (tester) async {
      await pumpScreen(tester, const CmsPageScreen(slug: 'terms'));
      expectsRendered(tester, 'Terms of Service');
    });
  });
}

/// Stand-ins for the admin-managed content the app fetches at launch.
///
/// Mirrors `database/seeds/009_onboarding_and_cities.sql` closely enough to
/// exercise the real parsing path without reaching the network.
const _slides = [
  OnboardingSlide(
    id: 1,
    title: 'Find the perfect Creator for your brand.',
    body: 'Browse verified creators by city, category, and budget.',
  ),
  OnboardingSlide(
    id: 2,
    title: 'Book with confidence.',
    body: 'Payments are held in escrow until you approve the deliverables.',
  ),
  OnboardingSlide(
    id: 3,
    title: 'Track every campaign.',
    body: 'Deliverables, chats, and invoices in one place.',
  ),
];

const _cityTerms = [
  TaxonomyTerm(
    value: 'Mumbai',
    label: 'Mumbai',
    description: 'Maharashtra',
    metadata: {'lat': 19.076, 'lng': 72.8777, 'metro': true},
  ),
  TaxonomyTerm(
    value: 'Delhi',
    label: 'Delhi',
    description: 'Delhi',
    metadata: {'lat': 28.6139, 'lng': 77.209, 'metro': true},
  ),
  TaxonomyTerm(
    value: 'Bengaluru',
    label: 'Bengaluru',
    description: 'Karnataka',
    metadata: {'lat': 12.9716, 'lng': 77.5946, 'metro': true},
  ),
  TaxonomyTerm(
    value: 'Pune',
    label: 'Pune',
    description: 'Maharashtra',
    metadata: {'lat': 18.5204, 'lng': 73.8567},
  ),
];
