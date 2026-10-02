-- =====================================================================================
-- 024_demo_finance.sql  —  Zustee demo money layer
-- =====================================================================================
-- Seeds the whole financial spine of the marketplace, derived from the bookings that
-- agent 023 seeds (ids 401-480). Every amount is computed with INSERT ... SELECT off the
-- bookings table so the money always reconciles — nothing is guessed or hard-coded.
--
-- Tables and id ranges owned by this file
--   payments             501-580    id = 100 + bookings.id   (one attempt per booking
--                                   that reached a payable state)
--   invoices             601-660    id = 600 + rank(completed bookings by id), max 60
--   refunds              661-670    id = 660 + rank(refundable payments), max 10
--   wallets              id = users.id  (1001-1020 brands, 2001-2045 creators) — one per
--                                   user that has any money movement
--   wallet_transactions  1701-1950  the ledger; balance_before/balance_after chain per
--                                   user in (created_at, event-ordinal) order
--   withdrawal_requests  1951-1970  id = 1950 + rank(creators by settled earnings)
--   bank_accounts        1971-1990  id = 1970 + same rank  (account numbers masked)
--   platform_earnings    2401-2480  id = 2000 + bookings.id (unique per booking)
--   settlements          3-4        two batch rows (ids 1-2 already exist)
--
-- Money model — read straight off each booking, never recomputed, so this file stays
-- correct whatever numbers the bookings seed chooses:
--   total_amount     = amount + platform_fee + tax_amount        (what the brand pays)
--   creator_earning  = amount - commission_amount                 (15% platform take)
--   tax_amount       = 18% GST as levied by the bookings seed
--   invoice discount = amount + platform_fee + tax_amount - total_amount, so an invoice
--                      always foots: subtotal + fee + tax - discount = total
--   GST split        = CGST 9% + SGST 9% when the brand is in Maharashtra (place of
--                      supply == Zustee's registered state), otherwise IGST 18%.
--   Zero-value lines (platform fee, discount, tax) are simply left off the invoice.
--
-- Ledger integrity
--   * wallets.id == wallets.user_id, so wallet_transactions.wallet_id needs no lookup.
--   * Running balances are produced by a window function over the union of every ledger
--     event, partitioned per user — balance_after really is the chained balance.
--   * escrow_hold rows are status='pending' and therefore do NOT move the spendable
--     balance; they roll up into wallets.locked_balance instead.
--   * Withdrawals are sized at 40% of earnings that settled more than 4 days ago and are
--     timestamped after those earnings, so no wallet can ever go negative.
--   * wallets balances are back-filled from the ledger by the UPDATE at the end.
--
-- Re-runnable: every statement is INSERT IGNORE (or an idempotent UPDATE). Applying the
-- file twice changes nothing and raises no error.
-- =====================================================================================


-- -------------------------------------------------------------------------------------
-- 1. payments (501-580)
-- -------------------------------------------------------------------------------------
-- One payment attempt per booking that got at least as far as "accepted". Gateway mix is
-- razorpay 40% / cashfree 30% / stripe 20% / wallet 10%, keyed off the booking id so it
-- is stable across runs. Status is derived from the booking's own payment_status, with
-- un-funded bookings split between abandoned ('created') and hard-failed attempts.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO payments
    (id, reference, booking_id, user_id, purpose, gateway, method,
     gateway_order_id, gateway_payment_id, gateway_reference, gateway_signature,
     amount, fee_amount, amount_refunded, currency, status,
     failure_code, failure_reason, card_last4, card_network, upi_vpa, bank_name,
     idempotency_key, gateway_response, ip_address,
     authorized_at, paid_at, failed_at, created_at, updated_at)
SELECT
    100 + c.bid,
    CONCAT('ZPAY', LPAD(100 + c.bid, 10, '0')),
    c.bid,
    c.brand_user_id,
    'booking',
    c.gw,
    c.mth,
    c.gworder,
    CASE WHEN c.pst = 'created' THEN NULL ELSE c.gwpay END,
    CASE WHEN c.pst IN ('captured','refunded','partially_refunded')
         THEN CONCAT('SETL', UPPER(SUBSTRING(MD5(CONCAT('zst-setl-', c.bid)), 1, 14)))
         ELSE NULL END,
    CASE WHEN c.gw IN ('razorpay','cashfree') AND c.pst IN ('captured','refunded','partially_refunded')
         THEN SHA2(CONCAT('zustee|', c.gworder, '|', c.gwpay), 256)
         ELSE NULL END,
    c.total_amount,
    CASE WHEN c.gw = 'wallet' THEN 0.00
         WHEN c.pst IN ('captured','refunded','partially_refunded') THEN ROUND(c.total_amount * 0.0236, 2)
         ELSE 0.00 END,
    CASE c.pst WHEN 'refunded'            THEN c.total_amount
               WHEN 'partially_refunded'  THEN ROUND(c.total_amount * 0.40, 2)
               ELSE 0.00 END,
    'INR',
    c.pst,
    CASE WHEN c.pst <> 'failed' THEN NULL
         ELSE CASE c.bid % 4
                WHEN 0 THEN 'GATEWAY_ERROR'
                WHEN 1 THEN 'BAD_REQUEST_ERROR'
                WHEN 2 THEN 'INSUFFICIENT_FUNDS'
                ELSE        'AUTHENTICATION_FAILED' END END,
    CASE WHEN c.pst <> 'failed' THEN NULL
         ELSE CASE c.bid % 4
                WHEN 0 THEN 'The issuing bank declined the transaction. Ask the customer to retry with a different card.'
                WHEN 1 THEN 'The UPI collect request expired before the customer approved it in their PSP app.'
                WHEN 2 THEN 'Insufficient balance in the customer account.'
                ELSE        '3D Secure authentication was cancelled by the customer.' END END,
    CASE WHEN c.mth = 'card' THEN LPAD((c.bid * 37) % 10000, 4, '0') ELSE NULL END,
    CASE WHEN c.mth = 'card'
         THEN CASE c.bid % 3 WHEN 0 THEN 'Visa' WHEN 1 THEN 'Mastercard' ELSE 'RuPay' END
         ELSE NULL END,
    CASE WHEN c.mth = 'upi'
         THEN CONCAT(
                LEFT(LOWER(REGEXP_REPLACE(COALESCE(NULLIF(c.company_name, ''), c.uname), '[^A-Za-z0-9]', '')), 18),
                CASE c.bid % 3 WHEN 0 THEN '@okhdfcbank' WHEN 1 THEN '@ybl' ELSE '@okaxis' END)
         ELSE NULL END,
    CASE WHEN c.mth = 'netbanking'
         THEN CASE c.bid % 5
                WHEN 0 THEN 'HDFC Bank'
                WHEN 1 THEN 'ICICI Bank'
                WHEN 2 THEN 'State Bank of India'
                WHEN 3 THEN 'Axis Bank'
                ELSE        'Kotak Mahindra Bank' END
         ELSE NULL END,
    CONCAT('bkpay:', c.bid, ':', c.brand_user_id),
    CONCAT(
        '{"entity":"payment","id":', JSON_QUOTE(COALESCE(CASE WHEN c.pst = 'created' THEN NULL ELSE c.gwpay END, '')),
        ',"order_id":', JSON_QUOTE(c.gworder),
        ',"amount":', CAST(ROUND(c.total_amount * 100) AS UNSIGNED),
        ',"amount_refunded":', CAST(ROUND(CASE c.pst WHEN 'refunded' THEN c.total_amount
                                                     WHEN 'partially_refunded' THEN ROUND(c.total_amount * 0.40, 2)
                                                     ELSE 0 END * 100) AS UNSIGNED),
        ',"currency":"INR","status":', JSON_QUOTE(c.gwstatus),
        ',"method":', JSON_QUOTE(c.mth),
        ',"captured":', IF(c.pst IN ('captured','refunded','partially_refunded'), 'true', 'false'),
        ',"international":false',
        ',"fee":', CAST(ROUND(CASE WHEN c.gw = 'wallet' THEN 0 ELSE c.total_amount * 2.36 END) AS UNSIGNED),
        ',"tax":', CAST(ROUND(CASE WHEN c.gw = 'wallet' THEN 0 ELSE c.total_amount * 0.36 END) AS UNSIGNED),
        ',"acquirer_data":{"rrn":', JSON_QUOTE(LPAD((c.bid * 1000003) % 1000000000000, 12, '0')), '}',
        ',"notes":{"booking_id":', c.bid, ',"booking_code":', JSON_QUOTE(c.code),
        ',"brand_user_id":', c.brand_user_id, '}',
        ',"created_at":', UNIX_TIMESTAMP(c.ts0), '}'),
    CONCAT('49.36.', (c.bid % 250) + 1, '.', ((c.bid * 13) % 250) + 1),
    CASE WHEN c.pst IN ('captured','refunded','partially_refunded')
         THEN DATE_SUB(c.ts0, INTERVAL 14 SECOND) ELSE NULL END,
    CASE WHEN c.pst IN ('captured','refunded','partially_refunded') THEN c.ts0 ELSE NULL END,
    CASE WHEN c.pst = 'failed' THEN DATE_ADD(c.ts0, INTERVAL 2 HOUR) ELSE NULL END,
    DATE_SUB(c.ts0, INTERVAL 3 MINUTE),
    CASE WHEN c.pst = 'failed' THEN DATE_ADD(c.ts0, INTERVAL 2 HOUR)
         WHEN c.pst IN ('captured','refunded','partially_refunded') THEN c.ts0
         ELSE DATE_SUB(c.ts0, INTERVAL 3 MINUTE) END
FROM (
    SELECT
        b.*,
        CASE WHEN b.gw = 'wallet' THEN 'wallet'
             WHEN b.gw = 'stripe' THEN 'card'
             WHEN b.gw = 'razorpay'
                  THEN CASE b.bid % 3 WHEN 0 THEN 'upi' WHEN 1 THEN 'card' ELSE 'netbanking' END
             ELSE CASE b.bid % 2 WHEN 0 THEN 'upi' ELSE 'netbanking' END END AS mth,
        CASE b.gw
             WHEN 'razorpay' THEN CONCAT('order_', UPPER(SUBSTRING(MD5(CONCAT('rzp-o-', b.bid)), 1, 14)))
             WHEN 'cashfree' THEN CONCAT('cf_order_', LPAD(b.bid, 10, '0'))
             WHEN 'stripe'   THEN CONCAT('cs_live_', SUBSTRING(MD5(CONCAT('stp-o-', b.bid)), 1, 24))
             ELSE                 CONCAT('ZWLTORD', LPAD(b.bid, 8, '0')) END AS gworder,
        CASE b.gw
             WHEN 'razorpay' THEN CONCAT('pay_', UPPER(SUBSTRING(MD5(CONCAT('rzp-p-', b.bid)), 1, 14)))
             WHEN 'cashfree' THEN CONCAT('cf_pay_', LPAD(b.bid * 7, 12, '0'))
             WHEN 'stripe'   THEN CONCAT('pi_', SUBSTRING(MD5(CONCAT('stp-p-', b.bid)), 1, 24))
             ELSE                 CONCAT('ZWLTPAY', LPAD(b.bid, 8, '0')) END AS gwpay,
        CASE WHEN b.gw = 'stripe'
                  THEN CASE b.pst WHEN 'captured' THEN 'succeeded'
                                  WHEN 'failed'   THEN 'requires_payment_method'
                                  WHEN 'created'  THEN 'requires_confirmation'
                                  WHEN 'pending'  THEN 'processing'
                                  ELSE 'succeeded' END
             WHEN b.gw = 'cashfree'
                  THEN CASE b.pst WHEN 'captured' THEN 'PAID'
                                  WHEN 'failed'   THEN 'FAILED'
                                  WHEN 'created'  THEN 'ACTIVE'
                                  WHEN 'pending'  THEN 'PENDING'
                                  ELSE 'PAID' END
             ELSE b.pst END AS gwstatus
    FROM (
        SELECT
            bk.id            AS bid,
            bk.code          AS code,
            bk.brand_user_id AS brand_user_id,
            bk.total_amount  AS total_amount,
            u.name           AS uname,
            u.company_name   AS company_name,
            COALESCE(bk.paid_at, bk.accepted_at, bk.responded_at, bk.created_at) AS ts0,
            CASE bk.id % 10
                 WHEN 9 THEN 'wallet'
                 WHEN 8 THEN 'stripe'
                 WHEN 7 THEN 'stripe'
                 WHEN 6 THEN 'cashfree'
                 WHEN 5 THEN 'cashfree'
                 WHEN 4 THEN 'cashfree'
                 ELSE 'razorpay' END AS gw,
            CASE
                 WHEN bk.payment_status = 'refunded'           THEN 'refunded'
                 WHEN bk.payment_status = 'partially_refunded' THEN 'partially_refunded'
                 WHEN bk.payment_status = 'failed'             THEN 'failed'
                 WHEN bk.payment_status = 'processing'         THEN 'pending'
                 WHEN bk.payment_status = 'paid' AND bk.status = 'refunded' THEN 'refunded'
                 WHEN bk.payment_status = 'paid'               THEN 'captured'
                 WHEN bk.id % 3 = 0                            THEN 'failed'
                 ELSE 'created' END AS pst
        FROM bookings bk
        JOIN users u ON u.id = bk.brand_user_id
        WHERE bk.id BETWEEN 401 AND 480
          AND bk.deleted_at IS NULL
          AND bk.status NOT IN ('pending','countered','rejected','expired')
    ) b
) c;


-- -------------------------------------------------------------------------------------
-- 2. invoices (601-660)
-- -------------------------------------------------------------------------------------
-- GST tax invoices raised on the brand for every completed booking (first 60 by id).
-- Financial year in the invoice number is computed from the issue date (Apr-Mar).
-- line_items carries the service line, the platform fee line and the GST breakup:
-- CGST+SGST for Maharashtra brands (intra-state), IGST otherwise.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO invoices
    (id, invoice_number, booking_id, user_id, type,
     bill_to_name, bill_to_email, bill_to_address, bill_to_gst,
     line_items, subtotal, fee_amount, tax_amount, tax_rate, discount_amount, total,
     currency, status, issued_at, due_at, paid_at, pdf_path, created_at, updated_at)
SELECT
    600 + i.rk,
    CONCAT('ZI/',
           CASE WHEN MONTH(i.idt) >= 4
                THEN CONCAT(YEAR(i.idt), '-', RIGHT(YEAR(i.idt) + 1, 2))
                ELSE CONCAT(YEAR(i.idt) - 1, '-', RIGHT(YEAR(i.idt), 2)) END,
           '/', LPAD(600 + i.rk, 6, '0')),
    i.bid,
    i.brand_user_id,
    'brand_invoice',
    COALESCE(NULLIF(i.company_name, ''), i.uname),
    i.email,
    CONCAT_WS(', ', NULLIF(i.city, ''), NULLIF(i.state, ''), NULLIF(i.postal_code, ''), 'India'),
    i.gst_number,
    CONCAT('[',
        '{"type":"service","description":', JSON_QUOTE(CONCAT('Influencer collaboration — ', i.title)),
        ',"hsn_sac":"998365","qty":', i.posts_count,
        ',"rate":', ROUND(i.amount / GREATEST(i.posts_count, 1), 2),
        ',"amount":', i.amount, '}',
        CASE WHEN i.platform_fee > 0
             THEN CONCAT(',{"type":"fee","description":"Zustee platform fee","hsn_sac":"998599","qty":1',
                         ',"rate":', i.platform_fee, ',"amount":', i.platform_fee, '}')
             ELSE '' END,
        CASE WHEN i.disc > 0
             THEN CONCAT(',{"type":"discount","description":"Promotional discount","qty":1,"rate":',
                         i.disc, ',"amount":-', i.disc, '}')
             ELSE '' END,
        CASE WHEN i.tax_amount > 0 THEN
            CASE WHEN i.state = 'Maharashtra'
                 THEN CONCAT(
                        ',{"type":"tax","description":"CGST @ 9%","rate":9,"amount":',
                        ROUND(i.tax_amount / 2, 2), '}',
                        ',{"type":"tax","description":"SGST @ 9%","rate":9,"amount":',
                        ROUND(i.tax_amount - ROUND(i.tax_amount / 2, 2), 2), '}')
                 ELSE CONCAT(',{"type":"tax","description":"IGST @ 18%","rate":18,"amount":',
                             i.tax_amount, '}') END
             ELSE '' END,
    ']'),
    i.amount,
    i.platform_fee,
    i.tax_amount,
    18.00,
    i.disc,
    i.total_amount,
    'INR',
    CASE WHEN i.rk % 13 = 0 THEN 'issued' ELSE 'paid' END,
    i.idt,
    DATE_ADD(i.idt, INTERVAL 7 DAY),
    CASE WHEN i.rk % 13 = 0 THEN NULL ELSE COALESCE(i.paid_at, i.idt) END,
    CONCAT('/storage/invoices/ZI-', LPAD(600 + i.rk, 6, '0'), '.pdf'),
    i.idt,
    i.idt
FROM (
    -- `disc` is derived from the booking's own arithmetic rather than copied blindly, so
    -- subtotal + fee + tax - discount always equals the invoice total.
    SELECT j.*,
           COALESCE(j.completed_at, j.paid_at, j.b_created_at) AS idt,
           GREATEST(0, ROUND(j.amount + j.platform_fee + j.tax_amount - j.total_amount, 2)) AS disc
    FROM (
        SELECT
            b.id AS bid, b.brand_user_id, b.title, b.posts_count,
            b.amount, b.platform_fee, b.tax_amount, b.discount_amount, b.total_amount,
            b.completed_at, b.paid_at, b.created_at AS b_created_at,
            u.name AS uname, u.company_name, u.email, u.city, u.state, u.postal_code, u.gst_number,
            ROW_NUMBER() OVER (ORDER BY b.id) AS rk
        FROM bookings b
        JOIN users u ON u.id = b.brand_user_id
        WHERE b.id BETWEEN 401 AND 480
          AND b.deleted_at IS NULL
          AND b.status = 'completed'
    ) j
) i
WHERE i.rk <= 60;


-- -------------------------------------------------------------------------------------
-- 3. refunds (661-670)
-- -------------------------------------------------------------------------------------
-- Raised against cancelled / disputed / refunded bookings that actually carry a captured
-- payment. Destination alternates between the original source and the Zustee wallet, and
-- statuses cover completed / processing / failed.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO refunds
    (id, reference, payment_id, booking_id, user_id, amount, currency, destination,
     reason, notes, gateway_refund_id, status, failure_reason,
     initiated_by_type, initiated_by_id, gateway_response, processed_at, created_at, updated_at)
SELECT
    660 + r.rk,
    CONCAT('ZRFN', LPAD(660 + r.rk, 10, '0')),
    r.pid,
    r.bid,
    r.uid,
    r.amt,
    'INR',
    CASE WHEN r.rk % 2 = 0 THEN 'wallet' ELSE 'source' END,
    CASE r.bstatus WHEN 'cancelled' THEN 'booking_cancelled'
                   WHEN 'disputed'  THEN 'dispute'
                   WHEN 'refunded'  THEN 'booking_cancelled'
                   ELSE 'other' END,
    CASE r.rk % 5
         WHEN 0 THEN 'Creator could not deliver within the agreed window; brand approved a full reversal.'
         WHEN 1 THEN 'Brand cancelled the campaign before the shoot date as per the 48-hour cancellation policy.'
         WHEN 2 THEN 'Deliverable did not match the approved brief. Resolved in the brand''s favour by the disputes desk.'
         WHEN 3 THEN 'Partial reversal — one of the two reels was never published.'
         ELSE        'Duplicate charge raised by the gateway during a retry; reversed on reconciliation.' END,
    CONCAT('rfnd_', UPPER(SUBSTRING(MD5(CONCAT('zst-rf-', r.pid)), 1, 14))),
    CASE WHEN r.rk <= 6 THEN 'completed'
         WHEN r.rk <= 8 THEN 'processing'
         WHEN r.rk = 9  THEN 'failed'
         ELSE 'completed' END,
    CASE WHEN r.rk = 9
         THEN 'Beneficiary account details rejected by the acquiring bank. Re-initiated to the Zustee wallet.'
         ELSE NULL END,
    CASE WHEN r.rk % 3 = 0 THEN 'user' ELSE 'admin' END,
    CASE WHEN r.rk % 3 = 0 THEN r.uid ELSE 1 END,
    CONCAT('{"entity":"refund","id":', JSON_QUOTE(CONCAT('rfnd_', UPPER(SUBSTRING(MD5(CONCAT('zst-rf-', r.pid)), 1, 14)))),
           ',"payment_id":', JSON_QUOTE(COALESCE(r.gwpay, '')),
           ',"amount":', CAST(ROUND(r.amt * 100) AS UNSIGNED),
           ',"currency":"INR","speed_processed":"normal"',
           ',"status":', JSON_QUOTE(CASE WHEN r.rk <= 6 THEN 'processed'
                                         WHEN r.rk <= 8 THEN 'pending'
                                         WHEN r.rk = 9  THEN 'failed'
                                         ELSE 'processed' END),
           ',"notes":{"booking_code":', JSON_QUOTE(COALESCE(r.code, '')), '}}'),
    CASE WHEN r.rk BETWEEN 7 AND 8 THEN NULL
         ELSE DATE_ADD(COALESCE(r.cancelled_at, r.bupd), INTERVAL 2 DAY) END,
    COALESCE(r.cancelled_at, r.bupd),
    COALESCE(r.cancelled_at, r.bupd)
FROM (
    SELECT
        p.id AS pid, p.booking_id AS bid, p.user_id AS uid,
        p.gateway_payment_id AS gwpay,
        CASE WHEN p.amount_refunded > 0 THEN p.amount_refunded ELSE ROUND(p.amount * 0.50, 2) END AS amt,
        b.status AS bstatus, b.code AS code, b.cancelled_at, b.updated_at AS bupd,
        ROW_NUMBER() OVER (ORDER BY b.id) AS rk
    FROM payments p
    JOIN bookings b ON b.id = p.booking_id
    WHERE p.id BETWEEN 501 AND 580
      AND p.status IN ('captured','refunded','partially_refunded')
      AND (p.status IN ('refunded','partially_refunded')
           OR b.status IN ('cancelled','disputed','refunded'))
) r
WHERE r.rk <= 10;


-- Keep payments in step with the refunds that actually completed. GREATEST() makes the
-- statement idempotent — re-running never inflates amount_refunded.
UPDATE payments p
JOIN (
    SELECT payment_id, SUM(amount) AS refunded
    FROM refunds
    WHERE id BETWEEN 661 AND 670 AND status = 'completed'
    GROUP BY payment_id
) x ON x.payment_id = p.id
SET p.amount_refunded = GREATEST(p.amount_refunded, x.refunded),
    p.status = CASE WHEN GREATEST(p.amount_refunded, x.refunded) >= p.amount
                    THEN 'refunded' ELSE 'partially_refunded' END
WHERE p.id BETWEEN 501 AND 580
  AND p.status IN ('captured','refunded','partially_refunded');


-- -------------------------------------------------------------------------------------
-- 4. wallets  (id == user_id, so 1001-1020 brands and 2001-2045 creators)
-- -------------------------------------------------------------------------------------
-- One wallet for every user that appears on either side of a booking. Balances are all
-- zero here and are back-filled from the ledger by the UPDATE in section 8.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO wallets
    (id, user_id, currency, balance, locked_balance, lifetime_credit, lifetime_debit,
     is_frozen, last_activity_at, created_at, updated_at)
SELECT
    w.uid, w.uid, 'INR', 0.00, 0.00, 0.00, 0.00, 0,
    NULL,
    COALESCE(u.created_at, DATE_SUB(NOW(), INTERVAL 420 DAY)),
    COALESCE(u.created_at, DATE_SUB(NOW(), INTERVAL 420 DAY))
FROM (
    SELECT DISTINCT b.brand_user_id AS uid
    FROM bookings b
    WHERE b.id BETWEEN 401 AND 480 AND b.deleted_at IS NULL
    UNION
    SELECT DISTINCT b.creator_user_id AS uid
    FROM bookings b
    WHERE b.id BETWEEN 401 AND 480 AND b.deleted_at IS NULL
) w
JOIN users u ON u.id = w.uid
WHERE w.uid BETWEEN 1001 AND 2045;


-- -------------------------------------------------------------------------------------
-- 5. bank_accounts (1971-1990)
-- -------------------------------------------------------------------------------------
-- Payout destinations for the 20 creators with the highest settled earnings. Account
-- numbers are stored masked (only the last four digits are ever readable).
-- Rank = earnings from completed bookings settled more than 4 days ago, desc.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO bank_accounts
    (id, user_id, method, account_holder_name, account_number, account_last4,
     ifsc_code, bank_name, branch_name, upi_id, is_primary, is_verified, verified_at,
     created_at, updated_at)
SELECT
    1970 + e.rk,
    e.uid,
    CASE WHEN e.rk % 5 = 0 THEN 'upi' ELSE 'bank' END,
    e.uname,
    CASE WHEN e.rk % 5 = 0 THEN NULL
         ELSE CONCAT('XXXXXXXXXX', LPAD((e.uid * 17) % 10000, 4, '0')) END,
    LPAD((e.uid * 17) % 10000, 4, '0'),
    CASE WHEN e.rk % 5 = 0 THEN NULL
         ELSE CONCAT(CASE e.rk % 6
                          WHEN 0 THEN 'HDFC'
                          WHEN 1 THEN 'ICIC'
                          WHEN 2 THEN 'SBIN'
                          WHEN 3 THEN 'UTIB'
                          WHEN 4 THEN 'KKBK'
                          ELSE        'IDFB' END,
                     '0', LPAD(e.uid % 1000000, 6, '0')) END,
    CASE WHEN e.rk % 5 = 0 THEN NULL
         ELSE CASE e.rk % 6
                   WHEN 0 THEN 'HDFC Bank'
                   WHEN 1 THEN 'ICICI Bank'
                   WHEN 2 THEN 'State Bank of India'
                   WHEN 3 THEN 'Axis Bank'
                   WHEN 4 THEN 'Kotak Mahindra Bank'
                   ELSE        'IDFC FIRST Bank' END END,
    CASE WHEN e.rk % 5 = 0 THEN NULL
         ELSE CASE e.rk % 8
                   WHEN 0 THEN 'Andheri West, Mumbai'
                   WHEN 1 THEN 'Indiranagar, Bengaluru'
                   WHEN 2 THEN 'Connaught Place, New Delhi'
                   WHEN 3 THEN 'Banjara Hills, Hyderabad'
                   WHEN 4 THEN 'Koregaon Park, Pune'
                   WHEN 5 THEN 'Adyar, Chennai'
                   WHEN 6 THEN 'Salt Lake Sector V, Kolkata'
                   ELSE        'C G Road, Ahmedabad' END END,
    CASE WHEN e.rk % 5 = 0
         THEN CONCAT(LEFT(LOWER(REGEXP_REPLACE(e.uname, '[^A-Za-z0-9]', '')), 18),
                     CASE e.rk % 4 WHEN 0 THEN '@okicici' WHEN 1 THEN '@ybl'
                                   WHEN 2 THEN '@okhdfcbank' ELSE '@paytm' END)
         ELSE NULL END,
    1,
    CASE WHEN e.rk <= 17 THEN 1 ELSE 0 END,
    CASE WHEN e.rk <= 17 THEN DATE_SUB(NOW(), INTERVAL (60 + e.rk) DAY) ELSE NULL END,
    DATE_SUB(NOW(), INTERVAL (90 + e.rk) DAY),
    DATE_SUB(NOW(), INTERVAL (60 + e.rk) DAY)
FROM (
    SELECT
        g.uid, g.uname, g.earn,
        ROW_NUMBER() OVER (ORDER BY g.earn DESC, g.uid ASC) AS rk
    FROM (
        SELECT cb.creator_user_id AS uid, u.name AS uname,
               SUM(cb.creator_earning) AS earn
        FROM (
            SELECT b.*, ROW_NUMBER() OVER (ORDER BY b.id) AS crk
            FROM bookings b
            WHERE b.id BETWEEN 401 AND 480
              AND b.deleted_at IS NULL
              AND b.status = 'completed'
              AND b.payment_status IN ('paid','partially_refunded')
        ) cb
        JOIN users u ON u.id = cb.creator_user_id
        WHERE cb.crk <= 60
          AND cb.completed_at < DATE_SUB(NOW(), INTERVAL 4 DAY)
        GROUP BY cb.creator_user_id, u.name
        HAVING SUM(cb.creator_earning) >= 5000
    ) g
) e
WHERE e.rk <= 20;


-- -------------------------------------------------------------------------------------
-- 6. withdrawal_requests (1951-1970)
-- -------------------------------------------------------------------------------------
-- Same 20 creators, same rank, so withdrawal 1950+rk always pairs with bank account
-- 1970+rk. The amount is 40% of settled earnings rounded down to ₹100, and every request
-- is timestamped ~3 days ago — after the earnings it draws on, so the wallet ledger in
-- section 7 can never dip below zero.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO withdrawal_requests
    (id, reference, user_id, bank_account_id, amount, fee_amount, net_amount, currency,
     method, status, payout_reference, remarks, rejection_reason, processed_by,
     approved_at, processed_at, snapshot, created_at, updated_at)
SELECT
    1950 + e.rk,
    CONCAT('ZWDR', LPAD(1950 + e.rk, 10, '0')),
    e.uid,
    1970 + e.rk,
    e.amt,
    CASE WHEN e.rk % 5 = 0 THEN 0.00 ELSE 11.80 END,
    e.amt - CASE WHEN e.rk % 5 = 0 THEN 0.00 ELSE 11.80 END,
    'INR',
    CASE WHEN e.rk % 5 = 0 THEN 'upi' ELSE 'bank' END,
    CASE WHEN e.rk <= 10 THEN 'completed'
         WHEN e.rk <= 13 THEN 'processing'
         WHEN e.rk <= 16 THEN 'pending'
         WHEN e.rk = 17  THEN 'approved'
         WHEN e.rk = 18  THEN 'rejected'
         WHEN e.rk = 19  THEN 'failed'
         ELSE 'cancelled' END,
    CASE WHEN e.rk <= 13
         THEN CONCAT('UTR', LPAD((e.uid * 987654321) % 1000000000000, 12, '0'))
         ELSE NULL END,
    CASE WHEN e.rk <= 10 THEN 'Payout settled via IMPS. Credited within the same banking day.'
         WHEN e.rk <= 13 THEN 'Batch queued with the payout partner; expected to land in 24 hours.'
         WHEN e.rk <= 16 THEN 'Awaiting finance approval — falls in the weekly Tuesday payout run.'
         WHEN e.rk = 17  THEN 'Approved by finance. Scheduled for the next NEFT window.'
         WHEN e.rk = 18  THEN 'Rejected at review.'
         WHEN e.rk = 19  THEN 'Bank rejected the credit; amount returned to the Zustee wallet.'
         ELSE 'Withdrawn by the creator before processing.' END,
    CASE WHEN e.rk = 18
         THEN 'PAN on the payout account does not match the verified KYC name. Please add a bank account in your own name.'
         ELSE NULL END,
    CASE WHEN e.rk <= 13 OR e.rk IN (17, 18) THEN 1 ELSE NULL END,
    CASE WHEN e.rk <= 13 OR e.rk = 17
         THEN DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL e.rk HOUR)
         ELSE NULL END,
    CASE WHEN e.rk <= 10 OR e.rk = 19
         THEN DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL e.rk HOUR)
         ELSE NULL END,
    CONCAT('{"method":', JSON_QUOTE(CASE WHEN e.rk % 5 = 0 THEN 'upi' ELSE 'bank' END),
           ',"account_holder_name":', JSON_QUOTE(e.uname),
           CASE WHEN e.rk % 5 = 0
                THEN CONCAT(',"upi_id":', JSON_QUOTE(CONCAT(
                        LEFT(LOWER(REGEXP_REPLACE(e.uname, '[^A-Za-z0-9]', '')), 18),
                        CASE e.rk % 4 WHEN 0 THEN '@okicici' WHEN 1 THEN '@ybl'
                                      WHEN 2 THEN '@okhdfcbank' ELSE '@paytm' END)))
                ELSE CONCAT(',"account_last4":', JSON_QUOTE(LPAD((e.uid * 17) % 10000, 4, '0')),
                            ',"ifsc_code":', JSON_QUOTE(CONCAT(CASE e.rk % 6
                                    WHEN 0 THEN 'HDFC' WHEN 1 THEN 'ICIC' WHEN 2 THEN 'SBIN'
                                    WHEN 3 THEN 'UTIB' WHEN 4 THEN 'KKBK' ELSE 'IDFB' END,
                                '0', LPAD(e.uid % 1000000, 6, '0'))),
                            ',"bank_name":', JSON_QUOTE(CASE e.rk % 6
                                    WHEN 0 THEN 'HDFC Bank'
                                    WHEN 1 THEN 'ICICI Bank'
                                    WHEN 2 THEN 'State Bank of India'
                                    WHEN 3 THEN 'Axis Bank'
                                    WHEN 4 THEN 'Kotak Mahindra Bank'
                                    ELSE        'IDFC FIRST Bank' END)) END,
           ',"bank_account_id":', 1970 + e.rk,
           ',"settled_earnings":', e.earn, '}'),
    DATE_ADD(DATE_SUB(NOW(), INTERVAL 3 DAY), INTERVAL e.rk HOUR),
    DATE_ADD(DATE_SUB(NOW(), INTERVAL 2 DAY), INTERVAL e.rk HOUR)
