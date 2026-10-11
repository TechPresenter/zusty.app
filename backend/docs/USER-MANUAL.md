# User Manual

How the platform works for the two people who use it: **brands**, who hire, and
**creators**, who get hired.

Written from the behaviour the API and the admin panel actually implement, so support
staff can answer "why did that happen?" without guessing.

---

## The model in one paragraph

A brand finds a creator, requests a booking with a price and a brief. The creator
accepts, rejects, or counters. Once both agree, the brand pays — and that money is
**held by Zusty**, not passed on (payment protection). The creator does the work and
submits deliverables. The brand approves, which releases the payment to the creator's
wallet. The creator
withdraws to their bank account. Both sides can review each other afterwards.

Everything else — search, messaging, disputes, refunds, coupons, referrals — supports
that one flow.

---

# For brands

## Getting started

Sign up with email and password, or with Google. Choose **Brand** at sign-up; the
account type determines which side of every booking you sit on and cannot be switched
later. After that you can also log in with a one-time code sent to your email address
(**Log in with email code**); a code only signs in an account that already exists.

Every account is made with an email address (there are no phone-only accounts).
Creating an account means agreeing to the Terms &
Conditions and the Privacy Policy: tick the box on the sign-up form, or, with Google,
the line above the Google button says that continuing agrees to them (both are linked
there). **Send me product updates and offers** is a separate box, left unticked: tick
it only if you want them. A Google sign-up starts with them off. Either way you can
change it any time under **Settings → Notifications → Product updates & offers**.

Complete the profile — company name, category, logo, contact. Creators see it when
deciding whether to accept, so an empty profile costs you acceptances.

## Finding creators

Browse by category, or search and filter by price band, city, rating, verification
status and availability. Sort by relevance, rating, price, popularity or newest.

A creator profile shows packages and prices, portfolio work, past reviews, response
rate, social reach, and availability.

**Verified** means the platform has checked identity documents. It is not an
endorsement of quality — reviews are.

## Booking

1. **Pick a package**, or request custom terms.
2. **Write the brief** — deliverables, timeline, references, anything mandatory. The
   creator decides based on this; vagueness here is the single biggest cause of
   disputes later.
3. **See the quote before you commit.** The app shows subtotal, platform fee, GST and
   total. Those figures are calculated server-side, so the number you see is the number
   you pay.
4. **Send the request.**

The creator then:

- **Accepts** — proceed to payment.
- **Counters** — proposes a different price, scope or timeline. Accept the counter or
  let it lapse.
- **Rejects** — with a reason.
- **Does nothing** — the request expires automatically after the configured window,
  and nothing is charged.

## Paying

After acceptance, pay for the booking on Cashfree's secure checkout page, with UPI, a
debit or credit card, or net banking. It is chosen for you on the payment screen; tap
**Pay** and finish on the checkout page.

