<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class HrmSaasEntitlements
{
    /**
     * Existing companies that never enrolled in SaaS retain their historical
     * functionality. New SaaS companies have hrm_company_legal_context.
     * The global feature switch is always disabled by default.
     *
     * @return array<string,mixed>|null
     */
    public function terms(string $company, bool $write = false): ?array
    {
        if (!config('hrm.saas_enforcement_enabled', false)) return null;

        // A historical company is never silently enrolled by a super-admin
        // subscription assignment. Only explicit SaaS onboarding is enforced.
        $onboarded = DB::table('hrm_company_legal_context')
            ->where('company_id', $company)->exists();
        if (!$onboarded) return null;
        $sub = DB::table('hrm_company_subscriptions')->where('company_id', $company)->first();
        if (!$sub) {
            if ($write) abort(402, 'An active HRM subscription is required');
            return null;
        }

        if ($sub->status !== 'active'
            || !$sub->starts_at || now()->lt($sub->starts_at)
            || ($sub->ends_at && now()->gte($sub->ends_at))) {
            if ($write) abort(402, 'HRM subscription is not paid or has expired');
            // Archived HR information can still be read/exported by existing staff.
            return null;
        }

        $snapshot = is_string($sub->snapshot) ? json_decode($sub->snapshot, true) : $sub->snapshot;
        $terms = $snapshot['terms'] ?? null;
        abort_unless(is_array($terms), 409, 'Missing immutable subscription snapshot');
        $sha256 = HrmSaasCatalog::hashTerms($terms);
        abort_unless(
            isset($snapshot['sha256']) && hash_equals($snapshot['sha256'], $sha256),
            409, 'Subscription terms integrity check failed'
        );
        return $terms;
    }

    public function requireModule(string $company, string $module, bool $write): void
    {
        $terms = $this->terms($company, $write);
        if (!$terms) return;
        abort_unless(in_array($module, HrmSaasCatalog::MODULES, true), 403);
        abort_unless(($terms['modules'][$module] ?? false) === true, 403, 'HRM module not included in this plan');
    }

    public function assertCapacity(string $company, string $kind): void
    {
        if (!config('hrm.saas_enforcement_enabled', false)) return;
        if (!in_array($kind, ['users', 'employees'], true)) {
            throw new \InvalidArgumentException('Invalid HRM quota');
        }
        // Caller must be in a DB transaction. The company row serializes
        // concurrent INSERTs across different users/employee UUIDs.
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('HRM quota checks must run inside a transaction');
        }
        DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
        $terms = $this->terms($company, true);
        if (!$terms) return;
        $limit = (int) ($terms['limits'][$kind] ?? 0);
        abort_unless($limit > 0, 409, 'HRM plan capacity is missing');
        $table = $kind === 'users' ? 'company_user_roles' : 'employees';
        $count = DB::table($table)->where('company_id', $company)->count();
        abort_unless($count < $limit, 409, 'HRM plan '.$kind.' limit reached');
    }

    public function assertFileSize(string $company, int $bytes): void
    {
        $terms = $this->terms($company, true);
        if (!$terms) return;
        abort_unless($bytes > 0 && $bytes <= (int) ($terms['limits']['max_file_bytes'] ?? 0), 413,
            'HRM plan file size limit exceeded');
    }

    public function assertStorageCapacity(string $company, int $bytes, int $replacedBytes = 0): void
    {
        if (!config('hrm.saas_enforcement_enabled', false)) return;
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('HRM storage checks must run inside a transaction');
        }
        DB::table('companies')->where('id', $company)->lockForUpdate()->firstOrFail();
        $terms = $this->terms($company, true);
        if (!$terms) return;
        $limit = (int) ($terms['limits']['storage_bytes'] ?? 0);
        abort_unless($limit > 0, 409, 'HRM storage capacity is missing');
        $used = $this->storageUsed($company);
        abort_unless($bytes > 0 && $replacedBytes >= 0 && $used - $replacedBytes + $bytes <= $limit,
            409, 'HRM storage capacity exceeded');
    }

    public function storageUsed(string $company): int
    {
        return (int) DB::table('personnel_documents')
            ->where('company_id', $company)->whereNotNull('storage_path')->sum('byte_size')
            + (int) DB::table('leave_request_evidence')
                ->where('company_id', $company)->whereNotNull('storage_path')->sum('byte_size')
            + (int) DB::table('employees')
                ->where('company_id', $company)->whereNotNull('photo_path')->sum('photo_byte_size');
    }

    public function forRequest(Request $request): void
    {
        if (!config('hrm.saas_enforcement_enabled', false)) return;
        $company = $request->route('company');
        if (!is_string($company) || !\Illuminate\Support\Str::isUuid($company)) return;
        $parts = $request->segments();
        $index = array_search($company, $parts, true);
        if ($index === false) return;
        $parts = array_slice($parts, $index + 1);
        // Billing and invoice proofs must remain accessible before payment.
        if (($parts[0] ?? '') === 'billing') return;
        $module = $this->moduleForPath($parts);
        $this->requireModule($company, $module, !$request->isMethodSafe());
    }

    /** @param list<string> $parts */
    public function moduleForPath(array $parts): string
    {
        $first = $parts[0] ?? '';
        if ($first === 'me') {
            $second = $parts[1] ?? '';
            if (str_starts_with($second, 'leave-')) return 'vacations';
            if ($second === 'timesheet') return 'worktime';
            if ($second === 'documents') return 'documents';
            return 'employees';
        }
        if ($first === 'employees') {
            $third = $parts[2] ?? '';
            if (in_array($third, ['contracts', 'assignments', 'personnel-actions'], true)) return 'personnel';
            if (in_array($third, ['education'], true)) return 'development';
            if ($third === 'profile' && in_array($parts[3] ?? '', ['training', 'reserve'], true)) {
                return 'development';
            }
            return 'employees';
        }
        if (in_array($first, ['staffing-plans', 'staffing-history', 'positions', 'departments'], true)) return 'staffing';
        if (str_starts_with($first, 'leave-') || $first === 'leaves') return 'vacations';
        if (str_starts_with($first, 'worktime') || $first === 'production-calendar') return 'worktime';
        if ($first === 'documents') return 'documents';
        if (in_array($first, ['personnel-actions', 'bonuses'], true)) return 'personnel';
        if ($first === 'development') return 'development';
        if (in_array($first, ['role-templates', 'memberships'], true)) return 'roles';
        return 'employees';
    }
}
