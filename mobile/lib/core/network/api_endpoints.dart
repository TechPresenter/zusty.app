/// Every route the app calls, mirroring `backend/routes/api.php`.
///
/// Paths are relative to the Dio base URL (`{origin}/api/v1`), so they always
/// start with a slash and never repeat the version prefix.
abstract final class ApiEndpoints {
  // -- System --------------------------------------------------------------
  static const health = '/health';
  static const appConfig = '/app/config';
  static const settings = '/settings';

  /// Every admin-managed option list in one cacheable payload.
  static const taxonomies = '/app/taxonomies';

  /// Admin-managed intro slides shown before sign-up.
  static const onboarding = '/app/onboarding';
  static String taxonomy(String key) => '/app/taxonomies/$key';

  // -- Auth ----------------------------------------------------------------
  static const register = '/auth/register';
  static const login = '/auth/login';
  static const firebaseSignIn = '/auth/firebase';
  static const refresh = '/auth/refresh';
  static const forgotPassword = '/auth/forgot-password';
  static const resetPassword = '/auth/reset-password';
  static const sendOtp = '/auth/otp/send';
  static const verifyOtp = '/auth/otp/verify';
  static const guestSignIn = '/auth/guest';
  static const guestUpgrade = '/auth/guest/upgrade';
  static const me = '/auth/me';
  static const logout = '/auth/logout';
  static const logoutAll = '/auth/logout-all';
  static const changePassword = '/auth/change-password';
  static const sessions = '/auth/sessions';
  static String session(int id) => '/auth/sessions/$id';

  // -- Privacy & account management ------------------------------------------
  static const privacy = '/me/privacy';
  static const privacyCode = '/me/privacy/verification-code';
  static const privacyExports = '/me/privacy/exports';

  /// A fresh link for an export that is still ready, after step-up.
  static String privacyExportLink(int id) => '/me/privacy/exports/$id/link';
  static const privacyDeleteAccount = '/me/privacy/delete-account';

  // -- Discovery -----------------------------------------------------------
  static const home = '/home';
  static const explore = '/explore';
  static const categories = '/categories';
  static const creators = '/creators';
  static const searchSuggestions = '/search/suggestions';
  static const searchHistory = '/search/history';

  /// Accepts either a numeric id or a public @handle.
  static String creator(String handleOrId) => '/creators/$handleOrId';
  static String creatorPortfolio(int creatorId) => '/creators/$creatorId/portfolio';

  /// Skills, education, experience, awards, working hours and FAQs. A separate
  /// call from the profile so the profile header is not held up by it — the
  /// extended block renders below the fold.
  static String creatorFields(int creatorId) => '/creators/$creatorId/fields';

  /// Share URL and QR payload. `qr` selects the representations wanted;
  /// omitting it returns the light default rather than every encoding.
  static String creatorShare(int creatorId) => '/creators/$creatorId/share';

  /// Records a share, a QR download, a portfolio download or a social click.
  static String creatorEngagement(int creatorId) => '/creators/$creatorId/engagement';
  static String creatorReviews(int creatorId) => '/creators/$creatorId/reviews';
  static String creatorAvailability(int creatorId) => '/creators/$creatorId/availability';
  static String toggleFavorite(int creatorId) => '/creators/$creatorId/favorite';
  static String toggleFollow(int creatorId) => '/creators/$creatorId/follow';

  // -- Account -------------------------------------------------------------
  static const profile = '/me';
  static const notificationPreferences = '/me/notification-preferences';
  static const devices = '/me/devices';
  static const upload = '/me/upload';
  // -- KYC and business verification ---------------------------------------
  static const kyc = '/me/kyc';
  static const kycDetails = '/me/kyc/details';
  static const kycDocuments = '/me/kyc/documents';
  static const kycSubmit = '/me/kyc/submit';
  static const kycTimeline = '/me/kyc/timeline';

  /// Public rulebook — what a brand or creator will be asked for.
  static const kycRequirements = '/kyc/requirements';
  static String kycDocument(int id) => '/me/kyc/documents/$id';
  static String kycDocumentDownload(int id) => '/me/kyc/documents/$id/download';
  static const favorites = '/me/favorites';
  static const referrals = '/me/referrals';
  static const myReviews = '/me/reviews';

  // -- Creator self-management --------------------------------------------
  static const myCreatorProfile = '/me/creator';
  static const creatorAnalytics = '/me/creator/analytics';

  /// The creator's own completion breakdown and the features it unlocks.
  static const creatorCompletion = '/me/creator/completion';

  // ---- Contact points and verified changes -------------------------------
  static const contacts = '/me/contacts';
  static const contactAlternates = '/me/contacts/alternates';
  static const contactChange = '/me/contacts/change';

  static String contactAlternate(int id) => '/me/contacts/alternates/$id';
  static String contactAlternateVerify(int id) => '/me/contacts/alternates/$id/verify';
  static String contactRequestVerify(int id) => '/me/contacts/requests/$id/verify';
  static String contactRequestResend(int id) => '/me/contacts/requests/$id/resend';
  static String contactRequestCancel(int id) => '/me/contacts/requests/$id/cancel';

  /// The creator's extended profile — the editable counterpart of
  /// [creatorFields], which serves the public view.
  static const myCreatorFields = '/me/creator/fields';
  static const myFieldDetails = '/me/creator/fields/details';

  static const myCredentials = '/me/creator/credentials';
  static const myCredentialsOrder = '/me/creator/credentials/order';
  static String myCredential(int id) => '/me/creator/credentials/$id';

  static const myExperience = '/me/creator/experience';
  static const myExperienceOrder = '/me/creator/experience/order';
  static String myExperienceItem(int id) => '/me/creator/experience/$id';

