<?php

namespace App\Services\Imports;

use Generator;
use League\Csv\Reader;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Streams rows from CSV or XLSX files as lists of strings (first row = headers).
 *
 * CSV: detects delimiter (, ; tab) and converts Windows-1252/Latin-1 to UTF-8
 * (typical of files exported by Excel in Spanish).
 */
class SpreadsheetReader
{
    public function __construct(private readonly string $path, private readonly string $type) {}

    public static function for(string $path, string $originalName): self
    {
        $extension = mb_strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        return new self($path, $extension === 'xlsx' ? 'xlsx' : 'csv');
    }

    /**
     * @return Generator<int, list<string>> 1-based row number => cells
     */
    public function rows(): Generator
    {
        return $this->type === 'xlsx' ? $this->xlsxRows() : $this->csvRows();
    }

    /**
     * @return array{headers: list<string>, rows: list<list<string>>}
     */
    public function preview(int $limit = 10): array
    {
        $headers = [];
        $rows = [];

        foreach ($this->rows() as $number => $cells) {
            if ($number === 1) {
                $headers = $cells;

                continue;
            }

            $rows[] = $cells;

            if (count($rows) >= $limit) {
                break;
            }
        }

        if ($headers === []) {
            throw new RuntimeException('El archivo está vacío o no tiene una fila de encabezados.');
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /** Data rows (without the header). */
    public function count(): int
    {
        $count = 0;

        foreach ($this->rows() as $number => $cells) {
            if ($number > 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function csvRows(): Generator
    {
        $sample = (string) file_get_contents($this->path, length: 65536);
        $utf8 = mb_check_encoding($sample, 'UTF-8');

        $csv = Reader::createFromPath($this->path, 'r');
        $csv->setDelimiter($this->detectDelimiter($sample));
        $csv->skipInputBOM();

        $number = 0;
        foreach ($csv->getRecords() as $record) {
            $number++;
            $cells = array_map(function ($cell) use ($utf8) {
                $value = (string) $cell;

                return trim($utf8 ? $value : (string) mb_convert_encoding($value, 'UTF-8', 'Windows-1252'));
            }, array_values($record));

            if ($this->isEmpty($cells)) {
                continue;
            }

            yield $number => $cells;
        }
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function xlsxRows(): Generator
    {
        $reader = new XlsxReader;
        $reader->open($this->path);

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    // Empty cells may be missing from $row->cells: fill the holes so
                    // column indexes stay aligned with the headers.
                    $cells = [];
                    for ($i = 0, $n = $row->getNumCells(); $i < $n; $i++) {
                        $cell = $row->cells[$i] ?? null;
                        $cells[] = $cell instanceof Cell ? $this->cellToString($cell->getValue()) : '';
                    }

                    if ($this->isEmpty($cells)) {
                        continue;
                    }

                    yield $number => $cells;
                }

                break; // first sheet only
            }
        } finally {
            $reader->close();
        }
    }

    private function cellToString(mixed $value): string
    {
        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('Y-m-d'),
            is_float($value) && floor($value) === $value && abs($value) < 1e15 => (string) (int) $value,
            is_bool($value) => $value ? '1' : '0',
            is_scalar($value) => trim((string) $value),
            default => '',
        };
    }

    private function detectDelimiter(string $sample): string
    {
        $firstLine = strtok($sample, "\n") ?: '';
        $counts = [',' => substr_count($firstLine, ','), ';' => substr_count($firstLine, ';'), "\t" => substr_count($firstLine, "\t")];
        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * @param  list<string>  $cells
     */
    private function isEmpty(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== '') {
                return false;
            }
        }

        return true;
    }
}
