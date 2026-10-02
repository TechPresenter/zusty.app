import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:go_router/go_router.dart';

import '../features/auth/login_screen.dart';
import '../features/auth/otp_screen.dart';
import '../features/auth/signup_screen.dart';
import '../features/billing/invoices_screen.dart';
import '../features/booking/booking_details_screen.dart';
import '../features/booking/checkout_success_screen.dart';
import '../features/booking/deliverable_review_screen.dart';
import '../shared/motion/branch_fade_container.dart';
import '../features/ai/ai_match_screen.dart';
import '../features/campaigns/campaign_detail_screen.dart';
import '../features/campaigns/campaign_form_screen.dart';
import '../features/campaigns/campaigns_screen.dart';
import '../features/dashboard/brand_dashboard_screen.dart';
import '../features/discovery/compare_creators_screen.dart';
import '../features/profile/business_profile_screen.dart';
import '../features/booking/my_bookings_screen.dart';
import '../features/booking/new_booking_screen.dart';
import '../features/booking/payment_screen.dart';
import '../features/calendar/availability_calendar_screen.dart';
import '../features/chat/chat_screen.dart';
import '../features/chat/messages_screen.dart';
import '../features/creator/creator_gallery_screen.dart';
import '../features/creator/creator_profile_screen.dart';
import '../features/opportunities/apply_screen.dart';
import '../features/opportunities/my_applications_screen.dart';
import '../features/opportunities/opportunities_screen.dart';
import '../features/opportunities/opportunity_detail_screen.dart';
import '../features/discovery/categories_screen.dart';
import '../features/discovery/explore_screen.dart';
import '../features/discovery/saved_creators_screen.dart';
import '../features/home/home_screen.dart';
import '../features/kyc/kyc_details_screen.dart';
import '../features/kyc/kyc_status_screen.dart';
import '../features/notifications/notifications_screen.dart';
import '../features/onboarding/onboarding_screen.dart';
import '../features/profile/edit_profile_screen.dart';
import '../features/profile/contacts_screen.dart';
import '../features/profile/creator_fields_screen.dart';
import '../features/profile/menu_screen.dart';
import '../features/privacy/account_deleted_screen.dart';
import '../features/privacy/data_export_screen.dart';
import '../features/privacy/delete_account_screen.dart';
import '../features/privacy/privacy_center_screen.dart';
import '../features/referral/invite_earn_screen.dart';
import '../features/search/search_results_screen.dart';
import '../features/search/search_screen.dart';
import '../features/settings/settings_screen.dart';
import '../features/shell/app_shell.dart';
import '../features/shell/force_update_screen.dart';
import '../features/support/faqs_screen.dart';
import '../features/support/new_ticket.dart';
import '../features/support/support_screen.dart';
import '../features/support/ticket_chat_screen.dart';
import '../features/wallet/transaction_detail_screen.dart';
import '../features/wallet/wallet_screen.dart';
import '../providers/app_config_provider.dart';
import '../providers/auth_provider.dart';
import '../providers/privacy_provider.dart';
import 'routes.dart';

final _rootKey = GlobalKey<NavigatorState>(debugLabel: 'root');
final _homeKey = GlobalKey<NavigatorState>(debugLabel: 'home');
final _searchKey = GlobalKey<NavigatorState>(debugLabel: 'search');
final _bookingsKey = GlobalKey<NavigatorState>(debugLabel: 'bookings');
final _menuKey = GlobalKey<NavigatorState>(debugLabel: 'menu');

