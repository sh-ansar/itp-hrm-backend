<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Identity\CompanyRole;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HrmPlatformAccess as Access;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class HrmPublicController extends Controller
{
    private function key(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private function registrationEnabled(): bool
    {
        return (bool) config('hrm.saas_registration_enabled', false);
    }

    public function capabilities(): JsonResponse
    {
        return response()->json([
            'registration_enabled' => $this->registrationEnabled(),
            'billing_enabled' => (bool) config('hrm.saas_billing_enabled', false),
            'languages' => ['ru', 'en', 'ka'],
        ]);
    }

    public function plans(): JsonResponse
    {
        $plans = DB::table('hrm_plan_versions')->where('status', 'published')
            ->where('is_public', true)->orderBy('display_order')->orderBy('monthly_price_minor')->get();
        return response()->json(['data' => $plans->map(fn ($p) => Access::plan($p))->values()]);
    }

    public function legal(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, \App\Services\HrmLegalDocuments::TYPES, true), 404);
        $d = $request->validate([
            'locale' => ['nullable', Rule::in(\App\Services\HrmLegalDocuments::LOCALES)],
            'jurisdiction' => ['nullable', Rule::in(\App\Services\HrmLegalDocuments::JURISDICTIONS)],
        ]);
        $locale = $d['locale'] ?? 'ru';
        $jurisdiction = $d['jurisdiction'] ?? 'KZ';
        $row = \App\Services\HrmLegalDocuments::selectedPublished($jurisdiction, $locale)
            ->firstWhere('type', $type);
        if (!$row) return response()->json(['data' => null], 404);
        return response()->json(['data' => [
            'id' => $row->id, 'type' => $row->type, 'title' => $row->title,
            'locale' => $row->locale, 'jurisdiction' => $row->jurisdiction,
            'body' => $row->body, 'sha256' => $row->sha256, 'acceptance' => $row->acceptance,
            'version' => $row->version, 'required' => (bool) $row->required,
            'effective_at' => $row->effective_at,
        ]]);
    }

    public function requiredLegal(Request $request): JsonResponse
    {
        $d = $request->validate([
            'locale' => ['nullable', Rule::in(\App\Services\HrmLegalDocuments::LOCALES)],
            'jurisdiction' => ['nullable', Rule::in(\App\Services\HrmLegalDocuments::JURISDICTIONS)],
        ]);
        $locale = $d['locale'] ?? 'ru';
        $jurisdiction = $d['jurisdiction'] ?? 'KZ';
        $documents = \App\Services\HrmLegalDocuments::selectedPublished($jurisdiction, $locale)
            ->filter(fn ($row) => in_array($row->type, \App\Services\HrmLegalDocuments::REQUIRED_TYPES, true))
            ->map(fn ($row) => [
                'id' => $row->id, 'type' => $row->type, 'locale' => $row->locale,
                'jurisdiction' => $row->jurisdiction, 'version' => $row->version,
                'title' => $row->title, 'sha256' => $row->sha256,
            ])->values();
        return response()->json([
            'data' => $documents,
            'ready' => $documents->count() === count(\App\Services\HrmLegalDocuments::REQUIRED_TYPES),
        ]);
    }

    public function startRegistration(Request $request): JsonResponse
    {
        abort_unless($this->registrationEnabled(), 409, 'Registration is not enabled');
        if (!app()->environment('testing')) {
            abort_if(in_array(config('mail.default'), ['array', 'log', 'failover', 'null'], true), 503, 'SMTP transport required');
        }
        $d = $request->validate([
            'email' => 'required|email:rfc|max:254',
            'locale' => ['required', Rule::in(['ru', 'en', 'ka'])],
            'jurisdiction' => ['nullable', Rule::in(['KZ', 'GE'])],
            'website' => 'nullable|string|max:100',
        ]);
        // Honeypot; do not reveal that we suppressed the request.
        if (!empty($d['website'])) return response()->json(['status' => 'sent'], 202);
        $email = mb_strtolower(trim($d['email']));
        $jurisdiction = $d['jurisdiction'] ?? 'KZ';
        \App\Services\HrmLegalDocuments::requiredForRegistration($jurisdiction, $d['locale']);
        $ip = $request->ip() ?? 'unknown';
        $ipKey = 'hrm:signup:ip:'.$this->key($ip);
        $emailKey = 'hrm:signup:email:'.$this->key($email);
        abort_if(
            RateLimiter::tooManyAttempts($ipKey, 12) || RateLimiter::tooManyAttempts($emailKey, 3),
            429, 'Please try again later'
        );
        RateLimiter::hit($ipKey, 3600);
        RateLimiter::hit($emailKey, 3600);
        // Identical response for existing and unknown emails.
        if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            return response()->json(['status' => 'sent'], 202);
        }
        $code = (string) random_int(100000, 999999);
        DB::table('hrm_registration_challenges')->updateOrInsert(['email' => $email], [
            'code_hash' => $this->key($email.'|'.$code),
            'registration_token_hash' => null,
            'jurisdiction' => $jurisdiction, 'locale' => $d['locale'],
            'attempts' => 0, 'verified_at' => null,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $subject = [
            'ru' => 'Подтверждение email — ITP Кадры',
            'en' => 'Verify your email — ITP HRM',
            'ka' => 'ელფოსტის დადასტურება — ITP HRM',
        ][$d['locale']];
        $body = [
            'ru' => 'Ваш код подтверждения: '.$code.'. Он действует 15 минут. Никому его не сообщайте.',
            'en' => 'Your verification code: '.$code.'. Valid for 15 minutes. Do not share it.',
            'ka' => 'დადასტურების კოდი: '.$code.'. მოქმედებს 15 წუთი. არავის გაუზიაროთ.',
        ][$d['locale']];
        try {
            Mail::raw($body, function ($message) use ($email, $subject): void {
                $message->to($email)->subject($subject);
            });
        } catch (\Throwable $e) {
            DB::table('hrm_registration_challenges')->where('email', $email)->delete();
            report($e);
            abort(503, 'Email verification unavailable');
        }
        return response()->json(['status' => 'sent'], 202);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        abort_unless($this->registrationEnabled(), 409);
        $d = $request->validate([
            'email' => 'required|email:rfc|max:254', 'code' => 'required|digits:6',
        ]);
        $email = mb_strtolower(trim($d['email']));
        return DB::transaction(function () use ($email, $d) {
            $challenge = DB::table('hrm_registration_challenges')->where('email', $email)
                ->lockForUpdate()->first();
            abort_unless($challenge && now()->lessThan($challenge->expires_at)
                && !$challenge->verified_at && $challenge->attempts < 5, 422, 'Invalid or expired code');
            if (!hash_equals($challenge->code_hash, $this->key($email.'|'.$d['code']))) {
                DB::table('hrm_registration_challenges')->where('id', $challenge->id)
                    ->update(['attempts' => $challenge->attempts + 1, 'updated_at' => now()]);
                return response()->json(['message' => 'Invalid verification code'], 422);
            }
            $token = bin2hex(random_bytes(32));
            DB::table('hrm_registration_challenges')->where('id', $challenge->id)->update([
                'verified_at' => now(), 'registration_token_hash' => $this->key($token),
                'updated_at' => now(),
            ]);
            return response()->json(['registration_token' => $token]);
        });
    }

    public function completeRegistration(Request $request): JsonResponse
    {
        abort_unless($this->registrationEnabled(), 409);
        $d = $request->validate([
            'email' => 'required|email:rfc|max:254',
            'registration_token' => 'required|string|size:64',
            'name' => 'required|string|min:2|max:120',
            'company' => 'required|string|min:2|max:200',
            'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->numbers()],
            'accepted_legal_ids' => 'required|array|max:20',
            'accepted_legal_ids.*' => 'integer|distinct',
            'jurisdiction' => ['nullable', Rule::in(['KZ', 'GE'])],
            'locale' => ['nullable', Rule::in(['ru', 'en', 'ka'])],
            'plan_code' => 'nullable|string|max:48',
        ]);
        $email = mb_strtolower(trim($d['email']));
        $record = DB::transaction(function () use ($d, $email, $request) {
            $challenge = DB::table('hrm_registration_challenges')->where('email', $email)
                ->lockForUpdate()->first();
            abort_unless(
                $challenge && $challenge->verified_at && now()->lessThan($challenge->expires_at)
                && $challenge->registration_token_hash
                && hash_equals($challenge->registration_token_hash, $this->key($d['registration_token'])),
                422, 'Verification required'
            );
            abort_if(DB::table('users')->where('email', $email)->exists(), 409, 'Account exists');
            $jurisdiction = $d['jurisdiction'] ?? $challenge->jurisdiction ?? 'KZ';
            $locale = $d['locale'] ?? $challenge->locale ?? 'ru';
            abort_unless($jurisdiction === ($challenge->jurisdiction ?? 'KZ'), 422, 'Jurisdiction changed after verification');
            $documents = \App\Services\HrmLegalDocuments::requiredForRegistration($jurisdiction, $locale);
            $expected = $documents->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
            $accepted = collect($d['accepted_legal_ids'])->map(fn ($id) => (int) $id)->sort()->values()->all();
            abort_unless($accepted === $expected, 422, 'Consent must match the current published documents');
            $plan = null;
            if (!empty($d['plan_code'])) {
                $plan = DB::table('hrm_plan_versions')->where('code', $d['plan_code'])
                    ->where('status', 'published')->where('is_public', true)
                    ->orderByDesc('version')->first();
                abort_unless($plan, 422, 'Selected plan is no longer available');
            }
             $user = User::create([
                'name' => $d['name'], 'email' => $email,
                'password' => $d['password'],
            ]);
            $user->forceFill(['email_verified_at' => now(), 'account_status' => 'active'])->save();
            $company = (string) Str::uuid();
            DB::table('companies')->insert([
                'id' => $company, 'name' => $d['company'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id,
                'role_code' => CompanyRole::CompanyAdmin->value,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('hrm_company_legal_context')->insert([
                'company_id' => $company, 'jurisdiction' => $jurisdiction,
                'registration_locale' => $locale, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($plan) {
                // Never activate a paid plan before payment approval.
                DB::table('hrm_company_subscriptions')->insert([
                    'company_id' => $company, 'plan_version_id' => $plan->id,
                    'snapshot' => json_encode([
                        'code' => $plan->code, 'version' => $plan->version, 'sha256' => $plan->sha256,
                        'terms' => \App\Services\HrmSaasCatalog::canonicalTerms([
                            'names' => json_decode($plan->names, true),
                            'descriptions' => json_decode($plan->descriptions ?: '{}', true),
                            'monthly_price_minor' => $plan->monthly_price_minor,
                            'currency' => $plan->currency, 'period_months' => $plan->period_months,
                            'limits' => json_decode($plan->limits, true),
                            'modules' => json_decode($plan->modules, true),
                        ]),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'status' => (int) $plan->monthly_price_minor > 0 ? 'pending_payment' : 'active',
                    'starts_at' => (int) $plan->monthly_price_minor > 0 ? null : now(),
                    'ends_at' => null, 'assigned_by' => null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach ($documents as $doc) {
                DB::table('hrm_legal_consents')->insert([
                    'user_id' => $user->id, 'company_id' => $company,
                    'legal_version_id' => $doc->id, 'sha256' => $doc->sha256,
                    'action' => $doc->acceptance, 'source' => 'registration',
                    'locale' => $doc->locale, 'jurisdiction' => $doc->jurisdiction,
                    'ip_hash' => $this->key($request->ip() ?? 'unknown'),
                    'accepted_at' => now(),
                ]);
            }
            DB::table('hrm_registration_challenges')->where('id', $challenge->id)->delete();
            Access::audit($user->id, $company, 'registration.complete', 'company:'.$company);
            return ['company_id' => $company];
        });
        return response()->json(['status' => 'created', 'company_id' => $record['company_id']], 201);
    }
}
