<?php

namespace App\Support\Cartola;

/**
 * Turns an uploaded cartola CSV into {@see Movement} objects.
 *
 * No Chilean bank publishes an account API, so the input is whatever the
 * user downloaded from their bank's portal. Rather than a class per bank,
 * the differences that actually matter — which column is the date, which
 * is the amount — are declared in `config/bank_reconciliation.php`, so
 * supporting one more bank is a config entry.
 *
 * The reader is tolerant by design: it finds the header row wherever it
 * is (banks print account details above it), accepts `;` or `,` or tab
 * separators, repairs Latin-1 encoded exports, and silently drops lines
 * whose date does not parse, which is how totals and footers are skipped.
 */
class CartolaReader
{
    /** How far into the file to look for the header row. */
    private const MAX_HEADER_SCAN_ROWS = 25;

    /** @var array<string,array> The `banks` section of the config. */
    private $banks;

    public function __construct(array $banks)
    {
        $this->banks = $banks;
    }

    public static function fromConfig(): self
    {
        return new self((array) config('bank_reconciliation.banks', []));
    }

    /**
     * Best guess at which configured bank an export came from: the layout
     * that resolves the most columns. Returns null when none of them can
     * even find a date and a description, which means the file isn't a
     * cartola we can read.
     */
    public function detect(string $path): ?string
    {
        $rows = $this->rows($path);
        $best = null;
        $bestScore = 0;

        foreach ($this->banks as $bank => $layout) {
            $header = $this->findHeader($rows, (array) $layout['columns']);

            if ($header === null) {
                continue;
            }

            $score = $this->resolvedFieldCount($rows[$header], (array) $layout['columns']);

            if ($score > $bestScore) {
                $best = $bank;
                $bestScore = $score;
            }
        }

        return $best;
    }

    /**
     * @return Movement[]
     *
     * @throws CartolaException when the file has no readable header row.
     */
    public function read(string $path, string $bank): array
    {
        if (!isset($this->banks[$bank])) {
            throw new CartolaException('El banco seleccionado no está configurado.');
        }

        $columns = (array) $this->banks[$bank]['columns'];
        $rows = $this->rows($path);
        $headerIndex = $this->findHeader($rows, $columns);

        if ($headerIndex === null) {
            throw new CartolaException(
                'No se reconocieron las columnas de la cartola. Verifique que el archivo '
                . 'corresponda al banco seleccionado y que conserve su fila de encabezados.'
            );
        }

        $map = new ColumnMap($rows[$headerIndex], $columns);
        $movements = [];

        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            $movement = $this->toMovement($row, $map);

            if ($movement !== null) {
                $movements[] = $movement;
            }
        }

        if ($movements === []) {
            throw new CartolaException('La cartola no contiene movimientos legibles.');
        }

        return $movements;
    }

    /**
     * A row becomes a movement only if it has a real date and a non-zero
     * amount. Everything else — blank spacers, subtotals, the "saldo
     * final" footer — fails one of those and is dropped.
     */
    private function toMovement(array $row, ColumnMap $map): ?Movement
    {
        $postedAt = Dates::parse($map->value($row, 'date'));

        if ($postedAt === null) {
            return null;
        }

        [$amount, $direction] = $this->amountAndDirection($row, $map);

        if ($amount === null || $amount === 0) {
            return null;
        }

        $description = (string) $map->value($row, 'description');
        $reference = $map->value($row, 'reference');

        return new Movement(
            $postedAt,
            mb_substr($description, 0, 500),
            $reference === '' ? null : mb_substr((string) $reference, 0, 100),
            $amount,
            $direction,
            Glosa::extractRut($description)
        );
    }

    /**
     * Two shapes are in the wild: separate Cargo/Abono columns, or one
     * signed column. Both collapse to a positive amount plus a direction.
     *
     * @return array{0: int|null, 1: string}
     */
    private function amountAndDirection(array $row, ColumnMap $map): array
    {
        $credit = Money::parseClp($map->value($row, 'credit'));
        $debit = Money::parseClp($map->value($row, 'debit'));

        if ($credit !== null && $credit !== 0) {
            return [abs($credit), Movement::CREDIT];
        }

        if ($debit !== null && $debit !== 0) {
            return [abs($debit), Movement::DEBIT];
        }

        $amount = Money::parseClp($map->value($row, 'amount'));

        if ($amount === null || $amount === 0) {
            return [null, Movement::CREDIT];
        }

        return [abs($amount), $amount > 0 ? Movement::CREDIT : Movement::DEBIT];
    }

    /**
     * The first row within the scan window that resolves enough columns
     * to describe a movement.
     */
    private function findHeader(array $rows, array $columns): ?int
    {
        foreach (array_slice($rows, 0, self::MAX_HEADER_SCAN_ROWS, true) as $index => $row) {
            if ((new ColumnMap($row, $columns))->isUsable()) {
                return $index;
            }
        }

        return null;
    }

    private function resolvedFieldCount(array $header, array $columns): int
    {
        $map = new ColumnMap($header, $columns);
        $count = 0;

        foreach (array_keys($columns) as $field) {
            if ($map->has($field)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * The whole file as rows of UTF-8 strings.
     *
     * @return array<int,string[]>
     */
    private function rows(string $path): array
    {
        if (!is_readable($path)) {
            throw new CartolaException('No se pudo leer el archivo de la cartola.');
        }

        $delimiter = $this->detectDelimiter($path);
        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new CartolaException('No se pudo abrir el archivo de la cartola.');
        }

        $rows = [];

        try {
            // The escape character is passed explicitly: leaving it to the
            // default is deprecated from PHP 8.4 on, and an empty escape
            // is plain RFC 4180 quoting, which is what banks emit.
            while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
                // fgetcsv reports a blank line as [null].
                if ($row === [null]) {
                    $rows[] = [];
                    continue;
                }

                $rows[] = array_map([$this, 'toUtf8'], $row);
            }
        } finally {
            fclose($handle);
        }

        if ($rows !== []) {
            $rows[0] = $this->stripBom($rows[0]);
        }

        return $rows;
    }

    /**
     * Chilean exports usually use `;`, because the comma is the decimal
     * mark. Guessing from the most-repeated candidate on the first
     * substantial line is more reliable than assuming either.
     */
    private function detectDelimiter(string $path): string
    {
        $handle = fopen($path, 'r');

        if ($handle === false) {
            return ';';
        }

        $counts = [';' => 0, ',' => 0, "\t" => 0, '|' => 0];

        try {
            for ($i = 0; $i < self::MAX_HEADER_SCAN_ROWS; $i++) {
                $line = fgets($handle);

                if ($line === false) {
                    break;
                }

                foreach ($counts as $candidate => $_) {
                    $counts[$candidate] += substr_count($line, $candidate);
                }
            }
        } finally {
            fclose($handle);
        }

        arsort($counts);
        $best = array_key_first($counts);

        return $counts[$best] > 0 ? $best : ';';
    }

    private function toUtf8(?string $value): string
    {
        $value = (string) $value;

        if (mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * @param  string[]  $row
     * @return string[]
     */
    private function stripBom(array $row): array
    {
        if (isset($row[0])) {
            $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        }

        return $row;
    }
}
