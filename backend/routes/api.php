<?php

declare(strict_types=1);

/**
 * REST API routes — version v1.
 *
 * Middleware vocabulary:
 *   maintenance      — 503 while maintenance mode is on
 *   throttle:N,S,key — rate limit N requests per S seconds in bucket `key`
 *   auth             — a valid JWT is required
 *   auth.optional    — attach the user when a token is present, else continue
 *   brand / creator  — restrict to one side of the marketplace
 *   verified         — require a verified email or phone
 *   cron             — shared-secret guard for scheduled triggers
 */

use Zuste\Controllers\Api\AccountController;
use Zuste\Controllers\Api\AiController;
use Zuste\Controllers\Api\AuthController;
use Zuste\Controllers\Api\BookingController;
use Zuste\Controllers\Api\CampaignController;
use Zuste\Controllers\Api\ContactController;
use Zuste\Controllers\Api\ContentController;
use Zuste\Controllers\Api\CreatorController;
use Zuste\Controllers\Api\CreatorEngagementController;
use Zuste\Controllers\Api\CreatorProfileFieldController;
use Zuste\Controllers\Api\KycController;
use Zuste\Controllers\Api\MessageController;
use Zuste\Controllers\Api\PrivacyController;
use Zuste\Controllers\Api\ReviewController;
use Zuste\Controllers\Api\SystemController;
use Zuste\Controllers\Api\TaxonomyController;
use Zuste\Controllers\Api\WalletController;
use Zuste\Controllers\Web\PageController as WebPageController;
use Zuste\Core\Router;

