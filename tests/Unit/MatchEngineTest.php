<?php

namespace Tests\Unit;

use App\Support\Cartola\Movement;
use App\Support\Reconciliation\CandidateCompany;
use App\Support\Reconciliation\MatchEngine;
use Carbon\Carbon;
use PHPUnit\Framework\TestCase;

/**
 * Scoring a bank deposit against the companies it might belong to.
 *
 * Every threshold is passed to the constructor so these run without the
 * Laravel container, matching the convention in PaymentStatusTest.
 */
class MatchEngineTest extends TestCase
{
    private function engine(): MatchEngine
    {
        return new MatchEngine(
            [
                'rut_in_glosa' => 50,
                'amount_exact' => 30,
                'amount_close' => 15,
                'date_window' => 15,
                'name_tokens' => 10,
            ],
            40,     // suggest
            80,     // high confidence
            10,     // tie margin
            2.0,    // amount tolerance %
            1000,   // amount tolerance CLP
            5,      // window before
            30      // window after
        );
    }

    private function movement(string $glosa, int $amount, ?string $rut = null, string $date = '2026-03-02'): Movement
    {
        return new Movement(
            Carbon::parse($date),
            $glosa,
            null,
            $amount,
            Movement::CREDIT,
            $rut
        );
    }

    private function company(int $id, string $rut, string $name, ?int $charge, ?string $due = '2026-03-01'): CandidateCompany
    {
        return new CandidateCompany(
            $id,
            $rut,
            $name,
            $charge,
            $due === null ? null : Carbon::parse($due)
        );
    }

    public function test_rut_plus_exact_amount_is_high_confidence(): void
    {
        $movement = $this->movement('TRANSFERENCIA DE 76.543.210-3 COMERCIAL ANDES', 35000, '765432103');
        $company = $this->company(1, '765432103', 'Comercial Andes SpA', 35000);

        $result = $this->engine()->match($movement, [$company]);

        // 50 (RUT) + 30 (exact) + 15 (date) + 10 (name) = 105
        $this->assertTrue($result->hasSuggestion());
        $this->assertTrue($result->isHighConfidence);
        $this->assertSame(1, $result->best()->company->id);
        $this->assertGreaterThanOrEqual(80, $result->best()->score);
    }

    /**
     * The signals are reported by key, separately from the Spanish
     * `reasons` they are shown as, because {@see \App\Services\ImportCartola}
     * decides whether to reconcile without a human from exactly these two —
     * and must not do it by reading display copy.
     */
    public function test_the_signals_that_fired_are_reported_by_key(): void
    {
        $movement = $this->movement('TRANSFERENCIA DE 76.543.210-3 COMERCIAL ANDES', 35000, '765432103');
        $best = $this->engine()->match($movement, [
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000),
        ])->best();

        $this->assertTrue($best->hasSignal('rut_in_glosa'));
        $this->assertTrue($best->hasAllSignals(['rut_in_glosa', 'amount_exact']));
        $this->assertFalse($best->hasSignal('amount_close'));

