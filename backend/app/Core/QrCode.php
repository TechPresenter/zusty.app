<?php

declare(strict_types=1);

namespace Zuste\Core;

/**
 * Minimal QR Code encoder (ISO/IEC 18004), byte mode, error-correction
 * level M, versions 1–10.
 *
 * Written from scratch because the alternative — posting the TOTP shared
 * secret to a third-party chart API to render the image — would hand the
 * second factor to that service. A QR code is generated entirely on this
 * server or not at all.
 *
 * Versions 1–10 hold up to 271 bytes at level M, comfortably more than an
 * `otpauth://` URI needs.
 */
final class QrCode
{
    /** Data codeword capacity at EC level M, indexed by version (1-based). */
    private const DATA_CODEWORDS_M = [
        1 => 16, 2 => 28, 3 => 44, 4 => 64, 5 => 86,
        6 => 108, 7 => 124, 8 => 154, 9 => 182, 10 => 216,
    ];

    /** EC codewords per block at level M, indexed by version. */
    private const EC_CODEWORDS_PER_BLOCK_M = [
        1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24,
        6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26,
    ];

    /** Number of EC blocks at level M, indexed by version. */
    private const EC_BLOCKS_M = [
        1 => 1, 2 => 1, 3 => 1, 4 => 2, 5 => 2,
        6 => 4, 7 => 4, 8 => 4, 9 => 5, 10 => 5,
    ];

    /** Alignment-pattern centre coordinates, indexed by version. */
    private const ALIGNMENT_CENTRES = [
        1  => [],
        2  => [6, 18],
        3  => [6, 22],
        4  => [6, 26],
        5  => [6, 30],
        6  => [6, 34],
        7  => [6, 22, 38],
        8  => [6, 24, 42],
        9  => [6, 26, 46],
        10 => [6, 28, 50],
    ];

    /** Pre-computed 15-bit format information for EC level M, masks 0-7. */
    private const FORMAT_BITS_M = [
        0b101010000010010, 0b101000100100101, 0b101111001111100, 0b101101101001011,
        0b100010111111001, 0b100000011001110, 0b100111110010111, 0b100101010100000,
    ];

    /** @var array<int,int>|null Galois field exponent table. */
    private static ?array $expTable = null;

    /** @var array<int,int>|null Galois field logarithm table. */
    private static ?array $logTable = null;

    /**
     * Encode text into a matrix of 0/1 modules.
     *
     * @return list<list<int>> Row-major; $matrix[y][x]
     */
    public static function encode(string $text): array
    {
        $version = self::chooseVersion($text);
        $size    = 17 + $version * 4;

        $dataCodewords = self::buildDataCodewords($text, $version);
        $finalCodewords = self::interleave($dataCodewords, $version);

        // Try every mask and keep the one with the lowest penalty, exactly as
        // the specification requires — a bad mask can make a code unreadable.
        $best        = null;
        $bestPenalty = PHP_INT_MAX;

        for ($mask = 0; $mask < 8; $mask++) {
            $matrix = self::buildMatrix($size, $version, $finalCodewords, $mask);
            $penalty = self::penalty($matrix, $size);

            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $best        = $matrix;
            }
        }