return static function (Router $router): void {

    // -----------------------------------------------------------------
    // Unversioned health check (kept stable for uptime monitors)
    // -----------------------------------------------------------------
    $router->get('/api/health', [ContentController::class, 'health']);

    $router->group(['prefix' => '/api/v1'], static function (Router $router): void {

        // -------------------------------------------------------------
        // Open endpoints
        // -------------------------------------------------------------
        $router->get('/health', [ContentController::class, 'health']);
        // `auth.optional` matters here: /app/config resolves feature flags and
        // popups by audience. Without it the bearer token is never read, every
        // caller is evaluated as an anonymous brand, and creator-targeted flags
        // and popups become undeliverable.
        $router->get('/app/config', [ContentController::class, 'appConfig'])
            ->middleware('auth.optional');
        $router->get('/settings', [ContentController::class, 'settings']);

        // Every option list the apps render, in one cacheable payload.
        $router->get('/app/taxonomies', [TaxonomyController::class, 'index']);
        $router->get('/app/onboarding', [TaxonomyController::class, 'onboarding']);
        $router->get('/app/taxonomies/{key:[a-z_]+}', [TaxonomyController::class, 'show']);

        // Webhooks authenticate by signature, not by token.
        $router->post('/webhooks/razorpay', [SystemController::class, 'razorpayWebhook']);
        $router->post('/webhooks/stripe', [SystemController::class, 'stripeWebhook']);
        $router->post('/webhooks/cashfree', [SystemController::class, 'cashfreeWebhook']);

        // A data-export download link. The token in the query IS the
        // credential — it is what lets a browser, or the link in the email,
        // fetch the archive without a bearer header — so it is long, bound to
        // one export, hashed at rest, short-lived and capped in uses.
        $router->get('/privacy/exports/download', [PrivacyController::class, 'download'])
            ->middleware('throttle:30,3600,privacy-download');

        // -------------------------------------------------------------
        // Authentication
        // -------------------------------------------------------------
        $router->group(['prefix' => '/auth', 'middleware' => ['maintenance']], static function (Router $router): void {
            $router->post('/register', [AuthController::class, 'register'])->middleware('throttle:5,900,register');
            $router->post('/login', [AuthController::class, 'login'])->middleware('throttle:10,300,login');
            $router->post('/firebase', [AuthController::class, 'firebase'])->middleware('throttle:15,300,login');
            $router->post('/refresh', [AuthController::class, 'refresh'])->middleware('throttle:30,300,refresh');
            $router->post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,900,password');
            $router->post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,900,password');
            $router->post('/otp/send', [AuthController::class, 'sendOtp'])->middleware('throttle:5,600,otp');
            $router->post('/otp/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:10,600,otp');
            $router->post('/guest', [AuthController::class, 'guest'])->middleware('throttle:10,300,login');

            $router->get('/me', [AuthController::class, 'me'])->middleware('auth');
            $router->post('/guest/upgrade', [AuthController::class, 'upgradeGuest'])->middleware('auth', 'throttle:5,900,register');
            $router->post('/logout', [AuthController::class, 'logout'])->middleware('auth');
            $router->post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('auth');
            // A guest has no password to change; upgrading is the path.
            $router->post('/change-password', [AuthController::class, 'changePassword'])->middleware('auth', 'no-guest', 'throttle:5,900,password');
            $router->get('/sessions', [AuthController::class, 'sessions'])->middleware('auth');
            $router->delete('/sessions/{id:\d+}', [AuthController::class, 'revokeSession'])->middleware('auth');
            $router->delete('/account', [AuthController::class, 'deleteAccount'])->middleware('auth');
        });

        // -------------------------------------------------------------
        // Public content and discovery
        // -------------------------------------------------------------
        $router->group(['middleware' => ['maintenance', 'throttle', 'auth.optional']], static function (Router $router): void {
            $router->get('/home', [ContentController::class, 'home']);
            $router->get('/explore', [ContentController::class, 'explore']);

            $router->get('/categories', [CreatorController::class, 'categories']);
            $router->get('/creators', [CreatorController::class, 'index']);
            $router->get('/creators/{handle}', [CreatorController::class, 'show']);
            $router->get('/creators/{id:\d+}/portfolio', [CreatorController::class, 'portfolio']);
            $router->get('/creators/{id:\d+}/reviews', [CreatorController::class, 'reviews']);
            $router->get('/creators/{id:\d+}/availability', [CreatorController::class, 'availability']);

            // Extended profile: skills, education, experience, awards, work
            // history, working hours, FAQs and the address/timezone fields.
            // Addressed by id like its portfolio/reviews siblings above, which
            // is what keeps it clear of the single-segment /creators/{handle}.
            $router->get('/creators/{id:\d+}/fields', [CreatorProfileFieldController::class, 'publicFields']);

            // Share sheet and QR payload. Public because sharing a profile is
            // something a signed-out visitor does.
            $router->get('/creators/{id:\d+}/share', [CreatorEngagementController::class, 'share']);

            // The catalogue of countable engagement events, so a client renders
            // the right buttons without holding a list of its own.
            $router->get('/engagement/options', [CreatorEngagementController::class, 'options']);

            // Recording a share, a QR download, a portfolio download or a
            // social-link click. In this group because a signed-out visitor
            // does all four — `auth.optional` attaches the actor when there is
            // one, which is what lets the service refuse to count a creator's
            // engagement with their own profile.
            //
            // Throttled per IP: it is an unauthenticated counter, so without a
            // ceiling anyone could inflate any creator's numbers.
            $router->post('/creators/{id:\d+}/engagement', [CreatorEngagementController::class, 'record'])
                ->middleware('throttle:60,3600,engagement');

            $router->get('/search/suggestions', [CreatorController::class, 'searchSuggestions']);

            // What a prospective user will need to get verified.
            $router->get('/kyc/requirements', [KycController::class, 'requirements']);

            $router->get('/pages', [ContentController::class, 'pages']);
            $router->get('/pages/{slug}', [ContentController::class, 'page']);
            $router->get('/faqs', [ContentController::class, 'faqs']);
            $router->post('/faqs/{id:\d+}/helpful', [ContentController::class, 'rateFaq']);
            $router->get('/blogs', [ContentController::class, 'blogs']);
            $router->get('/blogs/{slug}', [ContentController::class, 'blog']);
            $router->get('/testimonials', [ContentController::class, 'testimonials']);
            $router->post('/contact', [ContentController::class, 'contact']);
        });

        // -------------------------------------------------------------
        // Authenticated: the user's own account
        // -------------------------------------------------------------
        // A guest has no profile, no KYC, no creator record and no wallet, so
        // the whole group is closed to them.
        $router->group(['prefix' => '/me', 'middleware' => ['maintenance', 'auth', 'no-guest', 'throttle']], static function (Router $router): void {
            $router->get('', [AccountController::class, 'profile']);
            $router->put('', [AccountController::class, 'updateProfile']);
            $router->post('', [AccountController::class, 'updateProfile']); // multipart avatar upload
            $router->put('/notification-preferences', [AccountController::class, 'updateNotificationPreferences']);
            $router->post('/devices', [AccountController::class, 'registerDevice']);
            $router->post('/upload', [AccountController::class, 'upload'])->middleware('throttle:30,600,upload');

            // ---- Privacy: data export and account deletion ------------
            //
            // Both actions re-authenticate — the password, or a one-time code
            // sent to the account's own email or phone — because a stolen
            // session alone must not be enough to take a copy of everything,
            // or to erase it.
            $router->get('/privacy', [PrivacyController::class, 'overview']);
            $router->post('/privacy/verification-code', [PrivacyController::class, 'sendCode'])
                ->middleware('throttle:5,900,privacy-code');
            $router->post('/privacy/exports', [PrivacyController::class, 'requestExport'])
                ->middleware('throttle:5,3600,privacy-export');
            $router->post('/privacy/delete-account', [PrivacyController::class, 'deleteAccount'])
                ->middleware('throttle:10,3600,privacy-delete');

            // ---- Contact points and verified changes ------------------
            //
            // Throttled hard: every write here either sends an OTP or spends a
            // guess at one, so an unthrottled endpoint is both an SMS bill and
            // a brute-force surface. The service counts attempts per request as
            // well; this is the per-account ceiling above it.
            $router->get('/contacts', [ContactController::class, 'index']);
            $router->post('/contacts/alternates', [ContactController::class, 'addAlternate'])
                ->middleware('throttle:10,3600,contact');
            $router->post('/contacts/alternates/{id:\d+}/verify', [ContactController::class, 'reverifyAlternate'])
                ->middleware('throttle:20,3600,contact_verify');
            $router->delete('/contacts/alternates/{id:\d+}', [ContactController::class, 'removeAlternate']);
            $router->post('/contacts/change', [ContactController::class, 'requestChange'])
                ->middleware('throttle:5,3600,contact');
            $router->post('/contacts/requests/{id:\d+}/resend', [ContactController::class, 'resendCode'])
                ->middleware('throttle:5,600,contact_resend');
            $router->post('/contacts/requests/{id:\d+}/verify', [ContactController::class, 'confirmChange'])
                ->middleware('throttle:20,3600,contact_verify');
            $router->post('/contacts/requests/{id:\d+}/cancel', [ContactController::class, 'cancelRequest']);

            // Legacy single-document KYC. Reading still works for older builds;
            // submitting is retired (410) — it stored identity documents in the
            // public uploads tree and bypassed the review workflow.
            $router->get('/kyc/legacy', [AccountController::class, 'kycStatus']);
            $router->post('/kyc/legacy', [AccountController::class, 'submitKyc'])->middleware('throttle:5,3600,kyc');

            // Full verification module.
            $router->get('/kyc', [KycController::class, 'show']);
            $router->put('/kyc/details', [KycController::class, 'saveDetails']);
            $router->post('/kyc/details', [KycController::class, 'saveDetails']);
            $router->post('/kyc/documents', [KycController::class, 'uploadDocument'])
                ->middleware('throttle:40,3600,kyc_upload');
            $router->delete('/kyc/documents/{id:\d+}', [KycController::class, 'deleteDocument']);
            $router->get('/kyc/documents/{id:\d+}/download', [KycController::class, 'downloadDocument']);
            // Same signed-token rules as the download, served inline for display.
            $router->get('/kyc/documents/{id:\d+}/preview', [KycController::class, 'previewDocument']);
            $router->post('/kyc/submit', [KycController::class, 'submit'])
                ->middleware('throttle:10,3600,kyc_submit');
            $router->get('/kyc/timeline', [KycController::class, 'timeline']);

            $router->get('/favorites', [CreatorController::class, 'favorites']);
            $router->get('/referrals', [AccountController::class, 'referrals']);
            $router->get('/reviews', [ReviewController::class, 'received']);

            // Creator self-management
            $router->get('/creator', [CreatorController::class, 'myProfile'])->middleware('creator');
            $router->put('/creator', [CreatorController::class, 'updateProfile'])->middleware('creator');
            $router->get('/creator/analytics', [CreatorController::class, 'analytics'])->middleware('creator');
            $router->get('/creator/completion', [CreatorController::class, 'completion'])->middleware('creator');
            $router->post('/creator/packages', [CreatorController::class, 'createPackage'])->middleware('creator');
            $router->put('/creator/packages/{id:\d+}', [CreatorController::class, 'updatePackage'])->middleware('creator');
            $router->delete('/creator/packages/{id:\d+}', [CreatorController::class, 'deletePackage'])->middleware('creator');
            $router->post('/creator/portfolio', [CreatorController::class, 'addPortfolioItem'])->middleware('creator');
            $router->delete('/creator/portfolio/{id:\d+}', [CreatorController::class, 'deletePortfolioItem'])->middleware('creator');
            $router->put('/creator/social', [CreatorController::class, 'upsertSocialAccount'])->middleware('creator');
            // The spec's other two social verbs. Neither existed, so a creator
            // could link the wrong account and never remove it, and the
            // "reorder" the schema now supports had no way to be invoked.
            $router->delete('/creator/social/{id:\d+}', [CreatorController::class, 'deleteSocialAccount'])->middleware('creator');
            $router->put('/creator/social/order', [CreatorController::class, 'reorderSocialAccounts'])->middleware('creator');
            $router->put('/creator/availability', [CreatorController::class, 'setAvailability'])->middleware('creator');

            // ---- Extended profile fields -----------------------------
            //
            // Every literal segment is declared BEFORE the pattern that would
            // otherwise swallow it: `/order` and `/primary` are matched by
            // `{id:\d+}`-free patterns like `{section}`, so a late `/order`
            // would be read as a section named "order" and 422 instead of
            // reordering anything.
            $router->get('/creator/fields', [CreatorProfileFieldController::class, 'index'])->middleware('creator');
            $router->put('/creator/fields/details', [CreatorProfileFieldController::class, 'updateDetails'])->middleware('creator');

            $router->get('/creator/fields/terms', [CreatorProfileFieldController::class, 'terms'])->middleware('creator');
            $router->put('/creator/fields/terms/{section}/order', [CreatorProfileFieldController::class, 'reorderTerms'])->middleware('creator');
            $router->put('/creator/fields/terms/{section}/primary', [CreatorProfileFieldController::class, 'setPrimaryTerm'])->middleware('creator');
            $router->put('/creator/fields/terms/{section}', [CreatorProfileFieldController::class, 'syncTerms'])->middleware('creator');
            $router->post('/creator/fields/terms/{section}', [CreatorProfileFieldController::class, 'attachTerm'])->middleware('creator');
            $router->delete('/creator/fields/terms/{section}/{value}', [CreatorProfileFieldController::class, 'detachTerm'])->middleware('creator');

            $router->get('/creator/credentials', [CreatorProfileFieldController::class, 'credentials'])->middleware('creator');
            $router->post('/creator/credentials', [CreatorProfileFieldController::class, 'createCredential'])->middleware('creator');
            $router->put('/creator/credentials/order', [CreatorProfileFieldController::class, 'reorderCredentials'])->middleware('creator');
            $router->put('/creator/credentials/{id:\d+}', [CreatorProfileFieldController::class, 'updateCredential'])->middleware('creator');
            $router->delete('/creator/credentials/{id:\d+}', [CreatorProfileFieldController::class, 'deleteCredential'])->middleware('creator');

            $router->get('/creator/experience', [CreatorProfileFieldController::class, 'experience'])->middleware('creator');
            $router->post('/creator/experience', [CreatorProfileFieldController::class, 'createExperience'])->middleware('creator');
            $router->put('/creator/experience/order', [CreatorProfileFieldController::class, 'reorderExperience'])->middleware('creator');
            $router->put('/creator/experience/{id:\d+}', [CreatorProfileFieldController::class, 'updateExperience'])->middleware('creator');
            $router->delete('/creator/experience/{id:\d+}', [CreatorProfileFieldController::class, 'deleteExperience'])->middleware('creator');

            $router->get('/creator/working-hours', [CreatorProfileFieldController::class, 'workingHours'])->middleware('creator');
            $router->put('/creator/working-hours', [CreatorProfileFieldController::class, 'setWorkingHours'])->middleware('creator');
            $router->post('/creator/working-hours', [CreatorProfileFieldController::class, 'addWorkingHour'])->middleware('creator');
            $router->put('/creator/working-hours/{weekday:\d+}/order', [CreatorProfileFieldController::class, 'reorderWorkingHours'])->middleware('creator');
            $router->put('/creator/working-hours/{id:\d+}', [CreatorProfileFieldController::class, 'updateWorkingHour'])->middleware('creator');
            $router->delete('/creator/working-hours/{id:\d+}', [CreatorProfileFieldController::class, 'deleteWorkingHour'])->middleware('creator');

            $router->get('/creator/faqs', [CreatorProfileFieldController::class, 'faqs'])->middleware('creator');
            $router->post('/creator/faqs', [CreatorProfileFieldController::class, 'createFaq'])->middleware('creator');
            $router->put('/creator/faqs/order', [CreatorProfileFieldController::class, 'reorderFaqs'])->middleware('creator');
            $router->put('/creator/faqs/{id:\d+}', [CreatorProfileFieldController::class, 'updateFaq'])->middleware('creator');
            $router->delete('/creator/faqs/{id:\d+}', [CreatorProfileFieldController::class, 'deleteFaq'])->middleware('creator');

            // ---- Visibility, sharing and engagement -------------------
            $router->get('/creator/share', [CreatorEngagementController::class, 'myShare'])->middleware('creator');
            $router->get('/creator/visibility', [CreatorEngagementController::class, 'visibility'])->middleware('creator');
            $router->put('/creator/visibility', [CreatorEngagementController::class, 'updateVisibility'])->middleware('creator');
            $router->get('/creator/preview', [CreatorEngagementController::class, 'preview'])->middleware('creator');
            $router->get('/creator/engagement/targets', [CreatorEngagementController::class, 'targets'])->middleware('creator');
            $router->get('/creator/engagement', [CreatorEngagementController::class, 'dashboard'])->middleware('creator');

            // ---- Campaign applications -------------------------------
            // The creator's side of the brief board: every campaign they were
            // invited to or applied for, with its current status. Reading only;
            // applying stays on POST /campaigns/{id}/apply behind `kyc`.
            $router->get('/applications', [CampaignController::class, 'myApplications'])->middleware('creator');
        });

        // -------------------------------------------------------------
        // Authenticated: interactions
        // -------------------------------------------------------------
        // Personalisation a guest is allowed to build up, because it is exactly
        // what survives when they upgrade — the guest row keeps its id, so these
        // rows are still theirs afterwards.
        $router->group(['middleware' => ['maintenance', 'auth', 'throttle']], static function (Router $router): void {
            $router->post('/creators/{id:\d+}/favorite', [CreatorController::class, 'toggleFavorite']);
            $router->post('/creators/{id:\d+}/follow', [CreatorController::class, 'toggleFollow']);
            $router->delete('/search/history', [CreatorController::class, 'clearSearchHistory']);
        });

        // AI writing and matching, configured in Admin -> Settings -> AI.
        // AiService applies the admin's switches, the monthly budget and the
        // per-account daily allowance; the throttle here only stops bursts.
        $router->group(['prefix' => '/ai', 'middleware' => ['maintenance', 'auth', 'no-guest', 'throttle']], static function (Router $router): void {
            $router->post('/bio', [AiController::class, 'bio'])->middleware('creator', 'throttle:10,60,ai');
            $router->post('/campaign-brief', [AiController::class, 'campaignBrief'])->middleware('brand', 'throttle:10,60,ai');
            $router->post('/creator-match', [AiController::class, 'creatorMatch'])->middleware('brand', 'throttle:10,60,ai');
        });

        // Everything transactional. `no-guest` sits on the GROUP rather than on
        // each route so a route added here later is guest-denied by default —
        // failing closed, which is the direction this particular mistake should
        // fail in.
        $router->group(['middleware' => ['maintenance', 'auth', 'no-guest', 'throttle']], static function (Router $router): void {
            // -------------------------------------------------------------
            // Brand campaigns
            //
            // A brand publishes a brief and manages applications; a creator
            // browses the public board and applies. Ownership is enforced in
            // BrandCampaignService, which 404s rather than 403s on someone
            // else's campaign so ids cannot be enumerated.
            // -------------------------------------------------------------
            $router->get('/campaigns', [CampaignController::class, 'index']);
            $router->post('/campaigns', [CampaignController::class, 'store'])
                ->middleware('brand', 'throttle:30,3600,campaign');
            $router->get('/campaigns/{id:\d+}', [CampaignController::class, 'show']);
            $router->put('/campaigns/{id:\d+}', [CampaignController::class, 'update'])
                ->middleware('brand');
            $router->delete('/campaigns/{id:\d+}', [CampaignController::class, 'destroy'])
                ->middleware('brand');
            $router->post('/campaigns/{id:\d+}/publish', [CampaignController::class, 'publish'])
                ->middleware('brand');
            $router->post('/campaigns/{id:\d+}/close', [CampaignController::class, 'close'])
                ->middleware('brand');
            $router->get('/campaigns/{id:\d+}/recipients', [CampaignController::class, 'recipients'])
                ->middleware('brand');
            $router->post('/campaigns/{id:\d+}/invite', [CampaignController::class, 'invite'])
                ->middleware('brand');
            $router->put('/campaigns/{id:\d+}/recipients/{recipientId:\d+}', [CampaignController::class, 'decide'])
                ->middleware('brand');

            // A creator applying for work.
            $router->post('/campaigns/{id:\d+}/apply', [CampaignController::class, 'apply'])
                ->middleware('creator', 'kyc', 'throttle:30,3600,campaign_apply');

            // Bookings
            $router->get('/bookings', [BookingController::class, 'index']);
            $router->get('/bookings/quote', [BookingController::class, 'quote']);
            // Creating a booking commits money, so it is gated on verification.
            $router->post('/bookings', [BookingController::class, 'store'])
                ->middleware('brand', 'kyc', 'throttle:20,600,booking');
            $router->get('/bookings/{id:\d+}', [BookingController::class, 'show']);
            // Accepting work means being paid for it, so the creator must be
            // identity-verified first.
            $router->post('/bookings/{id:\d+}/accept', [BookingController::class, 'accept'])
                ->middleware('creator', 'kyc');
            $router->post('/bookings/{id:\d+}/reject', [BookingController::class, 'reject'])->middleware('creator');
            $router->post('/bookings/{id:\d+}/counter-offer', [BookingController::class, 'counterOffer']);
            $router->post('/bookings/{id:\d+}/accept-offer', [BookingController::class, 'acceptOffer']);
            $router->post('/bookings/{id:\d+}/deliver', [BookingController::class, 'deliver'])->middleware('creator');
            $router->post('/bookings/{id:\d+}/complete', [BookingController::class, 'complete'])->middleware('brand');
            $router->post('/bookings/{id:\d+}/cancel', [BookingController::class, 'cancel']);
            // The deliverable-review screen's other two branches. Both existed
            // in the app and neither had a route, so "Request changes" and
            // "Raise a dispute" reported success and did nothing.
            $router->post('/bookings/{id:\d+}/request-revision', [BookingController::class, 'requestRevision'])
                ->middleware('brand');
            $router->post('/bookings/{id:\d+}/dispute', [BookingController::class, 'dispute'])
                ->middleware('throttle:5,3600,dispute');
            // Coupons reprice the booking, so they are throttled: without a
            // limit the apply endpoint is a code-guessing oracle.
            $router->get('/bookings/{id:\d+}/coupons', [BookingController::class, 'coupons'])
                ->middleware('brand');
            $router->post('/bookings/{id:\d+}/coupon', [BookingController::class, 'applyCoupon'])
                ->middleware('brand', 'throttle:20,600,coupon');
            $router->delete('/bookings/{id:\d+}/coupon', [BookingController::class, 'removeCoupon'])
                ->middleware('brand');
            $router->get('/bookings/{id:\d+}/cancellation-preview', [BookingController::class, 'cancellationPreview']);
            $router->get('/bookings/{id:\d+}/invoice', [BookingController::class, 'invoice']);
            $router->post('/bookings/{id:\d+}/review', [ReviewController::class, 'store']);

            // Reviews and reports
            $router->post('/reviews/{id:\d+}/reply', [ReviewController::class, 'reply']);
            $router->post('/reports', [ReviewController::class, 'report'])->middleware('throttle:10,3600,report');

            // Wallet and payments
            $router->get('/wallet', [WalletController::class, 'show']);
            $router->get('/wallet/transactions', [WalletController::class, 'transactions']);
            $router->post('/wallet/topup', [WalletController::class, 'topup'])->middleware('verified');
            $router->post('/wallet/withdraw', [WalletController::class, 'withdraw'])
                ->middleware('verified', 'kyc', 'throttle:5,3600,withdraw');
            $router->get('/wallet/withdrawals', [WalletController::class, 'withdrawals']);
            $router->post('/wallet/withdrawals/{id:\d+}/cancel', [WalletController::class, 'cancelWithdrawal']);
            $router->get('/wallet/bank-accounts', [WalletController::class, 'bankAccounts']);
            $router->post('/wallet/bank-accounts', [WalletController::class, 'addBankAccount'])->middleware('verified');
            $router->delete('/wallet/bank-accounts/{id:\d+}', [WalletController::class, 'deleteBankAccount']);

            // Billing documents for both sides: brand invoices and creator
            // payout statements, keyed by `invoices.user_id`.
            $router->get('/invoices', [WalletController::class, 'invoices']);
            $router->get('/invoices/{id:\d+}', [WalletController::class, 'invoice']);

            $router->get('/payments', [WalletController::class, 'history']);
            $router->get('/payments/methods', [WalletController::class, 'methods']);
            $router->post('/payments/booking/{id:\d+}', [WalletController::class, 'payBooking'])
                ->middleware('brand', 'verified', 'kyc');
            $router->post('/payments/verify', [WalletController::class, 'verify']);

            // Messaging
            $router->get('/conversations', [MessageController::class, 'index']);
            $router->post('/conversations/start', [MessageController::class, 'start']);
            $router->get('/conversations/{id:\d+}/messages', [MessageController::class, 'messages']);
            $router->post('/conversations/{id:\d+}/messages', [MessageController::class, 'send'])->middleware('throttle:60,60,chat');
            $router->post('/conversations/{id:\d+}/read', [MessageController::class, 'markRead']);
            $router->post('/conversations/{id:\d+}/typing', [MessageController::class, 'typing'])->middleware('throttle:120,60,typing');
            $router->get('/conversations/{id:\d+}/typing', [MessageController::class, 'typingStatus']);
            $router->post('/conversations/{id:\d+}/mute', [MessageController::class, 'mute']);

            // Notifications
            $router->get('/notifications', [AccountController::class, 'notifications']);
            $router->post('/notifications/read-all', [AccountController::class, 'markAllNotificationsRead']);
            $router->post('/notifications/{id:\d+}/read', [AccountController::class, 'markNotificationRead']);
            $router->delete('/notifications/{id:\d+}', [AccountController::class, 'deleteNotification']);

            // Support
            $router->get('/support/tickets', [AccountController::class, 'tickets']);
            $router->post('/support/tickets', [AccountController::class, 'createTicket'])->middleware('throttle:10,3600,ticket');
            $router->get('/support/tickets/{id:\d+}', [AccountController::class, 'ticket']);
            $router->post('/support/tickets/{id:\d+}/reply', [AccountController::class, 'replyToTicket']);
        });

        // -------------------------------------------------------------
        // Scheduled jobs (shared-secret guarded)
        // -------------------------------------------------------------
        $router->group(['prefix' => '/cron', 'middleware' => ['cron']], static function (Router $router): void {
            $router->get('', [SystemController::class, 'cronStatus']);
            $router->match(['GET', 'POST'], '/{job}', [SystemController::class, 'runCronJob']);
        });
    });

    // -----------------------------------------------------------------
    // Public web pages (HTML, outside the API). The app links to
    // app.url/terms and /privacy, and Google Play needs a public privacy
    // policy and account-deletion page. No maintenance gate: legal pages
    // stay up while the API is down for maintenance.
    // -----------------------------------------------------------------
    $router->get('/{slug:' . implode('|', WebPageController::SLUGS) . '}', [WebPageController::class, 'show']);
    $router->get('/account-deletion', [WebPageController::class, 'accountDeletion']);
};
