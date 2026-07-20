<?php

namespace App\Support;

/**
 * Helpers for Chilean RUT handling: normalization, formatting and
 * check-digit validation (modulo 11).
 */
class Rut
{
    /**
     * Strip dots, dashes and whitespace; uppercase the check digit.
     * "12.345.678-5" => "123456785"
     */
    public static function normalize(string $rut): string
    {
        return strtoupper(preg_replace('/[^0-9kK]/', '', $rut));
    }

    /**
     * Format a normalized (or raw) RUT as "12.345.678-5".
     * Returns the input unchanged when it is too short to format.
     */
    public static function format(string $rut): string
    {
        $rut = self::normalize($rut);

        if (strlen($rut) < 2) {
            return $rut;
        }

        $dv   = substr($rut, -1);
        $body = substr($rut, 0, -1);

        return number_format((int) $body, 0, '', '.') . '-' . $dv;
    }

    /**
     * Validate the check digit using the standard modulo-11 algorithm.
     */
    public static function isValid(string $rut): bool
    {
        $rut = self::normalize($rut);

        if (!preg_match('/^\d{7,8}[0-9K]$/', $rut)) {
            return false;
        }

        $dv   = substr($rut, -1);
        $body = substr($rut, 0, -1);

        return self::checkDigit($body) === $dv;
    }

    /**
     * Compute the check digit for a RUT body (digits only).
     */
    public static function checkDigit(string $body): string
    {
        $sum    = 0;
        $factor = 2;

        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $sum += (int) $body[$i] * $factor;
            $factor = $factor === 7 ? 2 : $factor + 1;
        }

        $rest = 11 - ($sum % 11);

        if ($rest === 11) {
            return '0';
        }

        if ($rest === 10) {
            return 'K';
        }

        return (string) $rest;
    }
}
