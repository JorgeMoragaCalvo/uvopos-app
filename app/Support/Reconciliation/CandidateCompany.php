<?php

namespace App\Support\Reconciliation;

use Carbon\Carbon;

/**
 * The few facts about a company that the match engine needs, lifted out
 * of {@see \App\Models\Customer} so the scoring stays pure — no Eloquent,
 * no container, no database — and can be unit-tested the way
 * {@see \App\Enums\PaymentStatus} is.
 */
class CandidateCompany
{
    /** @var int */
    public $id;

    /** @var string Normalized RUT, as {@see \App\Support\Rut::normalize()} returns it. */
    public $rut;

    /** @var string Display name. */
    public $name;

    /** @var int|null Expected charge, or null when the company has no priced plan. */
    public $chargeAmount;

    /** @var Carbon|null */
    public $dueDate;

    public function __construct(int $id, string $rut, string $name, ?int $chargeAmount, ?Carbon $dueDate)
    {
        $this->id = $id;
        $this->rut = $rut;
        $this->name = $name;
        $this->chargeAmount = $chargeAmount;
        $this->dueDate = $dueDate;
    }

    public static function fromCustomer(\App\Models\Customer $customer): self
    {
        return new self(
            (int) $customer->id,
            \App\Support\Rut::normalize((string) $customer->rut),
            (string) $customer->name,
            $customer->charge_amount,
            $customer->proximoPago
        );
    }
}
