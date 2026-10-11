-- =====================================================================
-- Zusty Influencer Marketplace — Seed 002 (CMS + notification content)
-- Idempotent. Copy is taken from the reference design where available.
-- =====================================================================

SET NAMES utf8mb4;

-- The review window after which delivered work completes on its own
-- (booking.auto_complete_days, seeded by 001), quoted by the payment FAQ, the
-- terms, the refund policy and the about page. Migrations 053 and 059 print
-- the same into live rows.
SET @seed_auto_days := GREATEST(1, COALESCE(
    (SELECT CAST(`value` AS UNSIGNED) FROM `settings`
      WHERE `group_key` = 'booking' AND `setting_key` = 'auto_complete_days' LIMIT 1),
    7));
SET @seed_review_window := IF(@seed_auto_days = 1, '1 day', CONCAT(@seed_auto_days, ' days'));

-- ---------------------------------------------------------------------
-- FAQs — the first five mirror the FAQs screen of the reference design.
-- Every answer describes what the app does: bookings are paid on Cashfree's
-- checkout, nobody pays money into the wallet, and support is by email.
-- ---------------------------------------------------------------------
INSERT INTO `faqs` (`id`, `category`, `question`, `answer`, `audience`, `is_active`, `sort_order`) VALUES
  (1, 'general', 'How does Zusty work?',
      'Brands find creators and book them at the prices on their profiles, or post a campaign for creators to apply to. Zusty holds the brand''s payment until the work is approved. Discovery, chat, payments, invoices and payouts all happen in the app.',
      'all', 1, 1),
  (2, 'booking', 'How do I book a creator?',
      'Open a creator''s profile, choose a package or send a custom request, and add your brief and dates. The creator can accept, decline or make a counter offer. A request the creator does not answer expires on its own, and nothing is charged until the creator accepts and you pay.',
      'brands', 1, 2),
  (3, 'payment', 'What payment methods are accepted?',
      'Each booking is paid through Cashfree''s secure checkout, with UPI (Google Pay, PhonePe, Paytm), a debit or credit card, or net banking. If a refund has gone to your Zusty wallet and the balance covers the whole booking, you can pay with that instead. Every payment is processed over an encrypted connection by a PCI-DSS compliant gateway.',
      'all', 1, 3),
  (4, 'booking', 'Can I cancel a booking?',
      'Yes. Before you pay, cancelling is free. After you pay, you get a full refund if you cancel well before the delivery date; closer to it, a late-cancellation fee is kept and paid to the creator. The app shows the exact refund before you confirm. If the creator cancels, you get a full refund including fees. Refunds go back to your original payment method, or to your Zusty wallet when they cannot go back there automatically.',
      'all', 1, 4),
  (5, 'payment', 'Is my payment secure?',
      CONCAT('Yes. You pay on Cashfree''s hosted checkout over an encrypted connection, and Zusty never sees your full card details. Your payment is held by Zusty and released to the creator when you approve the work, or automatically ', @seed_review_window, ' after delivery if you do not approve it, ask for changes or raise a dispute. If there is a dispute, our team reviews it before any money is released.'),
      'all', 1, 5),
  (6, 'creator', 'How do I become a verified creator?',
      'Go to Profile → Verification and submit your PAN and a masked copy of your Aadhaar (only the last four digits visible), with the other details the screen asks for. A person on our team reviews every submission, and the blue tick appears only after that approval. You need an approved verification to accept bookings and withdraw earnings.',
      'creators', 1, 6),
  (7, 'wallet', 'When can I withdraw my earnings?',
      'When a booking completes, your earnings are credited to your wallet and become available to withdraw after a short hold. From your Wallet, tap Withdraw and choose a bank account or UPI ID you have added; you need an approved verification first. Our team reviews each withdrawal before it is paid, and the money usually arrives within 1–3 business days of approval.',
      'creators', 1, 7),
  (8, 'payment', 'What fees does Zusty charge?',
      'Brands pay a platform fee on top of the agreed creator fee, and creators pay a commission on their earnings. Both rates are shown transparently on the payment screen and on every invoice before you confirm.',
      'all', 1, 8),
  (9, 'account', 'How do I delete my account?',
      'Go to Settings → Privacy & data → Delete my account (also in the Profile menu under Privacy & data). Confirm it''s you with your password or a one-time code, then type DELETE. Anything still in progress must be settled first: bookings in progress, disputes under review, money in your wallet, and withdrawals or refunds still being processed. A small balance that can''t be paid out can be given up instead. The screen shows what is in the way. Deletion happens as soon as you confirm and can''t be undone: your profile, contact details, creator profile, saved items, payout details and uploaded files (except files attached to bookings or sent in chat) are erased, and you''re signed out everywhere. Bookings, payments and invoices are kept because tax law requires it, and messages you sent and reviews you wrote stay without your name. Want a copy of your data? Use Download my data before you delete.',
      'all', 1, 9),
  (10,'security','What happens if a creator does not deliver?',
      'Ask for changes from the booking screen, or raise a dispute. While a dispute is open, Zusty keeps holding the payment. Our team reviews the brief, the deliverables and the messages from both sides, then releases, refunds or splits the payment. A refund goes back to your original payment method, or to your Zusty wallet when it cannot go back there automatically or you agree that with our team.',
      'brands', 1, 10),
  (11,'creator','How do I set my pricing?',
      'Add packages to your creator profile. Each package sets a platform, deliverable type, quantity, price, turnaround time and number of revisions. Brands book a package directly, so clear pricing wins more collaborations.',
      'creators', 1, 11),
  (12,'booking','What is a counter offer?',
      'If a brand''s budget does not match your rate, you can propose different terms instead of rejecting the request. The brand can then accept your counter offer, reply with another, or withdraw the request.',
      'all', 1, 12),
  (13,'security','How do I report or block someone?',
      'Open the profile, chat, review, campaign brief or booking and use its menu to report it; a person on our team reviews every report. You can also block someone: then neither of you can message the other or start a booking, offer, invitation or application with the other. A booking that is already paid for can still be delivered, completed or disputed. You can see and unblock the people you blocked under Settings → Privacy & data → Blocked accounts.',
      'all', 1, 13)
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `answer` = VALUES(`answer`),
  `category` = VALUES(`category`), `audience` = VALUES(`audience`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Static pages
-- ---------------------------------------------------------------------
INSERT INTO `pages` (`slug`, `title`, `subtitle`, `content`, `template`, `is_system`, `is_active`, `show_in_app`, `sort_order`, `meta_title`, `meta_description`, `published_at`) VALUES
('about', 'About Zusty', 'Shoot. Connect. Grow.',
CONCAT(
         '<h2>Who we are</h2><p>Zusty is an influencer marketplace for India that makes creator collaborations as simple as booking a table. Brands find creators and book them at the prices on their profiles, or post a campaign for creators to apply to, and then handle the brief, the negotiation, the payment and the delivery in one place. Zusty is operated by Appsgain Technologies Private Limited.</p><h2>Why we exist</h2><p>Influencer marketing still runs on spreadsheets, screenshots and unpaid invoices. Brands find it hard to tell a real audience from a bought one, and creators chase payments for months. Zusty brings verified identities, clear pricing and payment protection to both sides.</p><h2>How it works</h2><ol><li><strong>Discover</strong> — search creators by category, city, audience size and budget, or show verified creators only.</li><li><strong>Book</strong> — send a brief with your budget, and negotiate with counter offers if you need to.</li><li><strong>Pay securely</strong> — pay on Cashfree''s checkout; Zusty holds the payment until you approve the work, or releases it to the creator automatically ', @seed_review_window, ' after delivery if you do not respond.</li><li><strong>Approve</strong> — review the delivered work, ask for changes if you need them, and rate the collaboration.</li></ol><h2>Our commitment</h2><p>Creators verify their identity before they can accept bookings or withdraw earnings, and a person on our team reviews every verification; the blue tick appears only after that approval. Every payment is recorded and invoiced, and every dispute is reviewed by a person.</p>'),
'default', 1, 1, 1, 1, 'About Zusty — Influencer Marketplace', 'Learn how Zusty connects brands with verified creators and holds each payment until the work is approved.', NOW()),

('terms', 'Terms & Conditions', 'Last updated: 10 October 2026',
CONCAT(
         '<h2>1. Acceptance of terms</h2><p>By creating an account or using the Zusty app, website or API (together, the "Platform"), you agree to these Terms &amp; Conditions, our <a href="/privacy">Privacy Policy</a>, our <a href="/refund-policy">Refund &amp; Cancellation Policy</a> and our <a href="/community-guidelines">Community Guidelines</a>. The Platform is operated by Appsgain Technologies Private Limited ("Zusty", "we"). If you do not agree, do not use the Platform.</p>',
         '<h2>2. Eligibility</h2><p>You must be at least 18 years old and legally capable of entering into a binding contract. Business accounts must be registered by an authorised representative of the organisation.</p>',
         '<h2>3. Accounts</h2><p>Every account needs an email address you can receive mail at. You sign in with your email and password, a one-time code sent to your email, or Google. You are responsible for the accuracy of the information you provide and for all activity under your account. Tell us straight away about any unauthorised access. We may suspend an account that breaks these terms, submits fraudulent documents or harms other users.</p>',
         '<h2>4. Verification</h2><p>Creators verify their identity, and brands their business, before they can take part in paid bookings; creators also need an approved verification to withdraw earnings. A person on our team reviews every verification, and the blue tick is shown only after that approval. Verification confirms who you are. It is not an endorsement of anyone''s work.</p>',
         '<h2>5. The marketplace role</h2><p>Zusty is a venue. Contracts for content are formed directly between the brand and the creator. We provide payment protection, messaging, dispute resolution and record-keeping, but we are not a party to the collaboration and do not guarantee campaign performance.</p>',
         '<h2>6. Bookings and payments</h2><p>A booking becomes binding when the creator accepts and the brand pays. Brands pay for each booking separately on Cashfree''s hosted checkout, by UPI, card or net banking, or from their Zusty wallet when a refund credited there covers the whole booking. The payment is held by Zusty and released to the creator when the brand approves the deliverables. If the brand does not approve the work, ask for changes or raise a dispute within ', @seed_review_window, ' of delivery, the booking completes automatically and the payment is released to the creator. If there is a dispute, our team reviews it before any money is released. Platform fees, commissions and taxes are shown before payment and on every invoice.</p>',
         '<h2>7. Wallet</h2><p>Your Zusty wallet records money we hold for you: a creator''s earnings from completed bookings, and any refunds or rewards credited to it. You cannot pay money into the wallet. Creators can withdraw available earnings to a bank account or UPI ID once their verification is approved, and our team reviews each withdrawal before it is paid. The wallet is not a bank account and earns no interest.</p>',
         '<h2>8. Cancellations and refunds</h2><p>Cancellation windows, retained fees and refunds are set out in our <a href="/refund-policy">Refund &amp; Cancellation Policy</a>, which forms part of these terms. Refunds go back to the original payment method by default.</p>',
         '<h2>9. Content and licensing</h2><p>Creators retain ownership of the content they produce. Unless a package states otherwise, the brand receives a non-exclusive licence to use the delivered content on the agreed platforms for the agreed period. Usage beyond that scope must be negotiated separately.</p>',
         '<h2>10. Reviews, reports and blocking</h2><p>Reviews must describe a real collaboration honestly. You can report a profile, message, review, campaign brief or booking from its menu in the app, and block another user. Once either of you blocks the other, you cannot message each other or start a booking, offer, invitation or application with each other; a booking already paid for can still be delivered, completed or disputed. We review every report and may remove content, or suspend or close accounts that break these terms or our <a href="/community-guidelines">Community Guidelines</a>.</p>',
         '<h2>11. Prohibited conduct</h2><p>You must not: take payment off-platform to avoid fees; misrepresent audience metrics; post fake reviews, or offer or accept anything in return for a review; submit content you do not own; harass other users; scrape the Platform; or attempt to circumvent our security controls.</p>',
         '<h2>12. Fees</h2><p>We charge brands a platform fee and creators a commission. Rates are published in the app and may change with 30 days'' notice. Changes never apply retroactively to a booking already paid for.</p>',
         '<h2>13. Messages from us</h2><p>We send service messages about your account, bookings, payments and payouts in the app, by push notification and by email. We do not send text messages. Marketing messages are sent only if you opt in, and you can opt out at any time in Settings.</p>',
         '<h2>14. Termination</h2><p>You may close your account at any time once bookings in progress, disputes, your wallet balance and any pending withdrawals or refunds are settled. We may terminate or suspend access for breach of these terms, with notice where practicable.</p>',
         '<h2>15. Liability</h2><p>To the maximum extent permitted by law, our aggregate liability arising from your use of the Platform is limited to the total fees you paid us in the three months preceding the claim. We are not liable for indirect or consequential loss.</p>',
         '<h2>16. Governing law</h2><p>These terms are governed by the laws of India. The courts at Mumbai, Maharashtra have exclusive jurisdiction.</p>',
         '<h2>17. Contact and complaints</h2><p>Questions about these terms: <a href="mailto:support@zusty.app">support@zusty.app</a>. Complaints go to our Grievance Officer at the same address, who acknowledges them within 24 hours and resolves them within 15 days; section 8 of our <a href="/privacy">Privacy Policy</a> explains how.</p>'),
'legal', 1, 1, 1, 2, 'Terms & Conditions — Zusty', 'The terms governing the use of the Zusty influencer marketplace.', NOW()),

('privacy', 'Privacy Policy', 'Last updated: 10 October 2026',
'<h2>1. Who we are</h2><p>Zusty (the Zusty app, the website at zusty.app and its API) is operated by Appsgain Technologies Private Limited (“Zusty”, “we”, “us”). This policy explains what personal data we collect, why we collect it, who we share it with, how long we keep it and the rights you have over it. Zusty is a marketplace where brands in India book creators. It is only for people aged 18 and over.</p><h2>2. Data we collect</h2><ul><li><strong>Account data</strong> — your name, email address (every account needs one), password (stored only as a bcrypt hash), profile photo, city, whether you are a brand or a creator, and how you sign in, including a linked Google account. A phone number is optional. Our payment provider, Cashfree, needs a mobile number for every checkout: we send the one on your account, or, if there is none, the app asks for one when you pay and we send it to Cashfree with that order only. That number is not saved to your account, and if the payment cannot be started, the log we keep of that request shows only its last four digits. We never send you text messages.</li><li><strong>Identifiers</strong> — your account ID, the Firebase user ID created when you sign in with Google, and your referral code.</li><li><strong>Profile data</strong> — for creators: bio, categories, languages, packages and prices, portfolio images, social handles with the audience figures you enter, and availability. For brands: company details and logo.</li><li><strong>Verification (KYC) data</strong> — for creators: PAN, date of birth, gender, address, a masked Aadhaar image and an optional selfie. We check your Aadhaar number when you submit it but never store or log the full number: we keep only a keyed hash of it and its last four digits. For brands: GSTIN, CIN for companies, business details and the documents you upload, such as a GST certificate. We ask for your consent before you submit. Verification data is stored encrypted and only the people who review it can see it.</li><li><strong>Payment and payout data</strong> — brands pay on Cashfree’s hosted checkout, so Zusty never receives your full card number, CVV or banking password. Cashfree tells us each payment’s reference, amount and status and the type of method used, such as UPI, card or net banking. We keep Cashfree’s reply with the card details, UPI ID and contact details taken out; for a net banking payment it can still name your bank. For payouts we keep the bank account (account holder, account number, IFSC and bank) or UPI ID you add.</li><li><strong>Purchase and wallet history</strong> — bookings, payments, invoices, coupons and refunds, and for creators their earnings, wallet entries and withdrawals. The wallet holds a creator’s earnings, any refunds credited to it, and referral rewards: while our referral programme runs, you and a friend you invited each get one when that friend completes a first booking as a brand. You cannot pay money into the wallet.</li><li><strong>Messages and content you create</strong> — in-app messages and the images you send in them, campaign briefs, applications, offers and counter offers, links to delivered work, reviews and replies, support tickets, and the reports, blocks and dispute notes you submit.</li><li><strong>Activity in the app</strong> — your searches and the filters you use, the profiles you view, the creators you save or follow, and when you were last active.</li><li><strong>Approximate location</strong> — only if you tap “Use my current location”. Your phone takes a coarse location fix and turns it into the nearest city on the phone itself. Only the city name is sent to us; precise coordinates never leave your phone. You can always choose a city yourself instead.</li><li><strong>Voice search</strong> — only when you tap the microphone. Your phone’s speech recognition service (part of Android, not Zusty) turns what you say into text, and Zusty receives only that text, which is used as a search term like anything you type. We never receive or keep the audio.</li><li><strong>Device and diagnostic data</strong> — an install ID the app creates, the push token Firebase Cloud Messaging gives the app so we can send you notifications, your device model, operating system and app version, your IP address, and crash logs and diagnostics collected by Firebase Crashlytics in the released app.</li></ul><p>We do not read your contacts, calendar, text messages, call log or the list of apps on your phone. You choose photos and documents with Android’s own picker, so the app does not need access to your photo library.</p><h2>3. How we use it</h2><ul><li><strong>To run Zusty</strong> — your account and sign-in, profiles, search, bookings, campaigns, chat, payments, invoices, payouts and support.</li><li><strong>For safety and the law</strong> — to verify identities and businesses, review reports and disputes, prevent fraud and abuse, keep sign-ins secure, enforce our terms, and keep the tax, accounting and KYC records the law requires.</li><li><strong>To tell you what is happening</strong> — notifications about your bookings, payments, payouts, messages, verification and account, in the app, by push notification and by email. You can switch push and email off under <strong>Settings → Notifications</strong>.</li><li><strong>For statistics</strong> — aggregate figures, such as how often a profile is viewed and which searches are popular, which we use to run and improve Zusty.</li><li><strong>For marketing, only if you opt in</strong> — product news and offers by email and push notification. Marketing is off unless you tick the box at sign-up or turn on product updates and offers under <strong>Settings → Notifications</strong>, and you can turn it off there at any time. Accounts created before the sign-up form asked had product updates switched on by default; if yours is one of them, you can turn them off in the same place.</li></ul><p>We do not sell your personal data or share it for advertising. The app shows no ads, contains no advertising SDK and does not use your device’s advertising ID.</p><h2>4. Who we share it with</h2><ul><li><strong>Other users, as the service needs</strong> — signed-in users can see your public profile. When you book, apply, make an offer or send a message, the other person sees what that involves, such as your name, profile, messages and the booking details. Other users never see your verification documents or numbers.</li><li><strong>Service providers</strong> that process data for us, under contract and only on our instructions: <strong>Cashfree Payments</strong> (checkout and refunds; it receives your name, email address, phone number and the amount of each order), <strong>Google Firebase</strong> (Firebase Authentication for Google sign-in, Firebase Cloud Messaging for push notifications and Firebase Crashlytics for crash reports) and <strong>Hostinger</strong> (our servers and email delivery). Some of them may process data outside India.</li><li><strong>Authorities</strong> — when the law requires it, for example tax authorities or a court order.</li></ul><h2>5. Retention and deletion</h2><p>We keep your account data while your account is open. Some data is deleted sooner, automatically: our copies of the emails and push notifications we send you after 7 days (30 days if they were never delivered), payment gateway callbacks after 90 days, API request logs after 180 days, sign-in history after one year, and data export files once their download link expires or has been used up. Identity documents are deleted 30 days after a verification draft you never submitted was last changed, 180 days after a verification is rejected or left waiting for changes, and 90 days after one is suspended or expires; a document you replace is deleted after 30 days.</p><p><strong>Deleting your account.</strong> You can delete your account at any time in the app under <strong>Settings → Privacy &amp; data → Delete my account</strong>. You confirm it is you with your password, a one-time code sent to your email or by signing in with Google again, then type DELETE. If you cannot use the app, see section 7.</p><p>An account cannot be deleted while something is still in progress: a booking in progress, a dispute under review, money in your wallet (including earnings still on hold), a withdrawal or refund still being processed, or a wallet on hold. The app shows what needs to be settled. A small balance that cannot be paid out can be given up instead: the app offers this when it applies, or you can contact support and we will pay it out first. We do not cancel bookings for you.</p><p><strong>What we erase.</strong> Deletion takes effect as soon as you confirm and cannot be undone. We erase your name, photo, bio, city and company details, and every email address and phone number on your account, except where listed below; your creator profile, portfolio, packages, social accounts, availability and its edit history; your saved creators, follows, the accounts you blocked, search history, the profiles you viewed and notifications, and our copies of the emails and push notifications sent to you; your password, linked Google sign-in, sessions, devices and push tokens, and you are signed out everywhere; your saved bank accounts and UPI IDs; the files you uploaded, except files attached to bookings or sent in chat; and any copies of your data still stored on our servers. Open and draft campaigns are closed. Identity (KYC) document images and numbers are deleted; we keep only a minimal record that a verification took place, with its reference, outcome and dates.</p><p><strong>What we keep, and why.</strong></p><ul><li><strong>Bookings, payments, invoices, refunds, payouts and wallet entries</strong>, because Indian tax and accounting law requires us to keep financial records, generally for eight years. They show “Deleted user” in place of your account. Tax invoices and payout statements stay exactly as issued, with the billing name, address and GSTIN printed on them, and payout records keep the account holder''s name, the bank and a masked account number or UPI ID. Payment providers keep their own records of your payments.</li><li><strong>Messages you sent</strong> stay in the other person''s conversation, shown as from “Deleted user”. They are part of that person''s record and may be needed to resolve a dispute. Notifications about you in their app lose your name, but emails we already delivered to them cannot be recalled.</li><li><strong>Reviews you wrote</strong> stay on the creator''s or brand''s page, shown as from “Deleted user” with no photo, so ratings stay honest.</li><li><strong>Support tickets, contact-form messages and reports</strong>: your name and contact details are removed from them, but what you and our team wrote stays as written, for safety, fraud prevention and legal claims.</li><li><strong>Security logs</strong>: the time, IP address and device of each sign-in and of other account activity, without your email address or phone number, kept for 12 months to detect fraud and abuse.</li><li><strong>A minimal identity verification record</strong>: that your identity was checked, when, the outcome, and the name and IP address recorded at each step, kept for the period the law requires.</li><li><strong>A deletion record</strong>: that an account was deleted, when, how it was confirmed, and the reason you chose and anything you wrote with it, with no contact details.</li><li><strong>Backups</strong>: copies of our database are kept for up to 14 days to recover from failures, then deleted. If we ever restore one, we delete your account again straight away.</li></ul><p>Copies of your data that you downloaded stay on your device. We cannot delete those. The same list is on our <a href="/account-deletion">account deletion page</a>.</p><h2>6. Security</h2><p>Everything sent between the app, our servers and our service providers is encrypted in transit with TLS. Passwords are hashed with bcrypt, and identity numbers and verification documents are encrypted with AES-256-GCM. Access to production data is restricted to the people who need it, role-based and logged.</p><h2>7. Your rights and choices</h2><p>You can see and correct most of your data in the app, and remove things such as your photo, saved creators and saved payout accounts yourself. To get a copy of the personal data we hold about you, use <strong>Settings → Privacy &amp; data → Download my data</strong>: after you confirm it is you, you get a ZIP file through a download link that works for a limited time. To delete your account, see section 5. You can withdraw marketing consent at any time under <strong>Settings → Notifications</strong>, and turn off location or microphone access for Zusty in your phone’s settings. If you cannot use the app, write to <a href="mailto:support@zusty.app">support@zusty.app</a> from the email address on your account; we act on the request once we have confirmed it comes from you.</p><h2>8. Questions and grievances</h2><p>Questions about this policy go to <a href="mailto:support@zusty.app">support@zusty.app</a>. Zusty is operated by Appsgain Technologies Private Limited.</p><p><strong>Grievance Officer.</strong> Complaints about Zusty, about content or conduct on it, or about how we handle your personal data go to the Grievance Officer, Appsgain Technologies Private Limited, at <a href="mailto:support@zusty.app">support@zusty.app</a>. Put “Grievance” in the subject line. We acknowledge a complaint within 24 hours and resolve it within 15 days of receiving it, as rule 3(2) of the Information Technology (Intermediary Guidelines and Digital Media Ethics Code) Rules, 2021 requires.</p><p>The Grievance Officer is also our contact for requests under the Digital Personal Data Protection Act, 2023: write to the same address to access, correct or erase your personal data, or to withdraw a consent you gave. We act on a request once we have confirmed it comes from you.</p><h2>9. Children</h2><p>Zusty is only for people aged 18 and over. We do not knowingly collect data from anyone younger, and we close an account if we learn it belongs to someone under 18.</p><h2>10. Changes</h2><p>When we change this policy we update the date at the top of this page. If a change materially affects how we use your data, we tell you in the app or by email before it takes effect.</p>',
'legal', 1, 1, 1, 3, 'Privacy Policy — Zusty', 'What personal data Zusty collects, why, who processes it, how long it is kept, and how to export or delete it.', NOW()),

('refund-policy', 'Refund & Cancellation Policy', 'Payment protection for both sides',
CONCAT(
         '<h2>Payment protection</h2><p>Brands pay for each booking on Cashfree''s checkout. The money is held by Zusty, not passed to the creator, and is released when the brand approves the deliverables, or automatically ', @seed_review_window, ' after delivery if the brand does not approve the work, ask for changes or raise a dispute.</p>',
         '<h2>Cancellation by the brand</h2><ul><li><strong>Before you pay</strong> — cancel free of charge; nothing has been taken.</li><li><strong>After you pay, well before the delivery date</strong> — a full refund of the amount paid, including the platform fee.</li><li><strong>After you pay, close to the delivery date</strong> — a late-cancellation fee is kept and paid to the creator for the time they reserved, and the rest is refunded. The app shows the cut-off and the exact refund before you confirm.</li><li><strong>After delivery</strong> — the booking can no longer be cancelled; ask for changes or raise a dispute instead.</li></ul>',
         '<h2>Cancellation by the creator</h2><p>If a creator cancels an accepted booking, the brand gets a full refund, including all fees, and the cancellation is recorded against the creator''s completion rate.</p>',
         '<h2>Disputes</h2><p>Once a booking is paid, either side can raise a dispute from the booking screen. Both sides can add evidence, a person on our team reviews the brief, the deliverables and the messages, and the money stays held by Zusty until then. The outcome decides whether the payment is released to the creator, refunded to the brand or split between them.</p>',
         '<h2>Refund destination and timing</h2><p>Refunds go back to the original payment method by default. A payment made from Zusty wallet balance is refunded to the wallet. If a payment cannot be returned to its original method automatically, or our support team agrees it with you, the refund is credited to your Zusty wallet instead. You can use it to pay for a booking it covers in full, or withdraw it to your bank account or UPI ID; for an amount below the minimum withdrawal, ask our support team to pay it out. Refunds to a card or bank account usually appear within 5–7 business days, depending on your bank; UPI refunds are usually quicker.</p>'),
'legal', 1, 1, 1, 4, 'Refund & Cancellation Policy — Zusty', 'How cancellations, payment releases, disputes and refunds work on Zusty.', NOW()),

('community-guidelines', 'Community Guidelines', 'Keeping the marketplace trustworthy',
'<h2>Be authentic</h2><p>Represent your real audience. Buying followers or engagement, or misreporting metrics, results in permanent removal.</p><h2>Be professional</h2><p>Respond to requests, meet agreed deadlines and communicate early if something slips. Bookings you cancel count against the completion rate shown on your profile.</p><h2>Keep it on-platform</h2><p>Negotiating or paying outside Zusty removes payment protection from both sides and breaches our terms.</p><h2>Respect ownership</h2><p>Only upload content you created or are licensed to use. Disclose paid partnerships as required by the ASCI guidelines.</p><h2>Honest reviews</h2><p>Reviews must describe a real collaboration. Do not offer, ask for or accept anything in return for a review, on Zusty or anywhere else, and do not make a review part of a brief.</p><h2>No harassment or harmful content</h2><p>Discrimination, threats, sexual harassment, hate speech, sexual content, violence, scams and anything illegal are grounds for immediate removal.</p><h2>Reporting and blocking</h2><p>Report a profile, a message, a review, a booking or a campaign brief from its menu in the app. Every report is reviewed by a human moderator, and the person you report is not told who reported them.</p><p>You can also block someone. Once you do, neither of you can message the other or send the other a booking request, an offer, a campaign invitation or an application, and you stop appearing in each other''s search. You can see and unblock the people you blocked under <strong>Settings → Privacy &amp; data → Blocked accounts</strong>. A booking that is already paid for can still be delivered, completed or disputed, so nobody''s payment is held up.</p><h2>How moderation works</h2><p>When we receive a report we look at the reported content, the conversation or booking around it and the history of the accounts involved. Depending on what we find, we may take no action, remove the content, or suspend or close the account. Money on an open booking is settled through the dispute process. If your account is suspended or closed, you can ask us to look again by writing to <a href="mailto:support@zusty.app">support@zusty.app</a>.</p>',
'default', 0, 1, 1, 5, 'Community Guidelines — Zusty', 'The standards every brand and creator on Zusty agrees to uphold.', NOW()),

('contact', 'Contact Us', 'We are here for you',
'<p>We aim to reply within one business day.</p><ul><li><strong>Email</strong> — <a href="mailto:support@zusty.app">support@zusty.app</a></li><li><strong>In the app</strong> — Profile → Help &amp; support → Start a new ticket</li><li><strong>Complaints</strong> — write to our Grievance Officer, Appsgain Technologies Private Limited, at <a href="mailto:support@zusty.app">support@zusty.app</a> with “Grievance” in the subject line. We acknowledge a complaint within 24 hours and resolve it within 15 days.</li></ul><p>Press enquiries and partnership proposals are welcome at the same address.</p>',
'contact', 0, 1, 1, 6, 'Contact Zusty', 'Reach the Zusty team at support@zusty.app.', NOW())
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `subtitle` = VALUES(`subtitle`), `content` = VALUES(`content`),
  `meta_title` = VALUES(`meta_title`), `meta_description` = VALUES(`meta_description`);

-- ---------------------------------------------------------------------
-- Blog categories
-- ---------------------------------------------------------------------
INSERT INTO `blog_categories` (`id`, `name`, `slug`, `description`, `is_active`, `sort_order`) VALUES
  (1, 'For Brands',      'for-brands',      'Playbooks and benchmarks for marketing teams.', 1, 1),
  (2, 'For Creators',    'for-creators',    'Pricing, pitching and growing your business.',  1, 2),
  (3, 'Product Updates', 'product-updates', 'What is new in Zusty.',                         1, 3),
  (4, 'Case Studies',    'case-studies',    'Campaigns that worked, and why.',               1, 4)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `description` = VALUES(`description`);

-- ---------------------------------------------------------------------
-- Notification templates
-- Placeholders use {{snake_case}} and are substituted at send time.
-- ---------------------------------------------------------------------
INSERT INTO `notification_templates`
  (`code`, `name`, `category`, `title`, `body`, `email_subject`, `sms_body`, `icon`, `action_type`, `channels`, `is_active`) VALUES
('booking.requested', 'Booking requested', 'booking',
 'New booking request',
 '{{brand_name}} sent you a booking request worth {{currency}}{{amount}} for {{campaign_type}}.',
 'New booking request from {{brand_name}}',
 'Zusty: {{brand_name}} sent you a booking request of {{currency}}{{amount}}. Open the app to respond.',
 'calendar_today', 'booking', '["in_app","push","email"]', 1),

('booking.accepted', 'Booking accepted', 'booking',
 'Booking accepted',
 'Your booking request with {{creator_name}} is accepted. Complete the payment to confirm.',
 '{{creator_name}} accepted your booking',
 'Zusty: {{creator_name}} accepted your request. Pay within {{payment_window}}h to confirm.',
 'check_circle', 'booking', '["in_app","push","email"]', 1),

('booking.rejected', 'Booking rejected', 'booking',
 'Booking declined',
 '{{creator_name}} is unable to take this collaboration. {{reason}}',
 '{{creator_name}} declined your booking', NULL,
 'cancel', 'booking', '["in_app","push","email"]', 1),

('booking.countered', 'Counter offer received', 'booking',
 'Counter offer received',
 '{{sender_name}} proposed {{currency}}{{amount}} for {{posts_count}} deliverable(s).',
 'New counter offer on booking {{booking_code}}', NULL,
 'swap_horiz', 'booking', '["in_app","push"]', 1),

('booking.confirmed', 'Booking confirmed', 'booking',
 'Booking confirmed!',
 'Your collaboration with {{creator_name}} for "{{campaign_title}}" is confirmed.',
 'Booking {{booking_code}} is confirmed',
 'Zusty: Booking {{booking_code}} with {{creator_name}} is confirmed.',
 'verified', 'booking', '["in_app","push","email"]', 1),

('booking.delivered', 'Work delivered', 'booking',
 'Deliverables submitted',
 '{{creator_name}} submitted the deliverables for {{booking_code}}. Review and approve to release payment.',
 'Deliverables ready for review', NULL,
 'task_alt', 'booking', '["in_app","push","email"]', 1),

('booking.completed', 'Booking completed', 'booking',
 'Campaign completed',
 '{{creator_name}} has completed the campaign. Leave a review to help other brands.',
 'Booking {{booking_code}} completed', NULL,
 'task_alt', 'review', '["in_app","push","email"]', 1),

('booking.cancelled', 'Booking cancelled', 'booking',
 'Booking cancelled',
 'Booking {{booking_code}} was cancelled. {{reason}}',
 'Booking {{booking_code}} cancelled', NULL,
 'event_busy', 'booking', '["in_app","push","email"]', 1),

('booking.expiring', 'Booking expiring', 'booking',
 'Request expiring soon',
 'The booking request from {{brand_name}} expires in {{hours_left}} hours.',
 NULL, NULL,
 'schedule', 'booking', '["in_app","push"]', 1),

('payment.success', 'Payment successful', 'payment',
 'Payment successful',
 'Payment of {{currency}}{{amount}} is successful. Zusty holds it until you approve the work.',
 'Payment receipt for {{booking_code}}',
 'Zusty: Payment of {{currency}}{{amount}} received for {{booking_code}}.',
 'check_circle', 'payment', '["in_app","push","email"]', 1),

('payment.failed', 'Payment failed', 'payment',
 'Payment failed',
 'We could not process your payment of {{currency}}{{amount}}. {{reason}}',
 'Payment failed for {{booking_code}}', NULL,
 'error', 'payment', '["in_app","push","email"]', 1),

('payment.refunded', 'Refund issued', 'payment',
 'Refund processed',
 'A refund of {{currency}}{{amount}} has been issued to your {{destination}}.',
 'Refund processed for {{booking_code}}', NULL,
 'refresh', 'wallet', '["in_app","push","email"]', 1),

('wallet.credited', 'Wallet credited', 'wallet',
 'Money added',
 '{{currency}}{{amount}} has been credited to your wallet. {{description}}',
 NULL, NULL,
 'arrow_downward', 'wallet', '["in_app","push"]', 1),

('wallet.debited', 'Wallet debited', 'wallet',
 'Wallet debited',
 '{{currency}}{{amount}} has been debited from your wallet. {{description}}',
 NULL, NULL,
 'arrow_upward', 'wallet', '["in_app","push"]', 1),

('wallet.earning', 'Earning credited', 'wallet',
 'You have been paid',
 'You earned {{currency}}{{amount}} from {{booking_code}}. It is now available in your wallet.',
 'You earned {{currency}}{{amount}} on Zusty',
 'Zusty: {{currency}}{{amount}} credited for {{booking_code}}.',
 'payments', 'wallet', '["in_app","push","email"]', 1),

('withdrawal.approved', 'Withdrawal approved', 'wallet',
 'Withdrawal approved',
 'Your payout of {{currency}}{{amount}} is on its way and should arrive within 1-3 business days.',
 'Payout {{reference}} approved', NULL,
 'account_balance', 'wallet', '["in_app","push","email"]', 1),

('withdrawal.rejected', 'Withdrawal rejected', 'wallet',
 'Withdrawal rejected',
 'Your payout request of {{currency}}{{amount}} was rejected. {{reason}} The amount has been returned to your wallet.',
 'Payout {{reference}} rejected', NULL,
 'error', 'wallet', '["in_app","push","email"]', 1),

('message.new', 'New message', 'message',
 'New message',
 '{{sender_name}} has sent you a new message.',
 NULL, NULL,
 'chat_bubble', 'chat', '["in_app","push"]', 1),

('review.received', 'Review received', 'review',
 'New review',
 '{{reviewer_name}} rated your collaboration {{rating}} out of 5.',
 'You received a new review', NULL,
 'star', 'review', '["in_app","push"]', 1),

('creator.approved', 'Creator approved', 'account',
 'Your profile is live',
 'Congratulations! Your creator profile has been approved and is now visible to brands.',
 'Your Zusty creator profile is approved',
 'Zusty: Your creator profile is approved and live.',
 'verified', 'creator', '["in_app","push","email"]', 1),

('creator.rejected', 'Creator rejected', 'account',
 'Profile needs changes',
 'Your creator profile needs a few changes before it can go live. {{reason}}',
 'Action needed on your Zusty profile', NULL,
 'info', 'creator', '["in_app","push","email"]', 1),

('kyc.verified', 'KYC verified', 'account',
 'Identity verified',
 'Your identity has been verified. Withdrawals are now enabled on your account.',
 'Your Zusty KYC is verified', NULL,
 'verified_user', 'none', '["in_app","push","email"]', 1),

('kyc.rejected', 'KYC rejected', 'account',
 'Verification failed',
 'We could not verify your documents. {{reason}} Please submit them again.',
 'Action needed on your Zusty verification', NULL,
 'gpp_bad', 'none', '["in_app","push","email"]', 1),

('referral.rewarded', 'Referral reward', 'promo',
 'Referral reward credited',
 '{{friend_name}} completed their first booking. {{currency}}{{amount}} has been added to your wallet.',
 'You earned a referral reward', NULL,
 'person_add', 'wallet', '["in_app","push","email"]', 1),

('ticket.replied', 'Support replied', 'system',
 'Support replied',
 'Our team replied to ticket {{ticket_code}}.',
 'Re: {{ticket_subject}}', NULL,
 'support_agent', 'ticket', '["in_app","push","email"]', 1),

('account.welcome', 'Welcome', 'account',
 'Welcome to Zusty',
 'Welcome aboard, {{name}}! Complete your profile to get the most out of Zusty.',
 'Welcome to Zusty',
 'Welcome to Zusty! Shoot. Connect. Grow.',
 'celebration', 'none', '["in_app","push","email"]', 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `body` = VALUES(`body`),
  `email_subject` = VALUES(`email_subject`), `sms_body` = VALUES(`sms_body`),
  `icon` = VALUES(`icon`), `action_type` = VALUES(`action_type`), `channels` = VALUES(`channels`);

-- ---------------------------------------------------------------------
-- Testimonials
--
-- Sample quotes written for the design, not said by customers, so they are
-- seeded switched off: an operator publishes real ones from the panel.
-- ---------------------------------------------------------------------
INSERT INTO `testimonials` (`id`, `author_name`, `author_role`, `company`, `content`, `rating`, `is_featured`, `is_active`, `sort_order`) VALUES
  (1, 'Priya Nair',   'Head of Marketing', 'Bloom Skincare',
      'We shortlisted, briefed and paid eleven creators in a single afternoon. What used to take our team three weeks now takes an evening.', 5, 0, 0, 1),
  (2, 'Rohit Verma',  'Travel Creator',    NULL,
      'Payment protection changed everything for me. I stopped chasing invoices and started taking on bigger campaigns because I know the money is already there.', 5, 0, 0, 2),
  (3, 'Ananya Sharma','Lifestyle Blogger', NULL,
      'The package pricing made my rates obvious to brands, so the conversations start at the brief instead of the budget.', 5, 0, 0, 3),
  (4, 'Karan Mehta',  'Growth Lead',       'Nexa Devices',
      'Verified audience data meant we stopped paying for inflated follower counts. Our cost per engagement dropped by about 40%.', 5, 0, 0, 4)
ON DUPLICATE KEY UPDATE `content` = VALUES(`content`), `rating` = VALUES(`rating`);

-- ---------------------------------------------------------------------
-- Home banners — what the app does, with no promise it cannot keep.
-- ---------------------------------------------------------------------
INSERT INTO `banners` (`id`, `title`, `subtitle`, `cta_label`, `image_url`, `position`, `action_type`, `action_value`, `audience`, `is_active`, `sort_order`) VALUES
  (1, 'Find the Right Creator for Your Next Campaign', 'Verified creators, transparent pricing, and payment protection on every booking.',
      'Explore Now', '/uploads/banners/home-hero-1.jpg', 'home_hero', 'screen', '/explore', 'brands', 1, 1),
  (2, 'Turn Your Audience Into Income', 'Set your packages, get booked, and get paid when the work is approved.',
      'Get Started', '/uploads/banners/home-hero-2.jpg', 'home_hero', 'screen', '/creator/onboarding', 'creators', 1, 2),
  (3, 'Invite Friends & Earn', 'Share your code and earn a reward when a friend completes their first booking.',
      'Invite Now', '/uploads/banners/home-mid-referral.jpg', 'home_mid', 'screen', '/invite', 'all', 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `subtitle` = VALUES(`subtitle`), `cta_label` = VALUES(`cta_label`);
