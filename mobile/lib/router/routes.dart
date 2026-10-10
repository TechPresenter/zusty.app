/// Every named route in the app, mapped to the Stitch screen it renders.
///
/// Kept as plain constants so `context.go(Routes.wallet)` is checked at compile
/// time and deep links from push notifications resolve against one list.
abstract final class Routes {
  // 01–03  Onboarding
  static const onboarding = '/onboarding';

  // 04–05  Auth
  static const login = '/login';
  static const signUp = '/signup';
  static const forgotPassword = '/forgot-password';
  static const otp = '/otp';

  // 06  Home (shell tab 1)
  static const home = '/home';

  // 07  Search (shell tab 2)
  static const search = '/search';

  // 31  Search results
  static const searchResults = '/search/results';

  // 08  Categories
  static const categories = '/categories';

  // 23  Explore creators
  static const explore = '/explore';

  // 25  Saved creators
  static const saved = '/saved';

  // 09  Creator profile
  static const creatorBase = '/creator';
  static String creator(String handle) => '$creatorBase/$handle';

  // 10  Creator gallery
  static String creatorGallery(String handle) => '$creatorBase/$handle/gallery';

  // 21  Creator reels
  static String creatorReels(String handle) => '$creatorBase/$handle/reels';

  // 22  Creator videos
  static String creatorVideos(String handle) => '$creatorBase/$handle/videos';

  // 11  New booking request
  static String newBooking(String handle) => '$creatorBase/$handle/book';

  // 14  My bookings (shell tab 4)
  static const bookings = '/bookings';

  // 12  Booking details
  static String booking(int id) => '$bookings/$id';

  // 15  Payment details
  static String payment(int id) => '$bookings/$id/payment';

  // 13  Messages (list) and chat thread
  static const messages = '/messages';
  static String chat(int conversationId) => '$messages/$conversationId';

  // 16  Wallet
  static const wallet = '/wallet';
  static const withdraw = '/wallet/withdraw';
  static const bankAccounts = '/wallet/bank-accounts';
  static const transactions = '/wallet/transactions';

  /// Receipt for a single ledger entry.
  static String transaction(int id) => '$transactions/$id';

  /// Escrow confirmation shown after a successful checkout.
  static String checkoutSuccess(int bookingId) =>
      '$bookings/$bookingId/payment/success';

  /// Creator-side availability calendar.
  static const availability = '/calendar';

  // -- Brand ----------------------------------------------------------------

  /// Brand dashboard — spend, campaigns, approvals.
  static const dashboard = '/dashboard';

  // -- Creator opportunities ---------------------------------------------------

  /// Open campaigns a creator can browse and apply to.
  static const creatorCampaigns = '/opportunities';
  static String opportunity(int id) => '$creatorCampaigns/$id';
  static String applyToOpportunity(int id) => '$creatorCampaigns/$id/apply';

  /// The creator's own campaign applications and where each one stands.
  static const applications = '/applications';

  /// Brand campaigns (one-to-many briefs), distinct from bookings.
  static const campaigns = '/campaigns';
  static const newCampaign = '/campaigns/new';
  static String campaign(int id) => '$campaigns/$id';
  static String editCampaign(int id) => '$campaigns/$id/edit';

  /// AI creator matching: on its own, or seeded from one campaign.
  static const aiMatch = '/ai/match';
  static String campaignMatches(int id) => '$campaigns/$id/matches';

  /// Business profile: company, GST, billing address.
  static const businessProfile = '/profile/business';

  /// GST invoices issued to the brand.
  static const invoices = '/invoices';
  static String invoice(int id) => '$invoices/$id';

  /// Deliverable review for a booking (approve / request changes / dispute).
  static String reviewDeliverables(int bookingId) =>
      '$bookings/$bookingId/review';

  /// Side-by-side creator comparison.
  static const compare = '/compare';

  // 17  Notifications
  static const notifications = '/notifications';

  // 24  Notification details
  static String notification(int id) => '$notifications/$id';

  // 18  Support and help
  static const support = '/support';

  // 27  Help & support chat (ticket thread)
  static String ticket(int id) => '$support/tickets/$id';
  static const newTicket = '/support/tickets/new';

  // 28  FAQs
  static const faqs = '/faqs';

  // 19  Edit profile
  static const profile = '/profile';
  static const editProfile = '/profile/edit';
  static const contacts = '/profile/contacts';
  static const creatorFields = '/profile/details';

  /// The creator's own packages, portfolio and linked accounts.
  static const myPackages = '/profile/packages';
  static const newPackage = '/profile/packages/new';
  static String editPackage(int id) => '$myPackages/$id';
  static const myPortfolio = '/profile/portfolio';
  static const newPortfolioItem = '/profile/portfolio/new';
  static const socialAccounts = '/profile/social';

