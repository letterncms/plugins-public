<?php

declare(strict_types=1);

namespace Cms\Analytics;

use Carbon\CarbonImmutable;
use Cms\Abilities\AbilityException;

/**
 * The time a report is about, in the site's clock: a period ending now, or two dates. It knows its steps
 * (minutes, hours, days, weeks or months, by its length) and the same length just before it, to compare.
 */
final readonly class Range
{
    /** What can be asked for. `realtime` is the last half hour; the others end today, today included. */
    public const array PERIODS = ['realtime', 'today', 'yesterday', '7d', '30d', '90d', '12mo', 'custom'];

    public const array INTERVALS = ['minute', 'hour', 'day', 'week', 'month'];

    /** The longest custom range: about five years. */
    private const int MAX_DAYS = 1830;

    private function __construct(
        public string $period,
        /** First and last day, in the site's clock. */
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $interval,
        /** For `realtime`: since when, to the second (UTC). */
        public ?CarbonImmutable $since = null,
    ) {}

    public static function of(string $period, ?string $from = null, ?string $to = null, ?CarbonImmutable $now = null): self
    {
        $now = Collector::local($now);
        $today = $now->startOfDay();

        return match ($period) {
            'realtime' => new self('realtime', $today, $today, 'minute', $now->utc()->subMinutes(30)),
            'today' => new self('today', $today, $today, 'hour'),
            'yesterday' => new self('yesterday', $today->subDay(), $today->subDay(), 'hour'),
            '7d', '30d', '90d' => self::days($period, $today->subDays((int) $period - 1), $today),
            '12mo' => new self('12mo', $today->startOfMonth()->subMonths(11), $today, 'month'),
            'custom' => self::custom($from, $to, $today),
            default => throw AbilityException::invalid('The period is one of realtime, today, yesterday, 7d, 30d, 90d, 12mo or custom.'),
        };
    }

    private static function custom(?string $from, ?string $to, CarbonImmutable $today): self
    {
        $parse = static function (?string $date, string $name) use ($today): CarbonImmutable {
            if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw AbilityException::invalid('A custom period needs from and to, dates such as 2026-11-01.');
            }

            return CarbonImmutable::parse($date, $today->getTimezone())->startOfDay();
        };
        $start = $parse($from, 'from');
        $end = min($parse($to, 'to'), $today);

        if ($start > $end || $start->diffInDays($end) > self::MAX_DAYS) {
            throw AbilityException::invalid('A custom period starts on or before the day it ends, and today at the latest, and lasts five years at most.');
        }

        return self::days('custom', $start, $end);
    }

    private static function days(string $period, CarbonImmutable $from, CarbonImmutable $to): self
    {
        $length = (int) $from->diffInDays($to) + 1;

        return new self($period, $from, $to, match (true) {
            $length <= 2 => 'hour',
            $length <= 92 => 'day',
            $length <= 400 => 'week',
            default => 'month',
        });
    }

    /** The same length just before: the day before, the 30 days before, the 12 months before. */
    public function previous(): self
    {
        if ($this->since !== null) {
            return new self($this->period, $this->from, $this->to, $this->interval, $this->since->subMinutes(30));
        }

        if ($this->period === '12mo') {
            return new self($this->period, $this->from->subMonths(12), $this->from->subDay(), $this->interval);
        }

        $length = (int) $this->from->diffInDays($this->to) + 1;

        return new self($this->period, $this->from->subDays($length), $this->from->subDay(), $this->interval);
    }

    /** For `realtime`, the end of the half hour before. */
    public function until(): ?CarbonImmutable
    {
        return $this->since?->addMinutes(30);
    }

    /** @return array{0: string, 1: string} */
    public function dates(): array
    {
        return [$this->from->toDateString(), $this->to->toDateString()];
    }

    /** @return list<string> every step's key, in order: what `key()` gives a moment */
    public function steps(): array
    {
        $out = [];

        if ($this->since !== null) {
            $start = Collector::local($this->since)->startOfMinute()->addMinute();

            for ($i = 0; $i < 30; $i++) {
                $out[] = $start->addMinutes($i)->format('Y-m-d H:i');
            }

            return $out;
        }

        // Today's hours stop at this one: the rest of the day hasn't happened.
        $end = min($this->to->endOfDay(), Collector::local());
        $step = match ($this->interval) {
            'hour' => $this->from,
            'week' => $this->from->startOfWeek(CarbonImmutable::MONDAY),
            'month' => $this->from->startOfMonth(),
            default => $this->from,
        };

        while ($step <= $end) {
            $out[] = $this->key($step);
            $step = match ($this->interval) {
                'hour' => $step->addHour(),
                'week' => $step->addWeek(),
                'month' => $step->addMonth(),
                default => $step->addDay(),
            };
        }

        return $out;
    }

    /** The step a day (and hour) of the site's clock falls in. */
    public function key(CarbonImmutable|string $day, int $hour = 0): string
    {
        $at = is_string($day) ? CarbonImmutable::parse(substr($day, 0, 10), $this->from->getTimezone())->setTime($hour, 0) : $day;

        return match ($this->interval) {
            'minute' => $at->format('Y-m-d H:i'),
            'hour' => $at->format('Y-m-d H:00'),
            'week' => $at->startOfWeek(CarbonImmutable::MONDAY)->format('Y-m-d'),
            'month' => $at->format('Y-m'),
            default => $at->format('Y-m-d'),
        };
    }
}
