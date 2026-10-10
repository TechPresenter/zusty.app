-- ---------------------------------------------------------------------
-- 053 - Public copy that matches the app Google Play reviews.
--
-- The operator's decisions (2026-10-10), on top of 052's one mailbox, no
-- published address and no SMS:
--
--   U2. Brands pay each booking on Cashfree's checkout. The wallet holds a
--       creator's earnings and refunds; nobody pays money into it. Refunds
--       go back to the original payment method by default.
--   U3. Marketing messages are off unless the user opts in. Migration 055
--       keeps the "marketing": true that sign-up used to store for every
--       account, so the privacy policy says those older accounts had
--       product updates on by default and where to turn them off.
--   U4. Every account has an email address.
--   U5. No phone number is published anywhere: only support@zusty.app.
--
--   1. CMS pages, each replaced whole while it still carries the seeded
--      wording this migration retires (an operator's rewrite is left alone):
--        privacy               covers everything the Play Data safety form
--                              declares: identifiers, approximate location,
--                              voice search, KYC fields, payment and payout
--                              data, activity, crash reports, the push
--                              token, opt-in marketing, the named service
--                              providers, retention, export and deletion.
--                              Section 5's code goes to the EMAIL only, and
--                              deletion erases no "texts".
--        terms                 per-booking Cashfree payment, escrow released
--                              on approval or after booking.auto_complete_days,
--                              the wallet, reports and blocking, no SMS.
--        refund-policy         refunds go to the original payment method.
--        contact               no phone, and the in-app route that exists.
--        about                 no "every creator is verified" claim.
--      The community guidelines are edited in place instead: honest
--      reviews, harmful content and how moderation works. Their Reporting
--      section is migration 054's to rewrite (blocking), so it is not
--      touched here. Any page still listing a phone number loses that list
--      item. A page whose content changes gets its version bumped, once.
--   2. FAQs: cancellation, escrow, verification, payouts, disputes and
--      pricing say what the app does; no phone support. A "report or block"
--      FAQ is added. The payment-methods FAQ is migration 056's (U2).
--   3. Home banners and onboarding slides lose promises the app cannot
--      back ("get paid on time, every time", "every creator passes KYC").
--   4. The four testimonials seed 002 shipped were written for the design,
--      not by customers, so they are switched off.
--   5. general.support_phone is emptied; the app hides the call row when it
--      is empty.
--   6. Two creator profile-completion hints (migration 037's rows, served to
--      the app) lose claims the code does not back: verified reach does not
--      rank higher in search, and withdrawals need a confirmed email OR
--      phone, not a phone.
--
-- booking.auto_complete_days is read once, here, and printed into the terms,
-- the refund policy and the escrow FAQ (ADMIN-MANUAL says to edit those if
-- the setting changes later).
--
-- Re-runnable: every statement is limited to rows that still hold the old
-- wording, and none of the new wording matches again, so a second run
-- changes nothing and bumps no version. Valid on MariaDB 10.4 and 11.8. In
-- SQL source the regex escapes are doubled: '\\s' reaches the server as \s.
-- database/seeds/002_cms_content.sql carries the same copy for fresh installs.
-- ---------------------------------------------------------------------

SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The review window, as the auto-complete job applies it (never under 1).
SET @z053_days := GREATEST(1, COALESCE(
    (SELECT CAST(`value` AS UNSIGNED) FROM `settings`
      WHERE `group_key` = 'booking' AND `setting_key` = 'auto_complete_days' LIMIT 1),
    7));
SET @z053_window := IF(@z053_days = 1, '1 day', CONCAT(@z053_days, ' days'));

-- ---------------------------------------------------------------------
-- 1. CMS pages
-- ---------------------------------------------------------------------

-- Privacy Policy. Sections 5 and 7 keep the deletion, export and "what we
-- keep" wording migration 048 introduced, which the privacy suites check.
UPDATE `pages`
   SET `content` = '<h2>1. Who we are</h2><p>Zusty (the Zusty app, the website at zusty.app and its API) is operated by Appsgain Technologies Private Limited (“Zusty”, “we”, “us”). This policy explains what personal data we collect, why we collect it, who we share it with, how long we keep it and the rights you have over it. Zusty is a marketplace where brands in India book creators. It is only for people aged 18 and over.</p><h2>2. Data we collect</h2><ul><li><strong>Account data</strong> — your name, email address (every account needs one), password (stored only as a bcrypt hash), profile photo, city, whether you are a brand or a creator, and how you sign in, including a linked Google account. A phone number is optional. Our payment provider needs a mobile number for every checkout: we send the one on your account, or, if there is none, the app asks for one when you pay and sends it with that order only, without saving it to your account. We never send you text messages.</li><li><strong>Identifiers</strong> — your account ID, the Firebase user ID created when you sign in with Google, and your referral code.</li><li><strong>Profile data</strong> — for creators: bio, categories, languages, packages and prices, portfolio images, social handles with the audience figures you enter, and availability. For brands: company details and logo.</li><li><strong>Verification (KYC) data</strong> — for creators: PAN, date of birth, gender, address, a masked Aadhaar image and an optional selfie. We check your Aadhaar number when you submit it but never store or log the full number: we keep only a keyed hash of it and its last four digits. For brands: GSTIN, CIN for companies, business details and the documents you upload, such as a GST certificate. We ask for your consent before you submit. Verification data is stored encrypted and only the people who review it can see it.</li><li><strong>Payment and payout data</strong> — brands pay on Cashfree’s hosted checkout, so Zusty never receives your full card number, CVV or banking password. Cashfree tells us each payment’s reference, amount and status and a summary of the method used, such as the card network and last four digits, the UPI ID or the bank name. For payouts we keep the bank account (account holder, account number, IFSC and bank) or UPI ID you add.</li><li><strong>Purchase and wallet history</strong> — bookings, payments, invoices, coupons and refunds, and for creators their earnings, wallet entries and withdrawals. The wallet holds a creator’s earnings and any refunds; you cannot pay money into it.</li><li><strong>Messages and content you create</strong> — in-app messages and the images you send in them, campaign briefs, applications, offers and counter offers, links to delivered work, reviews and replies, support tickets, and the reports, blocks and dispute notes you submit.</li><li><strong>Activity in the app</strong> — your searches and the filters you use, the profiles you view, the creators you save or follow, and when you were last active.</li><li><strong>Approximate location</strong> — only if you tap “Use my current location”. Your phone takes a coarse location fix and turns it into the nearest city on the phone itself. Only the city name is sent to us; precise coordinates never leave your phone. You can always choose a city yourself instead.</li><li><strong>Voice search</strong> — only when you tap the microphone. Your phone’s speech recognition service (part of Android, not Zusty) turns what you say into text, and Zusty receives only that text, which is used as a search term like anything you type. We never receive or keep the audio.</li><li><strong>Device and diagnostic data</strong> — an install ID the app creates, the push token Firebase Cloud Messaging gives the app so we can send you notifications, your device model, operating system and app version, your IP address, and crash logs and diagnostics collected by Firebase Crashlytics in the released app.</li></ul><p>We do not read your contacts, calendar, text messages, call log or the list of apps on your phone. You choose photos and documents with Android’s own picker, so the app does not need access to your photo library.</p><h2>3. How we use it</h2><ul><li><strong>To run Zusty</strong> — your account and sign-in, profiles, search, bookings, campaigns, chat, payments, invoices, payouts and support.</li><li><strong>For safety and the law</strong> — to verify identities and businesses, review reports and disputes, prevent fraud and abuse, keep sign-ins secure, enforce our terms, and keep the tax, accounting and KYC records the law requires.</li><li><strong>To tell you what is happening</strong> — notifications about your bookings, payments, payouts, messages, verification and account, in the app, by push notification and by email. You can switch push and email off under <strong>Settings → Notifications</strong>.</li><li><strong>For statistics</strong> — aggregate figures, such as how often a profile is viewed and which searches are popular, which we use to run and improve Zusty.</li><li><strong>For marketing, only if you opt in</strong> — product news and offers by email and push notification. Marketing is off unless you tick the box at sign-up or turn on product updates and offers under <strong>Settings → Notifications</strong>, and you can turn it off there at any time. Accounts created before the sign-up form asked had product updates switched on by default; if yours is one of them, you can turn them off in the same place.</li></ul><p>We do not sell your personal data or share it for advertising. The app shows no ads, contains no advertising SDK and does not use your device’s advertising ID.</p><h2>4. Who we share it with</h2><ul><li><strong>Other users, as the service needs</strong> — signed-in users can see your public profile. When you book, apply, make an offer or send a message, the other person sees what that involves, such as your name, profile, messages and the booking details. Other users never see your verification documents or numbers.</li><li><strong>Service providers</strong> that process data for us, under contract and only on our instructions: <strong>Cashfree Payments</strong> (checkout and refunds; it receives your name, email address, phone number and the amount of each order), <strong>Google Firebase</strong> (Firebase Authentication for Google sign-in, Firebase Cloud Messaging for push notifications and Firebase Crashlytics for crash reports) and <strong>Hostinger</strong> (our servers and email delivery). Some of them may process data outside India.</li><li><strong>Authorities</strong> — when the law requires it, for example tax authorities or a court order.</li></ul><h2>5. Retention and deletion</h2><p>We keep your account data while your account is open. Some data is deleted sooner, automatically: our copies of the emails and push notifications we send you after 7 days (30 days if they were never delivered), payment gateway callbacks after 90 days, API request logs after 180 days, sign-in history after one year, and data export files once their download link expires or has been used up. Identity documents are deleted 30 days after a verification draft you never submitted was last changed, 180 days after a verification is rejected or left waiting for changes, and 90 days after one is suspended or expires; a document you replace is deleted after 30 days.</p><p><strong>Deleting your account.</strong> You can delete your account at any time in the app under <strong>Settings → Privacy &amp; data → Delete my account</strong>. You confirm it is you with your password, a one-time code sent to your email or by signing in with Google again, then type DELETE. If you cannot use the app, see section 7.</p><p>An account cannot be deleted while something is still in progress: a booking in progress, a dispute under review, money in your wallet (including earnings still held in escrow), a withdrawal or refund still being processed, or a wallet on hold. The app shows what needs to be settled. A small balance that cannot be paid out can be given up instead: the app offers this when it applies, or you can contact support and we will pay it out first. We do not cancel bookings for you.</p><p><strong>What we erase.</strong> Deletion takes effect as soon as you confirm and cannot be undone. We erase your name, photo, bio, city and company details, and every email address and phone number on your account, except where listed below; your creator profile, portfolio, packages, social accounts, availability and its edit history; your saved creators, follows, the accounts you blocked, search history, the profiles you viewed and notifications, and our copies of the emails and push notifications sent to you; your password, linked Google sign-in, sessions, devices and push tokens, and you are signed out everywhere; your saved bank accounts and UPI IDs; the files you uploaded, except files attached to bookings or sent in chat; and any copies of your data still stored on our servers. Open and draft campaigns are closed. Identity (KYC) document images and numbers are deleted; we keep only a minimal record that a verification took place, with its reference, outcome and dates.</p><p><strong>What we keep, and why.</strong></p><ul><li><strong>Bookings, payments, invoices, refunds, payouts and wallet entries</strong>, because Indian tax and accounting law requires us to keep financial records, generally for eight years. They show “Deleted user” in place of your account. Tax invoices and payout statements stay exactly as issued, with the billing name, address and GSTIN printed on them, and payout records keep the account holder''s name, the bank and a masked account number or UPI ID. Payment providers keep their own records of your payments.</li><li><strong>Messages you sent</strong> stay in the other person''s conversation, shown as from “Deleted user”. They are part of that person''s record and may be needed to resolve a dispute. Notifications about you in their app lose your name, but emails we already delivered to them cannot be recalled.</li><li><strong>Reviews you wrote</strong> stay on the creator''s or brand''s page, shown as from “Deleted user” with no photo, so ratings stay honest.</li><li><strong>Support tickets, contact-form messages and reports</strong>: your name and contact details are removed from them, but what you and our team wrote stays as written, for safety, fraud prevention and legal claims.</li><li><strong>Security logs</strong>: the time, IP address and device of each sign-in and of other account activity, without your email address or phone number, kept for 12 months to detect fraud and abuse.</li><li><strong>A minimal identity verification record</strong>: that your identity was checked, when, the outcome, and the name and IP address recorded at each step, kept for the period the law requires.</li><li><strong>A deletion record</strong>: that an account was deleted, when, how it was confirmed, and the reason you chose and anything you wrote with it, with no contact details.</li><li><strong>Backups</strong>: copies of our database are kept for up to 14 days to recover from failures, then deleted. If we ever restore one, we delete your account again straight away.</li></ul><p>Copies of your data that you downloaded stay on your device. We cannot delete those. The same list is on our <a href="/account-deletion">account deletion page</a>.</p><h2>6. Security</h2><p>Everything sent between the app, our servers and our service providers is encrypted in transit with TLS. Passwords are hashed with bcrypt, and identity numbers and verification documents are encrypted with AES-256-GCM. Access to production data is restricted to the people who need it, role-based and logged.</p><h2>7. Your rights and choices</h2><p>You can see and correct most of your data in the app, and remove things such as your photo, saved creators and saved payout accounts yourself. To get a copy of the personal data we hold about you, use <strong>Settings → Privacy &amp; data → Download my data</strong>: after you confirm it is you, you get a ZIP file through a download link that works for a limited time. To delete your account, see section 5. You can withdraw marketing consent at any time under <strong>Settings → Notifications</strong>, and turn off location or microphone access for Zusty in your phone’s settings. If you cannot use the app, write to <a href="mailto:support@zusty.app">support@zusty.app</a> from the email address on your account; we act on the request once we have confirmed it comes from you.</p><h2>8. Questions and grievances</h2><p>Questions about this policy, requests about your data and complaints go to <a href="mailto:support@zusty.app">support@zusty.app</a>. For a complaint, put “Grievance” in the subject line; we acknowledge it and resolve it within the time Indian law sets. Zusty is operated by Appsgain Technologies Private Limited.</p><h2>9. Children</h2><p>Zusty is only for people aged 18 and over. We do not knowingly collect data from anyone younger, and we close an account if we learn it belongs to someone under 18.</p><h2>10. Changes</h2><p>When we change this policy we update the date at the top of this page. If a change materially affects how we use your data, we tell you in the app or by email before it takes effect.</p>',
       `subtitle`         = 'Last updated: 10 October 2026',
       `meta_description` = 'What personal data Zusty collects, why, who processes it, how long it is kept, and how to export or delete it.',
       `version`          = `version` + 1,
       `published_at`     = NOW()
 WHERE `slug` = 'privacy'
   AND `content` LIKE BINARY '%<h2>2. Data we collect</h2><ul><li><strong>Account data</strong> — name, email, phone number, password hash, profile photo, city.</li>%';

-- Terms & Conditions. Section 14 keeps 048's list of what must be settled
-- before an account closes.
UPDATE `pages`
   SET `content` = CONCAT(
         '<h2>1. Acceptance of terms</h2><p>By creating an account or using the Zusty app, website or API (together, the "Platform"), you agree to these Terms &amp; Conditions, our <a href="/privacy">Privacy Policy</a>, our <a href="/refund-policy">Refund &amp; Cancellation Policy</a> and our <a href="/community-guidelines">Community Guidelines</a>. The Platform is operated by Appsgain Technologies Private Limited ("Zusty", "we"). If you do not agree, do not use the Platform.</p>',
         '<h2>2. Eligibility</h2><p>You must be at least 18 years old and legally capable of entering into a binding contract. Business accounts must be registered by an authorised representative of the organisation.</p>',
         '<h2>3. Accounts</h2><p>Every account needs an email address you can receive mail at. You sign in with your email and password, a one-time code sent to your email, or Google. You are responsible for the accuracy of the information you provide and for all activity under your account. Tell us straight away about any unauthorised access. We may suspend an account that breaks these terms, submits fraudulent documents or harms other users.</p>',
         '<h2>4. Verification</h2><p>Creators verify their identity, and brands their business, before they can take part in paid bookings; creators also need an approved verification to withdraw earnings. A person on our team reviews every verification, and the blue tick is shown only after that approval. Verification confirms who you are. It is not an endorsement of anyone''s work.</p>',
         '<h2>5. The marketplace role</h2><p>Zusty is a venue. Contracts for content are formed directly between the brand and the creator. We provide escrow, messaging, dispute resolution and record-keeping, but we are not a party to the collaboration and do not guarantee campaign performance.</p>',
         '<h2>6. Bookings and payments</h2><p>A booking becomes binding when the creator accepts and the brand pays. Brands pay for each booking separately on Cashfree''s hosted checkout, by UPI, card or net banking, or from their Zusty wallet when a refund credited there covers the whole booking. The payment is held in escrow and released to the creator when the brand approves the deliverables. If the brand does not approve the work, ask for changes or raise a dispute within ', @z053_window, ' of delivery, the booking completes automatically and the payment is released to the creator. Platform fees, commissions and taxes are shown before payment and on every invoice.</p>',
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
         '<h2>17. Contact</h2><p>Questions about these terms: <a href="mailto:support@zusty.app">support@zusty.app</a>.</p>'),
       `subtitle`     = 'Last updated: 10 October 2026',
       `version`      = `version` + 1,
       `published_at` = NOW()
 WHERE `slug` = 'terms'
   AND `content` LIKE BINARY '%<h2>5. Bookings and payments</h2><p>A booking becomes binding when the creator accepts and the brand pays. Payment is held in escrow%';

-- Refund & Cancellation Policy: refunds to the original payment method, and
-- a wallet that nobody pays into.
UPDATE `pages`
   SET `content` = CONCAT(
         '<h2>Escrow</h2><p>Brands pay for each booking on Cashfree''s checkout. The money is held by Zusty, not passed to the creator, and is released when the brand approves the deliverables, or automatically ', @z053_window, ' after delivery if the brand does not approve the work, ask for changes or raise a dispute.</p>',
         '<h2>Cancellation by the brand</h2><ul><li><strong>Before you pay</strong> — cancel free of charge; nothing has been taken.</li><li><strong>After you pay, well before the delivery date</strong> — a full refund of the amount paid, including the platform fee.</li><li><strong>After you pay, close to the delivery date</strong> — a late-cancellation fee is kept and paid to the creator for the time they reserved, and the rest is refunded. The app shows the cut-off and the exact refund before you confirm.</li><li><strong>After delivery</strong> — the booking can no longer be cancelled; ask for changes or raise a dispute instead.</li></ul>',
         '<h2>Cancellation by the creator</h2><p>If a creator cancels an accepted booking, the brand gets a full refund, including all fees, and the cancellation is recorded against the creator''s completion rate.</p>',
         '<h2>Disputes</h2><p>Once a booking is paid, either side can raise a dispute from the booking screen. Both sides can add evidence, a person on our team reviews the brief, the deliverables and the messages, and the money stays in escrow until then. The outcome decides whether the payment is released to the creator, refunded to the brand or split between them.</p>',
         '<h2>Refund destination and timing</h2><p>Refunds go back to the original payment method by default. A payment made from Zusty wallet balance is refunded to the wallet. Refunds to a card or bank account usually appear within 5–7 business days, depending on your bank; UPI refunds are usually quicker.</p>'),
       `version` = `version` + 1
 WHERE `slug` = 'refund-policy'
   AND `content` LIKE BINARY '%or to your Zusty wallet if you prefer — wallet refunds are instant.%';

-- Community Guidelines, edited in place. The Reporting section is left for
-- migration 054, which rewrites it for blocking; "How moderation works"
-- goes after it, at the end of the page. A ranking the code never applies
-- ("no-shows reduce your ranking") is replaced by what the profile shows:
-- the completion rate (CreatorService), which counts cancelled bookings.
UPDATE `pages`
   SET `content` = CONCAT(
         REPLACE(
             REPLACE(`content`,
                 'Repeated no-shows reduce your ranking.',
                 'Bookings you cancel count against the completion rate shown on your profile.'),
             '<h2>No harassment</h2><p>Discrimination, threats, sexual harassment and hate speech are grounds for immediate removal.</p>',
             '<h2>Honest reviews</h2><p>Reviews must describe a real collaboration. Do not offer, ask for or accept anything in return for a review, on Zusty or anywhere else, and do not make a review part of a brief.</p><h2>No harassment or harmful content</h2><p>Discrimination, threats, sexual harassment, hate speech, sexual content, violence, scams and anything illegal are grounds for immediate removal.</p>'),
         '<h2>How moderation works</h2><p>When we receive a report we look at the reported content, the conversation or booking around it and the history of the accounts involved. Depending on what we find, we may take no action, remove the content, or suspend or close the account. Money on an open booking is settled through the dispute process. If your account is suspended or closed, you can ask us to look again by writing to <a href="mailto:support@zusty.app">support@zusty.app</a>.</p>'),
       `version` = `version` + 1
 WHERE `slug` = 'community-guidelines'
   AND `content` LIKE BINARY '%Repeated no-shows reduce your ranking.%'
   AND `content` NOT LIKE BINARY '%<h2>How moderation works</h2>%';

-- Contact: one mailbox, no phone, and the in-app route that exists.
UPDATE `pages`
   SET `content` = '<p>We aim to reply within one business day.</p><ul><li><strong>Email</strong> — <a href="mailto:support@zusty.app">support@zusty.app</a></li><li><strong>In the app</strong> — Profile → Help &amp; support → Start a new ticket</li></ul><p>Press enquiries and partnership proposals are welcome at the same address.</p>',
       `version` = `version` + 1
 WHERE `slug` = 'contact'
   AND `content` LIKE BINARY '%Help &amp; Support → Chat with Us%';

-- About: verification is required to take part, not of every profile.
UPDATE `pages`
   SET `content` = '<h2>Who we are</h2><p>Zusty is an influencer marketplace for India that makes creator collaborations as simple as booking a table. Brands find creators and book them at the prices on their profiles, or post a campaign for creators to apply to, and then handle the brief, the negotiation, the payment and the delivery in one place. Zusty is operated by Appsgain Technologies Private Limited.</p><h2>Why we exist</h2><p>Influencer marketing still runs on spreadsheets, screenshots and unpaid invoices. Brands find it hard to tell a real audience from a bought one, and creators chase payments for months. Zusty brings verified identities, clear pricing and escrow-backed payments to both sides.</p><h2>How it works</h2><ol><li><strong>Discover</strong> — search creators by category, city, audience size and budget, or show verified creators only.</li><li><strong>Book</strong> — send a brief with your budget, and negotiate with counter offers if you need to.</li><li><strong>Pay securely</strong> — pay on Cashfree''s checkout; the money is held in escrow until the work is approved.</li><li><strong>Approve</strong> — review the delivered work, ask for changes if you need them, and rate the collaboration.</li></ol><h2>Our commitment</h2><p>Creators verify their identity before they can accept bookings or withdraw earnings, and a person on our team reviews every verification; the blue tick appears only after that approval. Every payment is recorded and invoiced, and every dispute is reviewed by a person.</p>',
       `version` = `version` + 1
 WHERE `slug` = 'about'
   AND `content` LIKE BINARY '%Every creator on Zusty is identity-verified.%';

-- U5 on any page, the rewritten ones included: a list item that starts
-- with Phone, Telephone, Call us or WhatsApp AND gives a number (a tel:
-- link, or nine or more digits and separators) goes. An item that only
-- talks about phones, such as the privacy policy's "Phone number —
-- optional…" disclosure, has no number and stays. Runs after the
-- rewrites, so a page they replaced no longer matches and is not bumped
-- twice.
UPDATE `pages`
   SET `content` = REGEXP_REPLACE(`content`,
           '(?is)<li\\b[^>]*>\\s*(?:<(?:strong|b)>\\s*)?(?:Phone|Telephone|Call us|WhatsApp)\\b(?:(?!</li>).)*?(?:tel:|\\+?\\d[\\d\\s().-]{7,}\\d)(?:(?!</li>).)*</li>',
           ''),
       `version` = `version` + 1
 WHERE `content` REGEXP '(?is)<li\\b[^>]*>\\s*(?:<(?:strong|b)>\\s*)?(?:Phone|Telephone|Call us|WhatsApp)\\b(?:(?!</li>).)*?(?:tel:|\\+?\\d[\\d\\s().-]{7,}\\d)(?:(?!</li>).)*</li>';

-- ---------------------------------------------------------------------
-- 2. FAQs
--
-- Matched on the seeded question and a phrase of the seeded answer, so an
-- answer an operator has rewritten is kept.
-- ---------------------------------------------------------------------
UPDATE `faqs`
   SET `answer` = 'Brands find creators and book them at the prices on their profiles, or post a campaign for creators to apply to. Payment is held in escrow until the work is approved. Discovery, chat, payments, invoices and payouts all happen in the app.'
 WHERE `question` = 'How does Zusty work?'
   AND `answer` LIKE '%manage the entire booking and payment process securely within our platform%';

UPDATE `faqs`
   SET `answer` = 'Open a creator''s profile, choose a package or send a custom request, and add your brief and dates. The creator can accept, decline or make a counter offer. A request the creator does not answer expires on its own, and nothing is charged until the creator accepts and you pay.'
 WHERE `question` = 'How do I book a creator?'
   AND `answer` LIKE '%The creator responds within 48 hours%';

UPDATE `faqs`
   SET `answer` = 'Yes. Before you pay, cancelling is free. After you pay, you get a full refund if you cancel well before the delivery date; closer to it, a late-cancellation fee is kept and paid to the creator. The app shows the exact refund before you confirm. If the creator cancels, you get a full refund including fees. Refunds go back to your original payment method.'
 WHERE `question` = 'Can I cancel a booking?'
   AND `answer` LIKE '%You can cancel up to 7 days before the agreed delivery date%';

UPDATE `faqs`
   SET `answer` = CONCAT('Yes. You pay on Cashfree''s hosted checkout over an encrypted connection, and Zusty never sees your full card details. Your money is held in escrow and released to the creator when you approve the work, or automatically ', @z053_window, ' after delivery if you do not approve it, ask for changes or raise a dispute.')
 WHERE `question` = 'Is my payment secure?'
   AND `answer` LIKE '%Absolutely. We use industry-standard encryption%';

UPDATE `faqs`
   SET `answer` = 'Go to Profile → Verification and submit your PAN and a masked copy of your Aadhaar (only the last four digits visible), with the other details the screen asks for. A person on our team reviews every submission, and the blue tick appears only after that approval. You need an approved verification to accept bookings and withdraw earnings.'
 WHERE `question` = 'How do I become a verified creator?'
   AND `answer` LIKE '%Our moderation team reviews submissions within two business days%';

UPDATE `faqs`
   SET `answer` = 'When a booking completes, your earnings are credited to your wallet and become available to withdraw after a short hold. From your Wallet, tap Withdraw and choose a bank account or UPI ID you have added; you need an approved verification first. Our team reviews each withdrawal before it is paid, and the money usually arrives within 1–3 business days of approval.'
 WHERE `question` = 'When can I withdraw my earnings?'
   AND `answer` LIKE '%Earnings move from Locked to Available%';

UPDATE `faqs`
   SET `answer` = 'Ask for changes from the booking screen, or raise a dispute. While a dispute is open the money stays in escrow. Our team reviews the brief, the deliverables and the messages from both sides, then releases, refunds or splits the payment. A refund goes back to your original payment method.'
 WHERE `question` = 'What happens if a creator does not deliver?'
   AND `answer` LIKE '%Escrow keeps your money safe while our team reviews%';

UPDATE `faqs`
   SET `answer` = 'Add packages to your creator profile. Each package sets a platform, deliverable type, quantity, price, turnaround time and number of revisions. Brands book a package directly, so clear pricing wins more collaborations.'
 WHERE `question` = 'How do I set my pricing?'
   AND `answer` LIKE '%Create packages under Profile → Pricing%';

-- Reporting and blocking, for the people who look for it in the help centre.
-- Only on an install whose FAQs are already seeded: on a fresh install the
-- seeds run after the migrations and carry this FAQ themselves.
INSERT INTO `faqs` (`category`, `question`, `answer`, `audience`, `is_active`, `sort_order`)
SELECT 'security', 'How do I report or block someone?',
       'Open the profile, chat, review, campaign brief or booking and use its menu to report it; a person on our team reviews every report. You can also block someone: then neither of you can message the other or start a booking, offer, invitation or application with the other. A booking that is already paid for can still be delivered, completed or disputed. You can see and unblock the people you blocked under Settings → Privacy & data → Blocked accounts.',
       'all', 1, 13
  FROM DUAL
 WHERE EXISTS (SELECT 1 FROM `faqs` WHERE `question` = 'How does Zusty work?')
   AND NOT EXISTS (SELECT 1 FROM `faqs` WHERE `question` = 'How do I report or block someone?');

-- ---------------------------------------------------------------------
-- 3. Banners and onboarding
-- ---------------------------------------------------------------------
UPDATE `banners`
   SET `subtitle` = 'Set your packages, get booked, and get paid when the work is approved.'
 WHERE `subtitle` = 'Set your packages, get booked, get paid on time — every time.';

UPDATE `banners`
   SET `subtitle` = 'Share your code and earn a reward when a friend completes their first booking.'
 WHERE `subtitle` = 'Share your code and earn wallet credit on their first booking.';

UPDATE `onboarding_slides`
   SET `subtitle` = 'Search creators by city, category and price, and book them in a few taps.'
 WHERE `subtitle` = 'Connect with verified influencers and grow your brand effortlessly.';

UPDATE `onboarding_slides`
   SET `subtitle` = 'Look for the blue tick: it appears only after our team has checked a creator’s identity.'
 WHERE `subtitle` = 'Every creator passes KYC verification, so you always know exactly who you are working with.';

-- ---------------------------------------------------------------------
-- 4. Testimonials
--
-- The four seeded quotes, matched on author and wording; anything an
-- operator added or edited is left as it is.
-- ---------------------------------------------------------------------
UPDATE `testimonials`
   SET `is_active` = 0, `is_featured` = 0
 WHERE `is_active` = 1
   AND `source` = 'manual'
   AND ((`author_name` = 'Priya Nair'    AND `content` LIKE 'We shortlisted, briefed and paid eleven creators in a single afternoon.%')
     OR (`author_name` = 'Rohit Verma'   AND `content` LIKE 'Escrow changed everything for me.%')
     OR (`author_name` = 'Ananya Sharma' AND `content` LIKE 'The package pricing made my rates obvious to brands%')
     OR (`author_name` = 'Karan Mehta'   AND `content` LIKE 'Verified audience data meant we stopped paying for inflated follower counts.%'));

-- ---------------------------------------------------------------------
-- 5. No published phone number
-- ---------------------------------------------------------------------
UPDATE `settings`
   SET `value` = ''
 WHERE `group_key` = 'general'
   AND `setting_key` = 'support_phone'
   AND `value` IS NOT NULL
   AND `value` <> '';

UPDATE `settings`
   SET `description` = 'Leave empty: Zusty publishes no phone number, and support is by email only. The app shows a call option only when this is set.'
 WHERE `group_key` = 'general'
   AND `setting_key` = 'support_phone'
   AND `description` = 'Displayed on the Help & Support screen.';

-- ---------------------------------------------------------------------
-- 6. Profile-completion hints
--
-- Migration 037 creates these rows (no seed does), so this is also what a
-- fresh install ends up with. Matched on 037's wording, so an operator's own
-- hint is kept. Search orders by featured, search_boost and rating
-- (CreatorService::SORTABLE), never by a verified social account; the
-- withdrawal check (WalletService) wants a confirmed email or phone.
-- ---------------------------------------------------------------------
UPDATE `creator_profile_rules`
   SET `description` = 'Optional. Brands see a verified mark next to each linked account that has been verified.'
 WHERE `rule_key` = 'verified_account'
   AND `description` = 'Verified reach ranks higher in brand search.';

UPDATE `creator_profile_rules`
   SET `description` = NULL
 WHERE `rule_key` = 'phone_verified'
   AND `description` = 'Required before withdrawals.';
