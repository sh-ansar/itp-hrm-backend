<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Repeatable fixture catalogue only. Never a production bootstrap or statutory policy. */
final class ItpSolutionsQaSeeder extends Seeder
{
    public static function id(string $key): string
    {
        $hex = md5('hrm-synthetic-qa-20261025:'.$key);
        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-8'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function guardTarget(): void
    {
        if (!app()->environment(['local', 'testing'])) throw new \RuntimeException('Synthetic fixtures require local/testing environment.');
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlite' && app()->environment('testing')
            && config('database.connections.sqlite.database') === ':memory:'
            && getenv('HRM_QA_SEED_TARGET') === 'sqlite-unit-tests') return;
        if ($driver !== 'pgsql' || getenv('HRM_QA_SEED_TARGET') !== 'hrm-qa-20261025'
            || config('database.connections.pgsql.host') !== '127.0.0.1'
            || (string) config('database.connections.pgsql.port') !== '55432'
            || config('database.connections.pgsql.database') !== 'hrm_qa_synthetic_20261025') {
            throw new \RuntimeException('Refusing any database except the named isolated HRM QA target.');
        }
        $row = DB::selectOne('select current_database() as db, current_user as actor, inet_server_port() as port');
        if ($row->db !== 'hrm_qa_synthetic_20261025' || $row->actor !== 'hrm_qa_20261025' || (int) $row->port !== 55432) {
            throw new \RuntimeException('Actual PostgreSQL identity differs from isolated QA.');
        }
    }

    private function insert(string $table, string $key, array $values): string
    {
        $id = self::id($key);
        DB::table($table)->insertOrIgnore(['id' => $id] + $values + ['created_at' => now(), 'updated_at' => now()]);
        return $id;
    }

