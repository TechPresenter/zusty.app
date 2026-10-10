-- =====================================================================
-- Zusty Influencer Marketplace — Seed 002 (CMS + notification content)
-- Idempotent. Copy is taken from the reference design where available.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- FAQs — the first five mirror the FAQs screen of the reference design.
-- ---------------------------------------------------------------------
INSERT INTO `faqs` (`id`, `category`, `question`, `answer`, `audience`, `is_active`, `sort_order`) VALUES
  (1, 'general', 'How does Zusty work?',
      'Zusty connects brands with authentic creators. You can browse portfolios, request collaborations, and manage the entire booking and payment process securely within our platform.',
      'all', 1, 1),
  (2, 'booking', 'How do I book a creator?',
      'Once you find a creator you love, open their profile and tap Book Now. Fill in your campaign details — platform, deliverable type, number of posts and budget — then send the request. The creator responds within 48 hours to confirm availability and rates.',
      'brands', 1, 2),
  (3, 'payment', 'What payment methods are accepted?',
      'We accept all major credit and debit cards, UPI (Google Pay, PhonePe, Paytm), net banking and your Zusty wallet balance. Every payment is processed over an encrypted connection by a PCI-DSS compliant gateway.',
      'all', 1, 3),
  (4, 'booking', 'Can I cancel a booking?',
      'Yes. You can cancel up to 7 days before the agreed delivery date for a full refund. Cancellations made inside the 7-day window may retain a partial fee, which is shown to you before you confirm the cancellation.',
      'all', 1, 4),
  (5, 'payment', 'Is my payment secure?',
      'Absolutely. We use industry-standard encryption to protect your financial information. Funds are held in escrow and released to the creator only after you approve the final deliverables.',
      'all', 1, 5),
  (6, 'creator', 'How do I become a verified creator?',
      'Complete your profile, link at least one social account, add three or more portfolio items and submit your KYC documents. Our moderation team reviews submissions within two business days and awards the verified badge once your identity and audience are confirmed.',
      'creators', 1, 6),
  (7, 'wallet', 'When can I withdraw my earnings?',
      'Earnings move from Locked to Available once the booking is completed and the payout hold period has elapsed. From your Wallet, tap Withdraw, choose a verified bank account or UPI ID, and the payout is settled within 1–3 business days.',
      'creators', 1, 7),
  (8, 'payment', 'What fees does Zusty charge?',
      'Brands pay a platform fee on top of the agreed creator fee, and creators pay a commission on their earnings. Both rates are shown transparently on the payment screen and on every invoice before you confirm.',
      'all', 1, 8),
  (9, 'account', 'How do I delete my account?',
      'Go to Settings → Privacy & data → Delete my account (also in the Profile menu under Privacy & data). Confirm it''s you with your password or a one-time code, then type DELETE. Anything still in progress must be settled first: bookings in progress, disputes under review, money in your wallet, and withdrawals or refunds still being processed. A small balance that can''t be paid out can be given up instead. The screen shows what is in the way. Deletion happens as soon as you confirm and can''t be undone: your profile, contact details, creator profile, saved items, payout details and uploaded files (except files attached to bookings or sent in chat) are erased, and you''re signed out everywhere. Bookings, payments and invoices are kept because tax law requires it, and messages you sent and reviews you wrote stay without your name. Want a copy of your data? Use Download my data before you delete.',
      'all', 1, 9),
  (10,'security','What happens if a creator does not deliver?',
      'Raise a dispute from the booking screen. Escrow keeps your money safe while our team reviews the evidence from both sides, and we issue a full or partial refund if the work was not delivered as agreed.',
      'brands', 1, 10),
  (11,'creator','How do I set my pricing?',
      'Create packages under Profile → Pricing. Each package defines a platform, deliverable type, quantity, price, turnaround time and number of revisions. Brands book a package directly, so clear pricing wins more collaborations.',
      'creators', 1, 11),
  (12,'booking','What is a counter offer?',
      'If a brand''s budget does not match your rate, you can propose different terms instead of rejecting the request. The brand can then accept your counter offer, reply with another, or withdraw the request.',
      'all', 1, 12)
