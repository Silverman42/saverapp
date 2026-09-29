<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

class CollectionReceivedTime
{
    /** @return array<int, array{offset: string, received_at_utc: string}> */
    public function options(string $date, string $time, string $timezone): array
    {
        $utc = new DateTimeZone('UTC');
        $wallTime = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$time, $utc);
        if ($wallTime === false || $wallTime->format('Y-m-d H:i') !== $date.' '.$time) {
            throw ValidationException::withMessages(['received_local_time' => ['Choose a valid local date and time.']]);
        }

        $zone = new DateTimeZone($timezone);
        $naiveTimestamp = $wallTime->getTimestamp();
        $offsets = [];
        foreach ([-172800, 0, 172800] as $delta) {
            $instant = $wallTime->modify(($delta < 0 ? '' : '+').$delta.' seconds');
            $offsets[] = $zone->getOffset($instant);
        }
        foreach ($zone->getTransitions($naiveTimestamp - 172800, $naiveTimestamp + 172800) ?: [] as $transition) {
            $offsets[] = $transition['offset'];
        }

        $options = [];
        foreach (array_unique($offsets) as $offset) {
            $instant = $wallTime->modify(($offset > 0 ? '-' : '+').abs($offset).' seconds');
            if ($instant->setTimezone($zone)->format('Y-m-d H:i') !== $date.' '.$time) {
                continue;
            }
            $options[] = [
                'offset' => $this->formatOffset($offset),
                'received_at_utc' => $instant->format('Y-m-d\TH:i:s\Z'),
            ];
        }
        usort($options, static fn (array $left, array $right): int => strcmp($left['received_at_utc'], $right['received_at_utc']));

        return $options;
    }

    public function resolve(string $date, string $time, string $timezone, string $offset): CarbonImmutable
    {
        foreach ($this->options($date, $time, $timezone) as $option) {
            if ($option['offset'] === $offset) {
                return CarbonImmutable::parse($option['received_at_utc'], 'UTC');
            }
        }

        throw ValidationException::withMessages(['received_utc_offset' => ['Choose a valid offset for the received local time.']]);
    }

    private function formatOffset(int $seconds): string
    {
        $minutes = intdiv(abs($seconds), 60);

        return sprintf('%s%02d:%02d', $seconds < 0 ? '-' : '+', intdiv($minutes, 60), $minutes % 60);
    }
}
