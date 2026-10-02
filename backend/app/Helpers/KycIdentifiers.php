<?php

declare(strict_types=1);

namespace Zuste\Helpers;

/**
 * Format and checksum rules for Indian identity and business identifiers.
 *
 * The server is the authority — the app mirrors these rules for instant
 * feedback, but nothing it says is trusted. Every check here is a pure
 * function of the string, so the same class serves the API, the admin
 * approval guard and the test suite.
 *
 * Everything is normalised the same way first: upper-cased, with spaces and
 * hyphens removed, because that is how people type these numbers ("2345 6789
 * 0123", "abcde-1234-f") and how the documents print them.
 *
 * What is deliberately NOT done: no call to UIDAI, the GST portal or the MCA.
 * A checksum proves a number is well-formed, not that it belongs to this
 * person — the reviewer comparing it against the uploaded document is what
 * establishes that.
 */
final class KycIdentifiers
{
    /**
     * Company types whose registration carries a Corporate Identification
     * Number, mapped from the `company_type` taxonomy actually seeded in the
     * database (sole_proprietorship, partnership, llp, private_limited,
     * public_limited, opc, trust, society, ngo, other):
     *
     *   private_limited, public_limited, opc  -> CIN and incorporation certificate required
     *   sole_proprietorship, partnership       -> no CIN exists
     *   llp                                    -> has an LLPIN, which is not a CIN
     *   trust, society, ngo, other             -> registered under other acts; a
     *                                             Section 8 NGO may hold a CIN, so
     *                                             one is accepted but not demanded
     */
    public const CIN_COMPANY_TYPES = ['private_limited', 'public_limited', 'opc'];

    /** GST state codes: 01-38 are the states and union territories, 97 "other territory". */
    private const GST_EXTRA_STATE_CODES = [97];

