<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HrmCommercialBootstrap;
use App\Services\HrmLegalDocuments;
use App\Services\HrmSaasCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HrmCommercialAdaptationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
            'account_status' => 'active',
        ]);
        DB::table('hrm_platform_admin_grants')->insert([
            'user_id' => $user->id, 'permission' => 'platform.manage',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return $user;
    }

    public function test_the_three_hrm_plans_match_real_modules_and_keep_the_reference_prices(): void
    {
        $plans = HrmSaasCatalog::proposals();
        $this->assertSame(['start', 'growth', 'business'], array_column($plans, 'code'));
        $this->assertSame([1490000, 6990000, 29990000], array_column($plans, 'monthly_price_minor'));
        $this->assertSame([2, 5, 10], array_map(fn ($p) => $p['limits']['users'], $plans));
        $this->assertSame([50, 150, 500], array_map(fn ($p) => $p['limits']['employees'], $plans));
        foreach ($plans as $plan) {
            $this->assertSame(1, $plan['limits']['companies']);
            $this->assertSame(1073741824, $plan['limits']['storage_bytes']);
            $this->assertSame(36700160, $plan['limits']['max_file_bytes']);
            $this->assertSame(HrmSaasCatalog::MODULES, array_keys(HrmSaasCatalog::modules($plan['modules'])));
            $this->assertTrue($plan['modules']['employees']);
            $this->assertTrue($plan['modules']['roles']);
            $this->assertArrayNotHasKey('messenger', $plan['modules']);
            $this->assertArrayNotHasKey('projects', $plan['modules']);
            $this->assertArrayNotHasKey('calls', $plan['modules']);
        }
    }

    public function test_manual_prepare_creates_three_private_draft_tariffs_and_15_localized_kz_drafts(): void
    {
        $actor = $this->admin();
        $count = HrmCommercialBootstrap::prepare($actor->id);
        $this->assertSame(3, $count['plans_created']);
        $this->assertSame(15, $count['legal_created']);
        $this->assertDatabaseCount('hrm_plan_versions', 3);
        $this->assertDatabaseCount('hrm_legal_versions', 15);
        $this->assertSame(0, DB::table('hrm_plan_versions')->where('status', 'published')->count());
        $this->assertSame(0, DB::table('hrm_legal_versions')->where('status', 'published')->count());
        $this->getJson('/api/v1/public/plans')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/legal/privacy?locale=ru&jurisdiction=KZ')->assertNotFound();
        $this->getJson('/api/v1/public/legal/privacy?locale=ka&jurisdiction=GE')->assertNotFound();
        $this->getJson('/api/v1/public/legal/required?locale=ru&jurisdiction=KZ')
            ->assertOk()->assertJsonPath('ready', false)->assertJsonCount(0, 'data');
        $this->assertEqualsCanonicalizing(['ru', 'en', 'ka'],
            DB::table('hrm_legal_versions')->distinct()->pluck('locale')->all());
        $this->assertSame(['KZ'], DB::table('hrm_legal_versions')->distinct()->pluck('jurisdiction')->all());
        foreach (DB::table('hrm_legal_versions')->get() as $v) {
            $this->assertGreaterThan(1000, mb_strlen($v->body));
            $this->assertSame(HrmLegalDocuments::sha256($v), $v->sha256);
            $this->assertNotEmpty($v->title);
            $this->assertNull($v->effective_at);
        }
        $second = HrmCommercialBootstrap::prepare($actor->id);
        $this->assertSame(0, $second['plans_created']);
        $this->assertSame(0, $second['legal_created']);
        $this->assertDatabaseCount('hrm_plan_versions', 3);
        $this->assertDatabaseCount('hrm_legal_versions', 15);
    }

    public function test_company_admin_cannot_run_commercial_bootstrap(): void
    {
        $user = User::factory()->create();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        HrmCommercialBootstrap::prepare($user->id);
    }

    public function test_plan_modules_reject_portal_only_modules_and_missing_core_security(): void
    {
        $actor = $this->admin();
        $payload = [
            'code' => 'qa_trial',
            'names' => ['ru' => 'Тест', 'en' => 'Test', 'ka' => 'ტესტი'],
            'monthly_price_minor' => 0, 'currency' => 'KZT',
            'limits' => ['users' => 2, 'companies' => 1, 'employees' => 20,
                'storage_bytes' => 1073741824, 'max_file_bytes' => 36700160],
            'modules' => ['employees' => true, 'roles' => true, 'messenger' => true],
        ];
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/plans', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('modules');
        unset($payload['modules']['messenger'], $payload['modules']['roles']);
        $this->actingAs($actor)->postJson('/api/v1/platform-admin/plans', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('modules');
        $this->assertDatabaseCount('hrm_plan_versions', 0);
    }

    public function test_raw_legal_draft_cannot_be_approved_or_published_until_rewritten(): void
    {
        $actor = $this->admin();
        HrmCommercialBootstrap::prepare($actor->id);
        $reviewer = User::factory()->create();
        DB::table('hrm_platform_admin_grants')->insert([
            'user_id' => $reviewer->id, 'permission' => 'legal.approve',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $document = DB::table('hrm_legal_versions')
            ->where('locale', 'ru')->where('type', 'offer')->first();
        $document->effective_at = now()->subHour()->toDateTimeString();
        DB::table('hrm_legal_versions')->where('id', $document->id)->update([
            'effective_at' => $document->effective_at,
            'sha256' => HrmLegalDocuments::sha256($document),
        ]);
        $this->actingAs($reviewer)
            ->putJson('/api/v1/platform-admin/legal/'.$document->id.'/status', [
                'status' => 'approved', 'approval_note' => 'Verified legal reviewer QA document',
            ])->assertStatus(409);
        $this->assertDatabaseHas('hrm_legal_versions', ['id' => $document->id, 'status' => 'draft']);
    }

    public function test_legal_publication_keeps_kz_and_ge_jurisdictions_separate(): void
    {
        $actor = $this->admin();
        HrmCommercialBootstrap::prepare($actor->id);
        $approver = User::factory()->create();
        $publisher = User::factory()->create();
        foreach ([$approver, $publisher] as $user) {
            DB::table('hrm_platform_admin_grants')->insert([
                'user_id' => $user->id,
                'permission' => $user->id === $approver->id ? 'legal.approve' : 'legal.publish',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('hrm_legal_versions')->where('locale', 'ru')->get() as $document) {
            $date = now()->subHour()->toDateTimeString();
            $document->effective_at = $date;
            // Independent reviewer has replaced the source draft with approved QA legal text.
            $document->body = 'Финальная редакция тестовых юридических условий для HRM. '
                .str_repeat('Обработка кадровых данных выполняется в пределах прав организации. ', 30);
            DB::table('hrm_legal_versions')->where('id', $document->id)->update([
                'body' => $document->body,
                'effective_at' => $date,
                'sha256' => HrmLegalDocuments::sha256($document),
            ]);
            $this->actingAs($approver)->putJson(
                '/api/v1/platform-admin/legal/'.$document->id.'/status',
                ['status' => 'approved', 'approval_note' => 'QA legal approval reference only']
            )->assertOk();
            $this->actingAs($publisher)->putJson(
                '/api/v1/platform-admin/legal/'.$document->id.'/status',
                ['status' => 'published']
            )->assertOk();
        }
        $this->getJson('/api/v1/public/legal/required?locale=ka&jurisdiction=KZ')
            ->assertOk()->assertJsonPath('ready', true)->assertJsonCount(5, 'data');
        $this->getJson('/api/v1/public/legal/required?locale=ka&jurisdiction=GE')
            ->assertOk()->assertJsonPath('ready', false)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/public/legal/offer?locale=ka&jurisdiction=GE')->assertNotFound();
    }
}
