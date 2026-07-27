<?php

namespace App\Support\Cartola;

use App\Support\Text;

/**
 * Resolves a bank's configured column labels against the header row of an
 * actual export, so the rest of the importer can ask for "date" without
 * knowing whether this bank called it "Fecha" or "Fecha Transacción".
 *
 * Labels match accent- and case-insensitively, and on a prefix, because
 * banks append things: the configured "fecha" matches "Fecha Movimiento".
 */
class ColumnMap
{
    /** @var array<string,int> field name => column index */
    private $indexes = [];

    /**
     * @param  string[]              $header   Raw header cells.
     * @param  array<string,array>   $columns  Field name => candidate labels.
     */
    public function __construct(array $header, array $columns)
    {
        $normalized = array_map([Text::class, 'normalize'], $header);

        foreach ($columns as $field => $candidates) {
            foreach ($candidates as $candidate) {
                $index = $this->findColumn($normalized, Text::normalize($candidate));

                if ($index !== null) {
                    $this->indexes[$field] = $index;
                    break;
                }
            }
        }
    }

    public function has(string $field): bool
    {
        return isset($this->indexes[$field]);
    }

    /**
     * Every field the layout needs to describe a movement at all: a date,
     * something to show the staff member, and at least one amount column.
     */
    public function isUsable(): bool
    {
        return $this->has('date')
            && $this->has('description')
            && ($this->has('amount') || $this->has('credit') || $this->has('debit'));
    }

    public function value(array $row, string $field): ?string
    {
        if (!isset($this->indexes[$field])) {
            return null;
        }

        $index = $this->indexes[$field];

        return isset($row[$index]) ? trim((string) $row[$index]) : null;
    }

    /**
     * @param  string[]  $normalizedHeader
     */
    private function findColumn(array $normalizedHeader, string $label): ?int
    {
        if ($label === '') {
            return null;
        }

        foreach ($normalizedHeader as $index => $cell) {
            if ($cell === $label) {
                return $index;
            }
        }

        // Fall back to a prefix match ("fecha" finding "fecha movimiento"),
        // which is how banks usually differ from the configured label.
        foreach ($normalizedHeader as $index => $cell) {
            if ($cell !== '' && strpos($cell, $label) === 0) {
                return $index;
            }
        }

        return null;
    }
}
