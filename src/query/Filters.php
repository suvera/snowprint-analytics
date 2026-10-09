<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

use dev\suvera\snowprint\site\InvalidInput;

/**
 * Exact-match filters on report dimensions, ANDed together. "page" values may
 * use * as a wildcard ("/blog/*"); "source" = "Direct / None" matches traffic
 * without a source.
 */
final class Filters {

    /** @param array<string, string> $filters dimension => value */
    private function __construct(public readonly array $filters) {
    }

    /** @param array<string, mixed>|null $input */
    public static function of(?array $input): self {
        $filters = [];
        foreach ($input ?? [] as $dimension => $value) {
            if (!isset(Dimensions::COLUMNS[$dimension])) {
                throw new InvalidInput('unknown filter "' . $dimension . '"; use one of '
                    . implode(', ', array_keys(Dimensions::COLUMNS)));
            }
            if (!is_scalar($value) || trim((string) $value) === '') {
                throw new InvalidInput('filter "' . $dimension . '" needs a value');
            }
            $filters[$dimension] = mb_substr(trim((string) $value), 0, 2048);
        }
        ksort($filters);
        return new self($filters);
    }

    public static function none(): self {
        return new self([]);
    }

    public function isEmpty(): bool {
        return $this->filters === [];
    }

    /** @return array{0: string, 1: list<string>} SQL condition (TRUE when empty) and binds */
    public function toSql(): array {
        $conditions = [];
        $binds = [];
        foreach ($this->filters as $dimension => $value) {
            $column = Dimensions::COLUMNS[$dimension];
            if ($dimension === 'source' && $value === Dimensions::DIRECT) {
                $conditions[] = "$column IS NULL";
            } elseif ($dimension === 'page' && str_contains($value, '*')) {
                $conditions[] = "$column LIKE ?";
                $binds[] = str_replace('*', '%', addcslashes($value, '%_\\'));
            } else {
                $conditions[] = "$column = ?";
                $binds[] = $value;
            }
        }
        return [$conditions === [] ? 'TRUE' : implode(' AND ', $conditions), $binds];
    }
}