ON DUPLICATE KEY UPDATE
  `question` = VALUES(`question`), `answer` = VALUES(`answer`),
  `category` = VALUES(`category`), `audience` = VALUES(`audience`), `sort_order` = VALUES(`sort_order`);

-- ---------------------------------------------------------------------
-- Static pages
-- ---------------------------------------------------------------------
INSERT INTO `pages` (`slug`, `title`, `subtitle`, `content`, `template`, `is_system`, `is_active`, `show_in_app`, `sort_order`, `meta_title`, `meta_description`, `published_at`) VALUES
('about', 'About Zusty', 'Shoot. Connect. Grow.',
'<h2>Who we are</h2><p>Zusty is an influencer marketplace that makes creator collaborations as simple as booking a table. We connect brands with verified creators, then handle the brief, the negotiation, the contract, the payment and the proof of delivery in one place.</p><h2>Why we exist</h2><p>Influencer marketing still runs on spreadsheets, screenshots and unpaid invoices. Brands cannot tell a real audience from a bought one, and creators chase payments for months. Zusty fixes both sides of that problem with verified profiles, transparent pricing and escrow-backed payments.</p><h2>How it works</h2><ol><li><strong>Discover</strong> — search verified creators by category, city, audience size and budget.</li><li><strong>Book</strong> — send a campaign brief with your budget. Negotiate with counter offers if you need to.</li><li><strong>Pay securely</strong> — your payment is held in escrow until the work is delivered and approved.</li><li><strong>Grow</strong> — track deliverables, collect performance metrics and rate the collaboration.</li></ol><h2>Our commitment</h2><p>Every creator on Zusty is identity-verified. Every rupee is traceable. Every dispute gets a human review.</p>',
'default', 1, 1, 1, 1, 'About Zusty — Influencer Marketplace', 'Learn how Zusty connects brands with verified creators and secures every collaboration with escrow payments.', NOW()),

('terms', 'Terms & Conditions', 'Last updated: 10 October 2026',
'<h2>1. Acceptance of terms</h2><p>By creating an account or using the Zusty applications, website or API (together, the "Platform"), you agree to these Terms &amp; Conditions. If you do not agree, do not use the Platform.</p><h2>2. Eligibility</h2><p>You must be at least 18 years old and legally capable of entering into a binding contract. Business accounts must be registered by an authorised representative of the organisation.</p><h2>3. Accounts</h2><p>You are responsible for the accuracy of the information you provide and for all activity under your credentials. Notify us immediately of any unauthorised access. We may suspend an account that violates these terms, submits fraudulent documents or harms other users.</p><h2>4. The marketplace role</h2><p>Zusty is a venue. Contracts for content are formed directly between the brand and the creator. We provide escrow, messaging, dispute mediation and record-keeping, but we are not a party to the collaboration and do not guarantee campaign performance.</p><h2>5. Bookings and payments</h2><p>A booking becomes binding when the creator accepts and the brand pays. Payment is held in escrow and released to the creator after the deliverables are approved, or automatically after the auto-completion window if the brand does not respond. Platform fees and commissions are disclosed before payment and are shown on every invoice.</p><h2>6. Cancellations and refunds</h2><p>Cancellation windows, retained fees and refund destinations are set out in our Refund &amp; Cancellation Policy, which forms part of these terms.</p><h2>7. Content and licensing</h2><p>Creators retain ownership of the content they produce. Unless a package states otherwise, the brand receives a non-exclusive licence to use the delivered content on the agreed platforms for the agreed period. Usage beyond that scope must be negotiated separately.</p><h2>8. Prohibited conduct</h2><p>You must not: take payment off-platform to avoid fees; misrepresent audience metrics; submit content you do not own; harass other users; scrape the Platform; or attempt to circumvent our security controls.</p><h2>9. Fees</h2><p>We charge brands a platform fee and creators a commission. Rates are published in the app and may change with 30 days'' notice. Changes never apply retroactively to a booking already paid for.</p><h2>10. Termination</h2><p>You may close your account at any time once bookings in progress, disputes, your wallet balance and any pending withdrawals or refunds are settled. We may terminate or suspend access for breach of these terms, with notice where practicable.</p><h2>11. Liability</h2><p>To the maximum extent permitted by law, our aggregate liability arising from your use of the Platform is limited to the total fees you paid us in the three months preceding the claim. We are not liable for indirect or consequential loss.</p><h2>12. Governing law</h2><p>These terms are governed by the laws of India. The courts at Mumbai, Maharashtra have exclusive jurisdiction.</p><h2>13. Contact</h2><p>Questions about these terms: <a href="mailto:legal@zusty.app">legal@zusty.app</a>.</p>',
'legal', 1, 1, 1, 2, 'Terms & Conditions — Zusty', 'The terms governing the use of the Zusty influencer marketplace.', NOW()),

