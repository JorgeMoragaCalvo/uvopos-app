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

    public function __construct(CandidateCompany $company, int $score, array $reasons)
    {
        $this->company = $company;
        $this->score = $score;
        $this->reasons = $reasons;
    }

    public function reasonText(): string
    {
        return implode(' + ', $this->reasons);
    }
}
