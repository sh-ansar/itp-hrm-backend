<?php

namespace App\Http\Requests\Worktime;

use App\Domain\Worktime\WorkforceAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class UpsertTimesheetEntriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.worktime_writes_enabled', false), 404);

        return app(WorkforceAuthorization::class)->canEditTimesheet(
            $this->user(), (string) $this->route('company'),
        );
    }

    public function rules(): array
    {
        return [
            'month' => ['required', 'date_format:Y-m'],
            'days' => ['required', 'array', 'min:1', 'max:31'],
            'days.*.date' => ['required', 'date_format:Y-m-d'],
            'days.*.worked_minutes' => ['present', 'nullable', 'integer', 'between:0,1440'],
            'days.*.absence_minutes' => ['present', 'nullable', 'integer', 'between:0,1440'],
            'days.*.overtime_minutes' => ['present', 'nullable', 'integer', 'between:0,1440'],
            'days.*.revision' => ['present', 'nullable', 'integer', 'between:1,2147483646'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $month = (string) $this->input('month');
            $days = $this->input('days', []);
            $seen = [];

            foreach ($days as $index => $day) {
                $date = (string) ($day['date'] ?? '');
                if (! str_starts_with($date, $month.'-')) {
                    $validator->errors()->add("days.{$index}.date", 'Date must belong to the selected month.');
                }
                if (isset($seen[$date])) {
                    $validator->errors()->add("days.{$index}.date", 'Duplicate timesheet date.');
                }
                $seen[$date] = true;

                $worked = (int) ($day['worked_minutes'] ?? 0);
                $absence = (int) ($day['absence_minutes'] ?? 0);
                if ($worked + $absence > 1440) {
                    $validator->errors()->add("days.{$index}.worked_minutes", 'Worked and absence minutes cannot exceed one day.');
                }
            }
        }];
    }
}