/// The app's GoRouter, gated on [authProvider].
///
/// Four persistent branches sit behind the bottom navigation shell (Home,
/// Search, Bookings, Profile); everything else pushes on top of the root
/// navigator so it covers the bar.
final routerProvider = Provider<GoRouter>((ref) {
  final auth = ref.watch(authProvider);

  // Watched as a bool rather than the whole UpdateGate on purpose: this
  // provider rebuilds the GoRouter, and the config object is a fresh instance
  // after every resume even when nothing in it changed.
  final mustUpdate = ref.watch(mustUpdateProvider);

  return GoRouter(
    navigatorKey: _rootKey,
    // Read, not watched: the router is rebuilt on every sign-in change, and
    // the sign-out that follows an account deletion should land on the
    // goodbye screen rather than the sign-in form.
    initialLocation: ref.read(accountDeletedProvider) ? Routes.accountDeleted : Routes.home,
    debugLogDiagnostics: false,

    redirect: (context, state) {
      final location = state.matchedLocation;

      // The minimum-version gate outranks everything, including the auth
      // stack: a build the operator has retired must not be able to sign in,
      // take a booking, or move money. Cleared the moment `/app/config` stops
      // reporting force_update, so lifting it in the panel is enough.
      if (mustUpdate) {
        return location == Routes.updateRequired ? null : Routes.updateRequired;
      }

      if (location == Routes.updateRequired) {
        return auth.isAuthenticated ? Routes.home : Routes.login;
      }

      // Hold on the splash until the stored session has been checked.
      if (auth.stage == AuthStage.unknown) return null;

      // The goodbye after an account deletion is shown signed out: the
      // session ended with the account. Anyone signed in has no business here.
      if (location == Routes.accountDeleted) {
        return auth.isAuthenticated ? Routes.home : null;
      }

      // Just deleted: wherever the rebuilt router starts (on the web that is
      // the address the app was first opened on, not initialLocation), the
      // signed-out user lands on the goodbye until they leave it.
      if (!auth.isAuthenticated && ref.read(accountDeletedProvider)) {
        return Routes.accountDeleted;
      }

      final onboarding = location == Routes.onboarding;
      final inAuthStack = location == Routes.login ||
          location == Routes.signUp ||
          location == Routes.forgotPassword ||
          location == Routes.otp;

      if (auth.stage == AuthStage.onboarding) {
        return onboarding ? null : Routes.onboarding;
      }

      if (!auth.isAuthenticated) {
        return inAuthStack ? null : Routes.login;
      }

      // Signed in — keep the auth stack and onboarding out of reach.
      if (inAuthStack || onboarding) return Routes.home;

      return null;
    },

    routes: [
      // -- Minimum-version gate --------------------------------------------
      GoRoute(
        path: Routes.updateRequired,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const ForceUpdateScreen(),
      ),

      // -- Onboarding and auth -------------------------------------------
      GoRoute(
        path: Routes.onboarding,
        builder: (_, _) => const OnboardingScreen(),
      ),
      GoRoute(
        path: Routes.login,
        builder: (_, _) => const LoginScreen(),
      ),
      GoRoute(
        path: Routes.signUp,
        builder: (_, _) => const SignUpScreen(),
      ),
      GoRoute(
        path: Routes.forgotPassword,
        builder: (_, _) => const ForgotPasswordScreen(),
      ),
      GoRoute(
        path: Routes.otp,
        builder: (_, _) => const OtpScreen(),
      ),

      // -- Bottom-nav shell ------------------------------------------------
      // Every tab stays mounted as in an IndexedStack; the container adds a
      // quick fade-through when the tab changes.
      StatefulShellRoute(
        builder: (_, _, shell) => AppShell(navigationShell: shell),
        navigatorContainerBuilder: (_, shell, children) => BranchFadeContainer(
          currentIndex: shell.currentIndex,
          children: children,
        ),
        branches: [
          StatefulShellBranch(
            navigatorKey: _homeKey,
            routes: [
              GoRoute(
                path: Routes.home,
                builder: (_, _) => const HomeScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            navigatorKey: _searchKey,
            routes: [
              GoRoute(
                path: Routes.search,
                builder: (_, _) => const SearchScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            navigatorKey: _bookingsKey,
            routes: [
              GoRoute(
                path: Routes.bookings,
                builder: (_, _) => const MyBookingsScreen(),
              ),
            ],
          ),
          StatefulShellBranch(
            navigatorKey: _menuKey,
            routes: [
              GoRoute(
                path: Routes.menu,
                builder: (_, _) => const MenuScreen(),
              ),
            ],
          ),
        ],
      ),

      // -- Discovery ---------------------------------------------------------
      GoRoute(
        path: Routes.searchResults,
        parentNavigatorKey: _rootKey,
        builder: (_, state) => SearchResultsScreen(
          categorySlug: state.uri.queryParameters['category'],
        ),
      ),
      GoRoute(
        path: Routes.categories,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const CategoriesScreen(),
      ),
      GoRoute(
        path: Routes.explore,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const ExploreScreen(),
      ),
      GoRoute(
        path: Routes.saved,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const SavedCreatorsScreen(),
      ),

      // -- Creator -----------------------------------------------------------
      GoRoute(
        path: '${Routes.creatorBase}/:handle',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            CreatorProfileScreen(handle: state.pathParameters['handle']!),
        routes: [
          GoRoute(
            path: 'gallery',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => CreatorGalleryScreen(
              handle: state.pathParameters['handle']!,
            ),
          ),
          GoRoute(
            path: 'reels',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => CreatorGalleryScreen(
              handle: state.pathParameters['handle']!,
              initialKind: 'reel',
              title: 'Reels',
            ),
          ),
          GoRoute(
            path: 'videos',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => CreatorGalleryScreen(
              handle: state.pathParameters['handle']!,
              initialKind: 'video',
              title: 'Videos',
            ),
          ),
          GoRoute(
            path: 'book',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => NewBookingScreen(
              handle: state.pathParameters['handle']!,
              packageId: int.tryParse(
                state.uri.queryParameters['package'] ?? '',
              ),
            ),
          ),
        ],
      ),

      // -- Bookings ----------------------------------------------------------
      GoRoute(
        path: '${Routes.bookings}/:id',
        parentNavigatorKey: _rootKey,
        builder: (_, state) => BookingDetailsScreen(
          bookingId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
        ),
        routes: [
          GoRoute(
            path: 'review',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => DeliverableReviewScreen(
              bookingId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
          GoRoute(
            path: 'payment',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => PaymentScreen(
              bookingId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
            routes: [
              GoRoute(
                path: 'success',
                parentNavigatorKey: _rootKey,
                builder: (_, state) => CheckoutSuccessScreen(
                  bookingId:
                      int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                ),
              ),
            ],
          ),
        ],
      ),

      // -- Messaging ---------------------------------------------------------
      GoRoute(
        path: Routes.messages,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const MessagesScreen(),
        routes: [
          GoRoute(
            path: ':id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => ChatScreen(
              conversationId:
                  int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
        ],
      ),

      // -- Wallet ------------------------------------------------------------
      GoRoute(
        path: Routes.wallet,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const WalletScreen(),
        routes: [
          GoRoute(
            path: 'transactions/:id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => TransactionDetailScreen(
              transactionId:
                  int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
        ],
      ),

      // -- Availability calendar ---------------------------------------------
      GoRoute(
        path: Routes.availability,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const AvailabilityCalendarScreen(),
      ),

      // -- Creator: opportunities --------------------------------------------
      // The public brief board, one brief, the application form, and the
      // creator's own applications. Pushed over the shell like every other
      // detail screen; the screens themselves turn a brand or a signed-out
      // session away politely.
      GoRoute(
        path: Routes.creatorCampaigns,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const OpportunitiesScreen(),
        routes: [
          GoRoute(
            path: ':id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => OpportunityDetailScreen(
              campaignId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
            routes: [
              GoRoute(
                path: 'apply',
                parentNavigatorKey: _rootKey,
                builder: (_, state) => ApplyScreen(
                  campaignId:
                      int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                ),
              ),
            ],
          ),
        ],
      ),
      GoRoute(
        path: Routes.applications,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const MyApplicationsScreen(),
      ),

      // -- Brand: dashboard --------------------------------------------------
      GoRoute(
        path: Routes.dashboard,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const BrandDashboardScreen(),
      ),

      // -- Brand: campaigns --------------------------------------------------
      // `new` is declared before `:id` so it is not swallowed by the param.
      GoRoute(
        path: Routes.campaigns,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const CampaignsScreen(),
        routes: [
          GoRoute(
            path: 'new',
            parentNavigatorKey: _rootKey,
            builder: (_, _) => const CampaignFormScreen(),
          ),
          GoRoute(
            path: ':id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => CampaignDetailScreen(
              campaignId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
            routes: [
              GoRoute(
                path: 'edit',
                parentNavigatorKey: _rootKey,
                builder: (_, state) => CampaignFormScreen(
                  campaignId:
                      int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                ),
              ),
              GoRoute(
                path: 'matches',
                parentNavigatorKey: _rootKey,
                builder: (_, state) => AiMatchScreen(
                  campaignId:
                      int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
                ),
              ),
            ],
          ),
        ],
      ),

      // -- Brand: AI creator matching -----------------------------------------
      GoRoute(
        path: Routes.aiMatch,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const AiMatchScreen(),
      ),

      // -- Brand: invoices ---------------------------------------------------
      GoRoute(
        path: Routes.invoices,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const InvoicesScreen(),
        routes: [
          GoRoute(
            path: ':id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => InvoiceDetailScreen(
              invoiceId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
        ],
      ),

      // -- Brand: business profile -------------------------------------------
      GoRoute(
        path: Routes.businessProfile,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const BusinessProfileScreen(),
      ),

      // -- KYC and business verification -------------------------------------
      GoRoute(
        path: Routes.kyc,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const KycStatusScreen(),
      ),
      GoRoute(
        path: Routes.kycDetails,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const KycDetailsScreen(),
      ),

      // -- Brand: creator comparison -----------------------------------------
      GoRoute(
        path: Routes.compare,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const CompareCreatorsScreen(),
      ),

      // -- Notifications -----------------------------------------------------
      GoRoute(
        path: Routes.notifications,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const NotificationsScreen(),
        routes: [
          GoRoute(
            path: ':id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => NotificationDetailsScreen(
              notificationId:
                  int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
        ],
      ),

      // -- Support -----------------------------------------------------------
      GoRoute(
        path: Routes.support,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const SupportScreen(),
        routes: [
          // Before `tickets/:id`, or "new" is taken for an id and the screen
          // opens an empty thread.
          GoRoute(
            path: 'tickets/new',
            parentNavigatorKey: _rootKey,
            builder: (_, _) => const NewTicketScreen(),
          ),
          GoRoute(
            path: 'tickets/:id',
            parentNavigatorKey: _rootKey,
            builder: (_, state) => TicketChatScreen(
              ticketId: int.tryParse(state.pathParameters['id'] ?? '') ?? 0,
            ),
          ),
        ],
      ),
      GoRoute(
        path: Routes.faqs,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const FaqsScreen(),
      ),

      // -- Profile -----------------------------------------------------------
      GoRoute(
        path: Routes.editProfile,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const EditProfileScreen(),
      ),
      GoRoute(
        path: Routes.contacts,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const ContactsScreen(),
      ),
      GoRoute(
        path: Routes.creatorFields,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const CreatorFieldsScreen(),
      ),
      GoRoute(
        path: Routes.settings,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const SettingsScreen(),
      ),
      GoRoute(
        path: Routes.invite,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const InviteEarnScreen(),
      ),

      // -- Privacy & account management --------------------------------------
      GoRoute(
        path: Routes.privacy,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const PrivacyCenterScreen(),
      ),
      GoRoute(
        path: Routes.privacyExport,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const DataExportScreen(),
      ),
      GoRoute(
        path: Routes.deleteAccount,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const DeleteAccountScreen(),
      ),
      GoRoute(
        path: Routes.accountDeleted,
        parentNavigatorKey: _rootKey,
        builder: (_, _) => const AccountDeletedScreen(),
      ),

      // -- CMS ---------------------------------------------------------------
      GoRoute(
        path: '/pages/:slug',
        parentNavigatorKey: _rootKey,
        builder: (_, state) =>
            CmsPageScreen(slug: state.pathParameters['slug']!),
      ),
    ],

    errorBuilder: (context, state) => _RouteNotFound(location: state.uri.path),
  );
});

class _RouteNotFound extends StatelessWidget {
  const _RouteNotFound({required this.location});

  final String location;

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                const Icon(Icons.explore_off_rounded, size: 48),
                const SizedBox(height: 16),
                const Text('That page does not exist'),
                const SizedBox(height: 4),
                Text(location, textAlign: TextAlign.center),
                const SizedBox(height: 24),
                FilledButton(
                  onPressed: () => context.go(Routes.home),
                  child: const Text('Go home'),
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
