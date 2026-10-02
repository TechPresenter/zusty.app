<?php

declare(strict_types=1);

namespace Zuste\Services;

use Zuste\Core\Config;
use Zuste\Core\Database;
use Zuste\Core\HttpException;
use Zuste\Helpers\Money;
use Zuste\Helpers\Str;

/**
 * Invoice generation.
 *
 * Numbers follow the Indian financial year (April–March) so they line up with
 * GST filings: ZI/2025-26/000123. The sequence is derived from a counted
 * SELECT inside the same transaction as the INSERT, and the unique index on
 * `invoice_number` is the final guarantee against a duplicate.
 */
final class InvoiceService
{
    /** Issue the brand-facing invoice for a paid booking. Idempotent. */
    public static function issueForBooking(int $bookingId): array
    {
        $existing = Database::selectOne(
            "SELECT * FROM `invoices` WHERE `booking_id` = :id AND `type` = 'brand_invoice' LIMIT 1",
            ['id' => $bookingId]
        );

        if ($existing !== null) {
            return $existing;
        }

        $booking = Database::selectOne(
            'SELECT b.*, u.`name` AS `brand_name`, u.`email` AS `brand_email`,
                    u.`company_name`, u.`gst_number`, u.`city`, u.`state`, u.`country`, u.`postal_code`,
                    c.`display_name` AS `creator_name`,
                    cp.`code` AS `coupon_code`
             FROM `bookings` b
             INNER JOIN `users` u ON u.`id` = b.`brand_user_id`
             INNER JOIN `creators` c ON c.`id` = b.`creator_id`
             LEFT JOIN `coupons` cp ON cp.`id` = b.`coupon_id`
             WHERE b.`id` = :id',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        $taxLabel = Config::settingString('commission', 'tax_label', 'GST');

        $lineItems = [
            [
                'description' => sprintf(
                    '%s — %d %s on %s by %s',
                    (string) $booking['title'],
                    (int) $booking['posts_count'],
                    (int) $booking['posts_count'] === 1 ? 'deliverable' : 'deliverables',
                    ucfirst((string) $booking['platform']),
                    (string) $booking['creator_name']
                ),
                'quantity'   => 1,
                'unit_price' => (float) $booking['amount'],
                'total'      => (float) $booking['amount'],
            ],
            [
                'description' => 'Platform service fee',
                'quantity'    => 1,
                'unit_price'  => (float) $booking['platform_fee'],
                'total'       => (float) $booking['platform_fee'],
            ],
        ];

        if ((float) $booking['tax_amount'] > 0) {
            $lineItems[] = [
                'description' => $taxLabel . ' @ ' . rtrim(rtrim(number_format((float) $booking['tax_rate'], 2), '0'), '.') . '% on service fee',
                'quantity'    => 1,
                'unit_price'  => (float) $booking['tax_amount'],
                'total'       => (float) $booking['tax_amount'],
            ];
        }

        // A discount has to appear as its own negative line, or the invoice
        // does not add up: subtotal + fee + tax would exceed the total the
        // customer was actually charged, which is exactly the kind of thing a
        // tax audit picks up on.
        $discount = (float) ($booking['discount_amount'] ?? 0);

        if ($discount > 0) {
            $lineItems[] = [
                'description' => $booking['coupon_code'] === null
                    ? 'Discount'
                    : 'Discount (' . (string) $booking['coupon_code'] . ')',
                'quantity'    => 1,
                'unit_price'  => -$discount,
                'total'       => -$discount,
            ];
        }

        $address = implode(', ', array_filter([
            $booking['city'], $booking['state'], $booking['country'], $booking['postal_code'],
        ]));

        return Database::transaction(static function () use ($booking, $bookingId, $lineItems, $address, $discount): array {
            $invoiceId = Database::insert('invoices', [
                'invoice_number'  => 'PENDING-' . $bookingId,
                'booking_id'      => $bookingId,
                'user_id'         => (int) $booking['brand_user_id'],
                'type'            => 'brand_invoice',
                'bill_to_name'    => (string) ($booking['company_name'] ?: $booking['brand_name']),
                'bill_to_email'   => $booking['brand_email'] ?? null,
                'bill_to_address' => $address !== '' ? mb_substr($address, 0, 500) : null,
                'bill_to_gst'     => $booking['gst_number'] ?? null,
                'line_items'      => json_encode($lineItems, JSON_UNESCAPED_UNICODE),
                'subtotal'        => (float) $booking['amount'],
                'fee_amount'      => (float) $booking['platform_fee'],
                'tax_amount'      => (float) $booking['tax_amount'],
                'tax_rate'        => (float) $booking['tax_rate'],
                'discount_amount' => $discount,
                'total'           => (float) $booking['total_amount'],
                'currency'        => (string) $booking['currency'],
                'status'          => 'paid',
                'issued_at'       => date('Y-m-d H:i:s'),
                'paid_at'         => date('Y-m-d H:i:s'),
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            Database::update('invoices', ['invoice_number' => self::nextNumber('ZI')], ['id' => $invoiceId]);

            $invoice = Database::selectOne('SELECT * FROM `invoices` WHERE `id` = :id', ['id' => $invoiceId]);

            if ($invoice === null) {
                throw HttpException::server('Failed to issue the invoice.');
            }

            return $invoice;
        });
    }

    /** Creator-facing payout statement for a completed booking. */
    public static function issuePayoutStatement(int $bookingId): array
    {
        $existing = Database::selectOne(
            "SELECT * FROM `invoices` WHERE `booking_id` = :id AND `type` = 'creator_payout' LIMIT 1",
            ['id' => $bookingId]
        );

        if ($existing !== null) {
            return $existing;
        }

        $booking = Database::selectOne(
            'SELECT b.*, u.`name` AS `creator_name`, u.`email` AS `creator_email`
             FROM `bookings` b
             INNER JOIN `users` u ON u.`id` = b.`creator_user_id`
             WHERE b.`id` = :id',
            ['id' => $bookingId]
        );

        if ($booking === null) {
            throw HttpException::notFound('Booking not found.');
        }

        $lineItems = [
            [
                'description' => 'Agreed fee for ' . (string) $booking['title'],
                'quantity'    => 1,
                'unit_price'  => (float) $booking['amount'],
                'total'       => (float) $booking['amount'],
            ],
            [
                'description' => 'Zusty commission',
                'quantity'    => 1,
                'unit_price'  => -1 * (float) $booking['commission_amount'],
                'total'       => -1 * (float) $booking['commission_amount'],
            ],
        ];

        return Database::transaction(static function () use ($booking, $bookingId, $lineItems): array {
            $invoiceId = Database::insert('invoices', [
                'invoice_number' => 'PENDING-P' . $bookingId,
                'booking_id'     => $bookingId,
                'user_id'        => (int) $booking['creator_user_id'],
                'type'           => 'creator_payout',
                'bill_to_name'   => (string) $booking['creator_name'],
                'bill_to_email'  => $booking['creator_email'] ?? null,
                'line_items'     => json_encode($lineItems, JSON_UNESCAPED_UNICODE),
                'subtotal'       => (float) $booking['amount'],
                'fee_amount'     => (float) $booking['commission_amount'],
                'tax_amount'     => 0.00,
                'total'          => (float) $booking['creator_earning'],
                'currency'       => (string) $booking['currency'],
                'status'         => 'paid',
                'issued_at'      => date('Y-m-d H:i:s'),
                'paid_at'        => date('Y-m-d H:i:s'),
                'created_at'     => date('Y-m-d H:i:s'),
            ]);

            Database::update('invoices', ['invoice_number' => self::nextNumber('ZP')], ['id' => $invoiceId]);

            $invoice = Database::selectOne('SELECT * FROM `invoices` WHERE `id` = :id', ['id' => $invoiceId]);

            if ($invoice === null) {
                throw HttpException::server('Failed to issue the payout statement.');
            }

            return $invoice;
        });
    }

    /** Printable HTML invoice, styled with the brand palette. */
    public static function renderHtml(int $invoiceId, int $viewerId): string
    {
        $invoice = Database::selectOne('SELECT * FROM `invoices` WHERE `id` = :id', ['id' => $invoiceId]);

        if ($invoice === null) {
            throw HttpException::notFound('Invoice not found.');
        }

        if ((int) $invoice['user_id'] !== $viewerId) {
            throw HttpException::forbidden('This invoice belongs to another account.');
        }

        $booking  = Database::selectOne('SELECT `code` FROM `bookings` WHERE `id` = :id', ['id' => (int) $invoice['booking_id']]);
        $company  = Config::settingString('general', 'company_name', 'Zuste Technologies Pvt. Ltd.');
        $address  = Config::settingString('general', 'company_address', '');
        $gstin    = Config::settingString('general', 'company_gstin', '');
        $support  = Config::settingString('general', 'support_email', 'support@zustee.com');
        $currency = (string) $invoice['currency'];

        /** @var list<array<string,mixed>> $lineItems */
        $lineItems = json_decode((string) $invoice['line_items'], true) ?: [];

        $rows = '';
        foreach ($lineItems as $item) {
            $rows .= '<tr>'
                . '<td style="padding:12px 0;border-bottom:1px solid #FADBDE">' . Str::e($item['description'] ?? '') . '</td>'
                . '<td style="padding:12px 0;border-bottom:1px solid #FADBDE;text-align:right;white-space:nowrap">'
                . Money::format((float) ($item['total'] ?? 0), $currency) . '</td>'
                . '</tr>';
        }

        $bookingCode = Str::e((string) ($booking['code'] ?? ''));
        $number      = Str::e((string) $invoice['invoice_number']);
        $issued      = date('d M Y', strtotime((string) $invoice['issued_at']));
        $billTo      = Str::e((string) $invoice['bill_to_name']);
        $billAddress = Str::e((string) ($invoice['bill_to_address'] ?? ''));
        $billGst     = $invoice['bill_to_gst'] !== null ? 'GSTIN: ' . Str::e((string) $invoice['bill_to_gst']) : '';
        $total       = Money::format((float) $invoice['total'], $currency);
        $gstinLine   = $gstin !== '' ? 'GSTIN: ' . Str::e($gstin) : '';
        $logoUri     = self::logoDataUri();
        $brand       = $logoUri !== ''
            ? '<img src="' . $logoUri . '" width="166" height="36" alt="Zusty.app" style="display:block">'
            : '<div class="wordmark">Zusty<span>.app</span></div>';

        return <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {$number}</title>
<style>
  @page { margin: 18mm; }
  body { font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Arial,sans-serif;
         color:#28171A; margin:0; padding:32px; background:#FFF8F7; }
  .sheet { max-width:760px; margin:0 auto; background:#fff; border-radius:24px; padding:40px;
           box-shadow:0 4px 24px rgba(40,23,26,.06); }
  .wordmark { font-size:28px; font-weight:800; letter-spacing:-.02em; }
  .wordmark span { color:#B80049; }
  .muted { color:#5B3F43; font-size:13px; line-height:20px; }
  .label { font-size:11px; text-transform:uppercase; letter-spacing:.12em; color:#8F6F73; font-weight:600; }
  table { width:100%; border-collapse:collapse; margin-top:24px; font-size:14px; }
  .total { font-size:22px; font-weight:800; color:#B80049; }
  @media print { body { background:#fff; padding:0; } .sheet { box-shadow:none; border-radius:0; } .noprint { display:none; } }
</style></head>
<body><div class="sheet">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:24px;flex-wrap:wrap">
    <div>
      {$brand}
      <div class="muted" style="margin-top:8px">
        {$company}<br>{$address}<br>{$gstinLine}
      </div>
    </div>
    <div style="text-align:right">
      <div class="label">Tax Invoice</div>
      <div style="font-size:18px;font-weight:700;margin-top:4px">{$number}</div>
      <div class="muted">Issued {$issued}</div>
      <div class="muted">Booking #{$bookingCode}</div>
    </div>
  </div>

  <div style="margin-top:32px">
    <div class="label">Billed to</div>
    <div style="font-size:16px;font-weight:700;margin-top:6px">{$billTo}</div>
    <div class="muted">{$billAddress}<br>{$billGst}</div>
  </div>

  <table>
    <thead><tr>
      <th style="text-align:left;padding-bottom:10px;border-bottom:2px solid #28171A" class="label">Description</th>
      <th style="text-align:right;padding-bottom:10px;border-bottom:2px solid #28171A" class="label">Amount</th>
    </tr></thead>
    <tbody>{$rows}</tbody>
  </table>

  <div style="display:flex;justify-content:flex-end;margin-top:24px">
    <div style="text-align:right">
      <div class="label">Total paid</div>
      <div class="total">{$total}</div>
    </div>
  </div>

  <div class="muted" style="margin-top:40px;border-top:1px solid #FADBDE;padding-top:16px">
    This is a computer-generated invoice and does not require a signature.<br>
    Questions? Write to {$support}.
  </div>

  <div class="noprint" style="margin-top:24px;text-align:right">
    <button onclick="window.print()" style="background:#B80049;color:#fff;border:0;border-radius:12px;
      padding:12px 24px;font-weight:600;font-size:14px;cursor:pointer">Print / Save as PDF</button>
  </div>
</div></body></html>
HTML;
    }

    /**
     * The logo as a data URI. An invoice gets saved and printed as a file,
     * where a path to the server would break.
     */
    private static function logoDataUri(): string
    {
        $path = dirname(__DIR__, 2) . '/public/admin/assets/img/zusty-logo-email.png';
        $png  = is_file($path) ? file_get_contents($path) : false;

        return $png === false ? '' : 'data:image/png;base64,' . base64_encode($png);
    }

    /**
     * Next number in the current Indian financial year.
     * Called inside a transaction; the unique index is the hard guarantee.
     */
    private static function nextNumber(string $prefix): string
    {
        $month = (int) date('n');
        $year  = (int) date('Y');

        // The Indian FY runs April to March.
        $startYear   = $month >= 4 ? $year : $year - 1;
        $fiscalLabel = $startYear . '-' . substr((string) ($startYear + 1), 2);

        $pattern = $prefix . '/' . $fiscalLabel . '/%';

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM `invoices` WHERE `invoice_number` LIKE :pattern',
            ['pattern' => $pattern]
        );

        return sprintf('%s/%s/%06d', $prefix, $fiscalLabel, $count + 1);
    }
}
