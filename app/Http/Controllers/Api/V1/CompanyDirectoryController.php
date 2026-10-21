<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CompanyDirectoryController extends Controller
{
    private const GROUPS = [
        'leave-types','leave-limits','absence-types','balance-methods',
        'work-conditions','allowance-types','compensations','benefits','condition-bases',
        'tariff-types','calculation-units','position-schemes','base-tariffing','tariff-methods','tariffs','cargo-types',
        'training-types','training-modes','reserve-levels','reserve-types','recruiting-sources',
        'document-types','document-statuses','personnel-bases','order-types',
    ];

    public function index(Request $request, string $company, string $group): JsonResponse
    {
        $this->authorizeRead($request, $company);
        abort_unless(in_array($group, self::GROUPS, true), 404);

        $validated = $request->validate([
            'search' => ['sometimes','string','max:120'],
            'status' => ['sometimes', Rule::in(['active','inactive','draft'])],
        ]);

        $query = DB::table('company_directory_entries')
            ->where('company_id', $company)
            ->where('group_code', $group);

        if (!empty($validated['search'])) {
            $term = '%'.$validated['search'].'%';
            $query->where(fn ($scope) => $scope->whereLike('code', $term)->orWhereLike('name', $term));
        }
        if (!empty($validated['status'])) {
            $query->where('status', $validated['status']);
        }

        return response()->json([
            'data' => $query->orderBy('name')->get()->map(fn ($row) => $this->row($row))->all(),
            'meta' => ['group' => $group, 'writes_enabled' => (bool) config('hrm.directory_writes_enabled', false)],
        ]);
    }

    public function store(Request $request, string $company, string $group, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company);
        abort_unless(in_array($group, self::GROUPS, true), 404);

        $data = $this->validatePayload($request, $company, $group);
        $id = (string) Str::uuid();
        $at = now();

        DB::table('company_directory_entries')->insert([
            'id' => $id,
            'company_id' => $company,
            'group_code' => $group,
            ...$data,
            'revision' => 1,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $audit->record($request->user(), $company, HrAuditAction::DirectoryEntryCreated, null, [
            'directory_id' => $id, 'group' => $group, 'code' => $data['code'],
        ]);

        return response()->json([
            'data' => $this->row(DB::table('company_directory_entries')->where('id', $id)->first()),
        ], 201);
    }

    public function update(Request $request, string $company, string $group, string $entry, HrAuditWriter $audit): JsonResponse
    {
        $this->authorizeWrite($request, $company);
        abort_unless(in_array($group, self::GROUPS, true), 404);

        $current = DB::table('company_directory_entries')
            ->where('id', $entry)->where('company_id', $company)->where('group_code', $group)->first();
        abort_if($current === null, 404);

        $revision = (int) $request->validate(['revision' => ['required','integer','min:1']])['revision'];
        abort_if((int) $current->revision !== $revision, 409);

        $data = $this->validatePayload($request, $company, $group, $entry);
        DB::table('company_directory_entries')->where('id', $entry)->update([
            ...$data, 'revision' => $revision + 1, 'updated_at' => now(),
        ]);

        $audit->record($request->user(), $company, HrAuditAction::DirectoryEntryUpdated, null, [
            'directory_id' => $entry, 'group' => $group,
        ]);

        return response()->json([
            'data' => $this->row(DB::table('company_directory_entries')->where('id', $entry)->first()),
        ]);
    }

    public function destroy(Request $request, string $company, string $group, string $entry, HrAuditWriter $audit): Response
    {
        $this->authorizeWrite($request, $company);
        abort_unless(in_array($group, self::GROUPS, true), 404);

        $revision = (int) $request->validate(['revision' => ['required','integer','min:1']])['revision'];
        $row = DB::table('company_directory_entries')
            ->where('id', $entry)->where('company_id', $company)->where('group_code', $group)->first();
        abort_if($row === null, 404);
        abort_if((int) $row->revision !== $revision, 409);

        abort_if(DB::table('company_directory_entries')->where('parent_id', $entry)->exists(), 409, 'Remove child entries before deleting this parent.');
        DB::table('company_directory_entries')->where('id', $entry)->delete();
        $audit->record($request->user(), $company, HrAuditAction::DirectoryEntryDeleted, null, [
            'directory_id' => $entry, 'group' => $group, 'code' => $row->code,
        ]);

        return response()->noContent();
    }

    private function validatePayload(Request $request, string $company, string $group, ?string $entry = null): array
    {
        $data = $request->validate([
            'parent_id' => ['nullable','uuid'],
            'code' => ['required','string','max:100',
                Rule::unique('company_directory_entries','code')
                    ->where(fn ($query) => $query->where('company_id',$company)->where('group_code',$group))
                    ->ignore($entry,'id')],
            'name' => ['required','string','max:255'],
            'category' => ['nullable','string','max:120'],
            'unit' => ['nullable','string','max:80'],
            'value' => ['nullable','string','max:500'],
            'status' => ['required', Rule::in(['active','inactive','draft'])],
        ]);
        $parent = $data['parent_id'] ?? null;
        if ($parent !== null) {
            abort_if($parent === $entry, 422, 'An entry cannot be its own parent.');
            $ancestor = $parent;
            $visited = [];
            while ($ancestor !== null) {
                abort_if(isset($visited[$ancestor]) || $ancestor === $entry, 422, 'Cyclic directory hierarchy.');
                $visited[$ancestor] = true;
                $node = DB::table('company_directory_entries')
                    ->where('id', $ancestor)->where('company_id', $company)->where('group_code', $group)->first();
                abort_if($node === null, 422, 'Parent must belong to the same company and group.');
                $ancestor = $node->parent_id;
            }
        }
        return $data;
    }

    private function authorizeRead(Request $request, string $company): void
    {
        $role = DB::table('company_user_roles')
            ->where('company_id',$company)
            ->where('user_id',$request->user()->getAuthIdentifier())
            ->value('role_code');
        abort_unless(in_array($role,['company_admin','hr_specialist'],true),403);
    }

    private function authorizeWrite(Request $request, string $company): void
    {
        abort_unless((bool) config('hrm.directory_writes_enabled', false),404);
        $this->authorizeRead($request,$company);
    }

    private function row(object $row): array
    {
        return [
            'id'=>$row->id,'group'=>$row->group_code,'parent_id'=>$row->parent_id,'code'=>$row->code,'name'=>$row->name,
            'category'=>$row->category,'unit'=>$row->unit,'value'=>$row->value,'status'=>$row->status,
            'revision'=>(int)$row->revision,
        ];
    }
}
