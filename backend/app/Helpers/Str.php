<?php

declare(strict_types=1);

namespace Zuste\Helpers;

/**
 * String utilities shared by the API, the admin panel and the CLI scripts.
 */
final class Str
{
    /** URL-safe slug. Falls back to a random suffix when input is non-Latin. */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = self::transliterate($value);
        $value = preg_replace('/[^\p{L}\p{N}]+/u', $separator, $value) ?? '';
        $value = trim(strtolower($value), $separator);
        $value = preg_replace('/' . preg_quote($separator, '/') . '{2,}/', $separator, $value) ?? '';

        return $value === '' ? 'item-' . bin2hex(random_bytes(3)) : $value;
    }

    /** Ensure a slug is unique within a table by appending -2, -3, … */
    public static function uniqueSlug(string $base, string $table, string $column = 'slug', ?int $ignoreId = null): string
    {
        $slug    = self::slug($base);
        $attempt = $slug;
        $counter = 1;

        while (true) {
            $sql      = 'SELECT 1 FROM ' . \Zuste\Core\Database::quoteIdentifier($table)
                      . ' WHERE ' . \Zuste\Core\Database::quoteIdentifier($column) . ' = :slug';
            $bindings = ['slug' => $attempt];

            if ($ignoreId !== null) {
                $sql .= ' AND `id` <> :id';
                $bindings['id'] = $ignoreId;
            }

            if (\Zuste\Core\Database::scalar($sql . ' LIMIT 1', $bindings) === null) {
                return $attempt;
            }

            $counter++;
            $attempt = $slug . '-' . $counter;
        }
    }

    /** Best-effort ASCII folding without the intl extension. */
    public static function transliterate(string $value): string
    {
        $map = [
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae','ç'=>'c',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ñ'=>'n','ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o','ù'=>'u',
            'ú'=>'u','û'=>'u','ü'=>'u','ý'=>'y','ÿ'=>'y','ß'=>'ss','œ'=>'oe',
            '₹'=>'inr','&'=>'and','@'=>'at','+'=>'plus',
        ];

        return strtr(mb_strtolower($value), $map);
    }

    /** Truncate on a word boundary. */
    public static function limit(string $value, int $limit = 100, string $end = '…'): string
    {
        if (mb_strlen($value) <= $limit) {
            return $value;
        }

        $truncated = mb_substr($value, 0, $limit);
        $lastSpace = mb_strrpos($truncated, ' ');

        if ($lastSpace !== false && $lastSpace > $limit * 0.6) {
            $truncated = mb_substr($truncated, 0, $lastSpace);
        }

        return rtrim($truncated) . $end;
    }

    /** Strip tags and collapse whitespace — for meta descriptions and excerpts. */
    public static function excerpt(string $html, int $limit = 160): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));

        return self::limit($text, $limit);
    }

    public static function initials(string $name, int $count = 2): string
    {
        $parts    = preg_split('/\s+/u', trim($name)) ?: [];
        $initials = '';

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if (mb_strlen($initials) >= $count) {
                break;
            }
        }

        return $initials === '' ? '?' : $initials;
    }

    public static function title(string $value): string
    {
        return mb_convert_case(str_replace(['_', '-'], ' ', $value), MB_CASE_TITLE, 'UTF-8');
    }

    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $value)));
    }

    public static function snake(string $value): string
    {
        $value = preg_replace('/\s+/u', '', ucwords($value)) ?? $value;

        return mb_strtolower((string) preg_replace('/(.)(?=[A-Z])/u', '$1_', $value));
    }

    /**
     * Sequential business code, e.g. ZB100001. Zero-collision because the id
     * comes from an AUTO_INCREMENT column.
     */
    public static function referenceCode(string $prefix, int $id, int $padding = 6): string
    {
        return $prefix . str_pad((string) $id, $padding, '0', STR_PAD_LEFT);
    }

    /** Random referral code from an unambiguous alphabet (no O/0, I/1). */
    public static function referralCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code     = '';

        for ($i = 0; $i < $length; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /** Normalise a phone number to digits with an optional leading +. */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($digits, '+')) {
            return '+' . preg_replace('/\D/', '', substr($digits, 1));
        }

        return preg_replace('/\D/', '', $digits) ?? '';
    }

    /** Split an E.164 number into country code and national number. */
    public static function splitPhone(string $phone, string $defaultCountryCode = '+91'): array
    {
        $normalized = self::normalizePhone($phone);

        if (!str_starts_with($normalized, '+')) {
            return [$defaultCountryCode, $normalized];
        }

        // Longest-match against the codes we actually support.
        foreach (['+971', '+880', '+977', '+91', '+92', '+94', '+44', '+61', '+65', '+1'] as $code) {
            if (str_starts_with($normalized, $code)) {
                return [$code, substr($normalized, strlen($code))];
            }
        }

        return [$defaultCountryCode, ltrim($normalized, '+')];
    }

    public static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        if ($domain === '') {
            return str_repeat('*', mb_strlen($email));
        }

        $visible = mb_substr($local, 0, min(2, max(1, mb_strlen($local) - 1)));

        return $visible . str_repeat('*', max(1, mb_strlen($local) - mb_strlen($visible))) . '@' . $domain;
    }

    public static function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $length = strlen($digits);

        return $length <= 4 ? str_repeat('*', $length) : str_repeat('*', $length - 4) . substr($digits, -4);
    }

    /** Escape for HTML output. Every admin view uses this. */
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Allow-list HTML sanitiser for CMS content — keeps formatting tags,
     * drops scripts, event handlers and javascript: URLs.
     */
    public static function sanitizeHtml(string $html): string
    {
        $allowedTags = '<p><br><strong><b><em><i><u><s><h1><h2><h3><h4><h5><h6>'
                     . '<ul><ol><li><blockquote><a><img><table><thead><tbody><tr><th><td>'
                     . '<hr><code><pre><span><div><figure><figcaption>';

        $clean = strip_tags($html, $allowedTags);

        // Remove inline event handlers (onclick=, onerror=, …).
        $clean = preg_replace('/\son[a-z]+\s*=\s*(["\']).*?\1/is', '', $clean) ?? $clean;
        $clean = preg_replace('/\son[a-z]+\s*=\s*[^\s>]+/is', '', $clean) ?? $clean;

        // Neutralise dangerous URL schemes.
        $clean = preg_replace('/(href|src)\s*=\s*(["\'])\s*(javascript|vbscript|data)\s*:.*?\2/is', '$1="#"', $clean) ?? $clean;

        // Drop style attributes entirely: they enable expression()/url() tricks.
        $clean = preg_replace('/\sstyle\s*=\s*(["\']).*?\1/is', '', $clean) ?? $clean;

        return $clean;
    }

    /** Convert a Markdown-lite/plain string into safe paragraphs. */
    public static function nl2p(string $text): string
    {
        $blocks = preg_split('/\n{2,}/', trim($text)) ?: [];
        $html   = '';

        foreach ($blocks as $block) {
            $html .= '<p>' . nl2br(self::e($block)) . '</p>';
        }

        return $html;
    }

    public static function contains(string $haystack, string $needle): bool
    {
        return $needle !== '' && str_contains(mb_strtolower($haystack), mb_strtolower($needle));
    }

    /** Escape %, _ and \ so a user search term cannot alter a LIKE pattern. */
    public static function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /** Wrap a term for a LIKE ... ESCAPE '\\' comparison. */
    public static function likeTerm(string $value): string
    {
        return '%' . self::escapeLike(trim($value)) . '%';
    }

    /** Compact large numbers for the UI: 56000 → "56K". */
    public static function compactNumber(int|float $number): string
    {
        $abs = abs($number);

        return match (true) {
            $abs >= 10_000_000 => rtrim(rtrim(number_format($number / 10_000_000, 1, '.', ''), '0'), '.') . 'Cr',
            $abs >= 100_000    => rtrim(rtrim(number_format($number / 100_000, 1, '.', ''), '0'), '.') . 'L',
            $abs >= 1_000      => rtrim(rtrim(number_format($number / 1_000, 1, '.', ''), '0'), '.') . 'K',
            default            => (string) $number,
        };
    }

    /** Human-friendly relative time: "2m ago", "1d ago". */
    public static function timeAgo(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return '';
        }

        $timestamp = strtotime($datetime);
        if ($timestamp === false) {
            return '';
        }

        $seconds = time() - $timestamp;

        if ($seconds < 0) {
            return 'just now';
        }

        return match (true) {
            $seconds < 60        => 'just now',
            $seconds < 3600      => intdiv($seconds, 60) . 'm ago',
            $seconds < 86400     => intdiv($seconds, 3600) . 'h ago',
            $seconds < 604800    => intdiv($seconds, 86400) . 'd ago',
            $seconds < 2592000   => intdiv($seconds, 604800) . 'w ago',
            $seconds < 31536000  => intdiv($seconds, 2592000) . 'mo ago',
            default              => intdiv($seconds, 31536000) . 'y ago',
        };
    }

    /** Substitute {{placeholders}} in a notification template. */
    public static function interpolate(string $template, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static fn (array $m): string => (string) ($values[$m[1]] ?? ''),
            $template
        ) ?? $template;
    }

    /**
     * Substitute {{placeholders}} in an HTML template, escaping every value.
     *
     * The values are text — a user's name, a reviewer's reason, a document
     * label — never markup. Interpolated raw into an email body, a reason such
     * as "Name on PAN <RAHUL K> differs" lost its middle as an unknown tag, and
     * a name containing `<a href=…>` rendered as a live link in a platform-
     * branded email. Plain-text channels (in-app, push, SMS, the subject line)
     * keep using interpolate(): escaping there would show `&amp;` literally.
     */
    public static function interpolateHtml(string $template, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/',
            static function (array $m) use ($values): string {
                $value = $values[$m[1]] ?? '';

                return is_scalar($value) || $value instanceof \Stringable
                    ? self::e((string) $value)
                    : '';
            },
            $template
        ) ?? $template;
    }
}
