<?php

namespace App\Support\Reconciliation;

/**
 * One company scored against one bank movement, with the human-readable
 * reasons that produced the score. The reasons are shown to the staff
 * member making the call — a number on its own is not a justification for
 * moving someone's due date.
 */
class ScoredCandidate
{
    /** @var CandidateCompany */
    public $company;

    /** @var int */
    public $score;

    /** @var string[] Spanish, shown in the review queue. */
    public $reasons;

    /**
     * The same signals as `$reasons`, by their config key
     * (`rut_in_glosa`, `amount_exact`, …). `$reasons` is display copy and
     * will be reworded; anything deciding what to *do* with a match reads
     * these instead.
     *
     * @var string[]
     */
    public $signals;

    public function __construct(CandidateCompany $company, int $score, array $reasons, array $signals = [])
    {
        $this->company = $company;
        $this->score = $score;
        $this->reasons = $reasons;
        $this->signals = $signals;
    }

    public function reasonText(): string
    {
        return implode(' + ', $this->reasons);
    }

    public function hasSignal(string $signal): bool
    {
        return in_array($signal, $this->signals, true);
    }

    /**
     * An empty list is false, not vacuously true: the caller asking this
     * is deciding whether to act without a human, and a misconfigured
     * empty requirement must not qualify every match.
     *
     * @param  string[]  $signals
     */
    public function hasAllSignals(array $signals): bool
    {
        if ($signals === []) {
            return false;
        }

        foreach ($signals as $signal) {
            if (!$this->hasSignal($signal)) {
                return false;
            }
        }

        return true;
    }
}