FROM (
    SELECT
        f.uid, f.uname, f.earn, f.rk,
        GREATEST(1000.00, FLOOR(f.earn * 0.40 / 100) * 100) AS amt
    FROM (
        SELECT
            g.uid, g.uname, g.earn,
            ROW_NUMBER() OVER (ORDER BY g.earn DESC, g.uid ASC) AS rk
        FROM (
            SELECT cb.creator_user_id AS uid, u.name AS uname,
                   SUM(cb.creator_earning) AS earn
            FROM (
                SELECT b.*, ROW_NUMBER() OVER (ORDER BY b.id) AS crk
                FROM bookings b
                WHERE b.id BETWEEN 401 AND 480
                  AND b.deleted_at IS NULL
                  AND b.status = 'completed'
                  AND b.payment_status IN ('paid','partially_refunded')
            ) cb
            JOIN users u ON u.id = cb.creator_user_id
            WHERE cb.crk <= 60
              AND cb.completed_at < DATE_SUB(NOW(), INTERVAL 4 DAY)
            GROUP BY cb.creator_user_id, u.name
            HAVING SUM(cb.creator_earning) >= 5000
        ) g
    ) f
) e
WHERE e.rk <= 20;


-- -------------------------------------------------------------------------------------
-- 7. wallet_transactions (1701-1950) — the double-entry ledger
-- -------------------------------------------------------------------------------------
-- Every money event is unioned into one set, then a window function computes the running
-- balance PER USER in (created_at, event-ordinal) order. balance_after is that running
-- balance and balance_before is derived back from it, so the chain is arithmetically
-- exact. Pending rows (escrow holds) contribute 0 to the chain by design.
--
-- Event ordinals: 0 deposit, 1 booking payment, 2 escrow hold, 3 earning/escrow release,
--                 4 commission, 5 refund, 6 withdrawal, 7 referral.
-- The set is capped at 250 rows to stay inside the assigned id range.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO wallet_transactions
    (id, reference, wallet_id, user_id, direction, category, amount,
     balance_before, balance_after, currency, status,
     reference_type, reference_id, counterparty_id, description, idempotency_key,
     metadata, performed_by_type, performed_by_id, created_at)
