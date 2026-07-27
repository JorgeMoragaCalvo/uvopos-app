<?php

namespace App\Support\Reconciliation;

/**
 * What the match engine concluded about one bank movement.
 *
 * Note what is deliberately absent: any notion of "applied". The engine
 * ranks and explains; only a staff click records a payment.
 */
class MatchResult
{
    /** @var ScoredCandidate[] Best first, already filtered to the suggestible ones. */
    public $candidates;

    /**
     * @var bool True when the top candidate is clearly ahead and above the
     *           high-confidence threshold, so the UI may pre-select it for
     *           bulk confirmation.
     */
    public $isHighConfidence;

    /**
     * @var bool True when a runner-up scored almost as well as the top
     *           candidate. Distinct from a merely weak match: it means
     *           picking the leader would be a coin flip, which the review
     *           queue has to say out loud.
     */
    public $isAmbiguous;

    public function __construct(array $candidates, bool $isHighConfidence, bool $isAmbiguous = false)
    {
        $this->candidates = $candidates;
        $this->isHighConfidence = $isHighConfidence;
        $this->isAmbiguous = $isAmbiguous;
    }

    public function best(): ?ScoredCandidate
    {
        return $this->candidates[0] ?? null;
    }

    public function hasSuggestion(): bool
    {
        return $this->candidates !== [];
    }
}
