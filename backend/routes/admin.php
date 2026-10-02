<?php

declare(strict_types=1);

/**
 * Admin panel routes.
 *
 * Middleware vocabulary:
 *   admin.guest      — redirect away if already signed in
 *   admin.auth       — require an active admin session
 *   csrf             — verify the synchroniser token on every unsafe verb
 *   permission:slug  — require a specific RBAC permission
 *   throttle:N,S,key — rate limit
 *
 * The CMS entities (categories, pages, blogs, banners, faqs, testimonials)
 * share one generic controller driven by Admin\Resource definitions, so the
 * `{resource}` placeholder is constrained to exactly those keys.
 */

use Zuste\Controllers\Admin\AccessController;
use Zuste\Controllers\Admin\AiController;
use Zuste\Controllers\Admin\AiProviderController;
use Zuste\Controllers\Admin\AuthController;
use Zuste\Controllers\Admin\BookingController;
use Zuste\Controllers\Admin\ContactVerificationController;
use Zuste\Controllers\Admin\CreatorChangeController;
use Zuste\Controllers\Admin\CreatorProfileController;
use Zuste\Controllers\Admin\DashboardController;
use Zuste\Controllers\Admin\ProfileCompletionController;
use Zuste\Controllers\Admin\DeveloperController;
use Zuste\Controllers\Admin\EngagementController;
use Zuste\Controllers\Admin\FinanceController;
use Zuste\Controllers\Admin\ImportController;
use Zuste\Controllers\Admin\IntegrationController;
use Zuste\Controllers\Admin\KycController;
use Zuste\Controllers\Admin\LoginMethodController;
use Zuste\Controllers\Admin\MarketingController;
use Zuste\Controllers\Admin\MediaController;
use Zuste\Controllers\Admin\MenuController;
use Zuste\Controllers\Admin\PrivacyController;
use Zuste\Controllers\Admin\ProductivityController;
use Zuste\Controllers\Admin\ResourceController;
use Zuste\Controllers\Admin\SecurityController;
use Zuste\Controllers\Admin\SettingsController;
use Zuste\Controllers\Admin\SitemapController;
use Zuste\Controllers\Admin\TaxonomyController;
use Zuste\Controllers\Admin\WorkflowController;
use Zuste\Controllers\Admin\UserController;
use Zuste\Controllers\Admin\WidgetController;
use Zuste\Core\Router;