('privacy', 'Privacy Policy', 'Last updated: 10 October 2026',
'<h2>1. Scope</h2><p>This policy explains what personal data Zusty collects, why we collect it, how long we keep it and the rights you have over it.</p><h2>2. Data we collect</h2><ul><li><strong>Account data</strong> — name, email, phone number, password hash, profile photo, city.</li><li><strong>Creator data</strong> — portfolio media, linked social handles and their public audience metrics, pricing.</li><li><strong>Verification data</strong> — KYC documents and identity numbers, stored encrypted and visible only to authorised reviewers.</li><li><strong>Transaction data</strong> — bookings, invoices, wallet ledger entries and gateway payment references. We never store full card numbers.</li><li><strong>Communication data</strong> — in-app messages and support tickets.</li><li><strong>Technical data</strong> — device model, OS version, app version, IP address, crash reports and usage analytics.</li></ul><h2>3. Why we process it</h2><p>To operate your account, run the marketplace, process payments and payouts, prevent fraud, meet legal obligations including tax and KYC rules, provide support, and — only with your consent — send marketing.</p><h2>4. Sharing</h2><p>We share data with payment gateways, our cloud and messaging providers, and authorities where the law requires it. Your contact details are never sold. A creator sees only the information a brand chooses to share in a booking, and vice versa.</p><h2>5. Retention and deletion</h2><p>We keep your account data while your account is open.</p><p><strong>Deleting your account.</strong> You can delete your account at any time in the app under <strong>Settings → Privacy &amp; data → Delete my account</strong>. You confirm it is you with your password or a one-time code sent to your email or phone, then type DELETE. If you cannot use the app, see section 7.</p><p>An account cannot be deleted while something is still in progress: a booking in progress, a dispute under review, money in your wallet (including earnings still held in escrow), a payment into your wallet that is still being confirmed, a withdrawal or refund still being processed, or a wallet on hold. The app shows what needs to be settled. A small balance that cannot be paid out can be given up instead: the app offers this when it applies, or you can contact support and we will pay it out first. We do not cancel bookings for you.</p><p><strong>What we erase.</strong> Deletion takes effect as soon as you confirm and cannot be undone. We erase your name, photo, bio, city and company details, and every email address and phone number on your account, except where listed below; your creator profile, portfolio, packages, social accounts, availability and its edit history; your saved creators, follows, search history, the profiles you viewed and notifications, and our copies of the emails, texts and push messages sent to you; your password, linked Google or Apple sign-in, sessions and devices, and you are signed out everywhere; your saved bank accounts and UPI IDs; the files you uploaded, except files attached to bookings or sent in chat; and any copies of your data still stored on our servers. Open and draft campaigns are closed. Identity (KYC) document images and numbers are deleted; we keep only a minimal record that a verification took place, with its reference, outcome and dates.</p><p><strong>What we keep, and why.</strong></p><ul><li><strong>Bookings, payments, invoices, refunds, payouts and wallet entries</strong>, because Indian tax and accounting law requires us to keep financial records, generally for eight years. They show “Deleted user” in place of your account. Tax invoices and payout statements stay exactly as issued, with the billing name, address and GSTIN printed on them, and payout records keep the account holder''s name, the bank and a masked account number or UPI ID. Payment providers keep their own records of your payments.</li><li><strong>Messages you sent</strong> stay in the other person''s conversation, shown as from “Deleted user”. They are part of that person''s record and may be needed to resolve a dispute. Notifications about you in their app lose your name, but emails we already delivered to them cannot be recalled.</li><li><strong>Reviews you wrote</strong> stay on the creator''s or brand''s page, shown as from “Deleted user” with no photo, so ratings stay honest.</li><li><strong>Support tickets, contact-form messages and reports</strong>: your name and contact details are removed from them, but what you and our team wrote stays as written, for safety, fraud prevention and legal claims.</li><li><strong>Security logs</strong>: the time, IP address and device of each sign-in and of other account activity, without your email address or phone number, kept for 12 months to detect fraud and abuse.</li><li><strong>A minimal identity verification record</strong>: that your identity was checked, when, the outcome, and the name and IP address recorded at each step, kept for the period the law requires.</li><li><strong>A deletion record</strong>: that an account was deleted, when, how it was confirmed, and the reason you chose and anything you wrote with it, with no contact details.</li><li><strong>Backups</strong>: copies of our database are kept for up to 14 days to recover from failures, then deleted. If we ever restore one, we delete your account again straight away.</li></ul><p>Copies of your data that you downloaded stay on your device. We cannot delete those. The same list is on our <a href="/account-deletion">account deletion page</a>.</p><h2>6. Security</h2><p>Passwords are hashed with bcrypt. Sensitive identifiers are encrypted with AES-256-GCM. All traffic is TLS-encrypted. Access to production data is restricted, role-based and audited.</p><h2>7. Your rights</h2><p>You can see and correct most of your data in the app. To get a copy of the personal data we hold about you, use <strong>Settings → Privacy &amp; data → Download my data</strong>: after you confirm it is you, you get a ZIP file through a download link that works for a limited time. To delete your account, see section 5. If you cannot use the app, write to <a href="mailto:privacy@zusty.app">privacy@zusty.app</a> from the email address on your account; we act on the request once we have confirmed it comes from you. You may withdraw marketing consent at any time.</p><h2>8. Children</h2><p>The Platform is not directed at anyone under 18 and we do not knowingly collect their data.</p><h2>9. Changes</h2><p>We will notify you in-app before any material change takes effect.</p>',
'legal', 1, 1, 1, 3, 'Privacy Policy — Zusty', 'How Zusty collects, uses, secures and retains your personal data.', NOW()),

