<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductionCalendarWriteTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $role = 'company_admin'): array
    {
        $user = User::factory()->create();
        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Test Company']);
        DB::table('company_user_roles')->insert([
            'user_id' => $user->id, 'company_id' => $company, 'role_code' => $role,
        ]);
        return [$user, $company, "/api/v1/companies/{$company}/worktime/calendar"];
    }

    private function day(array $overrides = []): array
    {
        return array_merge([
            'date' => '2026-10-15',
            'day_type' => 'workday',
            'planned_minutes' => 480,
            'label' => null,
            'revision' => null,
        ], $overrides);
    }

    public function test_calendar_writes_are_default_off_even_for_company_admin(): void
    {
        [$user, , $url] = $this->fixture();
        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10', 'days' => [$this->day()],
        ])->assertNotFound();
        $this->assertDatabaseCount('production_calendar_days', 0);
    }

    public function test_only_company_admin_may_update_calendar_in_their_company(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$hr, $company, $url] = $this->fixture('hr_specialist');
        $this->actingAs($hr)->putJson($url, [
            'month' => '2026-10', 'days' => [$this->day()],
        ])->assertForbidden();
        $this->actingAs($hr)->getJson($url.'?month=2026-10')
            ->assertOk()->assertJsonPath('meta.can_edit_calendar', false);

        [$admin, $otherCompany, $otherUrl] = $this->fixture();
        $this->actingAs($admin)->putJson($url, [
            'month' => '2026-10', 'days' => [$this->day()],
        ])->assertForbidden();
        $this->actingAs($admin)->putJson($otherUrl, [
            'month' => '2026-10', 'days' => [$this->day()],
        ])->assertOk();
        $this->actingAs($admin)->getJson($otherUrl.'?month=2026-10')
            ->assertOk()->assertJsonPath('meta.can_edit_calendar', true);
        $this->assertDatabaseHas('production_calendar_days', [
            'company_id' => $otherCompany, 'work_date' => '2026-10-15',
        ]);
        $this->assertDatabaseMissing('production_calendar_days', [
            'company_id' => $company, 'work_date' => '2026-10-15',
        ]);
    }

    public function test_month_batch_create_update_and_audit_are_atomic(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, $company, $url] = $this->fixture();
        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [
                $this->day(),
                $this->day([
                    'date' => '2026-10-16', 'day_type' => 'holiday',
                    'planned_minutes' => 0, 'label' => 'Company holiday',
                ]),
            ],
        ])->assertOk()
            ->assertJsonPath('meta.created_count', 2)
            ->assertJsonPath('meta.updated_count', 0)
            ->assertJsonPath('data.0.revision', 1)
            ->assertJsonPath('data.1.day_type', 'holiday');

        $this->actingAs($user)->getJson($url.'?month=2026-10')
            ->assertOk()->assertJsonPath('meta.recorded_days', 2)
            ->assertJsonPath('meta.calendar_complete', false);

        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [
                $this->day(['revision' => 1, 'day_type' => 'shortened', 'planned_minutes' => 360]),
                $this->day(['date' => '2026-10-17', 'day_type' => 'weekend', 'planned_minutes' => null]),
            ],
        ])->assertOk()
            ->assertJsonPath('meta.created_count', 1)
            ->assertJsonPath('meta.updated_count', 1)
            ->assertJsonPath('data.0.revision', 2);

        $this->assertDatabaseHas('production_calendar_days', [
            'company_id' => $company, 'work_date' => '2026-10-15',
            'day_type' => 'shortened', 'planned_minutes' => 360, 'revision' => 2,
        ]);
        $this->assertSame(2, DB::table('hr_audit_events')
            ->where('company_id', $company)
            ->where('action', 'worktime.calendar_days_upserted')->count());

        // Both entries are validated before any changes are committed.
        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10',
            'days' => [
                $this->day(['date' => '2026-10-18', 'day_type' => 'weekend', 'planned_minutes' => 0]),
                $this->day(['revision' => 1, 'planned_minutes' => 300]),
            ],
        ])->assertStatus(409);

        $this->assertDatabaseMissing('production_calendar_days', ['work_date' => '2026-10-18']);
        $this->assertDatabaseHas('production_calendar_days', [
            'work_date' => '2026-10-15', 'planned_minutes' => 360, 'revision' => 2,
        ]);
        $this->assertSame(2, DB::table('hr_audit_events')
            ->where('action', 'worktime.calendar_days_upserted')->count());
    }

    public function test_invalid_dates_minutes_types_and_duplicates_are_rejected(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, , $url] = $this->fixture();
        foreach ([
            [$this->day(['date' => '2026-11-01'])],
            [$this->day(['day_type' => 'public_holiday'])],
            [$this->day(['day_type' => 'holiday', 'planned_minutes' => 480])],
            [$this->day(['day_type' => 'workday', 'planned_minutes' => 0])],
            [$this->day(), $this->day()],
        ] as $days) {
            $this->actingAs($user)->putJson($url, [
                'month' => '2026-10', 'days' => $days,
            ])->assertUnprocessable();
        }
        $this->assertDatabaseCount('production_calendar_days', 0);
    }

    public function test_updating_missing_day_with_non_null_revision_is_conflict(): void
    {
        config()->set('hrm.worktime_writes_enabled', true);
        [$user, , $url] = $this->fixture();
        $this->actingAs($user)->putJson($url, [
            'month' => '2026-10', 'days' => [$this->day(['revision' => 7])],
        ])->assertStatus(409);
    }
}
