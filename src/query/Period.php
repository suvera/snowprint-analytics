<?php
declare(strict_types=1);

namespace dev\suvera\snowprint\query;

use dev\suvera\snowprint\site\InvalidInput;

/**
 * A reporting period in the site's timezone, as a half-open UTC range
 * [start, end). Accepted forms:
 *
 *   today | yesterday | 7d | 30d | 90d | month | last_month | 12mo
 *   YYYY-MM-DD                     (one day)
 *   YYYY-MM-DD..YYYY-MM-DD         (inclusive custom range, max 400 days)
 *
 * "7d" means the last 7 days including today.
 */
final class Period {

    public const MAX_DAYS = 400;

    private function __construct(
        public readonly string $label,
        public readonly \DateTimeImmutable $start,
        public readonly \DateTimeImmutable $end,
        public readonly \DateTimeZone $timezone,
    ) {
    }

    public static function parse(string $spec, string $timezone, ?\DateTimeImmutable $now = null): self {
        $tz = new \DateTimeZone($timezone);
        $today = ($now ?? new \DateTimeImmutable('now'))->setTimezone($tz)->setTime(0, 0);
        $spec = strtolower(trim($spec));

        [$from, $toExclusive] = match (true) {
            $spec === 'today' => [$today, $today->modify('+1 day')],
            $spec === 'yesterday' => [$today->modify('-1 day'), $today],
            (bool) preg_match('/^(\d{1,3})d$/', $spec, $m) => self::lastDays($today, (int) $m[1]),
            $spec === 'month' => [$today->modify('first day of this month'), $today->modify('+1 day')],
            $spec === 'last_month' => [$today->modify('first day of last month'), $today->modify('first day of this month')],
            $spec === '12mo' => [$today->modify('first day of this month')->modify('-11 months'), $today->modify('+1 day')],
            (bool) preg_match('/^(\d{4}-\d{2}-\d{2})(?:\.\.(\d{4}-\d{2}-\d{2}))?$/', $spec, $m)
                => [self::date($m[1], $tz), self::date($m[2] ?? $m[1], $tz)->modify('+1 day')],
            default => throw new InvalidInput('unknown period "' . $spec . '"; use today, yesterday, 7d, 30d, month, '
                . 'last_month, 12mo, YYYY-MM-DD or YYYY-MM-DD..YYYY-MM-DD'),
        };
        if ($toExclusive <= $from) {
            throw new InvalidInput('period end is before its start');
        }
        if ($from->diff($toExclusive)->days > self::MAX_DAYS) {
            throw new InvalidInput('periods are limited to ' . self::MAX_DAYS . ' days');
        }
        $utc = new \DateTimeZone('UTC');
        return new self($spec, $from->setTimezone($utc), $toExclusive->setTimezone($utc), $tz);
    }

    /** The period of equal length immediately before this one. */
    public function previous(): self {
        $seconds = $this->end->getTimestamp() - $this->start->getTimestamp();
        return new self(
            'previous ' . $this->label,
            $this->start->modify("-$seconds seconds"),
            $this->start,
            $this->timezone,
        );
    }

    public function days(): int {
        return (int) ceil(($this->end->getTimestamp() - $this->start->getTimestamp()) / 86400);
    }

    /** Sensible time-series bucket: hour for up to 2 days, month beyond 90 days, else day. */
    public function defaultInterval(): string {
        $days = $this->days();
        return $days <= 2 ? 'hour' : ($days > 90 ? 'month' : 'day');
    }

    /** @return array{from: string, to: string, timezone: string} inclusive local dates */
    public function describe(): array {
        return [
            'from' => $this->start->setTimezone($this->timezone)->format('Y-m-d'),
            'to' => $this->end->setTimezone($this->timezone)->modify('-1 second')->format('Y-m-d'),
            'timezone' => $this->timezone->getName(),
        ];
    }

    /** @return array{\DateTimeImmutable, \DateTimeImmutable} */
    private static function lastDays(\DateTimeImmutable $today, int $days): array {
        if ($days < 1) {
            throw new InvalidInput('period must cover at least one day');
        }
        return [$today->modify('-' . ($days - 1) . ' days'), $today->modify('+1 day')];
    }

    private static function date(string $value, \DateTimeZone $tz): \DateTimeImmutable {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value, $tz);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidInput('invalid date ' . $value);
        }
        return $date;
    }
}
