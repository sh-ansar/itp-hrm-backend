<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\HrmPlatformAccess as Access;
use App\Services\HrmSaasCatalog;
use App\Services\HrmLegalDocuments;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

final class HrmPlatformController extends Controller
{
    private function jsonRow(object $row): array
    {
        $data = (array) $row;
        foreach (['snapshot', 'details'] as $field) {
            if (isset($data[$field]) && is_string($data[$field])) {
                $data[$field] = json_decode($data[$field], true);
            }
        }
        return $data;
    }

    public function overview(Request $request): JsonResponse
    {
        $id = Access::require($request, 'platform.access');
        $grants = DB::table('hrm_platform_admin_grants')->where('user_id', $id)->pluck('permission');
        return response()->json([
            'permissions' => $grants,
            'stats' => [
                'companies' => Access::allows($id, 'companies.read') ? DB::table('companies')->count() : null,
                'users' => Access::allows($id, 'companies.read') ? DB::table('users')->count() : null,
                'active_subscriptions' => Access::allows($id, 'subscriptions.write') ? DB::table('hrm_company_subscriptions')->where('status', 'active')->count() : null,
                'unpaid_invoices' => Access::allows($id, 'billing.read') ? DB::table('hrm_invoices')->whereIn('status', ['issued', 'awaiting_payment', 'overdue'])->count() : null,
            ],
            'commercial_enabled' => (bool) config('hrm.saas_billing_enabled', false),
        ]);
    }

