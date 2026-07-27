<?php

namespace App\Support;

/**
 * Accent- and case-insensitive text handling, shared by the cartola
 * importer (matching header labels like "Descripción" against the
 * config's "descripcion") and the match engine (comparing a bank glosa
 * against a company name).
 *
 * Chilean bank exports are inconsistent about accents and character
 * encoding, so nothing may depend on them being present or correct.
 */
class Text
{
    private const ACCENTED = 'áàäâãéèëêíìïîóòöôõúùüûñçÁÀÄÂÃÉÈËÊÍÌÏÎÓÒÖÔÕÚÙÜÛÑÇ';
    private const PLAIN = 'aaaaaeeeeiiiiooooouuuuncAAAAAEEEEIIIIOOOOOUUUUNC';

    /**
     * Lowercased, unaccented, with runs of non-alphanumerics collapsed to
     * single spaces: "Transf. de JOSÉ PÉREZ" -> "transf de jose perez".
     */
    public static function normalize(?string $value): string
    {
        $value = (string) $value;

        // strtr with multibyte needles needs matched arrays, not strings.
        $value = strtr($value, array_combine(
            self::split(self::ACCENTED),
            self::split(self::PLAIN)
        ));

        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);

        return trim($value);
    }

    /**
     * Meaningful words of a name, for overlap comparison. Very short
     * fragments and the Chilean company-form suffixes are dropped: every
     * second company is a "SpA", so matching on it means nothing.
     */
    public static function tokens(?string $value): array
    {
        $stopWords = ['spa', 'ltda', 'limitada', 'sa', 'eirl', 'y', 'de', 'del', 'la', 'el', 'los', 'las'];

        $tokens = array_filter(
            explode(' ', self::normalize($value)),
            function ($token) use ($stopWords) {
                return strlen($token) >= 3 && !in_array($token, $stopWords, true);
            }
        );

        return array_values(array_unique($tokens));
    }

    /** @return string[] */
    private static function split(string $value): array
    {
        return preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    }
}