    public function run(): void
    {
        $this->guardTarget();
        $password = (string) getenv('HRM_QA_ACCOUNT_PASSWORD');
        if (strlen($password) < 20) throw new \RuntimeException('Provide a private QA-only account password of at least 20 characters.');
        DB::transaction(function () use ($password): void {
            $company = $this->insert('companies', 'company', ['name' => 'ITP Solutions (Synthetic QA)']);
            DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
            $departments = [
                ['leadership', 'Руководство', null], ['hr', 'HR', 'leadership'],
                ['finance', 'Финансы', 'leadership'], ['legal', 'Юридический отдел', 'leadership'],
                ['pmo', 'PMO', 'leadership'], ['product', 'Product', 'leadership'],
                ['design', 'UX/UI Design', 'product'], ['engineering', 'Engineering', 'leadership'],
                ['frontend', 'Frontend', 'engineering'], ['backend', 'Backend', 'engineering'],
                ['qa', 'QA', 'engineering'], ['devops', 'DevOps', 'engineering'],
                ['support', 'IT Support', 'engineering'], ['sales', 'Sales', 'leadership'],
                ['marketing', 'Marketing', 'leadership'],
            ];
            foreach ($departments as [$code, $name, $parent]) {
                $this->insert('departments', 'department-'.$code, ['company_id' => $company, 'name' => $name,
                    'parent_id' => $parent ? self::id('department-'.$parent) : null]);
                foreach (['lead' => 'Руководитель', 'specialist' => 'Специалист'] as $level => $label) {
                    $this->insert('positions', 'position-'.$code.'-'.$level, ['company_id' => $company, 'name' => $label.' '.$name]);
                }
            }
            foreach ([['office', 'Стандартный 5/2', 'standard', 2400], ['flex', 'Гибкий', 'individual', 2400],
                ['part', 'Неполный рабочий день', 'individual', 1200]] as [$code, $name, $type, $minutes]) {
                $this->insert('work_schedules', 'schedule-'.$code, ['company_id' => $company, 'code' => 'qa_'.$code,
                    'name' => $name, 'schedule_type' => $type, 'weekly_minutes' => $minutes, 'revision' => 1]);
            }
            $names = ['Алексей', 'Анна', 'Данияр', 'Мария', 'Тимур', 'Елена', 'Арман', 'Ирина'];
            $surnames = ['Тестов', 'Примерова', 'Макетов', 'Демина'];
            for ($i = 0; $i < 32; $i++) {
                [$code] = $departments[$i % count($departments)];
                $employee = $this->insert('employees', 'employee-'.$i, ['company_id' => $company,
                    'personnel_number' => sprintf('QA-IT-%03d', $i + 1), 'first_name' => $names[$i % 8],
                    'last_name' => $surnames[intdiv($i, 8)], 'email' => 'qa.person'.($i + 1).'@hrm.example.test',
                    'supervisor_name' => 'QA: руководитель '.$code, 'work_site' => 'Synthetic QA office', 'revision' => 1]);
                $position = self::id('position-'.$code.'-'.($i < 15 ? 'lead' : 'specialist'));
                $assignment = $this->insert('employee_assignments', 'assignment-'.$i, ['employee_id' => $employee,
                    'department_id' => self::id('department-'.$code), 'position_id' => $position,
                    'valid_from' => '2026-01-01', 'valid_to' => in_array($i, [28, 29, 30, 31], true) ? '2026-06-30' : null,
                    'basis_reference' => 'SYNTHETIC-QA-HIRE', 'revision' => 1]);
                $this->insert('personnel_actions', 'hire-'.$i, ['company_id' => $company, 'employee_id' => $employee,
                    'action_type' => 'hire', 'effective_on' => '2026-01-01', 'basis_reference' => 'SYNTHETIC-QA-HIRE',
                    'notes' => 'Seed fixture, not a real personnel event', 'target_assignment_id' => $assignment]);
                if (in_array($i, [29, 30, 31], true)) {
                    $this->insert('personnel_actions', 'termination-'.$i, ['company_id' => $company, 'employee_id' => $employee,
                        'action_type' => 'termination', 'effective_on' => '2026-06-30', 'basis_reference' => 'SYNTHETIC-QA-TERMINATION',
                        'source_assignment_id' => $assignment]);
                }
                if (in_array($i, [28, 31], true)) {
                    $next = $this->insert('employee_assignments', 'assignment-next-'.$i, ['employee_id' => $employee,
                        'department_id' => self::id('department-frontend'), 'position_id' => self::id('position-frontend-specialist'),
                        'valid_from' => $i === 28 ? '2026-07-01' : '2026-09-01', 'basis_reference' => 'SYNTHETIC-QA-HISTORY', 'revision' => 1]);
                    $this->insert('personnel_actions', 'next-action-'.$i, ['company_id' => $company, 'employee_id' => $employee,
                        'action_type' => $i === 28 ? 'transfer' : 'hire', 'effective_on' => $i === 28 ? '2026-07-01' : '2026-09-01',
                        'basis_reference' => 'SYNTHETIC-QA-HISTORY', 'source_assignment_id' => $i === 28 ? $assignment : null,
                        'target_assignment_id' => $next]);
                }
                $this->insert('employee_work_schedules', 'employee-schedule-'.$i, ['employee_id' => $employee,
                    'work_schedule_id' => self::id('schedule-'.(['office', 'flex', 'part'][$i % 3])),
                    'valid_from' => '2026-01-01', 'revision' => 1]);
                if ($i < 4) $this->insert('employee_training', 'training-'.$i, ['employee_id' => $employee,
                    'training_type' => 'seminar', 'training_mode' => 'remote', 'topic' => 'Synthetic QA onboarding '.($i + 1),
                    'provider' => 'QA Academy', 'started_on' => '2026-02-01', 'ended_on' => '2026-02-03', 'revision' => 1]);
            }
            $actors = [];
            foreach (['admin' => 'company_admin', 'hr' => 'hr_specialist', 'manager' => 'department_manager', 'employee' => 'employee'] as $key => $role) {
                $actor = User::query()->firstOrCreate(['email' => $key.'@hrm.example.test'],
                    ['name' => 'Synthetic QA '.ucfirst($key), 'password' => Hash::make($password)]);
                DB::table('company_user_roles')->insertOrIgnore(['user_id' => $actor->id, 'company_id' => $company,
                    'role_code' => $role, 'created_at' => now(), 'updated_at' => now()]);
                $actors[$key] = $actor;
            }
            DB::table('department_access_scopes')->insertOrIgnore(['company_id' => $company, 'user_id' => $actors['manager']->id,
                'department_id' => self::id('department-engineering'), 'created_at' => now(), 'updated_at' => now()]);
            foreach (['admin' => 0, 'hr' => 1, 'manager' => 7, 'employee' => 16] as $key => $index) {
                DB::table('employee_user_links')->insertOrIgnore(['company_id' => $company, 'user_id' => $actors[$key]->id,
                    'employee_id' => self::id('employee-'.$index), 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $plan = $this->insert('staffing_plans', 'staffing-plan', ['company_id' => $company, 'name' => 'Synthetic QA staffing plan',
                'valid_from' => '2026-01-01', 'status' => 'approved', 'revision' => 1]);
            foreach ($departments as [$code]) foreach (['lead' => 1, 'specialist' => 3] as $level => $units) {
                $this->insert('staffing_plan_lines', 'plan-line-'.$code.'-'.$level, ['staffing_plan_id' => $plan,
                    'department_id' => self::id('department-'.$code), 'position_id' => self::id('position-'.$code.'-'.$level),
                    'planned_units' => $units, 'notes' => 'Synthetic vacancies; no real payroll amounts', 'revision' => 1]);
            }
            $type = $this->insert('leave_types', 'leave-type', ['company_id' => $company, 'code' => 'qa_annual',
                'name' => 'Synthetic QA annual leave', 'annual_days' => null, 'balance_method' => 'calendar_days', 'category' => 'ordinary', 'revision' => 1]);
            foreach ([3, 4, 5] as $index) {
                $booking = $this->insert('employee_leaves', 'leave-'.$index, ['employee_id' => self::id('employee-'.$index),
                    'leave_type_id' => $type, 'planned_started_on' => '2026-10-25', 'planned_ended_on' => '2026-10-29',
                    'planned_days' => 5, 'revision' => 1]);
                $this->insert('leave_requests', 'request-'.$index, ['company_id' => $company,
                    'employee_id' => self::id('employee-'.$index), 'leave_type_id' => $type,
                    'planned_started_on' => '2026-10-25', 'planned_ended_on' => '2026-10-29', 'planned_days' => 5,
                    'status' => 'approved', 'revision' => 3, 'created_by_user_id' => $actors['hr']->id,
                    'decided_by_user_id' => $actors['admin']->id, 'submitted_at' => now(), 'decided_at' => now(), 'published_leave_id' => $booking]);
            }
            foreach ([10, 11] as $index) $this->insert('employee_bonuses', 'bonus-'.$index, ['company_id' => $company,
                'employee_id' => self::id('employee-'.$index), 'request_key' => self::id('bonus-key-'.$index),
                'bonus_kind' => 'one_time', 'award_on' => '2026-10-25', 'amount' => 100, 'currency' => 'KZT',
                'basis_reference' => 'SYNTHETIC-QA-BONUS', 'note' => 'Test amount only', 'status' => 'draft',
                'revision' => 1, 'created_by_user_id' => $actors['hr']->id]);
            $cursor = new \DateTimeImmutable('2026-10-01');
            for ($day = 0; $day < 31; $day++, $cursor = $cursor->modify('+1 day')) {
                $weekend = (int) $cursor->format('N') >= 6;
                $this->insert('production_calendar_days', 'calendar-'.$day, ['company_id' => $company,
                    'work_date' => $cursor->format('Y-m-d'), 'day_type' => $weekend ? 'weekend' : 'workday',
                    'planned_minutes' => $weekend ? 0 : 480, 'label' => 'Synthetic QA calendar; not statutory holidays', 'revision' => 1]);
            }
        }, 3);
    }
}
