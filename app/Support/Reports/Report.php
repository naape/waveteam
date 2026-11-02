<?php

namespace App\Support\Reports;

use Closure;

/**
 * One downloadable report: its columns, the filters it accepts, a summary
 * block and the rows. Rows are produced lazily so large exports stream.
 */
final class Report
{
    /**
     * @param  array<string, string>  $columns  key => heading
     * @param  array<int, string>  $filters  supported filter keys (see ReportRegistry::FILTERS)
     * @param  Closure(array): iterable<array<string, scalar|null>>  $rows
     * @param  Closure(array): int  $count
     * @param  Closure(array): array<string, scalar>|null  $summary
     */
    public function __construct(
        public readonly string $key,
        public readonly string $title,
        public readonly string $description,
        public readonly array $columns,
        public readonly array $filters,
        private readonly Closure $rows,
        private readonly Closure $count,
        private readonly ?Closure $summary = null,
        public readonly string $orientation = 'landscape',
    ) {}

    /** @return iterable<array<string, scalar|null>> */
    public function rows(array $filters): iterable
    {
        return ($this->rows)($filters);
    }

    public function count(array $filters): int
    {
        return ($this->count)($filters);
    }

    /** @return array<string, scalar> */
    public function summary(array $filters): array
    {
        return $this->summary ? ($this->summary)($filters) : [];
    }

    /** Rows as ordered value lists matching the column order (for exports). */
    public function table(array $filters): iterable
    {
        $keys = array_keys($this->columns);
        foreach ($this->rows($filters) as $row) {
            yield array_map(fn ($k) => $row[$k] ?? null, $keys);
        }
    }
}
