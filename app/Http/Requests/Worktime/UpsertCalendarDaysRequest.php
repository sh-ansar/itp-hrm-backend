<?php

namespace App\Http\Requests\Worktime;

use App\Domain\Worktime\ProductionDayType;
use App\Domain\Worktime\WorkforceAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * One atomic month-scoped calendar mutation, with explicit optimistic
 * revisions. Revision null means a day has never been stored.
 */
final class UpsertCalendarDaysRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Disabled endpoints are not advertised as operational.
        abort_unless((bool) config('hrm.worktime_writes_enabled', false), 404);

        return app(WorkforceAuthorization::class)->canEditCalendar(
            $this->user(), (string) $this->route('company'),
        );
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'days' => ['required', 'array', 'list', 'min:1', 'max:31'],
            'days.*.date' => ['required', 'date_format:Y-m-d', 'distinct:strict'],
            'days.*.day_type' => ['required', Rule::enum(ProductionDayType::class)],
            'days.*.planned_minutes' => ['present', 'nullable', 'integer', 'between:0,1440'],
            'days.*.label' => ['present', 'nullable', 'string', 'max:255'],
            'days.*.revision' => ['present', 'nullable', 'integer', 'between:1,2147483646'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $month = $this->input('month');
                $days = $this->input('days', []);
                if (! is_string($month) || ! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) || ! is_array($days)) {
                    return;
                }

                foreach ($days as $index => $day) {
                    if (! is_array($day)) {
                        continue;
                    }

                    $date = $day['date'] ?? null;
                    if (is_string($date) && substr($date, 0, 7) !== $month) {
                        $validator->errors()->add("days.{$index}.date", 'The date must belong to the selected month.');
                    }

                    $type = ProductionDayType::tryFrom(is_string($day['day_type'] ?? null) ? $day['day_type'] : '');
                    $minutes = $day['planned_minutes'] ?? null;
                    if ($type === null || ($minutes !== null && ! is_int($minutes))) {
                        continue;
                    }
                    if ($type->expectsWorkMinutes() && ($minutes === null || $minutes < 1)) {
                        $validator->errors()->add("days.{$index}.planned_minutes", 'A working day requires a positive number of planned minutes.');
                    }
                    if (! $type->expectsWorkMinutes() && $minutes !== null && $minutes !== 0) {
                        $validator->errors()->add("days.{$index}.planned_minutes", 'A non-working day cannot contain planned work minutes.');
                    }
                }
            },
        ];
    }
}