('refund-policy', 'Refund & Cancellation Policy', 'Escrow protects both sides',
'<h2>Escrow</h2><p>When a brand pays for a booking, the money is held by Zusty — not passed to the creator. It is released only when the deliverables are approved, or automatically after the auto-completion window if the brand does not respond.</p><h2>Cancellation by the brand</h2><ul><li><strong>Before the creator accepts</strong> — cancel free of charge; nothing has been paid.</li><li><strong>After acceptance, before payment</strong> — cancel free of charge.</li><li><strong>After payment, outside the free-cancellation window</strong> — full refund of the amount paid, including the platform fee.</li><li><strong>After payment, inside the free-cancellation window</strong> — a late cancellation fee is retained and paid to the creator as compensation for reserved capacity. The exact amount is shown before you confirm.</li><li><strong>After delivery</strong> — no refund unless a dispute is upheld.</li></ul><h2>Cancellation by the creator</h2><p>If a creator cancels an accepted booking, the brand receives a 100% refund including all fees, and the cancellation is recorded against the creator''s completion rate.</p><h2>Disputes</h2><p>Raise a dispute from the booking screen within 7 days of delivery. Both parties submit evidence, our team reviews within three business days, and the escrow balance is split according to the outcome.</p><h2>Refund destination and timing</h2><p>Refunds return to the original payment method by default, or to your Zusty wallet if you prefer — wallet refunds are instant. Card and net banking refunds take 5–7 business days to appear; UPI refunds usually take 1–3.</p>',
'legal', 1, 1, 1, 4, 'Refund & Cancellation Policy — Zusty', 'How cancellations, escrow releases, disputes and refunds work on Zusty.', NOW()),

