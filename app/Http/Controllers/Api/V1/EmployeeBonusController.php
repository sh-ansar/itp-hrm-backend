<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Compensation\BonusAuthorization;
use App\Domain\Compensation\EmployeeBonusWorkflow;
use App\Http\Controllers\Controller;
use App\Http\Requests\Bonus\SaveEmployeeBonusRequest;
use App\Http\Requests\Bonus\TransitionEmployeeBonusRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class EmployeeBonusController extends Controller
{
    public function index(Request $request, string $company, BonusAuthorization $authorization): JsonResponse
    {
        abort_unless((bool) config('hrm.bonus_writes_enabled', false), 404);
        abort_unless($authorization->canManage($request->user(), $company), 403);

        $filters = $request->validate([
            'employee_id' => ['sometimes', 'uuid'],
            'status' => ['sometimes', Rule::in(['draft', 'submitted', 'approved', 'rejected', 'cancelled'])],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = DB::table('employee_bonuses as b')
            ->join('employees as e', function ($join) use ($company): void {
                $join->on('e.id', '=', 'b.employee_id')->where('e.company_id', $company);
            })
            ->where('b.company_id', $company)
            ->select('b.*', 'e.personnel_number', 'e.first_name', 'e.last_name', 'e.middle_name');

        if (isset($filters['employee_id'])) $query->where('b.employee_id', $filters['employee_id']);
        if (isset($filters['status'])) $query->where('b.status', $filters['status']);
        if (isset($filters['from'])) $query->where('b.award_on', '>=', $filters['from']);
        if (isset($filters['to'])) $query->where('b.award_on', '<=', $filters['to']);

        $page = $query->orderByDesc('b.award_on')->orderByDesc('b.created_at')
            ->orderBy('b.id')->paginate((int) ($filters['per_page'] ?? 25));

        return response()->json([
            'data' => array_map(function (object $row): array {
                $item = (array) $row;
                // Preserve precision / predictable JSON across PostgreSQL and SQLite.
                $amount = (string) $row->amount;
                [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
                $item['amount'] = (ltrim($units, '0') ?: '0').'.'.str_pad($fraction, 2, '0');

                return $item;
            }, $page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'can_approve' => $authorization->canApprove($request->user(), $company),
                'can_manage' => true,
                'payroll_posting_enabled' => false,
            ],
        ]);
    }

    public function store(
        SaveEmployeeBonusRequest $request, string $company, EmployeeBonusWorkflow $workflow,
    ): JsonResponse {
        return response()->json([
            'data' => $workflow->create($request->user(), $company, $request->validated()),
        ], 201);
    }

    public function update(
        SaveEmployeeBonusRequest $request, string $company, string $bonus, EmployeeBonusWorkflow $workflow,
    ): JsonResponse {
        return response()->json([
            'data' => $workflow->update($request->user(), $company, $bonus, $request->validated()),
        ]);
    }

    public function submit(
        TransitionEmployeeBonusRequest $request, string $company, string $bonus, EmployeeBonusWorkflow $workflow,
    ): JsonResponse {
        return response()->json(['data' => $workflow->submit(
            $request->user(), $company, $bonus, (int) $request->validated('revision'),
        )]);
    }

    public function cancel(
        TransitionEmployeeBonusRequest $request, string $company, string $bonus, EmployeeBonusWorkflow $workflow,
    ): JsonResponse {
        return response()->json(['data' => $workflow->cancel(
            $request->user(), $company, $bonus, (int) $request->validated('revision'),
        )]);
    }

    public function decision(
        TransitionEmployeeBonusRequest $request, string $company, string $bonus, EmployeeBonusWorkflow $workflow,
    ): JsonResponse {
        $values = $request->validated();
        return response()->json(['data' => $workflow->decide(
            $request->user(), $company, $bonus, (int) $values['revision'],
            $values['decision'], $values['comment'] ?? null,
        )]);
    }
}
