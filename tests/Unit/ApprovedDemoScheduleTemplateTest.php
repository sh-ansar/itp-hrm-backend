<?php

namespace Tests\Unit;

use App\Console\Commands\PrepareApprovedDemoWorktime2026;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class ApprovedDemoScheduleTemplateTest extends TestCase
{
    private function template(string $code, string $type, int $minutes): array
    {
        $command = new PrepareApprovedDemoWorktime2026();
        $method = new ReflectionMethod(PrepareApprovedDemoWorktime2026::class, 'template');
        return $method->invoke($command, (object) [
            'code'=>$code, 'schedule_type'=>$type, 'weekly_minutes'=>$minutes,
        ]);
    }

    public function test_office_templates_are_weekday_only_and_8_hours(): void
    {
        foreach (['STD-5X2', 'demo26_office'] as $code) {
            [$policy, $days] = $this->template($code, 'standard', 2400);
            self::assertSame('company', $policy);
            self::assertSame([480,480,480,480,480,0,0], array_column($days,'minutes'));
            self::assertCount(7, $days);
        }
    }

    public function test_two_on_two_off_rotation_matches_42_hour_average(): void
    {
        [$policy, $days] = $this->template('SHIFT-2X2','shift',2520);
        self::assertSame('cycle',$policy);
        self::assertSame([720,720,0,0],array_column($days,'minutes'));
        self::assertSame(['D','D',null,null],array_column($days,'shift_code'));
        self::assertSame(42.0, array_sum(array_column($days,'minutes'))/4*7/60);
    }

    public function test_unexpected_schedule_or_weekly_minutes_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->template('SHIFT-2X2','shift',2400);
    }
}