        // An empty requirement is never satisfied: the caller asking is
        // deciding whether to move money unattended.
        $this->assertFalse($best->hasAllSignals([]));
    }

    public function test_an_approximate_amount_is_not_reported_as_exact(): void
    {
        $movement = $this->movement('TRANSFERENCIA DE 76.543.210-3 COMERCIAL ANDES', 34500, '765432103');
        $best = $this->engine()->match($movement, [
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000),
        ])->best();

        $this->assertTrue($best->hasSignal('amount_close'));
        $this->assertFalse($best->hasAllSignals(['rut_in_glosa', 'amount_exact']));
    }

    /**
     * The amount alone is the weakest possible evidence: every company on
     * the same plan pays it. It must not reach the suggestion threshold
     * on its own.
     */
    public function test_a_matching_amount_alone_is_not_enough_to_suggest(): void
    {
        $movement = $this->movement('DEPOSITO EN EFECTIVO', 35000, null, '2026-06-15');
        $company = $this->company(1, '765432103', 'Comercial Andes SpA', 35000, '2026-03-01');

        $result = $this->engine()->match($movement, [$company]);

        $this->assertFalse($result->hasSuggestion());
    }

    /**
     * Two companies on the same plan produce identical amount and date
     * signals. Pre-selecting either would extend the wrong subscription,
     * so both are offered and neither is checked.
     */
    public function test_two_equally_plausible_companies_are_never_pre_selected(): void
    {
        $movement = $this->movement('TRANSFERENCIA PAGO MENSUAL', 35000);

        $result = $this->engine()->match($movement, [
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000),
            $this->company(2, '773533989', 'Comercial Andes Dos SpA', 35000),
        ]);

        $this->assertFalse($result->isHighConfidence);
        $this->assertTrue($result->isAmbiguous);
        $this->assertCount(2, $result->candidates);
    }

    /**
     * A single weak candidate is not the same problem as a tie: nothing
     * else is competing with it, it just isn't strongly evidenced.
     */
    public function test_a_lone_weak_candidate_is_not_reported_as_ambiguous(): void
    {
        $movement = $this->movement('TRANSFERENCIA PAGO MENSUAL', 35000);

        $result = $this->engine()->match($movement, [
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000),
        ]);

        $this->assertTrue($result->hasSuggestion());
        $this->assertFalse($result->isHighConfidence);
        $this->assertFalse($result->isAmbiguous);
    }

    /**
     * A RUT in the glosa still wins outright even when a second company
     * matches on amount and date, because it is the only signal the payer
     * controls deliberately.
     */
    public function test_the_rut_breaks_the_tie(): void
    {
        $movement = $this->movement('TRANSF 76.543.210-3', 35000, '765432103');

        $result = $this->engine()->match($movement, [
            $this->company(2, '773533989', 'Otra Empresa SpA', 35000),
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000),
        ]);

        $this->assertSame(1, $result->best()->company->id);
        $this->assertTrue($result->isHighConfidence);
    }

    public function test_an_amount_a_few_pesos_off_still_scores(): void
    {
        $exact = $this->engine()->score(
            $this->movement('TRANSF 76.543.210-3', 35000, '765432103'),
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000)
        );

        $close = $this->engine()->score(
            $this->movement('TRANSF 76.543.210-3', 34500, '765432103'),
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000)
        );

        $far = $this->engine()->score(
            $this->movement('TRANSF 76.543.210-3', 9000, '765432103'),
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000)
        );

        $this->assertGreaterThan($close->score, $exact->score);
        $this->assertGreaterThan($far->score, $close->score);
    }

    public function test_a_deposit_far_from_the_due_date_loses_the_date_signal(): void
    {
        $inWindow = $this->engine()->score(
            $this->movement('PAGO', 35000, null, '2026-03-02'),
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000, '2026-03-01')
        );

        $outOfWindow = $this->engine()->score(
            $this->movement('PAGO', 35000, null, '2026-09-02'),
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000, '2026-03-01')
        );

        $this->assertSame(15, $inWindow->score - $outOfWindow->score);
    }

    /**
     * "SpA" and "Ltda" appear in half the company names in Chile, so
     * matching on them would make every glosa look like every company.
     */
    public function test_the_company_form_suffix_is_not_a_name_match(): void
    {
        $result = $this->engine()->score(
            $this->movement('TRANSFERENCIA SPA LTDA', 1, null, '2026-09-09'),
            $this->company(1, '765432103', 'Comercial Andes SpA', null, null)
        );

        $this->assertSame(0, $result->score);
    }

    public function test_a_debit_is_never_matched_to_a_customer(): void
    {
        $debit = new Movement(
            Carbon::parse('2026-03-02'),
            'TRANSFERENCIA DE 76.543.210-3 COMERCIAL ANDES',
            null,
            35000,
            Movement::DEBIT,
            '765432103'
        );

        $result = $this->engine()->match($debit, [
            $this->company(1, '765432103', 'Comercial Andes SpA', 35000),
        ]);

        $this->assertFalse($result->hasSuggestion());
    }

    public function test_a_company_without_a_priced_plan_scores_no_amount_signal(): void
    {
        $result = $this->engine()->score(
            $this->movement('PAGO', 35000, null, '2026-03-02'),
            $this->company(1, '765432103', 'Empresa Sin Plan SpA', null, '2026-03-01')
        );

        // Date only.
        $this->assertSame(15, $result->score);
    }
}