SELECT
    1700 + z.rn,
    CONCAT('ZTXN', LPAD(1700 + z.rn, 10, '0')),
    z.uid,
    z.uid,
    z.dirn,
    z.cat,
    z.amt,
    z.bal - CASE WHEN z.st = 'completed' THEN IF(z.dirn = 'credit', z.amt, -z.amt) ELSE 0 END,
    z.bal,
    'INR',
    z.st,
    z.rt,
    z.rid,
    z.cp,
    z.descr,
    z.idem,
    CONCAT('{"category":', JSON_QUOTE(z.cat),
           ',"direction":', JSON_QUOTE(z.dirn),
           CASE WHEN z.rid IS NOT NULL THEN CONCAT(',"reference_id":', z.rid) ELSE '' END,
           ',"source":"zustee-seed"}'),
    z.pbt,
    z.pbi,
    z.ts
FROM (
    SELECT
        ev.*,
        ROW_NUMBER() OVER (ORDER BY ev.uid, ev.ts, ev.ord, COALESCE(ev.rid, 0)) AS rn,
        SUM(CASE WHEN ev.st = 'completed' THEN IF(ev.dirn = 'credit', ev.amt, -ev.amt) ELSE 0 END)
            OVER (PARTITION BY ev.uid
                  ORDER BY ev.ts, ev.ord, COALESCE(ev.rid, 0)
                  ROWS UNBOUNDED PRECEDING) AS bal
    FROM (

        /* 7a. Brand wallet top-ups — dated three days before the brand's first booking
               payment and sized to cover everything they later spend from the wallet. */
        SELECT
            b.brand_user_id                                   AS uid,
            'credit'                                          AS dirn,
            'deposit'                                         AS cat,
            CAST(GREATEST(200000,
                 CEIL((SUM(CASE WHEN b.id % 10 = 9
                                 AND b.payment_status IN ('paid','partially_refunded')
                                THEN b.total_amount ELSE 0 END) + 150000) / 50000) * 50000
                 ) AS DECIMAL(14,2))                          AS amt,
            'completed'                                       AS st,
            'manual'                                          AS rt,
            CAST(NULL AS UNSIGNED)                            AS rid,
            CAST(NULL AS UNSIGNED)                            AS cp,
            'Wallet top-up via NEFT — quarterly influencer budget' AS descr,
            CONCAT('wtx:deposit:', b.brand_user_id)           AS idem,
            0                                                 AS ord,
            DATE_SUB(MIN(COALESCE(b.paid_at, b.accepted_at, b.created_at)), INTERVAL 3 DAY) AS ts,
            'user'                                            AS pbt,
            b.brand_user_id                                   AS pbi
        FROM bookings b
        WHERE b.id BETWEEN 401 AND 480 AND b.deleted_at IS NULL
        GROUP BY b.brand_user_id

        UNION ALL

        /* 7b. Brand pays for a booking straight out of the wallet balance. */
        SELECT
            b.brand_user_id, 'debit', 'booking_payment', b.total_amount, 'completed',
            'booking', b.id, b.creator_user_id,
            CONCAT('Payment for booking #', b.code),
            CONCAT('wtx:bkpay:', b.id), 1,
            COALESCE(b.paid_at, b.accepted_at, b.created_at),
            'user', b.brand_user_id
        FROM bookings b
        WHERE b.id BETWEEN 401 AND 480
          AND b.deleted_at IS NULL
          AND b.id % 10 = 9
          AND b.payment_status IN ('paid','partially_refunded')

        UNION ALL

        /* 7c. Funds held in escrow for work that is paid for but not yet signed off.
               Status 'pending' => no movement in the spendable balance, rolls into
               wallets.locked_balance instead. */
        SELECT
            h.creator_user_id, 'credit', 'escrow_hold', h.amount, 'pending',
            'booking', h.id, h.brand_user_id,
            CONCAT('Funds held in escrow for booking #', h.code),
            CONCAT('wtx:hold:', h.id), 2,
            COALESCE(h.paid_at, h.accepted_at, h.created_at),
            'system', NULL
        FROM (
            SELECT b.*, ROW_NUMBER() OVER (ORDER BY b.id) AS hrk
            FROM bookings b
            WHERE b.id BETWEEN 401 AND 480
              AND b.deleted_at IS NULL
              AND b.status IN ('confirmed','in_progress','delivered')
              AND b.payment_status = 'paid'
        ) h
        WHERE h.hrk <= 25

        UNION ALL

        /* 7d. Booking signed off — escrow released into the creator's spendable balance
               (or credited directly when the brand paid from their Zustee wallet). */
        SELECT
            cb.creator_user_id, 'credit',
            CASE WHEN cb.id % 10 = 9 THEN 'booking_earning' ELSE 'escrow_release' END,
            cb.amount, 'completed', 'booking', cb.id, cb.brand_user_id,
            CONCAT(CASE WHEN cb.id % 10 = 9 THEN 'Earning credited for booking #'
                        ELSE 'Escrow released for booking #' END, cb.code),
            CONCAT('wtx:earn:', cb.id), 3,
            COALESCE(cb.completed_at, cb.paid_at, cb.created_at),
            'system', NULL
        FROM (
            SELECT b.*, ROW_NUMBER() OVER (ORDER BY b.id) AS crk
            FROM bookings b
            WHERE b.id BETWEEN 401 AND 480
              AND b.deleted_at IS NULL
              AND b.status = 'completed'
              AND b.payment_status IN ('paid','partially_refunded')
        ) cb
        WHERE cb.crk <= 60

        UNION ALL

        /* 7e. Platform commission deducted from the released amount. */
        SELECT
            cb.creator_user_id, 'debit', 'commission', cb.commission_amount, 'completed',
            'booking', cb.id, cb.brand_user_id,
            CONCAT('Zustee platform commission — booking #', cb.code),
            CONCAT('wtx:comm:', cb.id), 4,
            DATE_ADD(COALESCE(cb.completed_at, cb.paid_at, cb.created_at), INTERVAL 2 MINUTE),
            'system', NULL
        FROM (
            SELECT b.*, ROW_NUMBER() OVER (ORDER BY b.id) AS crk
            FROM bookings b
            WHERE b.id BETWEEN 401 AND 480
              AND b.deleted_at IS NULL
              AND b.status = 'completed'
              AND b.payment_status IN ('paid','partially_refunded')
        ) cb
        WHERE cb.crk <= 60 AND cb.commission_amount > 0

        UNION ALL

        /* 7f. Refunds that were settled to the Zustee wallet instead of the source. */
        SELECT
            r.user_id, 'credit', 'refund', r.amount, 'completed',
            'refund', r.id, NULL,
            CONCAT('Refund credited to wallet — booking #', COALESCE(b.code, 'ZB')),
            CONCAT('wtx:refund:', r.id), 5,
            COALESCE(r.processed_at, r.created_at),
            'system', NULL
        FROM refunds r
        LEFT JOIN bookings b ON b.id = r.booking_id
        WHERE r.id BETWEEN 661 AND 670
          AND r.destination = 'wallet'
          AND r.status = 'completed'

        UNION ALL

        /* 7g. Creator payouts that actually left the platform. */
        SELECT
            wr.user_id, 'debit', 'withdrawal', wr.amount, 'completed',
            'withdrawal', wr.id, NULL,
            CONCAT('Withdrawal to bank account — ', wr.reference),
            CONCAT('wtx:wdr:', wr.id), 6,
            COALESCE(wr.processed_at, wr.created_at),
            'admin', 1
        FROM withdrawal_requests wr
        WHERE wr.id BETWEEN 1951 AND 1970
          AND wr.status = 'completed'

        UNION ALL

        /* 7h. Referral rewards paid out to creators who brought a friend on board. */
        SELECT
            w.user_id, 'credit', 'referral', 750.00, 'completed',
            'referral', NULL, NULL,
            'Referral reward — your invitee completed their first paid campaign',
            CONCAT('wtx:ref:', w.user_id), 7,
            DATE_SUB(NOW(), INTERVAL 90 DAY),
            'system', NULL
        FROM wallets w
        WHERE w.id BETWEEN 2001 AND 2045
          AND w.user_id % 7 = 0

    ) ev
) z
WHERE z.rn <= 250;


