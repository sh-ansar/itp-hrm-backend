<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Identity\CompanyRole;
use App\Domain\Identity\CompanyRoleAssignments;
use App\Domain\Identity\CustomRolePermissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CompanyRoleTemplateController extends Controller
{
    public function index(Request $request, string $company, CompanyRoleAssignments $assignments, CustomRolePermissions $policy): JsonResponse
    {
        $this->authorizeManagement($request, $company, $assignments);
        $rows = DB::table('company_role_templates')
            ->where('company_id', $company)
            ->orderBy('name')
            ->get();

        $counts = DB::table('company_user_roles')
            ->where('company_id', $company)
            ->whereNotNull('custom_role_id')
            ->selectRaw('custom_role_id, count(*) as assigned_count')
            ->groupBy('custom_role_id')->pluck('assigned_count', 'custom_role_id');

        return response()->json([
            'data' => $rows->map(fn (object $row): array => $this->present($row, $policy, (int) ($counts[$row->id] ?? 0)))->all(),
            'meta' => ['custom_roles_enabled' => true],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(
        Request $request,
        string $company,
        CompanyRoleAssignments $assignments,
        CustomRolePermissions $policy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeManagement($request, $company, $assignments);
        $data = $request->validate([
            'code' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{1,47}$/'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'base_role' => ['required', Rule::in(['hr_specialist', 'department_manager', 'employee'])],
            'capabilities' => ['required', 'array'],
            'capabilities.*' => ['required', 'boolean'],
        ]);
        $base = CompanyRole::from($data['base_role']);
        $permissions = $policy->normalize($base, $data['capabilities']);

        $row = DB::transaction(function () use ($request, $company, $data, $permissions, $audit): object {
            if (DB::table('company_role_templates')->where('company_id', $company)->where('code', $data['code'])->exists()) {
                abort(409, 'This role code already exists in the company.');
            }

            $id = (string) Str::uuid();
            $now = now();
            DB::table('company_role_templates')->insert([
                'id' => $id,
                'company_id' => $company,
                'code' => $data['code'],
                'name' => trim($data['name']),
                'description' => $data['description'] ?? null,
                'base_role' => $data['base_role'],
                'capabilities' => json_encode($permissions, JSON_THROW_ON_ERROR),
                'status' => 'active',
                'revision' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $audit->record($request->user(), $company, HrAuditAction::RoleTemplateCreated, null, [
                'role_template_id' => $id,
                'role_code' => $data['code'],
                'base_role' => $data['base_role'],
            ]);

            return DB::table('company_role_templates')->where('id', $id)->first();
        }, 3);

        return response()->json(['data' => $this->present($row, $policy)], 201)
            ->header('Cache-Control', 'private, no-store');
    }

    public function update(
        Request $request,
        string $company,
        string $template,
        CompanyRoleAssignments $assignments,
        CustomRolePermissions $policy,
        HrAuditWriter $audit,
    ): JsonResponse {
        $this->authorizeManagement($request, $company, $assignments);
        $data = $request->validate([
            'revision' => ['required', 'integer', 'min:1'],
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'capabilities' => ['sometimes', 'required', 'array'],
            'capabilities.*' => ['required', 'boolean'],
            'status' => ['sometimes', Rule::in(['active', 'archived'])],
            'base_role' => ['prohibited'],
            'code' => ['prohibited'],
        ]);

        $row = DB::transaction(function () use ($request, $company, $template, $policy, $audit, $data): object {
            $existing = DB::table('company_role_templates')
                ->where('company_id', $company)->where('id', $template)
                ->lockForUpdate()->first();
            if ($existing === null) abort(404);
            if ($existing->revision !== $data['revision']) abort(409, 'The role has changed. Reload it.');

            $memberCount = DB::table('company_user_roles')
                ->where('company_id', $company)->where('custom_role_id', $template)->count();
            $status = $data['status'] ?? $existing->status;
            if ($status === 'archived' && $memberCount > 0) {
                abort(409, 'Reassign all members before archiving a role.');
            }

            $changes = [
                'name' => isset($data['name']) ? trim($data['name']) : $existing->name,
                'description' => array_key_exists('description', $data) ? $data['description'] : $existing->description,
                'capabilities' => array_key_exists('capabilities', $data)
                    ? json_encode($policy->normalize(CompanyRole::from($existing->base_role), $data['capabilities']), JSON_THROW_ON_ERROR)
                    : $existing->capabilities,
                'status' => $status,
            ];
            if ($changes['name'] === $existing->name && $changes['description'] === $existing->description
                && json_decode((string) $changes['capabilities'], true) === json_decode((string) $existing->capabilities, true)
                && $changes['status'] === $existing->status) {
                return $existing;
            }
            $changes['revision'] = $existing->revision + 1;
            $changes['updated_at'] = now();
            DB::table('company_role_templates')->where('id', $template)->where('company_id', $company)->update($changes);

            $audit->record($request->user(), $company, HrAuditAction::RoleTemplateUpdated, null, [
                'role_template_id' => $template, 'role_code' => $existing->code,
                'previous_revision' => $existing->revision, 'new_revision' => $changes['revision'],
                'status' => $status,
            ]);

            return DB::table('company_role_templates')->where('id', $template)->first();
        }, 3);

        $members = DB::table('company_user_roles')->where('company_id', $company)->where('custom_role_id', $row->id)->count();
        return response()->json(['data' => $this->present($row, $policy, $members)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function destroy(
        Request $request,
        string $company,
        string $template,
        CompanyRoleAssignments $assignments,
        HrAuditWriter $audit,
    ): Response {
        $this->authorizeManagement($request, $company, $assignments);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        DB::transaction(function () use ($request, $company, $template, $audit, $data): void {
            $row = DB::table('company_role_templates')->where('company_id', $company)
                ->where('id', $template)->lockForUpdate()->first();
            if ($row === null) abort(404);
            if ($row->revision !== $data['revision']) abort(409, 'The role has changed. Reload it.');
            if (DB::table('company_user_roles')->where('company_id', $company)
                ->where('custom_role_id', $template)->exists()) {
                abort(409, 'Reassign all members before deleting a role.');
            }
            DB::table('company_role_templates')->where('company_id', $company)->where('id', $template)->delete();
            $audit->record($request->user(), $company, HrAuditAction::RoleTemplateDeleted, null, [
                'role_template_id' => $template, 'role_code' => $row->code,
            ]);
        }, 3);

        return response()->noContent();
    }

    private function authorizeManagement(Request $request, string $company, CompanyRoleAssignments $assignments): void
    {
        abort_unless(config('hrm.role_management_enabled', false) && config('hrm.custom_role_templates_enabled', false), 404);
        $assignments->assertCompanyAdmin($request->user(), $company);
    }

    private function present(object $row, CustomRolePermissions $policy, int $members = 0): array
    {
        return [
            'id' => $row->id,
            'code' => $row->code,
            'name' => $row->name,
            'description' => $row->description,
            'base_role' => $row->base_role,
            'capabilities' => $policy->effective(CompanyRole::from($row->base_role), $row->capabilities),
            'status' => $row->status,
            'revision' => (int) $row->revision,
            'assigned_count' => $members,
        ];
    }
}
