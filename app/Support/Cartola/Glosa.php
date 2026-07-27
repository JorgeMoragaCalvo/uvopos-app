<?php

namespace App\Support\Cartola;

use App\Support\Rut;

/**
 * The free-text description of a bank movement.
 *
 * When a Chilean company pays by transfer the payer's RUT is usually
 * somewhere in here — "TRANSF DE 76.543.210-K COMERCIAL ANDES SPA" — but
 * the format is entirely up to the bank and the payer, so extraction is
 * best-effort and always subject to staff confirmation.
 */
class Glosa
{
    /**
     * Anything RUT-shaped: 7 or 8 body digits, optional thousands dots,
     * optional dash, and a digit or K check digit.
     */
    private const RUT_PATTERN = '/\b(\d{1,2}\.?\d{3}\.?\d{3})\s*-?\s*([\dkK])\b/u';

    /**
     * The first RUT in the glosa whose check digit is valid, normalized
     * ("765432104"). Candidates that fail modulo-11 are skipped rather
     * than returned, so an invoice or order number that happens to look
     * like a RUT doesn't become a false match signal.
     */
    public static function extractRut(?string $description): ?string
    {
        if (preg_match_all(self::RUT_PATTERN, (string) $description, $matches, PREG_SET_ORDER) === 0) {
            return null;
        }

        foreach ($matches as $match) {
            $candidate = $match[1] . '-' . $match[2];

            if (Rut::isValid($candidate)) {
                return Rut::normalize($candidate);
            }
        }

        return null;
    }
}
