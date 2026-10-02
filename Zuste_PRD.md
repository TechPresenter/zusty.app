# Product Requirement Document (PRD)
## Zuste — AI-Powered Influencer Marketplace Platform

| | |
|---|---|
| **Document Version** | 1.0 |
| **Prepared Date** | August 1, 2026 |
| **Status** | Draft — For Development Handoff |
| **Related Documents** | `Zuste_SRS.md` · `Zuste_TRD.md` · `Zuste_Workflow.md` |

---

## Table of Contents
1. [Product Overview](#1-product-overview)
2. [Product Goals](#2-product-goals)
3. [Target Users & Personas](#3-target-users--personas)
4. [Pain Points](#4-pain-points)
5. [Core Features (MVP)](#5-core-features-mvp)
6. [Premium Features](#6-premium-features)
7. [Future Features](#7-future-features)
8. [Functional Requirements](#8-functional-requirements)
9. [Non-Functional Requirements](#9-non-functional-requirements)
10. [Use Cases](#10-use-cases)
11. [Acceptance Criteria](#11-acceptance-criteria)
12. [Success Metrics](#12-success-metrics)
13. [Release & Version Planning](#13-release--version-planning)

---

## 1. Product Overview

Zuste is an AI-powered influencer marketplace that lets **brands** discover, negotiate with, book, pay, and review **creators/influencers** — and lets creators manage requests, deliverables, and earnings — from a single platform spanning Android, iOS, and Web. Zuste is not a content platform; it is the transaction and relationship layer that sits on top of existing social platforms (Instagram, YouTube, etc.), replacing spreadsheets, DMs, and manual invoicing with a structured, trackable workflow.

| Attribute | Detail |
|---|---|
| Primary users | Brands, Creators/Influencers, Platform Admin |
| Platforms | Android, iOS, Web (Flutter, responsive/PWA) |
| Core loop | Discover → Request → Negotiate → Book → Pay (escrow) → Deliver → Approve → Payout → Review |
| Monetization | Commission per booking (10–20%), premium subscriptions, featured listings, AI tools, ads |

## 2. Product Goals

- **G1 — Reduce discovery time.** Cut the time a brand takes to find and shortlist a relevant creator from days (manual outreach) to minutes (filtered search + AI recommendation).
- **G2 — Guarantee payment trust.** Use escrow so creators are confident they'll be paid and brands are confident work will be delivered before funds release.
- **G3 — Verified marketplace.** KYC-verify creators and verify brands to reduce fraud and fake-follower risk.
- **G4 — Data/AI moat.** Improve match quality, pricing suggestions, and fraud detection as booking volume grows.
- **G5 — Sustainable unit economics.** Keep the platform commission competitive while layering premium revenue (subscriptions, featured placement, AI tools) on top.

## 3. Target Users & Personas

### 3.1 Brand — "Priya, Marketing Manager"
| | |
|---|---|
| Age / Role | 29, Marketing Manager at a D2C skincare brand |
| Goal | Launch a 15-creator seeding campaign for a new product in under 2 weeks |
| Needs | Fast filtered search, transparent pricing, one place to track every creator's status |
| Frustration today | Juggling Instagram DMs, WhatsApp, and spreadsheets across 15 creators |

### 3.2 Creator / Influencer — "Arjun, Micro-Influencer"
| | |
|---|---|
| Age / Role | 23, lifestyle & fitness creator, ~40K Instagram followers |
| Goal | Get paid reliably and on time for brand collaborations without chasing invoices |
| Needs | Clear briefs, a booking calendar, a wallet with visible payout status |
| Frustration today | Brands go quiet after content is delivered; payment delays of 30–60 days |

### 3.3 Admin — "Platform Operations Team"
| | |
|---|---|
| Role | Internal ops / support / finance staff |
| Goal | Keep the marketplace safe (verified users), resolve disputes fast, keep commission flowing |
| Needs | A single dashboard for verification queues, disputes, payouts, and platform health |

## 4. Pain Points

| Persona | Pain Point | How Zuste Addresses It |
|---|---|---|
| Brand | Can't tell real reach from fake followers | AI Fake Followers Detection, AI Creator Score |
| Brand | Manual negotiation over chat/email, no audit trail | Structured Booking System with brief, budget, counter-offer, status |
| Brand | Payment released before work is confirmed | Escrow-based Payment Workflow |
| Creator | Payment delays or non-payment after delivery | Escrow + automated Settlement Workflow |
| Creator | No visibility into earnings or performance | Creator Dashboard: Monthly Earnings, Analytics, Performance Reports |
| Creator | Pricing guesswork | AI Price Suggestion |
| Admin | Manual, error-prone creator verification | KYC submission + admin verification queue |
| Admin | Fraudulent accounts or bookings | AI Fraud Detection, Activity Logs |

## 5. Core Features (MVP)

Features required for the v1.0 launch, grouped by module.

| Module | Core Features (v1.0) |
|---|---|
| Authentication | Email login, Mobile OTP, Google login, Apple login, Forgot password (Firebase Auth) |
| Onboarding | 3-screen intro (Brand Discovery, Verified Creators, Collaboration) |
| Discovery | Keyword search, category/price/location/followers/rating/platform filters |
| Creator Profile | Bio, portfolio, gallery, pricing packages, ratings, availability, book/chat/save actions |
| Booking | Campaign brief, budget, timeline, negotiation, counter-offer, accept/reject, status tracking, invoice |
| Chat | Real-time messaging, images, PDF sharing, read receipts, typing indicator, online status |
| Wallet & Payments | Balance, transactions, deposit, withdraw, Razorpay/Stripe/UPI/cards/net banking, coupons, GST invoice |
| Notifications | Booking, payment, message, and campaign alerts via Push/Email/SMS |
| Reviews | Two-way rating after campaign completion |
| Admin Panel | User/creator/booking/payment/wallet/withdrawal management, categories, CMS, roles & permissions, activity logs |

## 6. Premium Features

Monetized capabilities layered on top of the core marketplace:

| Feature | Description |
|---|---|
| AI Creator Recommendation | Personalised creator suggestions for a brand's brief |
| AI Campaign Matching | Matches open campaigns to the best-fit available creators |
| AI Price Suggestion | Suggests a fair rate for a given deliverable and creator profile |
| AI Creator Score | Composite trust/quality score surfaced on profiles |
| Featured Creator Listings | Paid placement boosting a creator's visibility in search |
| Featured Brand Campaigns | Paid placement boosting a campaign's visibility to creators |
| Premium Subscriptions | Recurring plans unlocking advanced analytics/tools |
| Enterprise Plans | Custom terms, dedicated support, and API access for large brands/agencies |

## 7. Future Features

Roadmap items beyond v1.0, not required for MVP:

- Video calling between brand and creator
- Live streaming integration
- Referral system and loyalty program
- Coupon-based growth campaigns
- Multi-language and multi-currency support
- Marketplace expansion (new creator categories, geographies)
- AI Chat Assistant, AI Fraud Detection v2, AI Campaign Performance Prediction

## 8. Functional Requirements

| ID | Requirement | Module | Priority |
|---|---|---|---|
| FR-001 | The system shall allow a brand or creator to register using email, mobile OTP, Google, or Apple. | Authentication | Must |
| FR-002 | The system shall allow password reset via email or OTP verification. | Authentication | Must |
| FR-003 | The system shall require creators to submit KYC documents before their profile is publicly listed. | Verification | Must |
| FR-004 | The system shall allow an admin to approve or reject a creator's KYC submission. | Verification | Must |
| FR-005 | The system shall allow brands to search creators by keyword, category, price, location, followers, rating, and platform. | Discovery | Must |
| FR-006 | The system shall display a creator's bio, portfolio, gallery, pricing, ratings, and availability on their public profile. | Profile | Must |
| FR-007 | The system shall allow a brand to send a campaign request containing a brief, deliverables, budget, and timeline. | Booking | Must |
| FR-008 | The system shall allow a creator to accept, reject, or counter-offer a campaign request. | Booking | Must |
| FR-009 | The system shall track booking status through defined states (Requested, Negotiating, Confirmed, In Progress, Completed, Cancelled). | Booking | Must |
| FR-010 | The system shall hold campaign payment in escrow upon booking confirmation. | Payment | Must |
| FR-011 | The system shall release escrowed funds to the creator's wallet only after the brand approves the delivered work. | Payment | Must |
| FR-012 | The system shall deduct the platform commission (10–20%, admin-configurable) before crediting the creator's wallet. | Payment | Must |
| FR-013 | The system shall support Razorpay, Stripe, UPI, credit card, debit card, net banking, and in-app wallet as payment methods. | Payment | Must |
| FR-014 | The system shall generate a GST-compliant invoice for every completed transaction. | Payment | Must |
| FR-015 | The system shall allow a creator to request withdrawal of their wallet balance to a linked bank account. | Wallet | Must |
| FR-016 | The system shall allow an admin to approve or reject withdrawal requests. | Wallet | Must |
| FR-017 | The system shall provide real-time chat between a brand and a creator, supporting text, images, PDFs, and voice notes. | Chat | Must |
| FR-018 | The system shall notify users of booking, payment, message, and campaign events via push, email, or SMS according to their preferences. | Notifications | Must |
| FR-019 | The system shall allow brands and creators to leave a rating and written review after a campaign is marked complete. | Reviews | Must |
| FR-020 | The system shall allow an admin to manage users, creators, bookings, payments, wallets, categories, and CMS content from a central panel. | Admin | Must |
| FR-021 | The system shall log all admin actions in an activity log for audit purposes. | Admin | Must |
| FR-022 | The system shall support role-based access control for Admin, Moderator, Support, and Finance panel users. | Admin | Must |
| FR-023 | The system shall allow a brand to save/bookmark creators for later comparison. | Discovery | Should |
| FR-024 | The system shall surface an AI-generated creator recommendation list based on a brand's search and booking history. | AI | Should |
| FR-025 | The system shall suggest a fair price range for a campaign based on the creator's profile and historical bookings. | AI | Should |
| FR-026 | The system shall flag accounts or transactions exhibiting fraud-like patterns for admin review. | AI / Trust & Safety | Should |
| FR-027 | The system shall estimate the authenticity of a creator's follower count and surface a fake-follower risk indicator. | AI / Trust & Safety | Should |
| FR-028 | The system shall allow a creator to set an availability calendar controlling which dates they can be booked. | Booking | Should |
| FR-029 | The system shall support coupon codes applied at checkout. | Payment | Could |
| FR-030 | The system shall support dark mode and language selection in app settings. | Settings | Could |

*(Priority key: **Must** = required for v1.0 launch · **Should** = high value, v1.0 if timeline allows, else v1.1 · **Could** = nice-to-have, later release.)*

## 9. Non-Functional Requirements

| Category | Requirement |
|---|---|
| Performance | Search results shall return within 2 seconds for a catalogue of up to 100,000 creators. |
| Scalability | The REST API shall support at least 5,000 concurrent users without degradation at launch, horizontally scalable behind Nginx. |
| Security | All API traffic shall use HTTPS/TLS; passwords shall be hashed (bcrypt/Argon2); sessions shall use JWT with expiry and refresh. |
| Availability | The platform shall target 99.5% uptime, excluding scheduled maintenance windows. |
| Usability | Core flows (search → book → pay) shall be completable by a first-time brand user without external help. |
| Compatibility | Mobile apps shall support the latest two major Android and iOS versions; Web shall support evergreen Chrome, Safari, and Edge. |
| Data Integrity | Financial transactions (escrow, commission, payout) shall be atomic and auditable. |
| Auditability | All financial and admin actions shall be logged with actor, timestamp, and before/after state. |

## 10. Use Cases

### UC-01 — Brand Books a Creator
- **Actors:** Brand, Creator
- **Preconditions:** Brand is registered and logged in; creator is verified and listed.
- **Main Flow:**
  1. Brand searches/filters creators and opens a profile.
  2. Brand sends a campaign request (brief, deliverables, budget, timeline).
  3. Creator receives a notification and reviews the request.
  4. Creator accepts, rejects, or counter-offers.
  5. Once terms are agreed, the brand confirms the booking.
  6. Brand completes payment; funds move to escrow.
- **Postcondition:** Booking status is "Confirmed" and payment is held in escrow.

### UC-02 — Creator Withdraws Earnings
- **Actors:** Creator, Admin
- **Preconditions:** Creator has a positive wallet balance from completed, approved campaigns.
- **Main Flow:**
  1. Creator opens Wallet and requests a withdrawal.
  2. System validates balance and bank details.
  3. Request appears in Admin's withdrawal queue.
  4. Admin approves (or rejects with a reason).
  5. Funds are transferred to the creator's bank account; wallet balance updates.
- **Postcondition:** Withdrawal is marked "Paid" and reflected in transaction history.

### UC-03 — Creator Verification (KYC)
- **Actors:** Creator, Admin
- **Preconditions:** Creator has completed registration and profile setup.
- **Main Flow:**
  1. Creator submits KYC documents (ID, social account proof).
  2. Submission enters the admin verification queue.
  3. Admin reviews and approves or rejects with a reason.
  4. On approval, the creator's profile becomes publicly searchable and bookable.
- **Postcondition:** Creator status is "Verified" or "Rejected" (with feedback).

### UC-04 — Escrow Payment Release
- **Actors:** Brand, Creator, System
- **Preconditions:** Booking is confirmed and payment is in escrow.
- **Main Flow:**
  1. Creator delivers content/completes deliverables and marks the campaign as complete.
  2. Brand reviews the delivered work.
  3. Brand approves (or raises a dispute).
  4. System deducts platform commission and credits the remainder to the creator's wallet.
- **Postcondition:** Escrow is released; both parties can leave a review.

### UC-05 — Dispute / Refund
- **Actors:** Brand, Creator, Admin
- **Preconditions:** A booking is in progress or delivered but one party raises an issue.
- **Main Flow:**
  1. Brand or creator opens a support ticket referencing the booking.
  2. Admin reviews chat history, brief, and deliverables.
  3. Admin decides: release funds to creator, refund brand, or partial split.
  4. System executes the decision and closes the ticket.
- **Postcondition:** Booking is resolved (Completed, Refunded, or Partially Refunded).

## 11. Acceptance Criteria

**Registration**
- Given a new user on the sign-up screen, when they enter a valid email/mobile and verify OTP, then an account is created and they are routed to profile setup.
- Given an existing email, when a user attempts to register with it again, then the system shows a clear "account already exists" message.

**Booking & Escrow**
- Given a confirmed booking, when the brand completes payment, then funds are moved to escrow and the booking status updates to "Confirmed," not directly to "Paid to Creator."
- Given a brand approves delivered work, when approval is submitted, then commission is deducted and the net amount appears in the creator's wallet within the same transaction.

**Withdrawal**
- Given a creator wallet balance greater than the minimum payout threshold, when a withdrawal is requested, then the request appears in the admin queue with the correct amount and bank details.
- Given an admin rejects a withdrawal, when rejection is submitted with a reason, then the creator is notified and the wallet balance is restored.

**Search**
- Given a brand applies category, price, and rating filters, when results load, then every returned creator satisfies all applied filters simultaneously.

## 12. Success Metrics

| Metric | Definition | Target (first 2 quarters post-launch) |
|---|---|---|
| Time-to-First-Booking | Days from brand sign-up to first confirmed booking | < 7 days |
| GMV (Gross Marketplace Value) | Total value of confirmed bookings per month | Growing month-over-month |
| Creator Payout SLA | Time from delivery approval to wallet credit | < 5 minutes |
| Withdrawal SLA | Time from withdrawal request to payout | < 48 hours |
| Creator Retention | % of verified creators active after 90 days | > 60% |
| Brand Repeat Rate | % of brands with 2+ bookings in 90 days | > 35% |
| Average Rating | Mean rating across completed campaigns | ≥ 4.3 / 5 |
| Dispute Rate | % of bookings escalated to support | < 3% |

## 13. Release & Version Planning

| Phase | Scope | Target |
|---|---|---|
| v1.0 — MVP | Auth, Discovery, Profiles, Booking, Chat, Escrow Payment, Wallet, Notifications, Reviews, Admin Panel | Launch |
| v1.1 | AI Creator Recommendation, AI Price Suggestion, Coupons, Availability Calendar | Launch + 2 months |
| v1.2 | AI Fraud Detection, AI Fake Follower Detection, Featured Listings, Premium Subscriptions | Launch + 4 months |
| v2.0 | Video calling, Live streaming, Referral program, Multi-language/currency | Launch + 8–12 months |

---

*This PRD should be read alongside `Zuste_SRS.md` (formal software requirements), `Zuste_TRD.md` (technical implementation standards), and `Zuste_Workflow.md` (process diagrams).*
