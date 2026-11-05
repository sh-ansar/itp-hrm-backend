<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HrmSaasCommercialTest extends TestCase
{
    use RefreshDatabase;

    private function company(string $name = 'QA HRM company'): string
    {
        $id = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $id, 'name' => $name]);
        return $id;
    }

    private function grant(User $user, string $permission): void
    {
        DB::table('hrm_platform_admin_grants')->insert([
            'user_id' => $user->id, 'permission' => $permission,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function planPayload(string $code = 'hrm_basic'): array
    {
        return [
            'code' => $code,
            'names' => ['ru' => 'Базовый', 'en' => 'Basic', 'ka' => 'საბაზო'],
            'descriptions' => ['ru' => 'Сотрудники', 'en' => 'Employees', 'ka' => 'თანამშრომლები'],
            'monthly_price_minor' => 1500000,
            'currency' => 'KZT',
            'limits' => [
                'users' => 50, 'companies' => 1, 'employees' => 100,
                'storage_bytes' => 1073741824, 'max_file_bytes' => 36700160,
            ],
            'modules' => ['employees' => true, 'staffing' => true, 'vacations' => false, 'roles' => true],
            'is_public' => true,
        ];
    }

    public function test_public_plan_list_does_not_expose_drafts_or_internal_plans(): void
    {
        $user = User::factory()->create();
        $this->grant($user, 'plans.write');
        $this->actingAs($user)
            ->postJson('/api/v1/platform-admin/plans', $this->planPayload())
            ->assertCreated()->assertJsonPath('data.version', 1);
        $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonCount(0, 'data');

        $this->actingAs($user)
            ->putJson('/api/v1/platform-admin/plans/1/status', ['status' => 'published'])
            ->assertOk()->assertJsonPath('data.status', 'published');
        $this->getJson('/api/v1/public/plans')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.terms.names.en', 'Basic');

        $this->actingAs($user)
            ->postJson('/api/v1/platform-admin/plans', array_replace($this->planPayload('hrm_internal'), ['is_public' => false]))
            ->assertCreated();
        $this->actingAs($user)
            ->putJson('/api/v1/platform-admin/plans/2/status', ['status' => 'published'])
            ->assertOk();
        $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_company_admin_cannot_use_platform_superadmin_endpoints(): void
    {
        $company = $this->company();
        $admin = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $admin->id, 'role_code' => 'company_admin',
        ]);
        $this->getJson('/api/v1/platform-admin/overview')->assertUnauthorized();
        $this->actingAs($admin)->getJson('/api/v1/platform-admin/overview')->assertForbidden();
        $this->actingAs($admin)->getJson('/api/v1/platform-admin/companies')->assertForbidden();
        $this->actingAs($admin)->postJson('/api/v1/platform-admin/plans', $this->planPayload())->assertForbidden();
        $this->assertDatabaseCount('hrm_plan_versions', 0);
    }

    public function test_platform_permissions_are_independent_and_limited(): void
    {
        $actor = User::factory()->create();
        $this->grant($actor, 'plans.read');
        $this->company('Hidden organization');
        $this->actingAs($actor)->getJson('/api/v1/platform-admin/overview')
            ->assertOk()->assertJsonPath('stats.companies', null)
            ->assertJsonPath('stats.users', null);
        $this->actingAs($actor)->getJson('/api/v1/platform-admin/companies')->assertForbidden();
        $this->actingAs($actor)->getJson('/api/v1/platform-admin/plans')->assertOk();
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/plans', $this->planPayload())->assertForbidden();
        $this->actingAs($actor)->getJson('/api/v1/platform-admin/grants')->assertForbidden();
    }

    public function test_subscriptions_are_company_scoped_and_require_published_version(): void
    {
        $companyA = $this->company('Company A');
        $companyB = $this->company('Company B');
        $actor = User::factory()->create();
        $this->grant($actor, 'platform.manage');
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/plans', $this->planPayload())->assertCreated();
        $this->actingAs($actor)->putJson('/api/v1/platform-admin/companies/'.$companyA.'/subscription',
            ['plan_version_id' => 1])->assertUnprocessable();
        $this->actingAs($actor)->putJson('/api/v1/platform-admin/plans/1/status',
            ['status' => 'published'])->assertOk();
        $this->actingAs($actor)->putJson('/api/v1/platform-admin/companies/'.$companyA.'/subscription',
            ['plan_version_id' => 1])->assertOk();
        $this->assertDatabaseHas('hrm_company_subscriptions', [
            'company_id' => $companyA, 'plan_version_id' => 1,
        ]);
        $this->assertDatabaseMissing('hrm_company_subscriptions', ['company_id' => $companyB]);
    }

    public function test_invoice_issuance_is_disabled_by_default(): void
    {
        $this->company();
        $actor = User::factory()->create();
        $this->grant($actor, 'platform.manage');
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/invoices', [
            'company_id' => DB::table('companies')->value('id'),
            'plan_version_id' => 1,
            'due_date' => now()->addWeeks(2)->toDateString(),
        ])->assertStatus(409);
        $this->assertDatabaseCount('hrm_invoices', 0);
    }

    public function test_legal_publishing_requires_separate_approval_and_publish_permissions(): void
    {
        $writer = User::factory()->create();
        $approver = User::factory()->create();
        $publisher = User::factory()->create();
        $this->grant($writer, 'legal.write');
        $this->grant($approver, 'legal.approve');
        $this->grant($publisher, 'legal.publish');
        $this->actingAs($writer)->postJson('/api/v1/platform-admin/legal', [
            'type' => 'privacy', 'locale' => 'ru', 'jurisdiction' => 'KZ',
            'acceptance' => 'acknowledged', 'effective_at' => now()->subDay()->toDateTimeString(),
            'title' => 'Правила HRM',
            'body' => str_repeat('Правила кадровой системы. ', 8), 'required' => true,
        ])->assertCreated();
        $this->getJson('/api/v1/public/legal/privacy?locale=ru')->assertNotFound();
        $this->actingAs($writer)->putJson('/api/v1/platform-admin/legal/1/status',
            ['status' => 'approved'])->assertForbidden();
        $this->actingAs($approver)->putJson('/api/v1/platform-admin/legal/1/status',
            ['status' => 'approved', 'approval_note' => 'Reviewed by QA counsel reference'])->assertOk();
        $this->actingAs($publisher)->putJson('/api/v1/platform-admin/legal/1/status',
            ['status' => 'published'])->assertOk();
        $this->getJson('/api/v1/public/legal/privacy?locale=ru')
            ->assertOk()->assertJsonPath('data.title', 'Правила HRM');
    }

    public function test_registration_never_works_when_feature_flag_is_disabled(): void
    {
        config()->set('hrm.saas_registration_enabled', false);
        $this->postJson('/api/v1/public/registration/start', [
            'email' => 'test@example.test', 'locale' => 'en',
        ])->assertStatus(409);
        $this->assertDatabaseCount('hrm_registration_challenges', 0);
    }

    public function test_registration_requires_verification_and_published_consent(): void
    {
        config()->set('hrm.saas_registration_enabled', true);
        Mail::fake();
        $start = [
            'email' => 'first-hrm@example.test', 'locale' => 'en',
        ];
        $this->postJson('/api/v1/public/registration/start', $start)->assertUnprocessable();
        Mail::assertQueuedCount(0);
        $this->assertDatabaseCount('hrm_registration_challenges', 0);
        $this->postJson('/api/v1/public/registration/complete', [
            'email' => $start['email'], 'registration_token' => str_repeat('x', 64),
            'name' => 'First HRM User', 'company' => 'Example Company',
            'password' => 'SecureHrmPassword123', 'password_confirmation' => 'SecureHrmPassword123',
            'accepted_legal_ids' => [],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('companies', 0);
        $this->assertDatabaseCount('users', 0);
    }

    public function test_last_platform_superadmin_cannot_be_revoked(): void
    {
        $actor = User::factory()->create();
        $this->grant($actor, 'platform.manage');
        $grantId = DB::table('hrm_platform_admin_grants')->where('user_id', $actor->id)->value('id');
        $this->actingAs($actor)->deleteJson('/api/v1/platform-admin/grants/'.$grantId)->assertStatus(409);
        $this->assertDatabaseHas('hrm_platform_admin_grants', ['id' => $grantId]);
    }
}
