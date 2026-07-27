<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Match scoring
    |--------------------------------------------------------------------------
    |
    | A bank movement is scored against every candidate company. The signals
    | and their weights live in `signals`; the thresholds decide what happens
    | to the result:
    |
    |   >= high_confidence : offered and pre-selected for bulk confirmation
    |   >= suggest         : offered, but the staff member must tick it
    |   <  suggest         : left unmatched
    |
    | Nothing is ever applied without a staff click, whatever the score.
    |
    */

    'suggest_threshold' => env('BANK_RECON_SUGGEST_THRESHOLD', 40),
    'high_confidence_threshold' => env('BANK_RECON_HIGH_THRESHOLD', 80),

    /*
    | When the two best candidates are within this many points of each other
    | the match is ambiguous, so neither is pre-selected — both are shown.
    */
    'tie_margin' => env('BANK_RECON_TIE_MARGIN', 10),

    'signals' => [
        'rut_in_glosa' => 50,
        'amount_exact' => 30,
        'amount_close' => 15,
        'date_window' => 15,
        'name_tokens' => 10,
    ],

    /*
    | Tolerance for the "close enough" amount signal. A transfer often
    | arrives a few pesos off the plan price (rounding, or the payer adding
    | a reference amount), so an exact-only rule misses real payments.
    */
    'amount_tolerance_pct' => env('BANK_RECON_AMOUNT_TOLERANCE_PCT', 2),
    'amount_tolerance_clp' => env('BANK_RECON_AMOUNT_TOLERANCE_CLP', 1000),

    /*
    | How far around the due date a deposit still counts as paying it:
    | `date_window_before_days` early (defaults to the due-soon banding) and
    | `date_window_after_days` late.
    */
    'date_window_before_days' => env('BANK_RECON_WINDOW_BEFORE_DAYS', 5),
    'date_window_after_days' => env('BANK_RECON_WINDOW_AFTER_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Transbank settlement
    |--------------------------------------------------------------------------
    |
    | Transbank deposits the day's Webpay takings a few business days later,
    | net of commission. Reconciliation compares each such deposit against
    | the sum of the Webpay payments recorded for the corresponding day.
    |
    */

    'transbank_glosa_patterns' => ['TRANSBANK', 'TBK', 'REDBANC'],
    'settlement_lag_days' => env('BANK_RECON_SETTLEMENT_LAG_DAYS', 2),
    'transbank_commission_pct' => env('BANK_RECON_TBK_COMMISSION_PCT', 0),
    'settlement_tolerance_clp' => env('BANK_RECON_TOLERANCE_CLP', 100),

    /*
    |--------------------------------------------------------------------------
    | Cartola layouts
    |--------------------------------------------------------------------------
    |
    | No Chilean bank publishes an account API, so movements come from the
    | CSV export the user downloads from the bank's portal — and every bank
    | names its columns differently. Each entry below maps a bank's header
    | labels onto the fields the importer needs, so supporting one more bank
    | is a config change, not a code change.
    |
    | `date`, `description` and `reference` name a single column each.
    | Amounts come either as two columns (`credit` + `debit`) or as one
    | signed column (`amount`). Matching is accent- and case-insensitive and
    | succeeds on a partial label, so "Fecha" matches "Fecha Movimiento".
    |
    */

    'banks' => [

        'chile' => [
            'label' => 'Banco de Chile',
            'columns' => [
                'date' => ['fecha'],
                'description' => ['descripcion', 'detalle'],
                'reference' => ['n documento', 'documento', 'numero documento'],
                'credit' => ['abonos', 'abono'],
                'debit' => ['cargos', 'cargo'],
            ],
        ],

        'santander' => [
            'label' => 'Santander',
            'columns' => [
                'date' => ['fecha'],
                'description' => ['detalle', 'descripcion movimiento'],
                'reference' => ['n documento', 'documento'],
                'credit' => ['abono', 'abonos'],
                'debit' => ['cargo', 'cargos'],
            ],
        ],

        'bci' => [
            'label' => 'BCI',
            'columns' => [
                'date' => ['fecha transaccion', 'fecha'],
                'description' => ['descripcion', 'transaccion'],
                'reference' => ['n documento', 'documento'],
                'credit' => ['abonos', 'abono'],
                'debit' => ['cargos', 'cargo'],
            ],
        ],

        'estado' => [
            'label' => 'BancoEstado',
            'columns' => [
                'date' => ['fecha'],
                'description' => ['descripcion', 'glosa'],
                'reference' => ['n operacion', 'operacion', 'documento'],
                'credit' => ['abono', 'abonos'],
                'debit' => ['cargo', 'cargos'],
            ],
        ],

        'generic' => [
            'label' => 'Otro banco (CSV genérico)',
            'columns' => [
                'date' => ['fecha', 'date'],
                'description' => ['descripcion', 'detalle', 'glosa', 'description'],
                'reference' => ['documento', 'operacion', 'referencia', 'reference'],
                'credit' => ['abono', 'abonos', 'credito', 'credit'],
                'debit' => ['cargo', 'cargos', 'debito', 'debit'],
                'amount' => ['monto', 'importe', 'amount'],
            ],
        ],

    ],

];