Cashfree needs a mobile number on every order. If your account has none (accounts made
with an email address or Google usually don't), the app asks for one when you tap
**Pay**: a 10-digit Indian mobile number. It is sent to Cashfree with that payment only.
It is not added to your account and no code is sent to it; if the payment cannot be
started, our log of that request keeps only its last four digits.

There is no "Add money" for brands: you do not load your Zusty wallet in advance, you
pay each booking as you go. If a refund has gone to your wallet and that balance
covers the whole booking, the payment screen also offers **Or use your Zusty
balance** — a second option you can pick instead. A balance short of the total cannot
be combined with another method, so it is not offered.

**Payment protection: your money is held by Zusty, not passed to the creator.** When
the booking is confirmed, your payment is held by Zusty. It is released to the creator
when you approve the work, or automatically if you do not respond within the review
window after delivery (`booking.auto_complete_days`, 7 days by default; the app and the
terms quote the current value). If there is a dispute, our team reviews it before any
money is released. This protects both sides: you are not paying up-front for nothing,
and the creator knows the funds exist before starting.

Coupons apply at this step, subject to their validity window, usage limit and minimum
booking amount.

## While the work happens

- **Messages** — one thread per booking, with file attachments. Keep everything here;
  it is what a dispute is judged on.
- **Status** — `confirmed → in_progress → delivered`.
- **Revisions** — request changes on delivered work, within whatever the package
  allows.

## Approving

When the creator submits deliverables, review them and **approve**. Approval releases
the held payment to the creator immediately and is not reversible.

If you do nothing, the booking auto-completes after the review window (the
`bookings:autocomplete` job). Read what was delivered before that window closes.

If the work is genuinely wrong, **raise a dispute instead of approving**. An operator
reviews the brief, the deliverables and the message thread, then releases, refunds, or
splits.

## Cancelling

Cancellation policy depends on when you cancel:

| When | Outcome |
|---|---|
| Before you pay | Free — nothing has been charged |
| After you pay, at least the free-cancellation window (Bookings settings) before the delivery date | Full refund, platform fee included |
| After you pay, closer to the delivery date than that | The late-cancellation fee is kept and paid to the creator; the rest is refunded |
| After delivery | Not cancellable — ask for changes or raise a dispute instead |
| The creator cancels | Full refund, all fees included |

This is what the public Refund & Cancellation Policy says too.

The app shows a **cancellation preview** with the exact figures before you confirm.
Refunds return to the original payment method and take 5–7 working days to appear —
that delay is the bank's, not the platform's. A booking you paid from your wallet
balance is refunded to the wallet. Rarely, a payment cannot be returned automatically,
or support sends a refund to your wallet; it is then credited there at once, and the
refund notification says where it went.

Money in a brand's wallet can pay for a booking it covers, or be withdrawn: open
**Wallet → Withdraw**, choose a bank account or UPI ID in your name, and request the
payout. Withdrawals are reviewed and need a completed business verification, and start
at the minimum withdrawal amount. A smaller balance can still pay for a booking it covers
in full; to have it paid out instead, write to support@zusty.app.

## After

Leave a review — rating and comment. Reviews are moderated before publication and
cannot be edited once published, so write it as you mean it.

Invoices for every booking are downloadable from the booking screen.

---

# For creators

## Getting started

Sign up choosing **Creator** (with an email address, as brands do, and the same terms
and optional product updates), then build the profile. It is not visible in search until
an operator approves it.

1. **Basics** — display name, handle, headline, about, city.
2. **Categories** — where you appear in browsing.
3. **Social accounts** — link them; the follower figures are the ones you enter.
4. **Packages** — what you sell, what it includes, what it costs, how long it takes.
5. **Portfolio** — past work. This is what converts a profile view into a booking.
6. **Availability** — when you can take work.
7. **Submit for review.**

Status moves `draft → pending_review → approved`. If rejected, you are told why and
can resubmit.

The **Profile** tab shows how complete your profile is and the next parts you can
finish in the app (contact details, social accounts, portfolio, packages, KYC or your
profile photo); tap one to open the screen where you finish it. A mobile number is not
part of it while text messages are switched off on the platform. KYC is not listed
while our team is reviewing it, and the *Email verified* badge follows on its own once
your email address is confirmed.

## Packages, portfolio and social accounts

All three are edited in the app from **Profile → For creators**. On your own
profile, an empty Packages, Media or Insights tab also has a button that opens the
right editor.

**My packages.** A package is a fixed price for a set piece of work — "one reel with
a story reshare, ₹15,000, delivered in 5 days, 1 revision". Brands can pick it from
your profile when they send you a booking request; you accept the request, then the
brand pays at checkout. Each package has a title, an optional description, the platform
and type of content, how many, the price, the delivery time in days (1–180), the
revisions included (0–10) and an optional "what's included" checklist of up to ten
short lines.

- The price must sit between the platform's minimum and maximum booking amounts
  (the form shows both), because a package's price becomes the booking amount.
- **Hide** a package to take it off your profile without deleting it. Hidden
  packages stay in the list, marked *Hidden*, and can be shown again. Bookings
  already made with a package are not affected by hiding or deleting it.
- Your cheapest live package is what your profile shows as your starting price. Hide
  or delete them all and your profile shows no starting price.
- A "what's included" line you typed but did not add with **+** is added when you
  save.
- A package saved before the price limits changed, and now priced outside them,
  cannot be booked until you edit its price.

**Portfolio.** Add a picture, choose which tab it belongs under (Posts, Reels, …),
and optionally a title, a caption and a link to the original. For a reel or a video
the picture is the **cover**: brands see it in your grid, and the link is what they
open to watch it. New items may be checked by our team first — they show as
*In review* in your list and appear on your profile once approved. An item that is
*Not approved* shows the reason; remove it and add a better one. A portfolio holds a
set number of items (100 unless our team changes it; items in review count); the
screen says when you reach it, and you remove one to add another.

**Social accounts.** Link Instagram, YouTube and the other networks the app lists,
plus a website, blog or any other link with a name you choose. Drag them into the
order your profile should show them. One account per network: a network you have
already linked is not offered again, so tap it in your list to edit it. To move a
link to a different network, unlink it and add the new one.

Follower and engagement figures are **self-reported**: the numbers you type are the
numbers brands see, and Zusty does not fetch them from the networks. Your profile
labels them *self-reported*, with the date you last entered them. Keep them current
and be ready to show a brand where they come from. Changing the handle or link of an
account our team verified removes its verified mark until it is checked again.

If our team has locked one of these sections, the screen says so and your changes
are refused with the reason; contact support to have it changed.

## KYC

Required before you can withdraw money. Submit an identity document and bank details.
Both are **encrypted at rest** and are decrypted only when a reviewer opens them for
the approval decision.

Do this early. Discovering that KYC is pending on the day you want to be paid is the
most common avoidable frustration on the platform.

## Receiving requests

A booking request arrives by push and email. You have a limited window to respond
before it expires — the operator sets it, typically 48 hours.

Read the brief before accepting. Then:

- **Accept** — you are committing to the scope, price and timeline as written.
- **Counter** — propose different terms. Use this rather than accepting work you cannot
  deliver as specified.
- **Reject** — with a reason.

**Answer requests promptly.** A request you leave unanswered expires on its own and the
brand moves on. Your profile shows the typical reply time you set, not a measured rate.

## Doing the work

Once the brand pays, the booking becomes `confirmed` and Zusty holds the payment — it
exists, it is committed, and it is yours on approval.

Mark it `in_progress`, use the message thread for questions, then **submit
deliverables**: files, links, drafts, whatever the brief asked for.

Everything through the platform thread. If terms change in a side conversation, they
are not what a dispute will be judged against.

## Getting paid

The brand approves, or the review window closes and the booking auto-completes. Either
way, the held payment is released into your **wallet**, minus the commission.

Your wallet shows:

- **Available balance** — withdrawable now.
- **On hold** (locked balance) — earnings from completed bookings during the short
  hold before they become withdrawable (`wallet.withdrawal_hold_days`), and any
  withdrawal already on its way to you.

The wallet also holds any refunds credited to it and referral rewards: while the
referral programme runs, you and a friend you invited each get one when that friend
completes a first booking as a brand. Nobody can pay money into the wallet.

Withdraw to a verified bank account, above the minimum withdrawal amount. Requests are
reviewed by an operator, then paid out. **Withdrawal is not instant** — it is a queue,
because it is a human transferring real money.

Fees: the platform commission and GST are deducted at booking time, so the amount that
reaches your wallet is what the quote showed as "creator receives".

## Growing

- **Reviews** — brands rate each completed booking. Your rating and review count show on
  your profile. Search results are ordered by featured placement and any boost our team
  sets, then by rating.
- **Completion rate** — shown on your profile once you have completed a booking.
  Cancelled, disputed and refunded bookings count against it. There is no response-rate
  figure.
- **Portfolio** — keep your best recent work on your profile; it is what brands look at
  before they book. It does not change your place in search.
- **Referrals** — invite other creators and earn a reward when they complete their first
  paid booking.

---

## Both sides

### Messaging

One thread per booking, plus direct threads. Attachments supported. Read receipts and
typing indicators.

Messages are retained and are visible to operators when a dispute or report is raised.
That is the point: the thread is the record.

### Notifications

Push and email, controlled per channel in **Settings → Notifications**. Turning off
push does not turn off email — they are separate switches. **Product updates & offers**
covers news and promotions from Zusty; it is off unless you turned it on (at sign-up or
here), and turning it off never stops booking, payment or security messages. Turning it
off takes effect straight away, including for a promotion that is already being sent;
a promotional notice may still appear in your in-app notification list, but it is not
pushed or emailed to you. There is no SMS switch while text messages are switched off
on the platform.

### Reporting

Report a profile, message, review, booking, portfolio post or campaign brief from its
menu in the app.
A person on the team reviews every report, and the person reported is not told who
reported them. What the team can do depends on what was reported: a message can be
removed from the chat for both people, a campaign brief taken down (it is cancelled and
leaves the board), a review hidden, and a portfolio item taken off the profile. The
person who posted it gets a notice in the app that it was removed. For a profile or a
booking, the team can suspend the account, and act on the booking (cancel or refund
it) from the booking itself. Reports are not anonymous to operators, and a pattern of
false reports is itself actionable.

Where to find it: the **⋮** menu on a creator's profile, a chat, each review, a
campaign brief and a booking, and at the top of a portfolio post opened full screen
(it reports the post on screen). To report one message rather than the whole chat,
long-press it and choose **Report message**. Pick the reason that fits best (spam, a
scam, being asked to pay outside Zusty, harassment, hate speech, sexual content,
violence, self-harm, a child at risk, impersonation, someone else's work, or something
else), add a note if it helps, and send. If you already reported the same thing and it
is still being looked at, the app says so instead of filing it twice.

Block someone from the same menu: neither of you can then message the other or start a
booking, offer, invitation or application with the other, and you drop out of each
other's search. Someone you blocked can no longer see when you are online. A booking
already paid for can still be delivered, completed or disputed. Unblock under
**Settings → Privacy & data → Blocked accounts**, which lists everyone you blocked. The
public Community Guidelines describe the same rules.

Blocking also closes your chat with them (it leaves your Messages; the booking still
opens it, read-only), and removes any follow or saved creator between you, which an
unblock does not bring back. The person is not notified; if they try to message you
they are told you are not accepting messages from them. The accounts you blocked are
part of **Download my data**, and deleting your account removes your blocks.

### Support

Raise a ticket from the app. It reaches the operator's queue with your account and
booking context attached, so you do not have to explain who you are.

**Complaints** go to the Grievance Officer, Appsgain Technologies Private Limited, at
support@zusty.app, with "Grievance" in the subject line. Complaints are acknowledged
within 24 hours and resolved within 15 days. The same address takes requests about
your personal data under the Digital Personal Data Protection Act, 2023 (privacy policy
section 8).

### Account

- **Sessions** — see every signed-in device and revoke any of them.
- **Password** — changing it requires the current password. An account you made with
  Google (or an older account made with a phone number) has none: **Set a password**
  adds one after you confirm it's you with a code we send to your email, or by signing
  in with Google again. Either way you are signed out on every device afterwards, and
  we email you about it when your account has an email address.
- **Contact details** — change your email address here, not on the profile screen. You
  confirm it's you first (your password, a code sent to your email, or your Google
  sign-in), the new address is verified, and we tell the old one. A new mobile number
  is confirmed by a code sent by text message, and text messages are switched off, so
  a number can't be added or changed for now; a brand without one types it at
  checkout instead (see Paying). For 72 hours after a change, the new email can't
  receive the codes that confirm privacy actions; the app says when it can again, and
  you can confirm another way meanwhile.
