<?php

namespace App\Domain\Worktime;

/**
 * Calendar day classification is company-specific; never infer public holidays
 * from server timezone or a user's browser.
 */
enum ProductionDayType: string
{
    case Workday = 'workday';
    case Weekend = 'weekend';
    case Holiday = 'holiday';
    case Shortened = 'shortened';

    public function expectsWorkMinutes(): bool
    {
        return in_array($this, [self::Workday, self::Shortened], true);
    }
}
