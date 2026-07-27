<?php

namespace App\Support\Cartola;

/**
 * Amounts as Chilean bank exports write them.
 *
 * Accepted shapes: "1.234.567", "$ 1.234.567", "1.234.567,00",
 * "1,234,567.00" (an export in US locale), "-1.234" and "(1.234)".
 *
 * A lone dot is always read as a thousands separator: the peso has no
 * subunit in practice, so "1.234" in a cartola is one thousand two
 * hundred thirty four pesos, never 1,23. Cents only appear when the
 * export also uses a comma, and they are rounded away.
 */
class Money
{
    /**
     * @return int|null CLP, or null when the cell holds no number at all.
     */
    public static function parseClp(?string $raw): ?int
    {
        $value = trim((string) $raw);

        if ($value === '') {
            return null;
        }

        // Accounting negatives: (1.234)
        $negative = false;

        if (preg_match('/^\((.*)\)$/', $value, $m) === 1) {
            $negative = true;
            $value = $m[1];
        }

        // Drop currency symbols, thin spaces and anything else that is
        // not part of the number.
        $value = preg_replace('/[^0-9,.\-]/', '', $value);

        if ($value === '' || $value === '-') {
            return null;
        }

        if (strpos($value, '-') === 0) {
            $negative = true;
        }

        $value = str_replace('-', '', $value);

        $lastDot = strrpos($value, '.');
        $lastComma = strrpos($value, ',');

        if ($lastDot !== false && $lastComma !== false) {
            // Both present: whichever comes last is the decimal mark.
            $decimalMark = $lastDot > $lastComma ? '.' : ',';
            $thousandsMark = $decimalMark === '.' ? ',' : '.';
            $value = str_replace($thousandsMark, '', $value);
            $value = str_replace($decimalMark, '.', $value);
        } elseif ($lastComma !== false) {
            // A single comma with two trailing digits is cents; anything
            // else is a thousands separator.
            $value = preg_match('/,\d{2}$/', $value) === 1
                ? str_replace(',', '.', $value)
                : str_replace(',', '', $value);
        } else {
            // Dots only — thousands separators, per the note above.
            $value = str_replace('.', '', $value);
        }

        if (!is_numeric($value)) {
            return null;
        }

        $amount = (int) round((float) $value);

        return $negative ? -$amount : $amount;
    }
}