  static const kyc = '/profile/kyc';
  static const kycDetails = '/profile/kyc/details';
  static const settings = '/settings';

  // Privacy & account management
  static const privacy = '/settings/privacy';
  static const privacyExport = '/settings/privacy/export';
  static const deleteAccount = '/settings/privacy/delete';

  /// The accounts the user blocked, with Unblock.
  static const blockedUsers = '/settings/privacy/blocked';

  /// The goodbye screen after an account is deleted. Reachable signed out,
  /// because by the time it shows, the session is gone.
  static const accountDeleted = '/account-deleted';

  // 20  Menu / more (shell tab 5)
  static const menu = '/menu';

  // 26  Invite and earn
  static const invite = '/invite';

  /// CMS pages (Terms, Privacy, About) rendered from the admin panel's content.
  static String page(String slug) => '/pages/$slug';

  /// The blocking screen shown when `/app/config` reports this build is below
  /// the operator's minimum version. Deliberately outside
  /// [registeredDestinations] — nothing may navigate *to* it, the router puts
  /// you there.
  static const updateRequired = '/update-required';

  /// Every top-level destination this build actually registers.
  ///
  /// The admin's menu builder lets an operator point an entry at any path they
  /// like, and an installed app can be older than the panel that configured it.
  /// Navigating to a path GoRouter does not know throws, so a menu target is
  /// checked against this list first — the seeded drawer shipped '/favorites'
  /// and '/settings' for months, neither of which is a route here.
  ///
  /// Keep this in step with app_router.dart. A path listed here that is not
  /// registered there is worse than one that is missing: it turns a graceful
  /// "not available" into a crash.
  static const registeredDestinations = <String>[
    home,
    search,
    searchResults,
    categories,
    explore,
    saved,
    bookings,
    messages,
    wallet,
    settings,
    privacy,
    privacyExport,
    deleteAccount,
    blockedUsers,
    availability,
    dashboard,
    creatorCampaigns,
    applications,
    campaigns,
    newCampaign,
    aiMatch,
    businessProfile,
    invoices,
    compare,
    notifications,
    support,
    newTicket,
    faqs,
    editProfile,
    contacts,
    creatorFields,
    myPackages,
    myPortfolio,
    socialAccounts,
    kyc,
    kycDetails,
    invite,
    menu,
    creatorBase,
  ];

  /// Bare screen names the admin panel accepts as a target, mapped to the path
  /// this build registers.
  ///
  /// The menu builder asks for a path, but the popup editor's "Button target"
  /// is free text and the seeded popups use short names — `referral`, `kyc`.
  /// Only names whose destination is genuinely the same screen belong here; an
  /// unknown name still resolves to null so the caller can say so.
  static const _screenAliases = <String, String>{
    'referral': invite,
    'invite': invite,
    'kyc': kyc,
    'verification': kyc,
    'profile': editProfile,
    'help': support,
    // Wallet sub-screens are sheets on the wallet, not routes of their own.
    // An admin menu entry aimed at one used to be "registered" here with no
    // GoRoute behind it, which crashed on tap; it now opens the wallet.
    'wallet/withdraw': wallet,
    'wallet/bank-accounts': wallet,
    'wallet/transactions': wallet,
    'faq': faqs,
  };

  /// The in-app route for an admin-configured menu target, or null when this
  /// build has no such screen.
  ///
  /// Returning null rather than a fallback is deliberate: silently sending
  /// someone to the wrong screen because their app is out of date is worse
  /// than telling them the entry needs an update.
  static String? resolveMenuTarget(String? path) {
    if (path == null || path.isEmpty) return null;

    final alias = _screenAliases[path.replaceFirst('/', '').toLowerCase()];
    if (alias != null) return alias;

    final normalised = path.startsWith('/') ? path : '/$path';

    // A CMS page is always resolvable: the slug is looked up at read time, so
    // any '/pages/...' target works without this build knowing the page.
    if (normalised.startsWith('/pages/')) return normalised;

    for (final destination in registeredDestinations) {
      if (normalised == destination) return normalised;

      // A sub-path of a registered destination — '/bookings/12',
      // '/creator/some-handle' — is resolvable too, since the router matches
      // those by pattern.
      if (normalised.startsWith('$destination/')) return normalised;
    }

    return null;
  }

  /// Turns a backend `action_url` from a notification into an in-app route.
  /// Falls back to the notifications list when the path is not recognised.
  static String resolveDeepLink(String? actionUrl) {
    if (actionUrl == null || actionUrl.isEmpty) return notifications;
    final path = actionUrl.startsWith('/') ? actionUrl : '/$actionUrl';

    const known = [
      bookings,
      wallet,
      messages,
      notifications,
      explore,
      search,
      profile,
      support,
      creatorBase,
      home,
      creatorCampaigns,
      applications,
    ];

    return known.any(path.startsWith) ? path : notifications;
  }
}
