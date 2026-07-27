<?php

namespace App\Support\Cartola;

use Carbon\Carbon;

/**
 * One parsed cartola line, normalized away from whatever the bank's
 * export looked like. Immutable — the importer turns these into
 * {@see \App\Models\BankMovement} rows.
 */
class Movement
{
    public const CREDIT = 'credit';
    public const DEBIT = 'debit';

    /** @var Carbon */
    public $postedAt;

    /** @var string */
    public $description;

    /** @var string|null */
    public $reference;

    /** @var int Always positive; the sign lives in $direction. */
    public $amount;

    /** @var string 'credit' | 'debit' */
    public $direction;

    /** @var string|null Normalized RUT found in the description. */
    public $counterpartyRut;

    public function __construct(
        Carbon $postedAt,
        string $description,
        ?string $reference,
        int $amount,
        string $direction,
        ?string $counterpartyRut
    ) {
        $this->postedAt = $postedAt;
        $this->description = $description;
        $this->reference = $reference;
        $this->amount = $amount;
        $this->direction = $direction;
        $this->counterpartyRut = $counterpartyRut;
    }

    /**
     * Fingerprint used to recognise the same movement arriving in a
     * second cartola. Cartola exports are requested by date range and
     * those ranges overlap constantly, so without this the same deposit
     * would be offered for reconciliation twice.
     *
     * The description is normalized first: the same line re-exported can
     * differ in spacing, casing or accents.
     */
    public function rowHash(): string
    {
        return hash('sha256', implode('|', [
            $this->postedAt->toDateString(),
            $this->direction,
            $this->amount,
            (string) $this->reference,
            \App\Support\Text::normalize($this->description),
        ]));
    }
}
