<?php

namespace Tests\Unit;

use App\Domain\Personnel\AssignmentPeriodPolicy;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AssignmentPeriodPolicyTest extends TestCase
{
    public function test_non_overlapping_successive_periods_are_allowed(): void
    {
        $policy = new AssignmentPeriodPolicy();
        $policy->assertNoOverlap('2026-02-01', null, [
            ['valid_from' => '2025-01-01', 'valid_to' => '2026-01-31'],
        ]);
        $policy->assertNoOverlap('2024-12-31', '2024-12-31', [
            ['valid_from' => '2025-01-01', 'valid_to' => null],
        ]);
        $this->assertTrue(true);
    }

    public function test_shared_boundary_day_is_an_overlap(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssignmentPeriodPolicy())->assertNoOverlap('2026-01-31', '2026-02-01', [
            ['valid_from' => '2025-01-01', 'valid_to' => '2026-01-31'],
        ]);
    }

    public function test_open_ended_periods_conflict(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssignmentPeriodPolicy())->assertNoOverlap('2026-03-01', null, [
            ['valid_from' => '2026-01-01', 'valid_to' => null],
        ]);
    }

    public function test_invalid_calendar_dates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssignmentPeriodPolicy())->assertNoOverlap('2026-02-30', null, []);
    }

    public function test_reversed_interval_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssignmentPeriodPolicy())->assertNoOverlap('2026-03-01', '2026-02-28', []);
    }

    public function test_reversed_legacy_interval_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new AssignmentPeriodPolicy())->assertNoOverlap('2027-01-01', null, [
            ['valid_from' => '2026-03-01', 'valid_to' => '2026-02-28'],
        ]);
    }
}