- **Privacy & data** (Settings, or the Profile menu) — two things, each confirmed with
  your password, a one-time code sent to your email, or your Google sign-in:
  - **Download my data** — a ZIP of your profile, bookings, messages you sent,
    payments, reviews, sign-in history and activity, ready in seconds. The download
    link shows in the app; you can get a fresh one there while the copy lasts, after
    confirming it's you again. By default the link works for 3 days and 5 downloads,
    and you can ask for a new copy once a day; the app always shows the current limits.
    We also send you a security notice by email, so you would know if anyone else
    asked. The notice has no link in it.
  - **Delete my account** — permanent. Before you confirm you see what is erased and
    what the law makes us keep: bookings, payments and invoices stay, showing "Deleted
    user" in place of your account, and invoices keep the billing details printed on
    them. Messages you sent and reviews you wrote stay without your name. Not available
    while you have bookings in progress, a dispute under review, money in your wallet,
    or a withdrawal or refund on its way; the screen says which, and how to settle it.
    A small balance that can't be paid out can be given up instead, by ticking a box
    that names the amount. You are signed out on every device, we confirm by email, and
    your email and phone can be used for a new account. If you can't use the app, write to
    support from the email address on your account.

---

## Money, plainly

| Term | Meaning |
|---|---|
| **Subtotal** | The agreed price of the work |
| **Platform fee** | Commission, a percentage set by the operator |
| **GST** | Tax on the platform fee |
| **Total** | What the brand pays |
| **Creator receives** | Subtotal minus commission |
| **Held payment** | Paid by the brand, held by Zusty until approval (or the review window), not yet the creator's |
| **On hold / locked balance** | A creator's earnings in the short hold after completion, or a withdrawal on its way |
| **Available balance** | Withdrawable now |

All amounts are in **INR**.

The brand sees every one of these figures in the quote *before* paying. The creator
sees "creator receives" before accepting. Neither number changes afterwards.

---

## Common questions

**"I paid but the creator says they see nothing."**
Payment confirmation is a webhook from the gateway and can lag by a minute or two. If
it persists, an operator can see the webhook trail under Payments and confirm whether
the gateway ever called back.

**"The creator never replied."**
The request expires automatically and nothing is charged.

**"I approved by mistake."**
Approval releases the held payment and cannot be reversed from the app. Contact support — an
operator can see the full history, but recovering released funds depends on the
creator's cooperation.

**"Where is my withdrawal?"**
Check that KYC is approved and the bank account is verified. Then it is in the payout
queue awaiting operator review. Wallet → Withdrawals shows the current status.

**"Why was my creator profile rejected?"**
The rejection carries a reason. The usual causes are an incomplete portfolio, unclear
packages, or social accounts that could not be verified. Fix and resubmit.

**"The app says the platform is under maintenance."**
The operator has enabled maintenance mode. The message shown is theirs. Nothing is
lost; the app works again when they finish.
