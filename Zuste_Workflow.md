# Business Workflow Document
## Zuste — AI-Powered Influencer Marketplace Platform

| | |
|---|---|
| **Document Version** | 1.0 |
| **Prepared Date** | August 1, 2026 |
| **Related Documents** | `Zuste_PRD.md` · `Zuste_SRS.md` · `Zuste_TRD.md` |

---

## Table of Contents
- [0. Campaign Lifecycle — End-to-End](#0-campaign-lifecycle--end-to-end)
- [1. Lead Generation Workflow](#1-lead-generation-workflow)
- [2. Registration Workflow](#2-registration-workflow)
- [3. Creator Verification (KYC) Workflow](#3-creator-verification-kyc-workflow)
- [4. Booking Workflow](#4-booking-workflow)
- [5. Approval Workflow](#5-approval-workflow)
- [6. Payment (Escrow) Workflow](#6-payment-escrow-workflow)
- [7. Commission Workflow](#7-commission-workflow)
- [8. Refund Workflow](#8-refund-workflow)
- [9. Settlement Workflow](#9-settlement-workflow)
- [10. Support Workflow](#10-support-workflow)
- [11. Notification Workflow](#11-notification-workflow)

---

## 0. Campaign Lifecycle — End-to-End

```mermaid
flowchart TD
    A[Brand Registers] --> B[Brand Completes Profile]
    C[Creator Registers] --> D[Creator Submits KYC]
    D --> E{Admin Verifies Creator}
    E -- Approved --> F[Creator Listed and Searchable]
    E -- Rejected --> D
    B --> G[Brand Searches Creators]
    F --> G
    G --> H[Brand Views Profile]
    H --> I[Brand Sends Campaign Request]
    I --> J[Creator Notified]
    J --> K{Creator Response}
    K -- Accept --> L[Brand Confirms Booking]
    K -- Counter-Offer --> I
    K -- Reject --> M[Booking Closed]
    L --> N[Payment Held in Escrow]
    N --> O[Creator Completes Campaign]
    O --> P{Brand Approves Work?}
    P -- Yes --> Q[Commission Deducted]
    P -- Dispute --> R[Support Workflow]
    Q --> S[Creator Payout to Wallet]
    S --> T[Both Users Rate Each Other]
    T --> U[Campaign Closed]
    R --> P
```

This is the master flow; every workflow below is a detailed view of one segment of this lifecycle.

## 1. Lead Generation Workflow

Covers how a first-time visitor becomes a registered brand or creator.

```mermaid
flowchart LR
    A[Visitor lands on Zuste<br/>website or app store listing] --> B{Visitor Type?}
    B -- Wants to hire creators --> C[Views Brand onboarding screens]
    B -- Wants to monetize audience --> D[Views Creator onboarding screens]
    C --> E[Signs up as Brand]
    D --> F[Signs up as Creator]
    E --> G[Registration Workflow]
    F --> G
```

**Notes**
- Marketing channels (app store, referrals, ads) feed into the same onboarding entry point; the 3-screen onboarding (Brand Discovery, Verified Creators, Collaboration) is shown regardless of eventual user type, with a role choice at sign-up.

## 2. Registration Workflow

```mermaid
flowchart TD
    A[Start Registration] --> B{Sign-up Method}
    B -- Email --> C[Enter email and password]
    B -- Mobile OTP --> D[Enter mobile number]
    B -- Google --> E[Google OAuth]
    B -- Apple --> F[Apple Sign-In]
    C --> G[Verify email link]
    D --> H[Verify OTP code]
    E --> I[Firebase token issued]
    F --> I
    G --> J[Account Created]
    H --> J
    I --> J
    J --> K{Role}
    K -- Brand --> L[Brand Profile Setup:<br/>business details, GST, PAN]
    K -- Creator --> M[Creator Profile Setup:<br/>bio, categories, social links]
    M --> N[Creator Verification Workflow]
    L --> O[Ready to Search and Book]
    N --> P[Ready to be Discovered]
```

## 3. Creator Verification (KYC) Workflow

```mermaid
flowchart TD
    A[Creator submits KYC documents] --> B[Documents queued for review]
    B --> C[Admin opens verification queue]
    C --> D{Documents valid?}
    D -- Yes --> E[Mark Creator Verified]
    D -- No --> F[Reject with reason code]
    F --> G[Creator notified to resubmit]
    G --> A
    E --> H[Creator profile becomes publicly searchable]
    H --> I[Verified badge shown on profile]
```

## 4. Booking Workflow

```mermaid
sequenceDiagram
    participant B as Brand
    participant S as System
    participant C as Creator

    B->>S: Create campaign request with brief, budget, and timeline
    S->>C: Notify creator of new request
    C->>S: Respond - accept, reject, or counter-offer
    alt Accept
        S->>B: Notify accepted
        B->>S: Confirm booking
        S->>S: Trigger Payment Escrow Workflow
    else Counter-offer
        S->>B: Notify counter-offer
        B->>S: Accept, reject, or re-counter
    else Reject
        S->>B: Notify rejected
        S->>S: Close booking
    end
```

## 5. Approval Workflow

```mermaid
flowchart TD
    A[Creator marks deliverables as complete] --> B[Brand notified for review]
    B --> C{Brand Decision}
    C -- Approve --> D[Trigger Commission Workflow]
    C -- Request Changes --> E[Creator revises deliverables]
    E --> A
    C -- Dispute --> F[Support Workflow: Ticket opened]
    D --> G[Booking marked Completed]
    F --> H[Admin resolves: approve, refund, or split]
    H --> G
```

## 6. Payment (Escrow) Workflow

```mermaid
flowchart TD
    A[Brand confirms booking] --> B[Brand completes payment via gateway]
    B --> C{Payment Successful?}
    C -- No --> D[Payment failed, booking stays Pending]
    C -- Yes --> E[Funds moved to Escrow ledger]
    E --> F[Booking status: Confirmed]
    F --> G[Creator delivers work]
    G --> H[Approval Workflow]
    H --> I{Approved?}
    I -- Yes --> J[Escrow released]
    I -- Refund or Dispute --> K[Refund Workflow]
    J --> L[Commission Workflow]
```

## 7. Commission Workflow

```mermaid
flowchart LR
    A[Escrow released for a booking] --> B[System reads commission rate<br/>admin-configurable, 10-20%]
    B --> C[Calculate commission amount]
    C --> D[Deduct commission from escrowed amount]
    D --> E[Remainder credited to Creator Wallet]
    D --> F[Commission recorded as Platform Revenue]
```

## 8. Refund Workflow

```mermaid
flowchart TD
    A[Dispute or Cancellation raised] --> B[Support ticket opened, referencing booking]
    B --> C[Admin reviews brief, chat log, deliverables]
    C --> D{Admin Decision}
    D -- Full Refund to Brand --> E[Escrow returned to Brand payment source]
    D -- Full Payout to Creator --> F[Commission Workflow proceeds normally]
    D -- Partial Split --> G[Escrow divided per admin decision]
    E --> H[Booking marked Refunded]
    F --> I[Booking marked Completed]
    G --> J[Booking marked Partially Refunded]
```

## 9. Settlement Workflow

```mermaid
flowchart TD
    A[Creator wallet balance exceeds minimum threshold] --> B[Creator requests withdrawal]
    B --> C[System validates linked bank details]
    C --> D[Request enters Admin payout queue]
    D --> E{Admin Decision}
    E -- Approve --> F[Funds transferred to creator bank account]
    E -- Reject --> G[Reason provided, wallet balance restored]
    F --> H[Wallet transaction ledger updated: Paid]
    G --> I[Creator notified to correct bank details]
```

## 10. Support Workflow

```mermaid
flowchart TD
    A[User opens support ticket] --> B{Ticket Category}
    B -- Booking Dispute --> C[Linked to Approval and Refund Workflow]
    B -- Payment Issue --> D[Linked to Payment and Settlement Workflow]
    B -- Account or KYC Issue --> E[Linked to Verification Workflow]
    B -- General Inquiry --> F[Direct admin response]
    C --> G[Admin investigates and resolves]
    D --> G
    E --> G
    F --> G
    G --> H[Ticket marked Resolved]
    H --> I[User notified of resolution]
```

## 11. Notification Workflow

```mermaid
flowchart LR
    A[Event occurs<br/>e.g. booking confirmed,<br/>payment released, new message] --> B[Notification Service]
    B --> C{User Channel Preferences}
    C -- Push enabled --> D[Firebase Cloud Messaging]
    C -- Email enabled --> E[Transactional Email]
    C -- SMS enabled --> F[SMS Gateway]
    D --> G[Delivered to device]
    E --> G
    F --> G
    G --> H[Logged in Notification History]
```

---

*This document should be read alongside `Zuste_PRD.md`, `Zuste_SRS.md`, and `Zuste_TRD.md`. Diagrams use Mermaid syntax and render automatically in most Markdown viewers, including GitHub and the Claude interface.*