    public function companies(Request $request): JsonResponse
    {
        Access::require($request, 'companies.read');
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'integer|min:1', 'per_page' => 'integer|min:1|max:100']);
        $members = DB::table('company_user_roles')->select('company_id')->selectRaw('count(*) as users_count')->groupBy('company_id');
        $q = DB::table('companies as c')
            ->leftJoinSub($members, 'm', 'm.company_id', '=', 'c.id')
            ->leftJoin('hrm_company_subscriptions as s', 's.company_id', '=', 'c.id')
            ->leftJoin('hrm_plan_versions as p', 'p.id', '=', 's.plan_version_id')
            ->select('c.id', 'c.name', 'c.created_at', 's.status as subscription_status', 's.ends_at', 'p.code as plan_code')
            ->selectRaw('COALESCE(m.users_count, 0) as users_count');
        if (!empty($data['search'])) {
            $needle = str_replace(['%', '_'], ['\%', '\_'], $data['search']);
            $q->where('c.name', 'like', '%'.$needle.'%');
        }
        return response()->json($q->orderByDesc('c.created_at')->orderBy('c.id')->paginate((int) ($data['per_page'] ?? 20)));
    }

    public function company(Request $request, string $company): JsonResponse
    {
        Access::require($request, 'companies.read');
        $c = DB::table('companies')->where('id', $company)->first();
        abort_unless($c, 404);
        $sub = DB::table('hrm_company_subscriptions as s')
            ->join('hrm_plan_versions as p', 'p.id', '=', 's.plan_version_id')
            ->where('s.company_id', $company)->select('s.*', 'p.code as plan_code')->first();
        return response()->json([
            'organization' => $c,
            'subscription' => $sub,
            'users_count' => DB::table('company_user_roles')->where('company_id', $company)->count(),
            'audit' => DB::table('hrm_commercial_audits')->where('company_id', $company)->latest('id')->limit(50)->get(),
        ]);
    }

    public function users(Request $request, string $company): JsonResponse
    {
        Access::require($request, 'companies.read');
        abort_unless(DB::table('companies')->where('id', $company)->exists(), 404);
        return response()->json(DB::table('company_user_roles as r')
            ->join('users as u', 'u.id', '=', 'r.user_id')
            ->where('r.company_id', $company)
            ->select('u.id', 'u.name', 'u.email', 'u.email_verified_at', 'u.account_status', 'r.role_code')
            ->orderBy('u.name')->paginate(30));
    }

    public function plans(Request $request): JsonResponse
    {
        Access::require($request, 'plans.read');
        return response()->json(['data' => DB::table('hrm_plan_versions')->orderBy('code')->orderByDesc('version')
            ->get()->map(fn ($p) => Access::plan($p))->values()]);
    }

    public function savePlan(Request $request): JsonResponse
    {
        $actor = Access::require($request, 'plans.write');
        $d = $request->validate([
            'code' => ['required', 'regex:/^[a-z][a-z0-9_-]{1,47}$/'],
            'names' => 'required|array',
            'names.ru' => 'required|string|max:120',
            'names.en' => 'required|string|max:120',
            'names.ka' => 'required|string|max:120',
            'descriptions' => 'nullable|array',
            'monthly_price_minor' => 'required|integer|min:0|max:1000000000000',
            'currency' => ['required', Rule::in(['KZT', 'USD', 'EUR', 'GEL'])],
            'limits' => 'required|array',
            'limits.users' => 'required|integer|min:1|max:1000000',
            'limits.companies' => 'required|integer|in:1',
            'limits.employees' => 'required|integer|min:1|max:1000000',
            'limits.storage_bytes' => 'required|integer|min:1048576|max:1099511627776',
            'limits.max_file_bytes' => 'required|integer|min:1048576|max:1073741824',
            'period_months' => 'nullable|integer|min:1|max:120',
            'display_order' => 'nullable|integer|min:-10000|max:10000',
            'modules' => 'required|array',
            'modules.*' => 'required|boolean',
            'is_public' => 'boolean',
        ]);
        $d['modules'] = HrmSaasCatalog::modules($d['modules']);
        $d['period_months'] = $d['period_months'] ?? 1;
        $d['display_order'] = $d['display_order'] ?? 0;
        $terms = HrmSaasCatalog::canonicalTerms($d);
        $p = DB::transaction(function () use ($d, $actor, $terms) {
            // Lock a stable parent row so simultaneous version creation is serializable on PostgreSQL.
            DB::table('users')->where('id', $actor)->lockForUpdate()->first();
            $next = (int) DB::table('hrm_plan_versions')->where('code', $d['code'])->max('version') + 1;
            $id = DB::table('hrm_plan_versions')->insertGetId([
                'code' => $d['code'], 'version' => $next,
                'names' => json_encode($d['names'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'descriptions' => json_encode($d['descriptions'] ?? [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'monthly_price_minor' => $d['monthly_price_minor'], 'currency' => $d['currency'],
                'limits' => json_encode($d['limits'], JSON_THROW_ON_ERROR),
                'modules' => json_encode($d['modules'], JSON_THROW_ON_ERROR),
                'period_months' => $d['period_months'], 'display_order' => $d['display_order'],
                'sha256' => HrmSaasCatalog::hashTerms($terms),
                'status' => 'draft', 'is_public' => (bool) ($d['is_public'] ?? true),
                'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Access::audit($actor, null, 'plan.draft', 'plan:'.$id, ['code' => $d['code'], 'version' => $next]);
            return DB::table('hrm_plan_versions')->where('id', $id)->first();
        });
        return response()->json(['data' => Access::plan($p)], 201);
    }

    public function planState(Request $request, int $version): JsonResponse
    {
        $actor = Access::require($request, 'plans.write');
        $d = $request->validate(['status' => ['required', Rule::in(['published', 'archived'])]]);
        $p = DB::transaction(function () use ($actor, $version, $d) {
            $p = DB::table('hrm_plan_versions')->where('id', $version)->lockForUpdate()->first();
            abort_unless($p, 404);
            if ($d['status'] === 'published') {
                $terms = HrmSaasCatalog::canonicalTerms([
                    'names' => json_decode($p->names, true),
                    'descriptions' => json_decode($p->descriptions ?: '{}', true),
                    'monthly_price_minor' => $p->monthly_price_minor, 'currency' => $p->currency,
                    'period_months' => $p->period_months,
                    'limits' => json_decode($p->limits, true), 'modules' => json_decode($p->modules, true),
                ]);
                abort_unless($p->sha256 && hash_equals($p->sha256, HrmSaasCatalog::hashTerms($terms)), 409, 'Tariff integrity check failed');
            }
            abort_unless(
                ($p->status === 'draft' && $d['status'] === 'published')
                    || ($p->status === 'published' && $d['status'] === 'archived'),
                409, 'Invalid plan transition'
            );
            if ($d['status'] === 'published') {
                DB::table('hrm_plan_versions')->where('code', $p->code)
                    ->where('status', 'published')->update(['status' => 'archived', 'updated_at' => now()]);
            }
            DB::table('hrm_plan_versions')->where('id', $version)
                ->update(['status' => $d['status'], 'updated_at' => now()]);
            Access::audit($actor, null, 'plan.'.$d['status'], 'plan:'.$version);
            return DB::table('hrm_plan_versions')->where('id', $version)->first();
        });
        return response()->json(['data' => Access::plan($p)]);
    }

    public function assign(Request $request, string $company): JsonResponse
    {
        $actor = Access::require($request, 'subscriptions.write');
        $d = $request->validate([
            'plan_version_id' => 'required|integer|exists:hrm_plan_versions,id',
            'ends_at' => 'nullable|date|after:today',
        ]);
        abort_unless(DB::table('companies')->where('id', $company)->exists(), 404);
        abort_unless(DB::table('hrm_plan_versions')->where('id', $d['plan_version_id'])
            ->where('status', 'published')->exists(), 422, 'Plan must be published');
        DB::transaction(function () use ($d, $actor, $company) {
            $plan = DB::table('hrm_plan_versions')->where('id', $d['plan_version_id'])->first();
            $snapshot = json_encode([
                'code' => $plan->code, 'version' => $plan->version,
                'terms' => HrmSaasCatalog::canonicalTerms([
                    'names' => json_decode($plan->names, true),
                    'descriptions' => json_decode($plan->descriptions ?: '{}', true),
                    'monthly_price_minor' => $plan->monthly_price_minor,
                    'currency' => $plan->currency, 'period_months' => $plan->period_months,
                    'limits' => json_decode($plan->limits, true), 'modules' => json_decode($plan->modules, true),
                ]),
                'sha256' => $plan->sha256,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
            DB::table('hrm_company_subscriptions')->updateOrInsert(
                ['company_id' => $company],
                [
                    'plan_version_id' => $d['plan_version_id'], 'snapshot' => $snapshot,
                    'status' => 'active',
                    'starts_at' => now(), 'ends_at' => $d['ends_at'] ?? null,
                    'assigned_by' => $actor, 'updated_at' => now(), 'created_at' => now(),
                ]
            );
            Access::audit($actor, $company, 'subscription.assigned', 'company:'.$company, $d);
        });
        return response()->json(['status' => 'assigned']);
    }

    public function profiles(Request $request, ?string $company = null): JsonResponse
    {
        Access::require($request, 'billing.read');
        if ($company) abort_unless(DB::table('companies')->where('id', $company)->exists(), 404);
        $q = DB::table('hrm_invoice_profiles')->where('scope', $company ? 'buyer' : 'supplier');
        $row = $company ? $q->where('company_id', $company)->first() : $q->whereNull('company_id')->first();
        return response()->json(['data' => $row ? json_decode($row->details, true) : []]);
    }

    public function saveProfile(Request $request, ?string $company = null): JsonResponse
    {
        $actor = Access::require($request, 'billing.write');
        if ($company) abort_unless(DB::table('companies')->where('id', $company)->exists(), 404);
        $d = $request->validate([
            'legal_name' => 'required|string|max:250', 'registration_number' => 'required|string|max:80',
            'address' => 'required|string|max:500', 'bank' => 'required|string|max:250',
            'iban' => 'required|string|max:80', 'bic' => 'required|string|max:80',
            'signatory' => 'required|string|max:250',
            'tax_basis_points' => 'required|integer|min:0|max:10000',
            'service_name' => 'required|string|max:500',
        ]);
        DB::transaction(function () use ($actor, $company, $d) {
            $q = DB::table('hrm_invoice_profiles')->where('scope', $company ? 'buyer' : 'supplier');
            $row = $company ? $q->where('company_id', $company)->lockForUpdate()->first()
                : $q->whereNull('company_id')->lockForUpdate()->first();
            if ($row) DB::table('hrm_invoice_profiles')->where('id', $row->id)
                ->update(['details' => json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
            else DB::table('hrm_invoice_profiles')->insert([
                'scope' => $company ? 'buyer' : 'supplier', 'company_id' => $company,
                'details' => json_encode($d, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Access::audit($actor, $company, 'billing.profile.updated', $company ?: 'supplier');
        });
        return response()->json(['data' => $d]);
    }

    public function invoices(Request $request): JsonResponse
    {
        Access::require($request, 'billing.read');
        return response()->json(DB::table('hrm_invoices as i')->join('companies as c', 'c.id', '=', 'i.company_id')
            ->select('i.*', 'c.name as organization')->orderByDesc('i.id')->paginate(25));
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $actor = Access::require($request, 'billing.write');
        abort_unless(config('hrm.saas_billing_enabled', false), 409, 'Billing not activated');
        $data = $request->validate([
            'company_id' => 'required|uuid|exists:companies,id',
            'plan_version_id' => 'required|integer|exists:hrm_plan_versions,id',
            'due_date' => 'required|date|after_or_equal:today',
            'months' => 'sometimes|integer|min:1|max:120',
            'tax_basis_points' => 'sometimes|integer|min:0|max:10000',
        ]);
        $invoice = app(\App\Services\HrmInvoiceCreator::class)->create(
            $data['company_id'], (int) $data['plan_version_id'],
            (int) ($data['months'] ?? 1), $data['due_date'], $actor,
            false, false, isset($data['tax_basis_points']) ? (int) $data['tax_basis_points'] : null
        );
        return response()->json(['data' => $this->jsonRow($invoice)], 201);
    }

    public function invoiceState(Request $request, int $invoice): JsonResponse
    {
        $actor = Access::require($request, 'billing.write');
        abort_unless(config('hrm.saas_billing_enabled', false), 409, 'Billing not activated');
        $d = $request->validate(['status' => ['required', Rule::in(['issued', 'awaiting_payment', 'overdue', 'cancelled'])]]);
        $row = DB::transaction(function () use ($actor, $invoice, $d) {
            $row = DB::table('hrm_invoices')->where('id', $invoice)->lockForUpdate()->first();
            abort_unless($row, 404);
            $allowed = [
                'draft' => ['issued', 'cancelled'],
                'issued' => ['awaiting_payment', 'cancelled'],
                'awaiting_payment' => ['overdue', 'cancelled'],
                'overdue' => ['cancelled'],
            ];
            abort_unless(in_array($d['status'], $allowed[$row->status] ?? [], true), 409);
            $update = ['status' => $d['status'], 'updated_at' => now()];
            if ($d['status'] === 'issued') {
                $snap = json_decode($row->snapshot, true) ?: [];
                abort_unless(!empty($snap['seller']['legal_name']) && !empty($snap['seller']['iban'])
                    && !empty($snap['seller']['bic']), 409, 'Supplier billing details required');
                $update['number'] = 'HRM-'.now()->format('Ymd').'-'.str_pad((string) $invoice, 7, '0', STR_PAD_LEFT);
                $update['issued_at'] = now();
            }
            DB::table('hrm_invoices')->where('id', $invoice)->update($update);
            Access::audit($actor, $row->company_id, 'invoice.'.$d['status'], 'invoice:'.$invoice);
            return DB::table('hrm_invoices')->where('id', $invoice)->first();
        });
        return response()->json(['data' => $this->jsonRow($row)]);
    }

    public function legal(Request $request): JsonResponse
    {
        Access::require($request, 'legal.read');
        return response()->json(DB::table('hrm_legal_versions')->select('id', 'type', 'locale', 'jurisdiction', 'title', 'version', 'status', 'required', 'acceptance', 'sha256', 'approval_note', 'effective_at', 'published_at', 'created_at')
            ->orderByDesc('id')->paginate(25));
    }

    public function legalDetail(Request $request, int $version): JsonResponse
    {
        Access::require($request, 'legal.read');
        $row = DB::table('hrm_legal_versions')->where('id', $version)->first();
        abort_unless($row, 404);
        return response()->json(['data' => $row]);
    }

    public function saveLegal(Request $request): JsonResponse
    {
        $actor = Access::require($request, 'legal.write');
        $d = $request->validate([
            'type' => ['required', Rule::in(HrmLegalDocuments::TYPES)],
            'locale' => ['required', Rule::in(HrmLegalDocuments::LOCALES)],
            'jurisdiction' => ['required', Rule::in(HrmLegalDocuments::JURISDICTIONS)],
            'acceptance' => ['required', Rule::in(HrmLegalDocuments::ACCEPTANCE)],
            'title' => 'required|string|max:200',
            'body' => 'required|string|min:80|max:200000',
            'required' => 'boolean', 'effective_at' => 'nullable|date',
        ]);
        $row = DB::transaction(function () use ($actor, $d) {
            DB::table('users')->where('id', $actor)->lockForUpdate()->first();
            $next = (int) DB::table('hrm_legal_versions')
                ->where('type', $d['type'])->where('locale', $d['locale'])
                ->where('jurisdiction', $d['jurisdiction'])->max('version') + 1;
            $supplier = DB::table('hrm_invoice_profiles')->where('scope', 'supplier')
                ->whereNull('company_id')->first();
            $operator = $supplier ? json_decode($supplier->details, true) ?: [] : [];
            $document = [
                ...$d, 'body' => str_replace("\r\n", "\n", $d['body']),
                'version' => $next, 'operator_snapshot' => $operator,
                'effective_at' => $d['effective_at'] ?? null,
            ];
            $id = DB::table('hrm_legal_versions')->insertGetId([
                ...$document,
                'operator_snapshot' => json_encode($operator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'sha256' => HrmLegalDocuments::sha256($document),
                'status' => 'draft', 'created_by' => $actor,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            Access::audit($actor, null, 'legal.draft', 'legal:'.$id);
            return DB::table('hrm_legal_versions')->where('id', $id)->first();
        });
        return response()->json(['data' => $row], 201);
    }

    public function legalState(Request $request, int $version): JsonResponse
    {
        $actor = Access::require($request, $request->input('status') === 'approved' ? 'legal.approve' : 'legal.publish');
        $d = $request->validate([
            'status' => ['required', Rule::in(['approved', 'published'])],
            'approval_note' => 'required_if:status,approved|nullable|string|min:15|max:5000',
        ]);
        $row = DB::transaction(function () use ($actor, $version, $d) {
            $v = DB::table('hrm_legal_versions')->where('id', $version)->lockForUpdate()->first();
            abort_unless($v, 404);
            abort_unless(
                ($v->status === 'draft' && $d['status'] === 'approved')
                || ($v->status === 'approved' && $d['status'] === 'published'),
                409, 'Invalid legal transition'
            );
            abort_unless(
                $v->effective_at && $v->sha256
                    && hash_equals($v->sha256, HrmLegalDocuments::sha256($v)),
                409, 'Document needs a valid effective date and intact contents'
            );
            $update = ['status' => $d['status'], 'updated_at' => now()];
            if ($d['status'] === 'approved') {
                abort_unless(!HrmLegalDocuments::containsDraftNotice($v->body), 409,
                    'Remove draft disclaimers and submit the reviewed legal text as a new version before approval');
                abort_unless((int) $v->created_by !== $actor, 409, 'Independent legal reviewer required');
                abort_unless(
                    strlen(trim($d['approval_note'] ?? '')) >= 15, 422,
                    'Record a legally approved reference'
                );
                $update['approved_by'] = $actor;
                $update['approval_note'] = $d['approval_note'];
            } else {
                abort_unless(
                    $v->approved_by && mb_strlen(trim($v->approval_note ?? '')) >= 15,
                    409, 'Legal review not recorded'
                );
                $current = DB::table('hrm_legal_versions')
                    ->where('type', $v->type)
                    ->where('locale', $v->locale)
                    ->where('jurisdiction', $v->jurisdiction)
                    ->where('status', 'published');
                $current->update(['status' => 'archived', 'updated_at' => now()]);
                $update['published_at'] = now();
            }
            DB::table('hrm_legal_versions')->where('id', $version)->update($update);
            Access::audit($actor, null, 'legal.'.$d['status'], 'legal:'.$version, [
                'jurisdiction' => $v->jurisdiction,
                'locale' => $v->locale,
                'sha256' => $v->sha256,
                'review' => $d['approval_note'] ?? $v->approval_note,
            ]);
            return DB::table('hrm_legal_versions')->where('id', $version)->first();
        });
        return response()->json(['data' => $row]);
    }

    public function consents(Request $request): JsonResponse
    {
        Access::require($request, 'legal.read');
        return response()->json(DB::table('hrm_legal_consents as c')
            ->join('users as u', 'u.id', '=', 'c.user_id')
            ->join('hrm_legal_versions as v', 'v.id', '=', 'c.legal_version_id')
            ->select('c.*', 'u.email', 'v.type', 'v.locale', 'v.title', 'v.version')
            ->orderByDesc('c.id')->paginate(25));
    }

    public function grants(Request $request): JsonResponse
    {
        Access::require($request, 'platform.manage');
        return response()->json(['data' => DB::table('hrm_platform_admin_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->select('g.id', 'g.user_id', 'g.permission', 'u.email', 'g.created_at')
            ->orderBy('g.id')->get()]);
    }

    public function grant(Request $request): JsonResponse
    {
        $actor = Access::require($request, 'platform.manage');
        $d = $request->validate([
            'user_id' => 'required|integer|exists:users,id',
            'permission' => ['required', Rule::in(Access::PERMISSIONS)],
        ]);
        $user = DB::table('users')->where('id', $d['user_id'])->first();
        abort_unless($user && $user->account_status === 'active' && $user->email_verified_at, 422);
        DB::transaction(function () use ($d, $actor) {
            DB::table('hrm_platform_admin_grants')->insertOrIgnore([
                'user_id' => $d['user_id'], 'permission' => $d['permission'],
                'granted_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
            Access::audit($actor, null, 'platform.grant', 'user:'.$d['user_id'], ['permission' => $d['permission']]);
        });
        return response()->json(['status' => 'granted']);
    }

    public function revoke(Request $request, int $grant): JsonResponse
    {
        $actor = Access::require($request, 'platform.manage');
        DB::transaction(function () use ($actor, $grant) {
            $row = DB::table('hrm_platform_admin_grants')->where('id', $grant)->lockForUpdate()->first();
            abort_unless($row, 404);
            if ($row->permission === 'platform.manage') {
                abort_unless(
                    DB::table('hrm_platform_admin_grants')->where('permission', 'platform.manage')->count() > 1,
                    409, 'Cannot revoke last platform administrator'
                );
            }
            DB::table('hrm_platform_admin_grants')->where('id', $grant)->delete();
            Access::audit($actor, null, 'platform.revoke', 'user:'.$row->user_id, ['permission' => $row->permission]);
        });
        return response()->json(['status' => 'revoked']);
    }
}