    private const GST_ALPHABET = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    /** Verhoeff multiplication table (dihedral group D5). */
    private const VERHOEFF_D = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 2, 3, 4, 0, 6, 7, 8, 9, 5],
        [2, 3, 4, 0, 1, 7, 8, 9, 5, 6],
        [3, 4, 0, 1, 2, 8, 9, 5, 6, 7],
        [4, 0, 1, 2, 3, 9, 5, 6, 7, 8],
        [5, 9, 8, 7, 6, 0, 4, 3, 2, 1],
        [6, 5, 9, 8, 7, 1, 0, 4, 3, 2],
        [7, 6, 5, 9, 8, 2, 1, 0, 4, 3],
        [8, 7, 6, 5, 9, 3, 2, 1, 0, 4],
        [9, 8, 7, 6, 5, 4, 3, 2, 1, 0],
    ];

    /** Verhoeff position permutation table. */
    private const VERHOEFF_P = [
        [0, 1, 2, 3, 4, 5, 6, 7, 8, 9],
        [1, 5, 7, 6, 2, 8, 3, 0, 9, 4],
        [5, 8, 0, 3, 7, 9, 6, 1, 4, 2],
        [8, 9, 1, 6, 0, 4, 3, 5, 2, 7],
        [9, 4, 5, 3, 1, 2, 6, 8, 7, 0],
        [4, 2, 8, 6, 5, 7, 3, 9, 0, 1],
        [2, 7, 9, 3, 8, 0, 6, 4, 1, 5],
        [7, 0, 4, 6, 9, 1, 3, 2, 5, 8],
    ];

    /** Verhoeff inverse table. */
    private const VERHOEFF_INV = [0, 4, 3, 2, 1, 5, 6, 7, 8, 9];

    /** Upper-case and strip the separators people type. */
    public static function normalize(string $value): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', trim($value)));
    }

    /**
     * Does this value look like a masked rendering rather than real input?
     *
     * The app used to send the masked value it had been shown straight back
     * ("XXXXXX234F"), and the server encrypted and stored it — overwriting
     * the real PAN with the mask. A run of four or more X's cannot occur in a
     * genuine PAN, GSTIN, CIN or Aadhaar (the PAN's 4th letter is an entity
     * code, never X), and `*` / `•` never appear in one at all, so any of
     * those means "the user did not change this field".
     */
    public static function isMasked(string $value, ?string $currentMasked = null): bool
    {
        $raw = trim($value);

        if ($raw === '') {
            return false;
        }

        if ($currentMasked !== null && $currentMasked !== ''
            && self::normalize($raw) === self::normalize($currentMasked)) {
            return true;
        }

        return preg_match('/[*\x{2022}\x{25CF}]/u', $raw) === 1
            || preg_match('/[Xx]{4,}/', (string) preg_replace('/[\s\-]+/', '', $raw)) === 1;
    }

    // -----------------------------------------------------------------
    // PAN
    // -----------------------------------------------------------------

    public static function isValidPan(string $pan): bool
    {
        return preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', self::normalize($pan)) === 1;
    }

    /** An individual's PAN carries `P` as its 4th character (the holder type). */
    public static function isPersonalPan(string $pan): bool
    {
        $pan = self::normalize($pan);

        return self::isValidPan($pan) && $pan[3] === 'P';
    }

    // -----------------------------------------------------------------
    // Aadhaar
    // -----------------------------------------------------------------

    /**
     * 12 digits, never starting with 0 or 1, with a valid Verhoeff check digit
     * — the checksum UIDAI itself uses, which catches every single-digit typo
     * and every adjacent transposition.
     */
    public static function isValidAadhaar(string $aadhaar): bool
    {
        $digits = self::normalize($aadhaar);

        if (preg_match('/^[2-9][0-9]{11}$/', $digits) !== 1) {
            return false;
        }

        return self::verhoeffValid($digits);
    }

    /** Shape only (12 digits, first 2-9) — lets the error say which rule failed. */
    public static function isAadhaarShaped(string $aadhaar): bool
    {
        return preg_match('/^[2-9][0-9]{11}$/', self::normalize($aadhaar)) === 1;
    }

    /**
     * Would this value be an Aadhaar number once every separator is removed?
     *
     * For the guard on free-text ID fields ("Other ID number"), where a full
     * Aadhaar must never be stored. normalize() only strips spaces and
     * hyphens, so "2345.6789.0123", "2345/6789/0123" or "2345_6789_0123"
     * slipped past isAadhaarShaped(). Here everything that is not a letter or
     * a digit goes first. normalize() itself is unchanged: PAN, GSTIN and CIN
     * callers rely on it as is.
     */
    public static function looksLikeAadhaar(string $value): bool
    {
        $compact = (string) preg_replace('/[^0-9A-Za-z]+/u', '', $value);

        return preg_match('/^[2-9][0-9]{11}$/', $compact) === 1;
    }

    public static function verhoeffValid(string $digits): bool
    {
        $check  = 0;
        $length = strlen($digits);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];
            $check = self::VERHOEFF_D[$check][self::VERHOEFF_P[$i % 8][$digit]];
        }

        return $check === 0;
    }

    /** The Verhoeff check digit for [$digits] — used by tests to build valid fixtures. */
    public static function verhoeffDigit(string $digits): int
    {
        $check  = 0;
        $length = strlen($digits);

        for ($i = 0; $i < $length; $i++) {
            $digit = (int) $digits[$length - 1 - $i];
            $check = self::VERHOEFF_D[$check][self::VERHOEFF_P[($i + 1) % 8][$digit]];
        }

        return self::VERHOEFF_INV[$check];
    }

    /** "XXXX XXXX 1234" — the only form an Aadhaar is ever displayed in. */
    public static function maskAadhaarLast4(?string $last4): ?string
    {
        return $last4 === null || $last4 === '' ? null : 'XXXX XXXX ' . $last4;
    }

    // -----------------------------------------------------------------
    // GSTIN
    // -----------------------------------------------------------------

    public static function isGstinShaped(string $gstin): bool
    {
        return preg_match(
            '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/',
            self::normalize($gstin)
        ) === 1;
    }

    public static function gstinStateValid(string $gstin): bool
    {
        $code = (int) substr(self::normalize($gstin), 0, 2);

        return ($code >= 1 && $code <= 38) || in_array($code, self::GST_EXTRA_STATE_CODES, true);
    }

    /**
     * The 15th character is a mod-36 Luhn-style check over the first 14:
     * alternate weights 1 and 2, sum the base-36 digits of each product.
     */
    public static function gstinCheckChar(string $first14): string
    {
        $factor = 1;
        $sum    = 0;

        foreach (str_split(strtoupper($first14)) as $char) {
            $code    = strpos(self::GST_ALPHABET, $char);
            $product = ($code === false ? 0 : $code) * $factor;
            $sum    += intdiv($product, 36) + ($product % 36);
            $factor  = $factor === 1 ? 2 : 1;
        }

        return self::GST_ALPHABET[(36 - ($sum % 36)) % 36];
    }

    public static function gstinChecksumValid(string $gstin): bool
    {
        $gstin = self::normalize($gstin);

        return strlen($gstin) === 15 && self::gstinCheckChar(substr($gstin, 0, 14)) === $gstin[14];
    }

    public static function isValidGstin(string $gstin): bool
    {
        return self::isGstinShaped($gstin)
            && self::gstinStateValid($gstin)
            && self::gstinChecksumValid($gstin);
    }

    /** Characters 3-12 of a GSTIN are the holder's PAN. */
    public static function panInGstin(string $gstin): string
    {
        return substr(self::normalize($gstin), 2, 10);
    }

    // -----------------------------------------------------------------
    // CIN
    // -----------------------------------------------------------------

    public static function isValidCin(string $cin): bool
    {
        return preg_match(
            '/^[LU][0-9]{5}[A-Z]{2}[0-9]{4}(PLC|PTC|FLC|FTC|GOI|NPL|ULL|ULT|SGC|GAT|GAP|OPC)[0-9]{6}$/',
            self::normalize($cin)
        ) === 1;
    }

    /**
     * Whether this company type must provide a CIN and an incorporation
     * certificate. An unknown (not yet chosen) type is treated as requiring
     * one, so the checklist never under-states what will be asked for.
     */
    public static function companyTypeRequiresCin(?string $companyType): bool
    {
        if ($companyType === null || $companyType === '') {
            return true;
        }

        return in_array($companyType, self::CIN_COMPANY_TYPES, true);
    }
}
