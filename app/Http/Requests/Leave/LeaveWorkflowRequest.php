<?php

namespace App\Http\Requests\Leave;

use App\Domain\Worktime\WorkforceAuthorization;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LeaveWorkflowRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless((bool) config('hrm.leave_writes_enabled', false), 404);
        $authorization = app(WorkforceAuthorization::class);
        $company = (string) $this->route('company');

        if (str_ends_with($this->path(), '/manager-decision')) {
            return $authorization->canReviewLeaveRequests($this->user(), $company);
        }

        return str_ends_with($this->path(), '/decision')
            ? $authorization->canApproveLeaveRequests($this->user(), $company)
            : $authorization->canManageLeaveRequests($this->user(), $company);
    }

    public function rules(): array
    {
        $decision = str_ends_with($this->path(), '/decision') || str_ends_with($this->path(), '/manager-decision');

        return [
            'revision' => ['required', 'integer', 'between:1,2147483646'],
            'decision' => $decision
                ? ['required', Rule::in(['approved', 'rejected'])]
                : ['prohibited'],
            'comment' => $decision
                ? ['nullable', 'string', 'max:2000', 'required_if:decision,rejected']
                : ['prohibited'],
        ];
    }
}
