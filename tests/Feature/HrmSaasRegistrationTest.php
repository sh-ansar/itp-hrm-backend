<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class HrmSaasRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('hrm.saas_registration_enabled', true);
    }

    private function verifiedChallenge(string $email, string $token): void
    {
        DB::table('hrm_registration_challenges')->insert([
            'email' => $email,
            'code_hash' => hash_hmac('sha256', $email.'|123456', (string) config('app.key')),
            'registration_token_hash' => hash_hmac('sha256', $token, (string) config('app.key')),
            'attempts' => 0, 'expires_at' => now()->addMinutes(10),
            'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function legalDocs(): array
    {
        $ids = [];
        foreach (['offer', 'tariff-policy', 'acceptable-use', 'personal-data-consent', 'privacy'] as $type) {
            $ids[] = DB::table('hrm_legal_versions')->insertGetId([
                'type' => $type, 'locale' => 'en', 'jurisdiction' => 'KZ', 'version' => 1,
                'effective_at' => now()->subDay(),
                'title' => ucfirst($type), 'body' => str_repeat('This is a published legal document. ', 5),
                'status' => 'published', 'required' => true,
                'published_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        return $ids;
    }

    private function payload(string $email, string $token, array $ids): array
    {
        return [
            'email' => $email, 'registration_token' => $token,
            'name' => 'QA Team Owner', 'company' => 'QA Mining',
            'password' => 'ValidPassword12345',
            'password_confirmation' => 'ValidPassword12345',
            'accepted_legal_ids' => $ids,
            'plan_code' => 'qa_paid',
        ];
    }

    private function plan(): void
    {
        DB::table('hrm_plan_versions')->insert([
            'code' => 'qa_paid', 'version' => 1,
            'names' => json_encode(['ru' => 'Платный', 'en' => 'Paid', 'ka' => 'ფასიანი']),
            'descriptions' => json_encode(['en' => 'QA plan']),
            'monthly_price_minor' => 2900000, 'currency' => 'KZT',
            'limits' => json_encode(['users' => 20, 'companies' => 1, 'employees' => 300, 'storage_bytes' => 1073741824, 'max_file_bytes' => 36700160]),
            'modules' => json_encode(['employees' => true, 'roles' => true]),
            'status' => 'published', 'is_public' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_verified_registration_creates_one_isolated_company_and_pending_paid_plan(): void
    {
        $email = 'qa-owner@example.test';
        $token = str_repeat('b', 64);
        $this->verifiedChallenge($email, $token);
        $ids = $this->legalDocs();
        $this->plan();
        $this->postJson('/api/v1/public/registration/complete', $this->payload($email, $token, $ids))
            ->assertCreated()->assertJsonPath('status', 'created');
        $user = DB::table('users')->where('email', $email)->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('ValidPassword12345', $user->password));
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseCount('companies', 1);
        $this->assertDatabaseHas('company_user_roles', ['user_id' => $user->id, 'role_code' => 'company_admin']);
        $this->assertDatabaseHas('hrm_company_subscriptions', ['status' => 'pending_payment']);
        $this->assertDatabaseCount('hrm_legal_consents', 5);
        $this->assertDatabaseHas('hrm_company_legal_context', ['jurisdiction' => 'KZ']);
        $this->assertDatabaseCount('hrm_registration_challenges', 0);
        $this->postJson('/api/v1/public/registration/complete', $this->payload($email, $token, $ids))
            ->assertUnprocessable();
        $this->assertDatabaseCount('companies', 1);
    }

    public function test_registration_refuses_to_proceed_without_required_published_legal_docs(): void
    {
        $email = 'qa-legal@example.test';
        $token = str_repeat('c', 64);
        $this->verifiedChallenge($email, $token);
        $this->plan();
        $this->postJson('/api/v1/public/registration/complete', $this->payload($email, $token, []))
            ->assertUnprocessable();
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('companies', 0);
    }

    public function test_registration_refuses_private_or_archived_tariff(): void
    {
        $email = 'qa-plan@example.test';
        $token = str_repeat('d', 64);
        $this->verifiedChallenge($email, $token);
        $ids = $this->legalDocs();
        $this->plan();
        DB::table('hrm_plan_versions')->where('code', 'qa_paid')->update(['is_public' => false]);
        $this->postJson('/api/v1/public/registration/complete', $this->payload($email, $token, $ids))
            ->assertUnprocessable();
        $this->assertDatabaseCount('companies', 0);
    }
}