        /** @var list<list<int>> $best */
        return $best;
    }

    // -----------------------------------------------------------------
    // Data encoding
    // -----------------------------------------------------------------

    private static function chooseVersion(string $text): int
    {
        $length = strlen($text);

        foreach (self::DATA_CODEWORDS_M as $version => $capacity) {
            // 4 bits mode + 8/16 bits length + payload, rounded up to bytes.
            $lengthBits = $version <= 9 ? 8 : 16;
            $required   = (int) ceil((4 + $lengthBits + $length * 8) / 8);

            if ($required <= $capacity) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('Text is too long for a version 1–10 QR code.');
    }

    /** @return list<int> */
    private static function buildDataCodewords(string $text, int $version): array
    {
        $capacity   = self::DATA_CODEWORDS_M[$version];
        $lengthBits = $version <= 9 ? 8 : 16;

        // Mode indicator 0100 = byte mode.
        $bits = '0100' . str_pad(decbin(strlen($text)), $lengthBits, '0', STR_PAD_LEFT);

        foreach (str_split($text) as $character) {
            $bits .= str_pad(decbin(ord($character)), 8, '0', STR_PAD_LEFT);
        }

        // Terminator: up to four zero bits.
        $bits .= str_repeat('0', min(4, $capacity * 8 - strlen($bits)));

        // Pad to a byte boundary.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }

        $codewords = [];

        foreach (str_split($bits, 8) as $byte) {
            $codewords[] = bindec($byte);
        }

        // Alternating pad bytes, per the specification.
        $padBytes = [0xEC, 0x11];
        $index    = 0;

        while (count($codewords) < $capacity) {
            $codewords[] = $padBytes[$index % 2];
            $index++;
        }

        return $codewords;
    }

    /**
     * Split into blocks, append Reed–Solomon parity, then interleave.
     *
     * @param  list<int> $dataCodewords
     * @return list<int>
     */
    private static function interleave(array $dataCodewords, int $version): array
    {
        $blockCount   = self::EC_BLOCKS_M[$version];
        $ecPerBlock   = self::EC_CODEWORDS_PER_BLOCK_M[$version];
        $totalData    = count($dataCodewords);

        $shortLength  = intdiv($totalData, $blockCount);
        $longBlocks   = $totalData % $blockCount;

        $dataBlocks = [];
        $ecBlocks   = [];
        $offset     = 0;

        for ($i = 0; $i < $blockCount; $i++) {
            $length = $shortLength + ($i >= $blockCount - $longBlocks ? 1 : 0);
            $block  = array_slice($dataCodewords, $offset, $length);
            $offset += $length;

            $dataBlocks[] = $block;
            $ecBlocks[]   = self::reedSolomon($block, $ecPerBlock);
        }

        $result   = [];
        $maxData  = max(array_map('count', $dataBlocks));

        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $ecPerBlock; $i++) {
            foreach ($ecBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    // -----------------------------------------------------------------
    // Reed–Solomon over GF(256)
    // -----------------------------------------------------------------

    private static function initGaloisTables(): void
    {
        if (self::$expTable !== null) {
            return;
        }

        self::$expTable = [];
        self::$logTable = [];

        $value = 1;

        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $value;
            self::$logTable[$value] = $i;

            $value <<= 1;

            // Reduce modulo the QR primitive polynomial x^8 + x^4 + x^3 + x^2 + 1.
            if ($value & 0x100) {
                $value ^= 0x11D;
            }
        }

        // Extend so exponent lookups above 254 need no modulo.
        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gfMultiply(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }

        self::initGaloisTables();

        return self::$expTable[(self::$logTable[$a] + self::$logTable[$b]) % 255];
    }

    /**
     * @param  list<int> $data
     * @return list<int>
     */
    private static function reedSolomon(array $data, int $ecCount): array
    {
        self::initGaloisTables();

        // Generator polynomial: product of (x - a^i) for i in 0..ecCount-1.
        $generator = [1];

        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($generator) + 1, 0);

            foreach ($generator as $index => $coefficient) {
                $next[$index]     ^= self::gfMultiply($coefficient, self::$expTable[$i]);
                $next[$index + 1] ^= $coefficient;
            }

            $generator = $next;
        }

        $remainder = array_merge($data, array_fill(0, $ecCount, 0));

        for ($i = 0; $i < count($data); $i++) {
            $factor = $remainder[$i];

            if ($factor === 0) {
                continue;
            }

            foreach ($generator as $index => $coefficient) {
                $remainder[$i + $index] ^= self::gfMultiply($coefficient, $factor);
            }
        }

        return array_values(array_slice($remainder, count($data)));
    }

    // -----------------------------------------------------------------
    // Matrix construction
    // -----------------------------------------------------------------

    /**
     * @param  list<int> $codewords
     * @return list<list<int>>
     */
    private static function buildMatrix(int $size, int $version, array $codewords, int $mask): array
    {
        // -1 = free, 0 = light, 1 = dark.
        $matrix   = array_fill(0, $size, array_fill(0, $size, -1));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        // Finder patterns and their separators.
        foreach ([[0, 0], [$size - 7, 0], [0, $size - 7]] as [$fx, $fy]) {
            for ($y = -1; $y <= 7; $y++) {
                for ($x = -1; $x <= 7; $x++) {
                    $px = $fx + $x;
                    $py = $fy + $y;

                    if ($px < 0 || $py < 0 || $px >= $size || $py >= $size) {
                        continue;
                    }

                    $isBorder = ($x >= 0 && $x <= 6 && ($y === 0 || $y === 6))
                              || ($y >= 0 && $y <= 6 && ($x === 0 || $x === 6));
                    $isCentre = $x >= 2 && $x <= 4 && $y >= 2 && $y <= 4;

                    $matrix[$py][$px]   = ($isBorder || $isCentre) ? 1 : 0;
                    $reserved[$py][$px] = true;
                }
            }
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $bit = ($i % 2 === 0) ? 1 : 0;

            $matrix[6][$i]   = $bit;
            $reserved[6][$i] = true;
            $matrix[$i][6]   = $bit;
            $reserved[$i][6] = true;
        }

        // Alignment patterns.
        $centres = self::ALIGNMENT_CENTRES[$version];

        foreach ($centres as $cy) {
            foreach ($centres as $cx) {
                // Skip the three that would overlap a finder pattern.
                if (($cx === 6 && $cy === 6)
                    || ($cx === 6 && $cy === $size - 7)
                    || ($cx === $size - 7 && $cy === 6)) {
                    continue;
                }

                for ($y = -2; $y <= 2; $y++) {
                    for ($x = -2; $x <= 2; $x++) {
                        $isDark = max(abs($x), abs($y)) !== 1;

                        $matrix[$cy + $y][$cx + $x]   = $isDark ? 1 : 0;
                        $reserved[$cy + $y][$cx + $x] = true;
                    }
                }
            }
        }

        // Reserve the format information areas.
        // Copy 1 wraps the top-left finder.
        for ($i = 0; $i < 9; $i++) {
            if (!$reserved[8][$i]) { $reserved[8][$i] = true; $matrix[8][$i] = 0; }
            if (!$reserved[$i][8]) { $reserved[$i][8] = true; $matrix[$i][8] = 0; }
        }

        // Copy 2 is asymmetric: 8 modules along row 8 on the right, but only
        // 7 down column 8 at the bottom. Reserving 8 there would overwrite
        // the dark module that sits directly above it at row size-8.
        for ($i = 0; $i < 8; $i++) {
            $reserved[8][$size - 1 - $i] = true;
            $matrix[8][$size - 1 - $i]   = 0;
        }

        for ($i = 0; $i < 7; $i++) {
            $reserved[$size - 7 + $i][8] = true;
            $matrix[$size - 7 + $i][8]   = 0;
        }

        // Dark module — always set, always at (4*version + 9, 8), which is
        // exactly row size-8 and therefore just clear of the block above.
        $matrix[4 * $version + 9][8]   = 1;
        $reserved[4 * $version + 9][8] = true;

        // Place the data bits in the zig-zag order.
        $bits = '';

        foreach ($codewords as $codeword) {
            $bits .= str_pad(decbin($codeword), 8, '0', STR_PAD_LEFT);
        }

        $bitIndex = 0;
        $upward   = true;

        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // Skip the vertical timing column.
            }

            for ($step = 0; $step < $size; $step++) {
                $y = $upward ? ($size - 1 - $step) : $step;

                foreach ([$right, $right - 1] as $x) {
                    if ($reserved[$y][$x]) {
                        continue;
                    }

                    $bit = $bitIndex < strlen($bits) ? (int) $bits[$bitIndex] : 0;
                    $bitIndex++;

                    if (self::maskApplies($mask, $x, $y)) {
                        $bit ^= 1;
                    }

                    $matrix[$y][$x] = $bit;
                }
            }

            $upward = !$upward;
        }

        self::placeFormatBits($matrix, $size, $mask);

        return $matrix;
    }

    private static function maskApplies(int $mask, int $x, int $y): bool
    {
        return match ($mask) {
            0 => ($y + $x) % 2 === 0,
            1 => $y % 2 === 0,
            2 => $x % 3 === 0,
            3 => ($y + $x) % 3 === 0,
            4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
            5 => (($y * $x) % 2) + (($y * $x) % 3) === 0,
            6 => ((($y * $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            7 => ((($y + $x) % 2) + (($y * $x) % 3)) % 2 === 0,
            default => false,
        };
    }

    /** @param list<list<int>> $matrix */
    private static function placeFormatBits(array &$matrix, int $size, int $mask): void
    {
        $format = self::FORMAT_BITS_M[$mask];
        $bits   = str_pad(decbin($format), 15, '0', STR_PAD_LEFT);

        for ($i = 0; $i < 15; $i++) {
            // Bit 0 is the least significant, placed first.
            $bit = (int) $bits[14 - $i];

            // Copy 1: around the top-left finder.
            if ($i < 6) {
                $matrix[8][$i] = $bit;
            } elseif ($i === 6) {
                $matrix[8][7] = $bit;
            } elseif ($i === 7) {
                $matrix[8][8] = $bit;
            } elseif ($i === 8) {
                $matrix[7][8] = $bit;
            } else {
                $matrix[14 - $i][8] = $bit;
            }

            // Copy 2: split between the other two finders.
            if ($i < 8) {
                $matrix[8][$size - 1 - $i] = $bit;
            } else {
                $matrix[$size - 15 + $i][8] = $bit;
            }
        }
    }

    /**
     * Penalty score used to pick the best mask (ISO/IEC 18004 §8.8.2).
     *
     * @param list<list<int>> $matrix
     */
    private static function penalty(array $matrix, int $size): int
    {
        $penalty = 0;

        // Rule 1: runs of five or more same-coloured modules.
        for ($y = 0; $y < $size; $y++) {
            for ($direction = 0; $direction < 2; $direction++) {
                $runColour = -1;
                $runLength = 0;

                for ($x = 0; $x < $size; $x++) {
                    $module = $direction === 0 ? $matrix[$y][$x] : $matrix[$x][$y];

                    if ($module === $runColour) {
                        $runLength++;
                    } else {
                        if ($runLength >= 5) {
                            $penalty += 3 + ($runLength - 5);
                        }

                        $runColour = $module;
                        $runLength = 1;
                    }
                }

                if ($runLength >= 5) {
                    $penalty += 3 + ($runLength - 5);
                }
            }
        }

        // Rule 2: 2×2 blocks of one colour.
        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $module = $matrix[$y][$x];

                if ($module === $matrix[$y][$x + 1]
                    && $module === $matrix[$y + 1][$x]
                    && $module === $matrix[$y + 1][$x + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: finder-like 1:1:3:1:1 patterns.
        $pattern = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x <= $size - 11; $x++) {
                $matchRow = true;
                $matchCol = true;

                for ($i = 0; $i < 11; $i++) {
                    if ($matrix[$y][$x + $i] !== $pattern[$i]) {
                        $matchRow = false;
                    }
                    if ($matrix[$x + $i][$y] !== $pattern[$i]) {
                        $matchCol = false;
                    }
                }

                if ($matchRow) { $penalty += 40; }
                if ($matchCol) { $penalty += 40; }
            }
        }

        // Rule 4: deviation from a 50% dark ratio.
        $dark = 0;

        foreach ($matrix as $row) {
            foreach ($row as $module) {
                if ($module === 1) {
                    $dark++;
                }
            }
        }

        $ratio    = ($dark * 100) / ($size * $size);
        $penalty += (int) (abs($ratio - 50) / 5) * 10;

        return $penalty;
    }
}