('community-guidelines', 'Community Guidelines', 'Keeping the marketplace trustworthy',
'<h2>Be authentic</h2><p>Represent your real audience. Buying followers or engagement, or misreporting metrics, results in permanent removal.</p><h2>Be professional</h2><p>Respond to requests, meet agreed deadlines and communicate early if something slips. Repeated no-shows reduce your ranking.</p><h2>Keep it on-platform</h2><p>Negotiating or paying outside Zusty removes escrow protection from both sides and breaches our terms.</p><h2>Respect ownership</h2><p>Only upload content you created or are licensed to use. Disclose paid partnerships as required by the ASCI guidelines.</p><h2>No harassment</h2><p>Discrimination, threats, sexual harassment and hate speech are grounds for immediate removal.</p><h2>Reporting</h2><p>Report any profile, message or booking from its overflow menu. Every report is reviewed by a human moderator.</p>',
'default', 0, 1, 1, 5, 'Community Guidelines — Zusty', 'The standards every brand and creator on Zusty agrees to uphold.', NOW()),

('contact', 'Contact Us', 'We are here for you',
'<p>Our support team replies within one business day.</p><ul><li><strong>Email</strong> — support@zusty.app</li><li><strong>In-app</strong> — Help &amp; Support → Chat with Us</li><li><strong>Registered office</strong> — Appsgain Technologies Private Limited, India</li></ul><p>For press enquiries write to press@zusty.app, and for partnership proposals write to partners@zusty.app.</p>',
'contact', 0, 1, 1, 6, 'Contact Zusty', 'Reach the Zusty support, press and partnerships teams.', NOW())
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
 'Payment of {{currency}}{{amount}} is successful. Your funds are held safely in escrow.',
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
-- ---------------------------------------------------------------------
INSERT INTO `testimonials` (`id`, `author_name`, `author_role`, `company`, `content`, `rating`, `is_featured`, `is_active`, `sort_order`) VALUES
  (1, 'Priya Nair',   'Head of Marketing', 'Bloom Skincare',
      'We shortlisted, briefed and paid eleven creators in a single afternoon. What used to take our team three weeks now takes an evening.', 5, 1, 1, 1),
  (2, 'Rohit Verma',  'Travel Creator',    NULL,
      'Escrow changed everything for me. I stopped chasing invoices and started taking on bigger campaigns because I know the money is already there.', 5, 1, 1, 2),
  (3, 'Ananya Sharma','Lifestyle Blogger', NULL,
      'The package pricing made my rates obvious to brands, so the conversations start at the brief instead of the budget.', 5, 1, 1, 3),
  (4, 'Karan Mehta',  'Growth Lead',       'Nexa Devices',
      'Verified audience data meant we stopped paying for inflated follower counts. Our cost per engagement dropped by about 40%.', 5, 0, 1, 4)
ON DUPLICATE KEY UPDATE `content` = VALUES(`content`), `rating` = VALUES(`rating`);

-- ---------------------------------------------------------------------
-- Home banners
-- ---------------------------------------------------------------------
INSERT INTO `banners` (`id`, `title`, `subtitle`, `cta_label`, `image_url`, `position`, `action_type`, `action_value`, `audience`, `is_active`, `sort_order`) VALUES
  (1, 'Find the Right Creator for Your Next Campaign', 'Verified creators, transparent pricing, escrow-backed payments.',
      'Explore Now', '/uploads/banners/home-hero-1.jpg', 'home_hero', 'screen', '/explore', 'brands', 1, 1),
  (2, 'Turn Your Audience Into Income', 'Set your packages, get booked, get paid on time — every time.',
      'Get Started', '/uploads/banners/home-hero-2.jpg', 'home_hero', 'screen', '/creator/onboarding', 'creators', 1, 2),
  (3, 'Invite Friends & Earn', 'Share your code and earn wallet credit on their first booking.',
      'Invite Now', '/uploads/banners/home-mid-referral.jpg', 'home_mid', 'screen', '/invite', 'all', 1, 1)
ON DUPLICATE KEY UPDATE
  `title` = VALUES(`title`), `subtitle` = VALUES(`subtitle`), `cta_label` = VALUES(`cta_label`);