-- -------------------------------------------------------------------------------------
-- 8. Roll the ledger up into the wallet balances
-- -------------------------------------------------------------------------------------
-- balance        = completed credits - completed debits - money locked in open payouts
-- locked_balance = pending escrow holds + payouts that are requested but not yet paid
-- Fully idempotent: the values are recomputed from scratch every run.
-- -------------------------------------------------------------------------------------
UPDATE wallets w
JOIN (
    SELECT
        user_id,
        SUM(CASE WHEN status = 'completed' AND direction = 'credit' THEN amount ELSE 0 END) AS cr,
        SUM(CASE WHEN status = 'completed' AND direction = 'debit'  THEN amount ELSE 0 END) AS dr,
        SUM(CASE WHEN status = 'pending'   AND direction = 'credit' THEN amount ELSE 0 END) AS held,
        MAX(created_at) AS last_at
    FROM wallet_transactions
    WHERE id BETWEEN 1701 AND 1950
    GROUP BY user_id
) t ON t.user_id = w.user_id
LEFT JOIN (
    SELECT user_id, SUM(amount) AS pend
    FROM withdrawal_requests
    WHERE id BETWEEN 1951 AND 1970
      AND status IN ('pending','approved','processing')
    GROUP BY user_id
) pw ON pw.user_id = w.user_id
SET w.balance          = t.cr - t.dr - COALESCE(pw.pend, 0),
    w.lifetime_credit  = t.cr,
    w.lifetime_debit   = t.dr,
    w.locked_balance   = t.held + COALESCE(pw.pend, 0),
    w.last_activity_at = t.last_at
