<?php

namespace App\Domain\Personnel;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validates one proposed exclusive primary assignment against existing intervals.
 * Inclusive dates; a next assignment may start the day after the prior end.
 * This pure policy is NOT a substitute for transactional write locks/DB constraints.
 */
final class AssignmentPeriodPolicy
{
    public function assertNoOverlap(string $start, ?string $end, iterable $existing): void
    {
        $startDate = $this->date($start);
        $endDate = $end === null ? null : $this->date($end);

        if ($endDate !== null && $endDate < $startDate) {
            throw new InvalidArgumentException('Assignment end precedes start.');
        }

        foreach ($existing as $period) {
            if (!is_array($period) || !array_key_exists('valid_from', $period)) {
                throw new InvalidArgumentException('Invalid existing assignment interval.');
            }
            $otherStart = $this->date((string) $period['valid_from']);
            $otherEnd = isset($period['valid_to']) ? $this->date((string) $period['valid_to']) : null;
            if ($otherEnd !== null && $otherEnd < $otherStart) {
                throw new InvalidArgumentException('Invalid existing assignment interval.');
            }
            if (($otherEnd === null || $startDate <= $otherEnd)
                && ($endDate === null || $otherStart <= $endDate)) {
                throw new InvalidArgumentException('Primary assignment periods overlap.');
            }
        }
    }

    private function date(string $input): DateTimeImmutable
    {
        $value = DateTimeImmutable::createFromFormat('!Y-m-d', $input);
        if ($value === false || $value->format('Y-m-d') !== $input) {
            throw new InvalidArgumentException('Expected valid ISO calendar date.');
        }
        return $value;
    }
}
