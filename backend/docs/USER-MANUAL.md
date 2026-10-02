# User Manual

How the platform works for the two people who use it: **brands**, who hire, and
**creators**, who get hired.

Written from the behaviour the API and the admin panel actually implement, so support
staff can answer "why did that happen?" without guessing.

---

## The model in one paragraph

A brand finds a creator, requests a booking with a price and a brief. The creator
accepts, rejects, or counters. Once both agree, the brand pays — and that money is
**held in escrow**, not passed on. The creator does the work and submits deliverables.
The brand approves, which releases the escrow to the creator's wallet. The creator
withdraws to their bank account. Both sides can review each other afterwards.

Everything else — search, messaging, disputes, refunds, coupons, referrals — supports
that one flow.

---

# For brands

## Getting started

Sign up with email and password, phone and OTP, or Google/Apple. Choose **Brand** at
sign-up; the account type determines which side of every booking you sit on and cannot
be switched later.

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

After acceptance, pay by card, UPI, net banking or wallet balance, through Razorpay,
Stripe or Cashfree depending on what the operator has enabled.

**Your money goes into escrow, not to the creator.** It is held by the platform until
you approve the work. This protects both sides: you are not paying up-front for
nothing, and the creator knows the funds exist before starting.

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
escrow to the creator immediately and is not reversible.

If you do nothing, the booking auto-completes after the review window (the
`bookings:autocomplete` job). Read what was delivered before that window closes.

If the work is genuinely wrong, **raise a dispute instead of approving**. An operator
reviews the brief, the deliverables and the message thread, then releases, refunds, or
splits.

## Cancelling

Cancellation policy depends on when you cancel:

| When | Typical outcome |
|---|---|
| Before acceptance | Full refund, no fee |
| After acceptance, before work starts | Refund minus cancellation fee |
| After work has started | Partial, at the operator's discretion |
| After delivery | Not cancellable — dispute instead |

The app shows a **cancellation preview** with the exact figures before you confirm.
Refunds return to the original payment method and take 5–7 working days to appear —
that delay is the bank's, not the platform's.

## After

Leave a review — rating and comment. Reviews are moderated before publication and
cannot be edited once published, so write it as you mean it.

Invoices for every booking are downloadable from the booking screen.

---

# For creators

## Getting started

Sign up choosing **Creator**, then build the profile. It is not visible in search until
an operator approves it.

1. **Basics** — display name, handle, headline, about, city.
2. **Categories** — where you appear in browsing.
3. **Social accounts** — connect them; follower counts inform your visibility.
4. **Packages** — what you sell, what it includes, what it costs, how long it takes.
5. **Portfolio** — past work. This is what converts a profile view into a booking.
6. **Availability** — when you can take work.
7. **Submit for review.**

Status moves `draft → pending_review → approved`. If rejected, you are told why and
can resubmit.

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

**Your response rate is visible on your profile.** Letting requests expire costs you
more than rejecting them.

## Doing the work

Once the brand pays, the booking becomes `confirmed` and the money is in escrow — it
exists, it is committed, and it is yours on approval.

Mark it `in_progress`, use the message thread for questions, then **submit
deliverables**: files, links, drafts, whatever the brief asked for.

Everything through the platform thread. If terms change in a side conversation, they
are not what a dispute will be judged against.

## Getting paid

The brand approves, or the review window closes and the booking auto-completes. Either
way, escrow releases into your **wallet**.

Your wallet shows:

- **Available balance** — withdrawable now.
- **Locked balance** — escrow on bookings not yet completed. Real money, not yet yours.

Withdraw to a verified bank account, above the minimum withdrawal amount. Requests are
reviewed by an operator, then paid out. **Withdrawal is not instant** — it is a queue,
because it is a human transferring real money.

Fees: the platform commission and GST are deducted at booking time, so the amount that
reaches your wallet is what the quote showed as "creator receives".

## Growing

- **Reviews** — the strongest ranking and conversion signal.
- **Response rate and completion rate** — both visible, both affect ranking.
- **Portfolio freshness** — updated portfolios rank better and convert better.
- **Referrals** — invite other creators and earn a bonus when they complete their first
  booking.
- **Analytics** — profile views, conversion rate and earnings over time, on your own
  dashboard.

---

## Both sides

### Messaging

One thread per booking, plus direct threads. Attachments supported. Read receipts and
typing indicators.

Messages are retained and are visible to operators when a dispute or report is raised.
That is the point: the thread is the record.

### Notifications

Push, email and SMS, controlled per channel and per category in settings. Turning off
push does not turn off email — they are separate switches.

### Reporting

Report a user, booking, review or message. An operator investigates. Reports are not
anonymous to operators, and a pattern of false reports is itself actionable.

### Support

Raise a ticket from the app. It reaches the operator's queue with your account and
booking context attached, so you do not have to explain who you are.

### Account

- **Sessions** — see every signed-in device and revoke any of them.
- **Password** — changing it requires the current password.
- **Privacy & data** (Settings, or the Profile menu) — two things, each confirmed with
  your password or a one-time code sent to your email or phone:
  - **Download my data** — a ZIP of your profile, bookings, messages you sent,
    payments, reviews and activity, ready in seconds. The link works for 3 days and 5
    downloads, and you can ask for a new copy once a day. We email you too, so you
    would know if anyone else asked.
  - **Delete my account** — permanent. You see what is erased and what the law makes
    us keep (bookings, invoices and payments, without your name) before you confirm.
    Not available while you have bookings in progress, money in your wallet, or a
    withdrawal or refund on its way; the screen says which, and how to settle it. You
    are signed out on every device, and your email and phone can be used for a new
    account.

---

## Money, plainly

| Term | Meaning |
|---|---|
| **Subtotal** | The agreed price of the work |
| **Platform fee** | Commission, a percentage set by the operator |
| **GST** | Tax on the platform fee |
| **Total** | What the brand pays |
| **Creator receives** | Subtotal minus commission |
| **Escrow / locked balance** | Paid by the brand, held by the platform, not yet the creator's |
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
The request expires automatically and nothing is charged. Expired requests count
against the creator's response rate.

**"I approved by mistake."**
Approval releases escrow and cannot be reversed from the app. Contact support — an
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
