<?php

namespace App\Support\Reconciliation;

use App\Support\Cartola\Movement;
use App\Support\Text;

/**
 * Scores a bank deposit against the companies it might be paying for.
 *
 * Chilean transfers carry no structured reference — the payer types
 * whatever they like into the glosa — so no single signal is conclusive.
 * The engine combines four of them and hands the ranked result, with the
 * reasons, to whoever asked: usually the review queue, and — when the
 * result carries the identity-grade signals {@see \App\Services\ImportCartola} requires —
 * the importer, which records that payment without a human.
 *
 * The engine itself never decides that; it reports what matched
 * ({@see ScoredCandidate::$signals}) and how the candidates compare
 * ({@see MatchResult::$isAmbiguous}).
 *
 * Pure logic on purpose. Every threshold is injected rather than read
 * from config(), so this can be unit-tested without the Laravel container
 * (the same convention as {@see \App\Enums\PaymentStatus}).
 */
class MatchEngine
{
    /** @var array<string,int> */
    private $signals;

    /** @var int */
    private $suggestThreshold;

    /** @var int */
    private $highConfidenceThreshold;

    /** @var int */
    private $tieMargin;

    /** @var float */
    private $amountTolerancePct;

    /** @var int */
    private $amountToleranceClp;

    /** @var int */
    private $windowBeforeDays;

    /** @var int */
    private $windowAfterDays;

    public function __construct(
        array $signals,
        int $suggestThreshold,
        int $highConfidenceThreshold,
        int $tieMargin,
        float $amountTolerancePct,
        int $amountToleranceClp,
        int $windowBeforeDays,
        int $windowAfterDays
    ) {
        $this->signals = $signals;
        $this->suggestThreshold = $suggestThreshold;
        $this->highConfidenceThreshold = $highConfidenceThreshold;
        $this->tieMargin = $tieMargin;
        $this->amountTolerancePct = $amountTolerancePct;
        $this->amountToleranceClp = $amountToleranceClp;
        $this->windowBeforeDays = $windowBeforeDays;
        $this->windowAfterDays = $windowAfterDays;
    }

    public static function fromConfig(): self
    {
        return new self(
            (array) config('bank_reconciliation.signals'),
            (int) config('bank_reconciliation.suggest_threshold', 40),
            (int) config('bank_reconciliation.high_confidence_threshold', 80),
            (int) config('bank_reconciliation.tie_margin', 10),
            (float) config('bank_reconciliation.amount_tolerance_pct', 2),
            (int) config('bank_reconciliation.amount_tolerance_clp', 1000),
            (int) config('bank_reconciliation.date_window_before_days', 5),
            (int) config('bank_reconciliation.date_window_after_days', 30)
        );
    }

    /**
     * @param  CandidateCompany[]  $candidates
     */
    public function match(Movement $movement, array $candidates): MatchResult
    {
        // Only money coming in can settle a subscription.
        if ($movement->direction !== Movement::CREDIT) {
            return new MatchResult([], false);
        }

        $scored = [];

        foreach ($candidates as $candidate) {
            $result = $this->score($movement, $candidate);

            if ($result->score >= $this->suggestThreshold) {
                $scored[] = $result;
            }
        }

        usort($scored, function (ScoredCandidate $a, ScoredCandidate $b) {
            return $b->score <=> $a->score;
        });

        return new MatchResult($scored, $this->isHighConfidence($scored), $this->isAmbiguous($scored));
    }

    /**
     * Two candidates within the tie margin of each other. Reported
     * separately from confidence so the review queue can say "there is
     * another company just as likely" rather than the vaguer "this match
     * is weak".
     *
     * @param  ScoredCandidate[]  $scored
     */
    private function isAmbiguous(array $scored): bool
    {
        if (!isset($scored[1])) {
            return false;
        }

        return ($scored[0]->score - $scored[1]->score) <= $this->tieMargin;
    }

    public function score(Movement $movement, CandidateCompany $candidate): ScoredCandidate
    {
        $score = 0;
        $reasons = [];
        $signals = [];

        if ($movement->counterpartyRut !== null && $movement->counterpartyRut === $candidate->rut) {
            $score += $this->weight('rut_in_glosa');
            $reasons[] = 'RUT en la glosa';
            $signals[] = 'rut_in_glosa';
        }

        if ($candidate->chargeAmount !== null && $candidate->chargeAmount > 0) {
            if ($movement->amount === $candidate->chargeAmount) {
                $score += $this->weight('amount_exact');
                $reasons[] = 'monto exacto';
                $signals[] = 'amount_exact';
            } elseif ($this->amountIsClose($movement->amount, $candidate->chargeAmount)) {
                $score += $this->weight('amount_close');
                $reasons[] = 'monto aproximado';
                $signals[] = 'amount_close';
            }
        }

        if ($this->dateIsInWindow($movement, $candidate)) {
            $score += $this->weight('date_window');
            $reasons[] = 'fecha cercana al vencimiento';
            $signals[] = 'date_window';
        }

        $nameScore = $this->nameScore($movement->description, $candidate->name);

        if ($nameScore > 0) {
            $score += $nameScore;
            $reasons[] = 'nombre en la glosa';
            $signals[] = 'name_tokens';
        }

        return new ScoredCandidate($candidate, $score, $reasons, $signals);
    }

    /**
     * The top candidate may only be pre-selected when it is both strong
     * enough on its own and clearly ahead of the runner-up. Two companies
     * on the same plan produce identical amount and date signals, and
     * silently picking one of them would extend the wrong subscription.
     *
     * @param  ScoredCandidate[]  $scored
     */
    private function isHighConfidence(array $scored): bool
    {
        if ($scored === [] || $scored[0]->score < $this->highConfidenceThreshold) {
            return false;
        }

        if (!isset($scored[1])) {
            return true;
        }

        return ($scored[0]->score - $scored[1]->score) > $this->tieMargin;
    }

    private function amountIsClose(int $paid, int $expected): bool
    {
        $tolerance = max(
            $this->amountToleranceClp,
            (int) round($expected * $this->amountTolerancePct / 100)
        );

        return abs($paid - $expected) <= $tolerance;
    }

    private function dateIsInWindow(Movement $movement, CandidateCompany $candidate): bool
    {
        if ($candidate->dueDate === null) {
            return false;
        }

        $from = $candidate->dueDate->copy()->subDays($this->windowBeforeDays)->startOfDay();
        $to = $candidate->dueDate->copy()->addDays($this->windowAfterDays)->endOfDay();

        return $movement->postedAt->between($from, $to);
    }

    /**
     * Proportion of the company's distinctive name words present in the
     * glosa, scaled to the signal's weight. Partial credit matters here:
     * banks truncate the glosa, so "COMERCIAL ANDES SPA" often arrives as
     * "TRANSF COMERCIAL AND".
     */
    private function nameScore(string $description, string $name): int
    {
        $nameTokens = Text::tokens($name);

        if ($nameTokens === []) {
            return 0;
        }

        $glosaTokens = Text::tokens($description);
        $hits = count(array_intersect($nameTokens, $glosaTokens));

        if ($hits === 0) {
            return 0;
        }

        return (int) round($this->weight('name_tokens') * $hits / count($nameTokens));
    }

    private function weight(string $signal): int
    {
        return (int) ($this->signals[$signal] ?? 0);
    }
}