  static const myFaqs = '/me/creator/faqs';
  static const myFaqsOrder = '/me/creator/faqs/order';
  static String myFaq(int id) => '/me/creator/faqs/$id';

  static const myWorkingHours = '/me/creator/working-hours';
  static String myWorkingHour(int id) => '/me/creator/working-hours/$id';

  /// Skills, interests, professions, languages, service areas. [section] is a
  /// section name, not a taxonomy key — the server maps one to the other.
  static String myTerms(String section) => '/me/creator/fields/terms/$section';
  static String myTermsPrimary(String section) =>
      '/me/creator/fields/terms/$section/primary';
  static String myTerm(String section, String value) =>
      '/me/creator/fields/terms/$section/$value';
  static const creatorPackages = '/me/creator/packages';
  static const creatorPortfolioItems = '/me/creator/portfolio';
  static const creatorSocial = '/me/creator/social';
  static const creatorSetAvailability = '/me/creator/availability';
  static String creatorPackage(int packageId) => '/me/creator/packages/$packageId';
  static String creatorPortfolioItem(int itemId) => '/me/creator/portfolio/$itemId';

  // -- Bookings ------------------------------------------------------------
  static const bookings = '/bookings';
  static const bookingQuote = '/bookings/quote';
  static String booking(int id) => '/bookings/$id';
  static String acceptBooking(int id) => '/bookings/$id/accept';
  static String rejectBooking(int id) => '/bookings/$id/reject';
  static String counterOffer(int id) => '/bookings/$id/counter-offer';
  static String acceptOffer(int id) => '/bookings/$id/accept-offer';
  static String deliverBooking(int id) => '/bookings/$id/deliver';
  static String completeBooking(int id) => '/bookings/$id/complete';
  static String cancelBooking(int id) => '/bookings/$id/cancel';
  static String bookingCoupons(int id) => '/bookings/$id/coupons';
  static String bookingCoupon(int id) => '/bookings/$id/coupon';
  static String cancellationPreview(int id) => '/bookings/$id/cancellation-preview';
  static String invoice(int id) => '/bookings/$id/invoice';
  static String reviewBooking(int id) => '/bookings/$id/review';

  // -- Brand campaigns -----------------------------------------------------
  static const campaigns = '/campaigns';
  static String campaign(int id) => '/campaigns/$id';
  static String campaignPublish(int id) => '/campaigns/$id/publish';
  static String campaignClose(int id) => '/campaigns/$id/close';
  static String campaignRecipients(int id) => '/campaigns/$id/recipients';
  static String campaignInvite(int id) => '/campaigns/$id/invite';

  /// A creator applies to an open brief (rate, pitch, portfolio links).
  static String campaignApply(int id) => '/campaigns/$id/apply';

  /// The creator's own applications and invitations, newest first.
  static const myApplications = '/me/applications';

  // -- AI (switched on in Admin → Settings → AI) ---------------------------
  static const aiBio = '/ai/bio';
  static const aiCampaignBrief = '/ai/campaign-brief';
  static const aiCreatorMatch = '/ai/creator-match';
  static String campaignRecipient(int campaignId, int recipientId) =>
      '/campaigns/$campaignId/recipients/$recipientId';

  // -- Invoices ------------------------------------------------------------
  static const invoices = '/invoices';
  static String invoiceDetail(int id) => '/invoices/$id';

  // -- Deliverable review --------------------------------------------------
  static String requestRevision(int bookingId) =>
      '/bookings/$bookingId/request-revision';
  static String disputeBooking(int bookingId) => '/bookings/$bookingId/dispute';

  // -- Reviews and reports -------------------------------------------------
  static const reports = '/reports';
  static String replyToReview(int reviewId) => '/reviews/$reviewId/reply';

  // -- Wallet --------------------------------------------------------------
  static const wallet = '/wallet';
  static const walletTransactions = '/wallet/transactions';
  static const walletTopup = '/wallet/topup';
  static const walletWithdraw = '/wallet/withdraw';
  static const withdrawals = '/wallet/withdrawals';
  static const bankAccounts = '/wallet/bank-accounts';
  static String cancelWithdrawal(int id) => '/wallet/withdrawals/$id/cancel';
  static String bankAccount(int id) => '/wallet/bank-accounts/$id';

  // -- Payments ------------------------------------------------------------
  static const payments = '/payments';
  static const paymentMethods = '/payments/methods';
  static const verifyPayment = '/payments/verify';
  static String payBooking(int bookingId) => '/payments/booking/$bookingId';

  // -- Messaging -----------------------------------------------------------
  static const conversations = '/conversations';
  static const startConversation = '/conversations/start';
  static String messages(int conversationId) => '/conversations/$conversationId/messages';
  static String markConversationRead(int conversationId) => '/conversations/$conversationId/read';
  static String typing(int conversationId) => '/conversations/$conversationId/typing';
  static String muteConversation(int conversationId) => '/conversations/$conversationId/mute';

  // -- Notifications -------------------------------------------------------
  static const notifications = '/notifications';
  static const readAllNotifications = '/notifications/read-all';
  static String readNotification(int id) => '/notifications/$id/read';
  static String notification(int id) => '/notifications/$id';

  // -- Support -------------------------------------------------------------
  static const tickets = '/support/tickets';
  static String ticket(int id) => '/support/tickets/$id';
  static String replyToTicket(int id) => '/support/tickets/$id/reply';

  // -- CMS -----------------------------------------------------------------
  static const pages = '/pages';
  static const faqs = '/faqs';
  static const blogs = '/blogs';
  static const testimonials = '/testimonials';
  static const contact = '/contact';
  static String page(String slug) => '/pages/$slug';
  static String blog(String slug) => '/blogs/$slug';
  static String rateFaq(int id) => '/faqs/$id/helpful';
}
