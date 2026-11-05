<?php

namespace Tests\Feature;

use App\Domain\Identity\CompanyRole;
use App\Domain\Identity\CompanyRoleAssignments;
use App\Models\User;
use App\Services\HrmSaasCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HrmSaasEnforcementBillingTest extends TestCase
{
    use RefreshDatabase;

    private function company(?User $owner = null, bool $enrolled = false): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name' => 'QA Mining',
            'created_at' => now(), 'updated_at' => now()]);
        if ($owner) DB::table('company_user_roles')->insert([
            'company_id' => $id, 'user_id' => $owner->id,
            'role_code' => 'company_admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($enrolled) DB::table('hrm_company_legal_context')->insert([
            'company_id' => $id, 'jurisdiction' => 'KZ', 'registration_locale' => 'ru',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $id;
    }

    private function platformAdmin(): User
    {
        $user = User::factory()->create(['account_status' => 'active']);
        DB::table('hrm_platform_admin_grants')->insert([
            'user_id' => $user->id, 'permission' => 'platform.manage',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    private function plan(int $users = 2, int $employees = 2, bool $vacations = false): int
    {
        $base = HrmSaasCatalog::proposals()[0];
        $base['limits']['users'] = $users;
        $base['limits']['employees'] = $employees;
        $base['modules']['vacations'] = $vacations;
        $terms = HrmSaasCatalog::canonicalTerms($base);
        return DB::table('hrm_plan_versions')->insertGetId([
            'code' => 'qa_start', 'version' => 1,
            'names' => json_encode($base['names'], JSON_UNESCAPED_UNICODE),
            'descriptions' => json_encode($base['descriptions'], JSON_UNESCAPED_UNICODE),
            'monthly_price_minor' => $base['monthly_price_minor'], 'currency' => 'KZT',
            'period_months' => 1, 'display_order' => 1,
            'limits' => json_encode($base['limits']),
            'modules' => json_encode($base['modules']),
            'sha256' => HrmSaasCatalog::hashTerms($terms),
            'status' => 'published', 'is_public' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function subscribe(string $company, int $plan, string $status = 'active'): void
    {
        $p = DB::table('hrm_plan_versions')->where('id', $plan)->first();
        $terms = HrmSaasCatalog::canonicalTerms([
            'names' => json_decode($p->names, true),
            'descriptions' => json_decode($p->descriptions, true),
            'monthly_price_minor' => $p->monthly_price_minor, 'currency' => $p->currency,
            'period_months' => $p->period_months, 'limits' => json_decode($p->limits, true),
            'modules' => json_decode($p->modules, true),
        ]);
        DB::table('hrm_company_subscriptions')->insert([
            'company_id' => $company, 'plan_version_id' => $plan, 'status' => $status,
            'snapshot' => json_encode([
                'code' => $p->code, 'version' => $p->version, 'terms' => $terms,
                'sha256' => HrmSaasCatalog::hashTerms($terms),
            ], JSON_UNESCAPED_UNICODE),
            'starts_at' => $status === 'active' ? now()->subDay() : null,
            'ends_at' => $status === 'active' ? now()->addMonths(2) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function billingProfiles(string $company): void
    {
        DB::table('hrm_invoice_profiles')->insert([
            'scope' => 'supplier', 'company_id' => null,
            'details' => json_encode([
                'legal_name' => 'ITP Mining', 'registration_number' => '1234567890',
                'address' => 'Astana', 'bank' => 'QA Bank',
                'iban' => 'KZ123456789', 'bic' => 'QAABKZ',
                'tax_basis_points' => 1600,
            ]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('hrm_invoice_profiles')->insert([
            'scope' => 'buyer', 'company_id' => $company,
            'details' => json_encode(['legal_name' => 'QA Mining',
                'registration_number' => 'QACOMPANY', 'address' => 'Astana']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function invoice(User $admin, string $company, int $plan, int $months = 2): int
    {
        $response = $this->actingAs($admin)->postJson('/api/v1/platform-admin/invoices', [
            'company_id' => $company, 'plan_version_id' => $plan,
            'due_date' => now()->addWeek()->toDateString(), 'months' => $months,
        ]);
        $response->assertCreated();
        $id = (int) $response->json('data.id');
        $this->actingAs($admin)->putJson('/api/v1/platform-admin/invoices/'.$id.'/status',
            ['status' => 'issued'])->assertOk();
        return $id;
    }

    private function upload(User $customer, string $company, int $invoice): int
    {
        $res = $this->actingAs($customer)->post(
            '/api/v1/companies/'.$company.'/billing/invoices/'.$invoice.'/proofs',
            ['file' => UploadedFile::fake()->createWithContent(
                'bank.pdf', "%PDF-1.4\n1 0 obj\n<</Type/Catalog>>\nendobj\n%%EOF"
            )], ['Accept' => 'application/json']
        );
        $res->assertCreated();
        return (int) $res->json('data.id');
    }

    public function test_new_unpaid_company_is_read_only_and_existing_customer_is_unaffected(): void
    {
        config()->set('hrm.saas_enforcement_enabled', true);
        config()->set('hrm.personnel_writes_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $new = $this->company($owner, true);
        $old = $this->company($owner);
        $payload = ['personnel_number' => 'QA-001', 'first_name' => 'Alice', 'last_name' => 'QA'];
        $this->actingAs($owner)->postJson('/api/v1/companies/'.$new.'/employees', $payload)
            ->assertStatus(402);
        $this->actingAs($owner)->getJson('/api/v1/companies/'.$new.'/employees')->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/companies/'.$old.'/employees', $payload)
            ->assertCreated();
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_employee_quota_and_disabled_leave_module_are_enforced_on_the_server(): void
    {
        config()->set('hrm.saas_enforcement_enabled', true);
        config()->set('hrm.personnel_writes_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $company = $this->company($owner, true);
        $plan = $this->plan(2, 1);
        $this->subscribe($company, $plan);
        $this->actingAs($owner)->getJson('/api/v1/companies/'.$company.'/leaves')->assertForbidden();
        $this->actingAs($owner)->postJson('/api/v1/companies/'.$company.'/employees', [
            'personnel_number' => 'QA-1', 'first_name' => 'Alice', 'last_name' => 'QA',
        ])->assertCreated();
        $this->actingAs($owner)->postJson('/api/v1/companies/'.$company.'/employees', [
            'personnel_number' => 'QA-2', 'first_name' => 'Bob', 'last_name' => 'QA',
        ])->assertStatus(409);
        $this->assertDatabaseCount('employees', 1);
    }

    public function test_membership_quota_is_enforced_within_assignment_transaction(): void
    {
        config()->set('hrm.saas_enforcement_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $candidate = User::factory()->create(['account_status' => 'active']);
        $company = $this->company($owner, true);
        $plan = $this->plan(1, 100);
        $this->subscribe($company, $plan);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(CompanyRoleAssignments::class)->assign($owner, $company, $candidate->id,
            CompanyRole::Employee, [], null);
    }

    public function test_upload_bank_review_activates_paid_subscription_once_and_renews_without_losing_time(): void
    {
        config()->set('hrm.saas_billing_enabled', true);
        config()->set('hrm.saas_enforcement_enabled', true);
        Storage::fake('local');
        $owner = User::factory()->create(['account_status' => 'active']);
        $actor = $this->platformAdmin();
        $company = $this->company($owner, true);
        $plan = $this->plan(4, 40);
        $this->billingProfiles($company);
        $invoice = $this->invoice($actor, $company, $plan, 2);
        $record = DB::table('hrm_invoices')->where('id', $invoice)->first();
        $this->assertSame(2 * 1490000, (int) $record->total_minor);
        $this->assertSame((int) $record->total_minor,
            (int) $record->subtotal_minor + (int) $record->tax_minor);
        $this->assertGreaterThan(0, (int) $record->tax_minor);
        $this->assertDatabaseMissing('hrm_company_subscriptions', ['company_id' => $company]);
        $this->actingAs($owner)->get('/api/v1/companies/'.$company.'/billing/invoices/'.$invoice.'/print')
            ->assertOk()->assertSee('QA Mining');
        $pdf = $this->actingAs($owner)->get('/api/v1/companies/'.$company.'/billing/invoices/'.$invoice.'/pdf');
        $pdf->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-', substr($pdf->getContent(), 0, 5));
        $this->assertGreaterThan(3000, strlen($pdf->getContent()));
        $proof = $this->upload($owner, $company, $invoice);
        $this->assertDatabaseMissing('hrm_company_subscriptions', ['company_id' => $company]);
        $this->actingAs($owner)->postJson('/api/v1/platform-admin/invoices/'.$invoice
            .'/proofs/'.$proof.'/review', [
                'decision' => 'accepted', 'bank_reference' => 'QA-UNAUTHORIZED',
                'review_note' => 'Not allowed to review',
                'received_amount_minor' => $record->total_minor,
            ])->assertForbidden();
        $payload = ['decision' => 'accepted', 'bank_reference' => 'BANK-QA-0001',
            'review_note' => 'Confirmed deposit in bank statement',
            'received_amount_minor' => $record->total_minor];
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices/'.$invoice
            .'/proofs/'.$proof.'/review', $payload)->assertOk()->assertJsonPath('data.status', 'paid');
        $first = DB::table('hrm_company_subscriptions')->where('company_id', $company)->first();
        $this->assertSame('active', $first->status);
        $this->assertSame((int) $plan, (int) $first->plan_version_id);
        $this->assertSame('paid', DB::table('hrm_invoices')->where('id', $invoice)->value('status'));
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices/'.$invoice
            .'/proofs/'.$proof.'/review', $payload)->assertOk();
        $this->assertSame($first->ends_at, DB::table('hrm_company_subscriptions')
            ->where('company_id', $company)->value('ends_at'));

        $renewalInvoice = $this->invoice($actor, $company, $plan, 1);
        $renewalProof = $this->upload($owner, $company, $renewalInvoice);
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices/'.$renewalInvoice
            .'/proofs/'.$renewalProof.'/review', [
                'decision' => 'accepted', 'bank_reference' => 'BANK-QA-0002',
                'received_amount_minor' => 1490000,
                'review_note' => 'Confirmed second bank statement',
            ])->assertOk();
        $second = DB::table('hrm_company_subscriptions')->where('company_id', $company)->first();
        $this->assertTrue(\Carbon\Carbon::parse($second->ends_at)->gt($first->ends_at));
        $this->assertDatabaseHas('hrm_payment_proofs', ['id' => $proof, 'status' => 'accepted']);
        $this->assertDatabaseCount('hrm_commercial_audits', 8); // draft/issue/upload/paid x 2
    }

    public function test_bank_reference_collision_and_wrong_amount_do_not_activate_other_company(): void
    {
        config()->set('hrm.saas_billing_enabled', true);
        Storage::fake('local');
        $actor = $this->platformAdmin();
        $ownerA = User::factory()->create(['account_status' => 'active']);
        $ownerB = User::factory()->create(['account_status' => 'active']);
        $a = $this->company($ownerA, true);
        $b = $this->company($ownerB, true);
        $plan = $this->plan();
        $this->billingProfiles($a);
        $invoice = $this->invoice($actor, $a, $plan);
        $this->actingAs($ownerB)->post('/api/v1/companies/'.$b.'/billing/invoices/'.$invoice.'/proofs', [
            'file' => UploadedFile::fake()->createWithContent('bank.pdf', "%PDF-1.4\n%%EOF"),
        ], ['Accept' => 'application/json'])->assertNotFound();
        $proof = $this->upload($ownerA, $a, $invoice);
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices/'.$invoice
            .'/proofs/'.$proof.'/review', [
                'decision' => 'accepted', 'bank_reference' => 'BANK-QA-WRONG',
                'received_amount_minor' => 1,
                'review_note' => 'Wrong amount must not be accepted',
            ])->assertUnprocessable();
        $this->assertDatabaseMissing('hrm_company_subscriptions', ['company_id' => $a]);
        $this->assertDatabaseHas('hrm_payment_proofs', ['id' => $proof, 'status' => 'pending']);
    }

    public function test_company_checkout_issues_once_without_activating_and_is_company_scoped(): void
    {
        config()->set('hrm.saas_billing_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $stranger = User::factory()->create(['account_status' => 'active']);
        $company = $this->company($owner, true);
        $plan = $this->plan();
        $this->billingProfiles($company);
        $url = '/api/v1/companies/'.$company.'/billing/checkout';
        $payload = ['plan_version_id' => $plan, 'months' => 2];

        $this->actingAs($stranger)->postJson($url, $payload)->assertForbidden();
        $first = $this->actingAs($owner)->postJson($url, $payload)
            ->assertCreated()->assertJsonPath('data.status', 'issued');
        $id = (int) $first->json('data.id');
        $this->assertDatabaseHas('hrm_invoices', ['id' => $id, 'status' => 'issued',
            'total_minor' => 2980000]);
        $this->actingAs($owner)->postJson($url, $payload)
            ->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('hrm_invoices', 1);
        $this->assertDatabaseMissing('hrm_company_subscriptions', ['company_id' => $company]);
        $this->actingAs($owner)->get('/api/v1/companies/'.$company.'/billing/invoices/'.$id.'/pdf')
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_paid_bank_reference_cannot_be_reused_for_another_invoice(): void
    {
        config()->set('hrm.saas_billing_enabled', true);
        Storage::fake('local');
        $actor = $this->platformAdmin();
        $owner1 = User::factory()->create(['account_status' => 'active']);
        $owner2 = User::factory()->create(['account_status' => 'active']);
        $firstCompany = $this->company($owner1, true);
        $secondCompany = $this->company($owner2, true);
        $plan = $this->plan();
        $this->billingProfiles($firstCompany);
        $firstInvoice = $this->invoice($actor, $firstCompany, $plan, 1);
        $firstProof = $this->upload($owner1, $firstCompany, $firstInvoice);
        $payload = [
            'decision' => 'accepted', 'bank_reference' => 'BANK-RECONCILED-01',
            'received_amount_minor' => 1490000,
            'review_note' => 'Verified on approved bank statement',
        ];
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices/'.$firstInvoice
            .'/proofs/'.$firstProof.'/review', $payload)->assertOk();
        $secondInvoice = $this->invoice($actor, $secondCompany, $plan, 1);
        $secondProof = $this->upload($owner2, $secondCompany, $secondInvoice);
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices/'.$secondInvoice
            .'/proofs/'.$secondProof.'/review', $payload)->assertStatus(409);
        $this->assertDatabaseHas('hrm_invoices', ['id' => $secondInvoice, 'status' => 'issued']);
        $this->assertDatabaseMissing('hrm_company_subscriptions', ['company_id' => $secondCompany]);
    }

    public function test_legacy_company_is_not_silently_migrated_into_paid_limit_enforcement(): void
    {
        config()->set('hrm.saas_enforcement_enabled', true);
        config()->set('hrm.personnel_writes_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $legacy = $this->company($owner, false);
        $plan = $this->plan(1, 1);
        $this->subscribe($legacy, $plan);
        foreach (['LEGACY-1', 'LEGACY-2'] as $number) {
            $this->actingAs($owner)->postJson('/api/v1/companies/'.$legacy.'/employees', [
                'personnel_number' => $number, 'first_name' => 'Legacy', 'last_name' => 'QA',
            ])->assertCreated();
        }
        $this->assertSame(2, DB::table('employees')->where('company_id', $legacy)->count());
    }

    public function test_storage_usage_counts_employee_photos_and_rejects_overage(): void
    {
        config()->set('hrm.saas_enforcement_enabled', true);
        config()->set('hrm.personnel_writes_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $company = $this->company($owner, true);
        $plan = $this->plan(5, 5);
        $this->subscribe($company, $plan);
        $response = $this->actingAs($owner)->postJson('/api/v1/companies/'.$company.'/employees', [
            'personnel_number' => 'PHOTO-1', 'first_name' => 'Photo', 'last_name' => 'QA',
        ])->assertCreated();
        DB::table('employees')->where('id', $response->json('data.id'))->update([
            'photo_path' => 'employee-photos/qa/image.jpg', 'photo_byte_size' => 2048,
        ]);
        $quota = app(\App\Services\HrmSaasEntitlements::class);
        $this->assertSame(2048, $quota->storageUsed($company));
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        DB::transaction(static function () use ($company, $quota): void {
            $quota->assertStorageCapacity($company, 1073741824);
        });
    }

    public function test_expired_subscription_preserves_read_access_but_blocks_new_employee(): void
    {
        config()->set('hrm.saas_enforcement_enabled', true);
        config()->set('hrm.personnel_writes_enabled', true);
        $owner = User::factory()->create(['account_status' => 'active']);
        $company = $this->company($owner, true);
        $plan = $this->plan(2, 10);
        $this->subscribe($company, $plan);
        DB::table('hrm_company_subscriptions')->where('company_id', $company)
            ->update(['ends_at' => now()->subHour()]);
        $this->actingAs($owner)->getJson('/api/v1/companies/'.$company.'/employees')->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/companies/'.$company.'/employees', [
            'personnel_number' => 'EXPIRED-1', 'first_name' => 'Alice', 'last_name' => 'QA',
        ])->assertStatus(402);
        $this->assertDatabaseCount('employees', 0);
    }
}