WHERE w.id BETWEEN 1001 AND 2045;


-- -------------------------------------------------------------------------------------
-- 9. platform_earnings (2401-2480)
-- -------------------------------------------------------------------------------------
-- What Zustee actually keeps per booking: the brand-side platform fee, the creator-side
-- commission and the GST collected. Recognised once the booking is completed, reversed
-- if the booking was cancelled / refunded / disputed, accrued while still in flight.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO platform_earnings
    (id, booking_id, payment_id, platform_fee, commission, tax_collected, total,
     currency, status, earned_on, created_at, updated_at)
SELECT
    2000 + b.id,
    b.id,
    p.id,
    b.platform_fee,
    b.commission_amount,
    b.tax_amount,
    b.platform_fee + b.commission_amount + b.tax_amount,
    'INR',
    CASE WHEN b.status = 'completed' THEN 'recognised'
         WHEN b.status IN ('refunded','cancelled','disputed') THEN 'reversed'
         ELSE 'accrued' END,
    DATE(COALESCE(b.completed_at, b.paid_at, b.created_at)),
    COALESCE(b.completed_at, b.paid_at, b.created_at),
    COALESCE(b.completed_at, b.paid_at, b.created_at)
FROM bookings b
LEFT JOIN payments p ON p.id = 100 + b.id
WHERE b.id BETWEEN 401 AND 480
  AND b.deleted_at IS NULL
  AND b.payment_status IN ('paid','partially_refunded','refunded');


