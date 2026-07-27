<?php

namespace Tests\Unit;

use App\Support\Cartola\CartolaException;
use App\Support\Cartola\CartolaReader;
use App\Support\Cartola\Dates;
use App\Support\Cartola\Glosa;
use App\Support\Cartola\Money;
use App\Support\Cartola\Movement;
use PHPUnit\Framework\TestCase;

/**
 * Reading a Chilean bank statement export. Pure parsing — no container,
 * no database — so the column layouts are passed in explicitly rather
 * than read from config(), the same way PaymentStatusTest passes its
 * thresholds.
 */
class CartolaParsingTest extends TestCase
{
    /**
     * Same shape as the `banks` section of config/bank_reconciliation.php.
     */
    private function banks(): array
    {
        return [
            'chile' => [
                'label' => 'Banco de Chile',
                'columns' => [
                    'date' => ['fecha'],
                    'description' => ['descripcion', 'detalle'],
                    'reference' => ['n documento', 'documento'],
                    'credit' => ['abonos', 'abono'],
                    'debit' => ['cargos', 'cargo'],
                ],
            ],
            'santander' => [
                'label' => 'Santander',
                'columns' => [
                    'date' => ['fecha'],
                    'description' => ['detalle'],
                    'reference' => ['n documento', 'documento'],
                    'credit' => ['abono', 'abonos'],
                    'debit' => ['cargo', 'cargos'],
                ],
            ],
        ];
    }

    private function fixture(string $name): string
    {
        return __DIR__ . '/../fixtures/cartolas/' . $name;
    }

    private function reader(): CartolaReader
    {
        return new CartolaReader($this->banks());
    }

    // ---------------------------------------------------------------
    // Amounts
    // ---------------------------------------------------------------

    public function test_chilean_thousands_dots_are_not_decimals(): void
    {
        $this->assertSame(1234567, Money::parseClp('1.234.567'));
        $this->assertSame(35000, Money::parseClp('35.000'));

        // A lone dot is a thousands separator, never cents: the peso has
        // no subunit in a bank export.
        $this->assertSame(1234, Money::parseClp('1.234'));
    }

    public function test_comma_decimals_and_currency_symbols_are_handled(): void
    {
        $this->assertSame(1234568, Money::parseClp('1.234.567,50'));
        $this->assertSame(35000, Money::parseClp('$ 35.000'));
        $this->assertSame(1234567, Money::parseClp('1,234,567.00'));
    }

    public function test_negative_amounts_in_both_notations(): void
    {
        $this->assertSame(-1234, Money::parseClp('-1.234'));
        $this->assertSame(-1234, Money::parseClp('(1.234)'));
    }

    public function test_blank_cells_are_not_zero(): void
    {
        $this->assertNull(Money::parseClp(''));
        $this->assertNull(Money::parseClp('   '));
        $this->assertNull(Money::parseClp('-'));
        $this->assertNull(Money::parseClp(null));
    }

    // ---------------------------------------------------------------
    // Dates
    // ---------------------------------------------------------------

    public function test_dates_are_day_first(): void
    {
        $this->assertSame('2026-02-01', Dates::parse('01/02/2026')->toDateString());
        $this->assertSame('2026-02-01', Dates::parse('01-02-2026')->toDateString());
        $this->assertSame('2026-03-12', Dates::parse('12/03/2026 14:05:00')->toDateString());
    }

    public function test_iso_dates_still_parse(): void
    {
        $this->assertSame('2026-03-12', Dates::parse('2026-03-12')->toDateString());
    }

    public function test_non_dates_are_rejected_rather_than_coerced(): void
    {
        $this->assertNull(Dates::parse('Saldo final'));
        $this->assertNull(Dates::parse('32/01/2026'));
        $this->assertNull(Dates::parse(''));
    }

    // ---------------------------------------------------------------
    // RUT extraction from the glosa
    // ---------------------------------------------------------------

    public function test_a_rut_in_the_glosa_is_found_and_normalized(): void
    {
        $this->assertSame(
            '765432103',
            Glosa::extractRut('TRANSFERENCIA DE 76.543.210-3 COMERCIAL ANDES SPA')
        );

        $this->assertSame('765432103', Glosa::extractRut('TRANSF DE 76543210-3'));
    }

    /**
     * Order and document numbers are RUT-shaped often enough that
     * accepting them would poison the strongest match signal.
     */
    public function test_a_rut_shaped_number_with_a_bad_check_digit_is_ignored(): void
    {
        $this->assertNull(Glosa::extractRut('FACTURA 76.543.210-9 PENDIENTE'));
        $this->assertNull(Glosa::extractRut('PAGO SIN IDENTIFICAR'));
    }

    // ---------------------------------------------------------------
    // Whole-file reading
    // ---------------------------------------------------------------

    public function test_it_reads_a_banco_de_chile_export(): void
    {
        $movements = $this->reader()->read($this->fixture('banco-chile.csv'), 'chile');

        // Five movement lines: the account preamble and the "Saldo final"
        // footer have no parseable date, so they drop out.
        $this->assertCount(5, $movements);

        $first = $movements[0];
        $this->assertSame('2026-03-02', $first->postedAt->toDateString());
        $this->assertSame(35000, $first->amount);
        $this->assertSame(Movement::CREDIT, $first->direction);
        $this->assertSame('765432103', $first->counterpartyRut);
        $this->assertSame('123456', $first->reference);
    }

    public function test_a_cargo_column_becomes_a_debit(): void
    {
        $movements = $this->reader()->read($this->fixture('banco-chile.csv'), 'chile');

        $debits = array_values(array_filter($movements, function (Movement $movement) {
            return $movement->direction === Movement::DEBIT;
        }));

        $this->assertCount(1, $debits);
        $this->assertSame(45000, $debits[0]->amount);
    }

    public function test_it_reads_a_comma_separated_export_with_a_different_layout(): void
    {
        $movements = $this->reader()->read($this->fixture('santander.csv'), 'santander');

        $this->assertCount(3, $movements);
        $this->assertSame(35000, $movements[0]->amount);
        $this->assertSame('765432103', $movements[0]->counterpartyRut);
        $this->assertSame(Movement::DEBIT, $movements[1]->direction);
    }

    public function test_the_bank_is_detected_from_the_header_row(): void
    {
        $this->assertSame('chile', $this->reader()->detect($this->fixture('banco-chile.csv')));
    }

    /**
     * The same movement re-exported in an overlapping date range must
     * fingerprint identically, or it would be offered for reconciliation
     * a second time.
     */
    public function test_the_same_movement_from_two_exports_hashes_the_same(): void
    {
        $chile = $this->reader()->read($this->fixture('banco-chile.csv'), 'chile');
        $santander = $this->reader()->read($this->fixture('santander.csv'), 'santander');

        // Same date, amount and description, different file and reference.
        $this->assertSame(
            $chile[0]->rowHash(),
            (new Movement(
                $chile[0]->postedAt,
                'transferencia de 76.543.210-3   COMERCIAL Andes SpA',
                $chile[0]->reference,
                $chile[0]->amount,
                $chile[0]->direction,
                $chile[0]->counterpartyRut
            ))->rowHash()
        );

        // A genuinely different movement does not collide.
        $this->assertNotSame($chile[0]->rowHash(), $santander[1]->rowHash());
    }

    public function test_an_unreadable_layout_is_reported_rather_than_guessed(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'cartola');
        file_put_contents($path, "algo;otra cosa\n1;2\n");

        try {
            $this->expectException(CartolaException::class);
            $this->reader()->read($path, 'chile');
        } finally {
            @unlink($path);
        }
    }
}