return static function (Router $router): void {

    // Only these keys may reach ResourceController. Kept in step with
    // Admin\Resource::registry() — anything else 404s at the router.
    $resources = 'categories|pages|blogs|banners|faqs|testimonials'
               . '|onboarding-slides'
               . '|coupons|popups|redirects|flags|sections|ai-prompts';

    // -----------------------------------------------------------------
    // Guest
    // -----------------------------------------------------------------
    $router->group(['prefix' => '/admin', 'middleware' => ['csrf']], static function (Router $router): void {
        $router->get('/login', [AuthController::class, 'showLogin'])->middleware('admin.guest');
        $router->post('/login', [AuthController::class, 'login'])
               ->middleware('admin.guest', 'throttle:20,600,admin-login');

        // The second factor. Guarded by `admin.guest` like the login form
        // itself: an already-authenticated admin has no challenge to answer.
        // The pending-identity check lives in the controller, because the
        // visitor here is deliberately NOT an authenticated admin yet.
        $router->get('/login/two-factor', [AuthController::class, 'showTwoFactorChallenge'])
               ->middleware('admin.guest');
        $router->post('/login/two-factor', [AuthController::class, 'verifyTwoFactorChallenge'])
               ->middleware('admin.guest', 'throttle:20,600,admin-2fa');

        $router->get('/forgot-password', [AuthController::class, 'showForgotPassword'])->middleware('admin.guest');
        $router->post('/forgot-password', [AuthController::class, 'sendResetLink'])
               ->middleware('admin.guest', 'throttle:5,900,admin-reset');

        $router->get('/reset-password', [AuthController::class, 'showResetPassword'])->middleware('admin.guest');
        $router->post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('admin.guest');
    });

    // -----------------------------------------------------------------
    // Authenticated
    // -----------------------------------------------------------------
    $router->group(['prefix' => '/admin', 'middleware' => ['admin.auth', 'csrf']], static function (Router $router) use ($resources): void {

        $router->post('/logout', [AuthController::class, 'logout']);

        // ---- Dashboard & analytics --------------------------------
        $router->get('', [WidgetController::class, 'dashboard']);
        $router->get('/analytics', [DashboardController::class, 'analytics'])->middleware('permission:analytics.view');
        $router->get('/api/stats', [DashboardController::class, 'stats']);

        // ---- Dashboard widgets -------------------------------------
        // Fixed segments first so `/widgets/{key}` cannot shadow them.
        $router->get('/widgets/data', [WidgetController::class, 'data']);
        $router->post('/widgets/layout', [WidgetController::class, 'saveLayout']);
        $router->post('/widgets/layout/reset', [WidgetController::class, 'resetLayout']);
        $router->post('/widgets/tasks', [WidgetController::class, 'addTask']);
        $router->post('/widgets/tasks/{id:\d+}/toggle', [WidgetController::class, 'toggleTask']);
        $router->post('/widgets/tasks/{id:\d+}/delete', [WidgetController::class, 'deleteTask']);
        $router->post('/widgets/notes', [WidgetController::class, 'saveNote']);
        $router->post('/widgets/notes/{id:\d+}/delete', [WidgetController::class, 'deleteNote']);
        $router->get('/widgets/{key:[a-z0-9_]+}/export', [WidgetController::class, 'export'])->middleware('permission:export.data');
        $router->post('/widgets/{key:[a-z0-9_]+}/preference', [WidgetController::class, 'savePreference']);
        $router->get('/widgets/{key:[a-z0-9_]+}', [WidgetController::class, 'single']);

        // ---- Own account ------------------------------------------
        $router->get('/account', [AuthController::class, 'account']);
        $router->post('/account', [AuthController::class, 'updateAccount']);
        $router->post('/account/avatar', [AuthController::class, 'updateAvatar']);
        $router->post('/account/avatar/delete', [AuthController::class, 'removeAvatar']);
        $router->get('/account/password', [AuthController::class, 'showChangePassword']);
        $router->post('/account/password', [AuthController::class, 'changePassword']);
        $router->post('/account/sessions/{id}/revoke', [AuthController::class, 'revokeSession']);

        // ---- Users -------------------------------------------------
        $router->get('/users', [UserController::class, 'index'])->middleware('permission:users.view');
        $router->get('/users/{id:\d+}', [UserController::class, 'show'])->middleware('permission:users.view');
        $router->post('/users/{id:\d+}/status', [UserController::class, 'updateStatus'])->middleware('permission:users.edit');
        $router->post('/users/{id:\d+}/wallet-adjust', [UserController::class, 'adjustWallet'])->middleware('permission:wallet.adjust');
        $router->post('/users/{id:\d+}/delete', [UserController::class, 'destroy'])->middleware('permission:users.delete');

        // Data exports and self-service account deletions, with reasons.
        $router->get('/privacy-requests', [PrivacyController::class, 'index'])->middleware('permission:users.view');

        // ---- Creators ----------------------------------------------
        $router->get('/creators', [UserController::class, 'creators'])->middleware('permission:creators.view');

        // Declared before `/creators/{id:\d+}` for readability; the numeric
        // constraint already keeps the two apart.
        $router->get('/creators/completion', [ProfileCompletionController::class, 'index'])->middleware('permission:creators.view');
        $router->post('/creators/completion/recompute', [ProfileCompletionController::class, 'recomputeAll'])->middleware('permission:creators.edit');
        $router->get('/creators/{id:\d+}/completion', [ProfileCompletionController::class, 'show'])->middleware('permission:creators.view');
        $router->post('/creators/{id:\d+}/completion/recompute', [ProfileCompletionController::class, 'recompute'])->middleware('permission:creators.edit');
        $router->post('/creators/{id:\d+}/features', [ProfileCompletionController::class, 'setOverride'])->middleware('permission:creators.edit');
        $router->delete('/creators/{id:\d+}/features', [ProfileCompletionController::class, 'clearOverride'])->middleware('permission:creators.edit');
        $router->get('/creators/{id:\d+}', [UserController::class, 'creatorShow'])->middleware('permission:creators.view');
        $router->post('/creators/{id:\d+}/moderate', [UserController::class, 'moderateCreator'])->middleware('permission:creators.approve');
        $router->post('/creators/{id:\d+}/flags', [UserController::class, 'toggleCreatorFlags'])->middleware('permission:creators.verify');

        // Verification badges and per-section profile locks. The permission on
        // each route is the coarse gate; CreatorProfileController narrows it
        // further per badge, because featuring a creator is a promotional
        // decision rather than a verification one.
        $router->get('/creators/{id:\d+}/badges', [CreatorProfileController::class, 'badgeList'])->middleware('permission:creators.view');
        $router->post('/creators/{id:\d+}/badges/award', [CreatorProfileController::class, 'awardBadge'])->middleware('permission:creators.verify');
        $router->post('/creators/{id:\d+}/badges/revoke', [CreatorProfileController::class, 'revokeBadge'])->middleware('permission:creators.verify');
        $router->post('/creators/{id:\d+}/badges/sync', [CreatorProfileController::class, 'syncBadges'])->middleware('permission:creators.verify');
        $router->get('/creators/{id:\d+}/locks', [CreatorProfileController::class, 'locks'])->middleware('permission:creators.view');
        $router->post('/creators/{id:\d+}/locks', [CreatorProfileController::class, 'lock'])->middleware('permission:creators.edit');
        $router->delete('/creators/{id:\d+}/locks', [CreatorProfileController::class, 'unlock'])->middleware('permission:creators.edit');

        // ---- Creator profile change approval ------------------------
        //
        // The literal `/fields/` and `/items/` segments come before
        // `/{id:\d+}` so a field decision is never read as a request id.
        $router->get('/creator-changes', [CreatorChangeController::class, 'index'])->middleware('permission:creators.view');
        $router->post('/creator-changes/fields/{id:\d+}/approve', [CreatorChangeController::class, 'approveField'])->middleware('permission:creators.approve');
        $router->post('/creator-changes/fields/{id:\d+}/reject', [CreatorChangeController::class, 'rejectField'])->middleware('permission:creators.approve');
        $router->post('/creator-changes/items/{id:\d+}/approve', [CreatorChangeController::class, 'approveItem'])->middleware('permission:creators.approve');
        $router->post('/creator-changes/items/{id:\d+}/reject', [CreatorChangeController::class, 'rejectItem'])->middleware('permission:creators.approve');
        $router->get('/creator-changes/{id:\d+}', [CreatorChangeController::class, 'show'])->middleware('permission:creators.view');
        $router->post('/creator-changes/{id:\d+}/approve', [CreatorChangeController::class, 'approve'])->middleware('permission:creators.approve');
        $router->post('/creator-changes/{id:\d+}/reject', [CreatorChangeController::class, 'reject'])->middleware('permission:creators.approve');
        $router->post('/creator-changes/{id:\d+}/withdraw', [CreatorChangeController::class, 'withdraw'])->middleware('permission:creators.edit');

        // ---- Contact verification (per user) ------------------------
        $router->get('/users/{id:\d+}/contacts', [ContactVerificationController::class, 'show'])->middleware('permission:users.view');
        $router->get('/users/{id:\d+}/contacts/log', [ContactVerificationController::class, 'verificationLog'])->middleware('permission:users.view');
        $router->post('/users/{id:\d+}/contacts/force-verify', [ContactVerificationController::class, 'forceVerify'])->middleware('permission:users.edit');
        $router->post('/users/{id:\d+}/contacts/revoke', [ContactVerificationController::class, 'revokeVerification'])->middleware('permission:users.edit');
        $router->post('/users/{id:\d+}/contacts/requests/{requestId:\d+}/reject', [ContactVerificationController::class, 'rejectRequest'])->middleware('permission:users.edit');

        // ---- KYC & business verification ---------------------------
        // Static segments are declared before `{id}` so they are not
        // swallowed by the numeric parameter.
        $router->get('/kyc', [KycController::class, 'index'])->middleware('permission:kyc.view');
        $router->get('/kyc/brands', [KycController::class, 'brands'])->middleware('permission:kyc.view');
        $router->get('/kyc/creators', [KycController::class, 'creators'])->middleware('permission:kyc.view');
        $router->get('/kyc/export', [KycController::class, 'export'])->middleware('permission:kyc.view');
        $router->get('/kyc/requirements', [KycController::class, 'requirements'])->middleware('permission:kyc.settings');
        $router->post('/kyc/requirements/{id:\d+}', [KycController::class, 'updateRequirement'])->middleware('permission:kyc.settings');
        $router->get('/kyc/documents/{id:\d+}', [KycController::class, 'download'])->middleware('permission:kyc.download');
        $router->get('/kyc/documents/{id:\d+}/preview', [KycController::class, 'preview'])->middleware('permission:kyc.download');
        $router->get('/kyc/documents/{id:\d+}/link', [KycController::class, 'documentLink'])->middleware('permission:kyc.download');
        $router->post('/kyc/documents/{id:\d+}/review', [KycController::class, 'reviewDocument'])->middleware('permission:kyc.review');
        $router->post('/kyc/bulk', [KycController::class, 'bulk'])->middleware('permission:kyc.review');
        $router->get('/kyc/{id:\d+}', [KycController::class, 'show'])->middleware('permission:kyc.view');
        $router->post('/kyc/{id:\d+}/review', [KycController::class, 'review'])->middleware('permission:kyc.review');
        $router->post('/kyc/{id:\d+}/resubmission', [KycController::class, 'requestResubmission'])->middleware('permission:kyc.review');
        $router->post('/kyc/{id:\d+}/notes', [KycController::class, 'addNote'])->middleware('permission:kyc.review');
        // Take an Aadhaar / PAN / GSTIN / CIN off a verification so its
        // rightful holder can use it.
        $router->post('/kyc/{id:\d+}/identifiers/release', [KycController::class, 'releaseIdentifier'])->middleware('permission:kyc.review');
        // The blue tick is its own decision, with its own permission.
        $router->post('/kyc/{id:\d+}/badge/grant', [KycController::class, 'grantBadge'])->middleware('permission:kyc.badge');
        $router->post('/kyc/{id:\d+}/badge/revoke', [KycController::class, 'revokeBadge'])->middleware('permission:kyc.badge');

        // Legacy single-document queue: read-only history (reviewKyc now
        // refuses and points at /admin/kyc). Lists only documents that never
        // belonged to a verification profile, behind kyc.view like the rest of
        // the KYC data.
        $router->get('/kyc-legacy', [KycController::class, 'legacy'])->middleware('permission:kyc.view');
        $router->post('/kyc-legacy/{id:\d+}/review', [UserController::class, 'reviewKyc'])->middleware('permission:users.kyc');

        // ---- Bookings ----------------------------------------------
        $router->get('/bookings', [BookingController::class, 'index'])->middleware('permission:bookings.view');
        $router->get('/bookings/{id:\d+}', [BookingController::class, 'show'])->middleware('permission:bookings.view');
        $router->post('/bookings/{id:\d+}/cancel', [BookingController::class, 'cancel'])->middleware('permission:bookings.cancel');
        $router->post('/bookings/{id:\d+}/complete', [BookingController::class, 'complete'])->middleware('permission:bookings.edit');
        $router->post('/bookings/{id:\d+}/refund', [BookingController::class, 'refund'])->middleware('permission:payments.refund');

        // ---- Disputes ----------------------------------------------
        $router->get('/disputes', [BookingController::class, 'disputes'])->middleware('permission:bookings.dispute');
        $router->post('/disputes/{id:\d+}/resolve', [BookingController::class, 'resolveDispute'])->middleware('permission:bookings.dispute');

        // ---- Reviews & reports -------------------------------------
        $router->get('/reviews', [BookingController::class, 'reviews'])->middleware('permission:reviews.view');
        $router->post('/reviews/{id:\d+}/moderate', [BookingController::class, 'moderateReview'])->middleware('permission:reviews.moderate');
        $router->get('/reports', [BookingController::class, 'reports'])->middleware('permission:reports.view');
        $router->post('/reports/{id:\d+}/handle', [BookingController::class, 'handleReport'])->middleware('permission:reports.handle');

        // ---- Finance -----------------------------------------------
        $router->get('/payments', [FinanceController::class, 'payments'])->middleware('permission:payments.view');
        $router->post('/payments/{id:\d+}/refund', [FinanceController::class, 'refundPayment'])->middleware('permission:payments.refund');
        $router->get('/refunds', [FinanceController::class, 'refunds'])->middleware('permission:payments.view');

        $router->get('/wallets', [FinanceController::class, 'wallets'])->middleware('permission:wallet.view');
        $router->post('/wallets/{userId:\d+}/freeze', [FinanceController::class, 'freezeWallet'])->middleware('permission:wallet.adjust');
        $router->get('/wallets/{userId:\d+}/reconcile', [FinanceController::class, 'reconcileWallet'])->middleware('permission:wallet.view');

        $router->get('/withdrawals', [FinanceController::class, 'withdrawals'])->middleware('permission:wallet.withdrawals');
        $router->post('/withdrawals/{id:\d+}/approve', [FinanceController::class, 'approveWithdrawal'])->middleware('permission:wallet.withdrawals');
        $router->post('/withdrawals/{id:\d+}/complete', [FinanceController::class, 'completeWithdrawal'])->middleware('permission:wallet.withdrawals');
        $router->post('/withdrawals/{id:\d+}/reject', [FinanceController::class, 'rejectWithdrawal'])->middleware('permission:wallet.withdrawals');

        $router->get('/settlements', [FinanceController::class, 'settlements'])->middleware('permission:payments.view');

        // ---- Support -----------------------------------------------
        $router->get('/tickets', [EngagementController::class, 'tickets'])->middleware('permission:support.view');
        $router->get('/tickets/{id:\d+}', [EngagementController::class, 'ticket'])->middleware('permission:support.view');
        $router->post('/tickets/{id:\d+}/reply', [EngagementController::class, 'replyToTicket'])->middleware('permission:support.reply');
        $router->post('/tickets/{id:\d+}/assign', [EngagementController::class, 'assignTicket'])->middleware('permission:support.assign');

        // ---- Notifications ------------------------------------------
        $router->get('/notifications', [EngagementController::class, 'notifications'])->middleware('permission:notifications.view');
        $router->post('/notifications/broadcast', [EngagementController::class, 'broadcast'])->middleware('permission:notifications.send');
        $router->post('/notifications/templates/{id:\d+}', [EngagementController::class, 'updateTemplate'])->middleware('permission:notifications.templates');

        // ---- Contact inbox ------------------------------------------
        $router->get('/contact', [EngagementController::class, 'contact'])->middleware('permission:cms.view');
        $router->post('/contact/{id:\d+}/reply', [EngagementController::class, 'replyToContact'])->middleware('permission:support.reply');
        $router->post('/contact/{id:\d+}/status', [EngagementController::class, 'updateContactStatus'])->middleware('permission:support.reply');

        // ---- Media library ------------------------------------------
        // Superseded EngagementController::media() — MediaController adds the
        // folder tree, bulk actions and storage reporting. The fixed segments
        // are registered before /media/{id} so they cannot be shadowed.
        $router->get('/media', [MediaController::class, 'index'])->middleware('permission:cms.media');
        $router->get('/media/grid', [MediaController::class, 'grid'])->middleware('permission:cms.media');
        $router->post('/media/upload', [MediaController::class, 'upload'])->middleware('permission:cms.media');
        $router->post('/media/bulk', [MediaController::class, 'bulk'])->middleware('permission:cms.media');
        $router->post('/media/folders', [MediaController::class, 'storeFolder'])->middleware('permission:cms.media');
        $router->post('/media/folders/{id:\d+}/update', [MediaController::class, 'updateFolder'])->middleware('permission:cms.media');
        $router->post('/media/folders/{id:\d+}/delete', [MediaController::class, 'destroyFolder'])->middleware('permission:cms.media');
        $router->post('/media/{id:\d+}/update', [MediaController::class, 'updateFile'])->middleware('permission:cms.media');
        $router->post('/media/{id:\d+}/delete', [MediaController::class, 'destroyFile'])->middleware('permission:cms.media');
        $router->post('/media/{id:\d+}/restore', [MediaController::class, 'restoreFile'])->middleware('permission:cms.media');
        $router->post('/media/{id:\d+}/purge', [MediaController::class, 'purgeFile'])->middleware('permission:cms.media');

        // ---- Access control ------------------------------------------
        $router->get('/admins', [AccessController::class, 'admins'])->middleware('permission:admins.view');
        $router->post('/admins', [AccessController::class, 'storeAdmin'])->middleware('permission:admins.manage');
        $router->post('/admins/{id:\d+}', [AccessController::class, 'updateAdmin'])->middleware('permission:admins.manage');
        $router->post('/admins/{id:\d+}/permissions', [AccessController::class, 'updateAdminPermissions'])->middleware('permission:roles.manage');
        $router->post('/admins/{id:\d+}/delete', [AccessController::class, 'deleteAdmin'])->middleware('permission:admins.manage');

        $router->get('/roles', [AccessController::class, 'roles'])->middleware('permission:roles.manage');
        $router->post('/roles', [AccessController::class, 'storeRole'])->middleware('permission:roles.manage');
        $router->post('/roles/{id:\d+}', [AccessController::class, 'updateRole'])->middleware('permission:roles.manage');
        $router->post('/roles/{id:\d+}/delete', [AccessController::class, 'deleteRole'])->middleware('permission:roles.manage');

        $router->get('/logs', [AccessController::class, 'activityLog'])->middleware('permission:logs.activity');

        // ---- Settings -----------------------------------------------
        $router->get('/settings', [SettingsController::class, 'index'])->middleware('permission:settings.view');
        $router->post('/settings', [SettingsController::class, 'update'])->middleware('permission:settings.manage');
        $router->post('/settings/test', [SettingsController::class, 'testIntegration'])->middleware('permission:settings.manage');
        $router->post('/settings/ai/provider', [SettingsController::class, 'saveAiProvider'])->middleware('permission:ai.manage');
        $router->post('/settings/ai/clear-key', [SettingsController::class, 'clearAiKey'])->middleware('permission:ai.manage');

        // ---- System --------------------------------------------------
        $router->get('/system', [SettingsController::class, 'system'])->middleware('permission:logs.system');
        $router->post('/system/jobs/{job}/run', [SettingsController::class, 'runJob'])->middleware('permission:system.jobs');
        $router->post('/system/jobs/{job}/toggle', [SettingsController::class, 'toggleJob'])->middleware('permission:system.jobs');
        $router->post('/system/backup', [SettingsController::class, 'createBackup'])->middleware('permission:system.backup');
        $router->get('/system/backup/{id:\d+}/download', [SettingsController::class, 'downloadBackup'])->middleware('permission:system.backup');
        $router->post('/system/backup/{id:\d+}/restore', [SettingsController::class, 'restoreBackup'])->middleware('permission:system.restore');
        $router->post('/system/optimize', [SettingsController::class, 'optimizeDatabase'])->middleware('permission:system.optimize');
        $router->post('/system/logs/clear', [SettingsController::class, 'clearLogs'])->middleware('permission:logs.system');

        // ---- Integrations hub ----------------------------------------
        // The {key} pattern is brace-free on purpose: a quantifier inside the
        // placeholder terminates the router's brace matching early.
        $router->get('/integrations', [IntegrationController::class, 'index'])->middleware('permission:integrations.view');
        $router->get('/integrations/{key:[a-z0-9_]+}', [IntegrationController::class, 'show'])->middleware('permission:integrations.view');
        $router->post('/integrations/{key:[a-z0-9_]+}', [IntegrationController::class, 'save'])->middleware('permission:integrations.manage');
        $router->post('/integrations/{key:[a-z0-9_]+}/toggle', [IntegrationController::class, 'toggle'])->middleware('permission:integrations.manage');
        $router->post('/integrations/{key:[a-z0-9_]+}/test', [IntegrationController::class, 'test'])->middleware('permission:integrations.manage');
        $router->post('/integrations/{key:[a-z0-9_]+}/sync', [IntegrationController::class, 'sync'])->middleware('permission:integrations.manage');
        $router->post('/integrations/{key:[a-z0-9_]+}/webhook', [IntegrationController::class, 'saveWebhook'])->middleware('permission:integrations.manage');
        $router->post('/integrations/{key:[a-z0-9_]+}/schedule', [IntegrationController::class, 'saveSchedule'])->middleware('permission:integrations.manage');
        $router->post('/integrations/{key:[a-z0-9_]+}/secret/{field:[a-z0-9_]+}/clear', [IntegrationController::class, 'clearSecret'])->middleware('permission:integrations.manage');

        // ---- Authentication & login ----------------------------------
        // /policy is declared before the {key} routes: 'policy' matches
        // [a-z0-9_]+ and would otherwise be dispatched to save() as a method
        // key. Placeholders stay brace-free — a nested {n} breaks the parser.
        $router->get('/login-methods', [LoginMethodController::class, 'index'])->middleware('permission:auth.view');
        $router->post('/login-methods/policy', [LoginMethodController::class, 'savePolicy'])->middleware('permission:auth.manage');
        $router->post('/login-methods/{key:[a-z0-9_]+}', [LoginMethodController::class, 'save'])->middleware('permission:auth.manage');
        $router->post('/login-methods/{key:[a-z0-9_]+}/toggle', [LoginMethodController::class, 'toggle'])->middleware('permission:auth.manage');
        $router->post('/login-methods/{key:[a-z0-9_]+}/test', [LoginMethodController::class, 'test'])->middleware('permission:auth.test');
        $router->post('/login-methods/{key:[a-z0-9_]+}/secret/{field:[a-z0-9_]+}/clear', [LoginMethodController::class, 'clearSecret'])->middleware('permission:auth.manage');

        // ---- Security ------------------------------------------------
        $router->get('/security', [SecurityController::class, 'index'])->middleware('permission:security.logins');
        $router->get('/security/login-history', [SecurityController::class, 'loginHistory'])->middleware('permission:security.logins');

        // Two-factor is self-service: every admin manages their own, so these
        // carry no permission beyond being signed in.
        $router->get('/security/two-factor', [SecurityController::class, 'twoFactor']);
        $router->post('/security/two-factor/begin', [SecurityController::class, 'beginTwoFactor']);
        $router->post('/security/two-factor/confirm', [SecurityController::class, 'confirmTwoFactor']);
        $router->post('/security/two-factor/disable', [SecurityController::class, 'disableTwoFactor']);

        $router->get('/security/ip-whitelist', [SecurityController::class, 'ipWhitelist'])->middleware('permission:security.ip');
        $router->post('/security/ip-whitelist', [SecurityController::class, 'storeIp'])->middleware('permission:security.ip');
        $router->post('/security/ip-whitelist/{id:\d+}/toggle', [SecurityController::class, 'toggleIp'])->middleware('permission:security.ip');
        $router->post('/security/ip-whitelist/{id:\d+}/delete', [SecurityController::class, 'destroyIp'])->middleware('permission:security.ip');

        $router->get('/security/sessions', [SecurityController::class, 'sessions'])->middleware('permission:security.sessions');
        // The session id is a 64-character hex hash. The pattern stays brace-free
        // because the router parses `{name:regex}` by brace matching, so a
        // quantifier like {64} inside would terminate the placeholder early.
        $router->post('/security/sessions/{id:[a-f0-9]+}/revoke', [SecurityController::class, 'revokeSession'])->middleware('permission:security.sessions');

        // ---- Developer tools -----------------------------------------
        $router->get('/developer', [DeveloperController::class, 'index'])->middleware('permission:developer.logs');
        $router->post('/developer/settings', [DeveloperController::class, 'saveSettings'])->middleware('permission:settings.manage');
        $router->get('/developer/api-logs', [DeveloperController::class, 'apiLogs'])->middleware('permission:developer.logs');
        $router->post('/developer/api-logs/prune', [DeveloperController::class, 'pruneApiLogs'])->middleware('permission:logs.system');
        $router->get('/developer/queries', [DeveloperController::class, 'queryMonitor'])->middleware('permission:developer.queries');
        $router->post('/developer/queries/clear', [DeveloperController::class, 'clearQueries'])->middleware('permission:logs.system');
        $router->get('/developer/cache', [DeveloperController::class, 'cache'])->middleware('permission:developer.cache');
        $router->post('/developer/cache/flush', [DeveloperController::class, 'flushCache'])->middleware('permission:developer.cache');
        $router->post('/developer/cache/forget', [DeveloperController::class, 'forgetCacheGroup'])->middleware('permission:developer.cache');
        $router->post('/developer/cache/prune', [DeveloperController::class, 'pruneCache'])->middleware('permission:developer.cache');
        $router->get('/developer/tester', [DeveloperController::class, 'tester'])->middleware('permission:developer.tester');
        $router->post('/developer/tester/run', [DeveloperController::class, 'runTest'])->middleware('permission:developer.tester');

        // ---- Marketing campaigns -------------------------------------
        // Subscriber paths come first: /marketing/{id} would otherwise swallow
        // /marketing/subscribers.
        $router->get('/marketing', [MarketingController::class, 'index'])->middleware('permission:marketing.view');
        $router->get('/marketing/create', [MarketingController::class, 'create'])->middleware('permission:marketing.manage');
        $router->get('/marketing/subscribers', [MarketingController::class, 'subscribers'])->middleware('permission:marketing.view');
        $router->post('/marketing/subscribers', [MarketingController::class, 'storeSubscriber'])->middleware('permission:marketing.manage');
        $router->post('/marketing/subscribers/import', [MarketingController::class, 'importSubscribers'])->middleware('permission:marketing.manage');
        $router->post('/marketing/subscribers/{id:\d+}/unsubscribe', [MarketingController::class, 'unsubscribeSubscriber'])->middleware('permission:marketing.manage');
        $router->post('/marketing/subscribers/{id:\d+}/resubscribe', [MarketingController::class, 'resubscribeSubscriber'])->middleware('permission:marketing.manage');
        $router->post('/marketing/subscribers/{id:\d+}/delete', [MarketingController::class, 'destroySubscriber'])->middleware('permission:marketing.manage');
        $router->post('/marketing/preview-audience', [MarketingController::class, 'previewAudience'])->middleware('permission:marketing.view');
        $router->post('/marketing', [MarketingController::class, 'store'])->middleware('permission:marketing.manage');
        $router->get('/marketing/{id:\d+}/edit', [MarketingController::class, 'edit'])->middleware('permission:marketing.manage');
        $router->get('/marketing/{id:\d+}', [MarketingController::class, 'show'])->middleware('permission:marketing.view');
        $router->post('/marketing/{id:\d+}/send', [MarketingController::class, 'send'])->middleware('permission:marketing.send');
        $router->post('/marketing/{id:\d+}/dispatch', [MarketingController::class, 'dispatch'])->middleware('permission:marketing.send');
        $router->post('/marketing/{id:\d+}/pause', [MarketingController::class, 'pause'])->middleware('permission:marketing.send');
        $router->post('/marketing/{id:\d+}/resume', [MarketingController::class, 'resume'])->middleware('permission:marketing.send');
        $router->post('/marketing/{id:\d+}/cancel', [MarketingController::class, 'cancel'])->middleware('permission:marketing.send');
        $router->post('/marketing/{id:\d+}/delete', [MarketingController::class, 'destroy'])->middleware('permission:marketing.manage');
        $router->post('/marketing/{id:\d+}', [MarketingController::class, 'update'])->middleware('permission:marketing.manage');

        // ---- Workflow and approvals ----------------------------------
        // Rule paths before /workflow/{id} for the same reason as above.
        $router->get('/workflow', [WorkflowController::class, 'index'])->middleware('permission:workflow.view');
        $router->get('/workflow/rules', [WorkflowController::class, 'rules'])->middleware('permission:workflow.manage');
        $router->post('/workflow/rules', [WorkflowController::class, 'storeRule'])->middleware('permission:workflow.manage');
        $router->post('/workflow/rules/test', [WorkflowController::class, 'testRule'])->middleware('permission:workflow.manage');
        $router->post('/workflow/rules/{id:\d+}/toggle', [WorkflowController::class, 'toggleRule'])->middleware('permission:workflow.manage');
        $router->post('/workflow/rules/{id:\d+}/duplicate', [WorkflowController::class, 'duplicateRule'])->middleware('permission:workflow.manage');
        $router->post('/workflow/rules/{id:\d+}/delete', [WorkflowController::class, 'deleteRule'])->middleware('permission:workflow.manage');
        $router->post('/workflow/rules/{id:\d+}', [WorkflowController::class, 'updateRule'])->middleware('permission:workflow.manage');
        $router->post('/workflow/requests', [WorkflowController::class, 'raise'])->middleware('permission:workflow.view');
        $router->get('/workflow/{id:\d+}', [WorkflowController::class, 'show'])->middleware('permission:workflow.view');
        $router->post('/workflow/{id:\d+}/act', [WorkflowController::class, 'act'])->middleware('permission:workflow.act');
        $router->post('/workflow/{id:\d+}/cancel', [WorkflowController::class, 'cancelRequest'])->middleware('permission:workflow.view');

        // ---- AI ------------------------------------------------------
        $router->get('/ai', [AiController::class, 'index'])->middleware('permission:ai.view');
        $router->get('/ai/logs', [AiController::class, 'logs'])->middleware('permission:ai.view');
        // Provider, key, model and the app's AI switches: Settings -> AI.

        // Provider catalogue. Registered before /ai/{...} style paths so the
        // fixed segments always win.
        $router->get('/ai/providers', [AiProviderController::class, 'index'])->middleware('permission:ai.view');
        $router->post('/ai/providers', [AiProviderController::class, 'store'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}', [AiProviderController::class, 'update'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}/delete', [AiProviderController::class, 'destroy'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}/default', [AiProviderController::class, 'makeDefault'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}/clear-key', [AiProviderController::class, 'clearKey'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}/test', [AiProviderController::class, 'test'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}/models', [AiProviderController::class, 'storeModel'])->middleware('permission:ai.manage');
        $router->post('/ai/providers/{id:\d+}/models/{model:\d+}/delete', [AiProviderController::class, 'deleteModel'])->middleware('permission:ai.manage');

        // ---- Menu builder --------------------------------------------
        $router->get('/menus', [MenuController::class, 'index'])->middleware('permission:cms.view');
        $router->post('/menus', [MenuController::class, 'store'])->middleware('permission:cms.pages');
        $router->get('/menus/{id:\d+}', [MenuController::class, 'builder'])->middleware('permission:cms.view');
        $router->post('/menus/{id:\d+}/tree', [MenuController::class, 'saveTree'])->middleware('permission:cms.pages');
        $router->post('/menus/{id:\d+}/duplicate', [MenuController::class, 'duplicate'])->middleware('permission:cms.pages');
        $router->post('/menus/{id:\d+}/delete', [MenuController::class, 'destroy'])->middleware('permission:cms.pages');
        $router->post('/menus/{id:\d+}/items/{item:\d+}/delete', [MenuController::class, 'destroyItem'])->middleware('permission:cms.pages');
        $router->post('/menus/{id:\d+}/items/{item:\d+}', [MenuController::class, 'updateItem'])->middleware('permission:cms.pages');
        $router->post('/menus/{id:\d+}/items', [MenuController::class, 'storeItem'])->middleware('permission:cms.pages');
        $router->post('/menus/{id:\d+}', [MenuController::class, 'update'])->middleware('permission:cms.pages');

        // ---- Sitemap -------------------------------------------------
        $router->get('/sitemap', [SitemapController::class, 'index'])->middleware('permission:cms.view');
        $router->post('/sitemap/generate', [SitemapController::class, 'generate'])->middleware('permission:cms.pages');
        $router->post('/sitemap/settings', [SitemapController::class, 'saveSettings'])->middleware('permission:cms.pages');
        $router->post('/sitemap/robots', [SitemapController::class, 'writeRobots'])->middleware('permission:cms.pages');

        // ---- Import --------------------------------------------------
        // Each action re-checks the target resource's own manage permission,
        // so no blanket permission middleware is attached here.
        $router->get('/import', [ImportController::class, 'index']);
        $router->post('/import/preview', [ImportController::class, 'preview']);
        $router->post('/import/commit', [ImportController::class, 'commit']);
        $router->get('/import/{resource:[a-z-]+}/template', [ImportController::class, 'template']);

        // ---- Productivity --------------------------------------------
        // Global search and saved filters carry no permission of their own:
        // results are filtered per source inside SearchService, and every saved
        // filter query is scoped to the signed-in admin.
        $router->get('/search', [ProductivityController::class, 'search']);
        $router->get('/search/json', [ProductivityController::class, 'searchJson']);
        $router->get('/saved-filters', [ProductivityController::class, 'savedFilters']);
        $router->post('/saved-filters', [ProductivityController::class, 'storeSavedFilter']);
        $router->post('/saved-filters/{id:\d+}/rename', [ProductivityController::class, 'renameSavedFilter']);
        $router->post('/saved-filters/{id:\d+}/share', [ProductivityController::class, 'toggleSavedFilterShared']);
        $router->post('/saved-filters/{id:\d+}/default', [ProductivityController::class, 'toggleSavedFilterDefault']);
        $router->post('/saved-filters/{id:\d+}/apply', [ProductivityController::class, 'applySavedFilter']);
        $router->post('/saved-filters/{id:\d+}/delete', [ProductivityController::class, 'deleteSavedFilter']);
        $router->get('/recycle-bin', [ProductivityController::class, 'recycleBin'])->middleware('permission:recyclebin.manage');
        $router->post('/recycle-bin/restore', [ProductivityController::class, 'restoreDeleted'])->middleware('permission:recyclebin.manage');
        $router->post('/recycle-bin/delete', [ProductivityController::class, 'purgeDeleted'])->middleware('permission:recyclebin.manage');
        $router->post('/recycle-bin/empty', [ProductivityController::class, 'emptyBinType'])->middleware('permission:recyclebin.manage');
        $router->post('/recycle-bin/purge', [ProductivityController::class, 'purgeOlderThan'])->middleware('permission:recyclebin.manage');
        $router->get('/revisions', [ProductivityController::class, 'revisions'])->middleware('permission:revisions.view');
        $router->get('/revisions/{id:\d+}', [ProductivityController::class, 'revision'])->middleware('permission:revisions.view');
        $router->post('/revisions/{id:\d+}/restore', [ProductivityController::class, 'restoreRevision'])->middleware('permission:revisions.view');

        // ---- App option lists (taxonomies) ---------------------------
        // These drive every dropdown and chip row in the mobile and web apps.
        $router->get('/taxonomies', [TaxonomyController::class, 'index'])->middleware('permission:taxonomies.view');
        $router->get('/taxonomies/{id:\d+}', [TaxonomyController::class, 'show'])->middleware('permission:taxonomies.view');
        $router->post('/taxonomies/{id:\d+}', [TaxonomyController::class, 'update'])->middleware('permission:taxonomies.manage');
        $router->post('/taxonomies/{id:\d+}/reorder', [TaxonomyController::class, 'reorder'])->middleware('permission:taxonomies.manage');
        $router->post('/taxonomies/{id:\d+}/terms', [TaxonomyController::class, 'storeTerm'])->middleware('permission:taxonomies.manage');
        $router->post('/taxonomies/{id:\d+}/terms/{termId:\d+}', [TaxonomyController::class, 'updateTerm'])->middleware('permission:taxonomies.manage');
        $router->post('/taxonomies/{id:\d+}/terms/{termId:\d+}/delete', [TaxonomyController::class, 'destroyTerm'])->middleware('permission:taxonomies.manage');

        // ---- Generic CMS resources -----------------------------------
        // Registered last so the fixed paths above always win.
        $router->get('/{resource:' . $resources . '}', [ResourceController::class, 'index']);
        $router->get('/{resource:' . $resources . '}/create', [ResourceController::class, 'create']);
        $router->post('/{resource:' . $resources . '}', [ResourceController::class, 'store']);
        $router->post('/{resource:' . $resources . '}/bulk', [ResourceController::class, 'bulk']);
        $router->post('/{resource:' . $resources . '}/reorder', [ResourceController::class, 'reorder']);
        $router->get('/{resource:' . $resources . '}/{id:\d+}/edit', [ResourceController::class, 'edit']);
        $router->post('/{resource:' . $resources . '}/{id:\d+}', [ResourceController::class, 'update']);
        $router->post('/{resource:' . $resources . '}/{id:\d+}/delete', [ResourceController::class, 'destroy']);
    });

    // Any unmatched path renders the panel's own 404 page.
    $router->fallback(static function (\Zuste\Core\Request $request): never {
        throw \Zuste\Core\HttpException::notFound(
            str_starts_with($request->path(), '/admin')
                ? 'That admin page does not exist.'
                : 'Page not found.'
        );
    });
};