-- -------------------------------------------------------------------------------------
-- 10. settlements (3-4)
-- -------------------------------------------------------------------------------------
-- Two reconciliation batches that between them cover the whole trading history:
-- id 3 is everything settled more than 45 days ago (reconciled), id 4 is the last 45 days
-- up to yesterday (closed). Each summarises gateway volume, platform revenue, refunds and
-- creator payouts for its window. Ids 1-2 already exist in the database.
-- -------------------------------------------------------------------------------------
INSERT IGNORE INTO settlements
    (id, settlement_date, gross_volume, platform_fees, commissions, taxes,
     refunds_total, payouts_total, net_revenue, bookings_count, payments_count,
     currency, status, closed_at, created_at, updated_at)
SELECT
    3,
    DATE(DATE_SUB(CURDATE(), INTERVAL 45 DAY)),
    s.gross, s.fees, s.comm, s.tax, s.refs, s.pays,
    s.fees + s.comm - s.refs,
    s.bk, s.pc, 'INR', 'reconciled',
    DATE_SUB(NOW(), INTERVAL 44 DAY),
    DATE_SUB(NOW(), INTERVAL 45 DAY),
    DATE_SUB(NOW(), INTERVAL 44 DAY)
FROM (
    SELECT
        COALESCE((SELECT SUM(p.amount) FROM payments p
                  WHERE p.id BETWEEN 501 AND 580
                    AND p.status IN ('captured','refunded','partially_refunded')
                    AND p.paid_at <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS gross,
        COALESCE((SELECT SUM(pe.platform_fee) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS fees,
        COALESCE((SELECT SUM(pe.commission) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS comm,
        COALESCE((SELECT SUM(pe.tax_collected) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS tax,
        COALESCE((SELECT SUM(r.amount) FROM refunds r
                  WHERE r.id BETWEEN 661 AND 670
                    AND r.status = 'completed'
                    AND r.processed_at <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS refs,
        COALESCE((SELECT SUM(wr.net_amount) FROM withdrawal_requests wr
                  WHERE wr.id BETWEEN 1951 AND 1970
                    AND wr.status = 'completed'
                    AND wr.processed_at <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS pays,
        COALESCE((SELECT COUNT(*) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS bk,
        COALESCE((SELECT COUNT(*) FROM payments p
                  WHERE p.id BETWEEN 501 AND 580
                    AND p.status IN ('captured','refunded','partially_refunded')
                    AND p.paid_at <  DATE_SUB(CURDATE(), INTERVAL 45 DAY)), 0) AS pc
) s;

INSERT IGNORE INTO settlements
    (id, settlement_date, gross_volume, platform_fees, commissions, taxes,
     refunds_total, payouts_total, net_revenue, bookings_count, payments_count,
     currency, status, closed_at, created_at, updated_at)
SELECT
    4,
    DATE(DATE_SUB(CURDATE(), INTERVAL 1 DAY)),
    s.gross, s.fees, s.comm, s.tax, s.refs, s.pays,
    s.fees + s.comm - s.refs,
    s.bk, s.pc, 'INR', 'closed',
    DATE_SUB(NOW(), INTERVAL 2 HOUR),
    DATE_SUB(NOW(), INTERVAL 1 DAY),
    DATE_SUB(NOW(), INTERVAL 2 HOUR)
FROM (
    SELECT
        COALESCE((SELECT SUM(p.amount) FROM payments p
                  WHERE p.id BETWEEN 501 AND 580
                    AND p.status IN ('captured','refunded','partially_refunded')
                    AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND p.paid_at <  CURDATE()), 0) AS gross,
        COALESCE((SELECT SUM(pe.platform_fee) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND pe.earned_on <  CURDATE()), 0) AS fees,
        COALESCE((SELECT SUM(pe.commission) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND pe.earned_on <  CURDATE()), 0) AS comm,
        COALESCE((SELECT SUM(pe.tax_collected) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND pe.earned_on <  CURDATE()), 0) AS tax,
        COALESCE((SELECT SUM(r.amount) FROM refunds r
                  WHERE r.id BETWEEN 661 AND 670
                    AND r.status = 'completed'
                    AND r.processed_at >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND r.processed_at <  CURDATE()), 0) AS refs,
        COALESCE((SELECT SUM(wr.net_amount) FROM withdrawal_requests wr
                  WHERE wr.id BETWEEN 1951 AND 1970
                    AND wr.status = 'completed'
                    AND wr.processed_at >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND wr.processed_at <  CURDATE()), 0) AS pays,
        COALESCE((SELECT COUNT(*) FROM platform_earnings pe
                  WHERE pe.id BETWEEN 2401 AND 2480
                    AND pe.earned_on >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND pe.earned_on <  CURDATE()), 0) AS bk,
        COALESCE((SELECT COUNT(*) FROM payments p
                  WHERE p.id BETWEEN 501 AND 580
                    AND p.status IN ('captured','refunded','partially_refunded')
                    AND p.paid_at >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
                    AND p.paid_at <  CURDATE()), 0) AS pc
) s;

-- =====================================================================================
-- end of 024_demo_finance.sql
-- =====================================================================================
