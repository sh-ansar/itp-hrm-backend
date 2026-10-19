<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProtectedLeaveEvidenceTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private function fixture(): array
    {
        Storage::fake('minio');
        config()->set([
            'hrm.leave_writes_enabled' => true,
            'hrm.protected_leave_evidence_enabled' => true,
            'hrm.protected_leave_evidence_required' => true,
        ]);
        $company = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $otherEmployee = (string) Str::uuid();
        $medical = (string) Str::uuid();
        $ordinary = (string) Str::uuid();
        $hr = User::factory()->create();
        $admin = User::factory()->create();
        $staff = User::factory()->create();
        $manager = User::factory()->create();
        $other = User::factory()->create();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Protected leave company'],
            ['id' => $foreign, 'name' => 'Other company'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company, 'personnel_number' => 'MED-001',
                'last_name' => 'Own', 'first_name' => 'Employee'],
            ['id' => $otherEmployee, 'company_id' => $company, 'personnel_number' => 'MED-002',
                'last_name' => 'Other', 'first_name' => 'Employee'],
        ]);
        DB::table('leave_types')->insert([
            ['id' => $medical, 'company_id' => $company, 'code' => 'medical',
                'name' => 'Medical protected', 'category' => 'medical',
                'balance_method' => 'calendar_days', 'annual_days' => null],
            ['id' => $ordinary, 'company_id' => $company, 'code' => 'annual',
                'name' => 'Annual ordinary', 'category' => 'ordinary',
                'balance_method' => 'calendar_days', 'annual_days' => 24],
        ]);
        foreach ([[$admin, 'company_admin'], [$hr, 'hr_specialist'], [$staff, 'employee'],
            [$manager, 'department_manager'], [$other, 'employee']] as [$user, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'role_code' => $role,
            ]);
        }
        DB::table('employee_user_links')->insert([
            ['company_id' => $company, 'user_id' => $staff->id,
                'employee_id' => $employee, 'revision' => 1],
            ['company_id' => $company, 'user_id' => $other->id,
                'employee_id' => $otherEmployee, 'revision' => 1],
        ]);
        $base = '/api/v1/companies/'.$company;
        return compact('company', 'foreign', 'employee', 'otherEmployee', 'medical',
            'ordinary', 'admin', 'hr', 'staff', 'manager', 'other', 'base');
    }

    private function draft(User $staff, string $base, string $type): array
    {
        return $this->actingAs($staff)->postJson($base.'/me/leave-requests', [
            'leave_type_id' => $type,
            'planned_started_on' => '2026-11-01',
            'planned_ended_on' => '2026-11-05',
            'note' => null,
        ])->assertCreated()->json('data');
    }

    private function upload(User $actor, string $base, string $id, int $revision = 1,
        string $pdf = self::PDF)
    {
        return $this->actingAs($actor)->post($base.'/leave-requests/'.$id.'/evidence/file', [
            'file' => UploadedFile::fake()->createWithContent('private.pdf', $pdf),
            'request_revision' => $revision,
        ], ['Accept' => 'application/json']);
    }

    public function test_private_pdf_requires_explicit_upload_and_hr_verification_before_protected_approval(): void
    {
        extract($this->fixture());
        $draft = $this->draft($staff, $base, $medical);
        $id = $draft['id'];
        $fileUrl = $base.'/leave-requests/'.$id.'/evidence/file';
        $reviewUrl = $base.'/leave-requests/'.$id.'/evidence/review';

        $this->upload($other, $base, $id)->assertNotFound();
        $this->upload($manager, $base, $id)->assertNotFound();
        $this->actingAs($staff)->post($fileUrl, [
            'request_revision' => 1,
            'file' => UploadedFile::fake()->createWithContent('notpdf.pdf', 'not a pdf'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->actingAs($staff)->post($fileUrl, [
            'request_revision' => 1, 'employee_id' => $otherEmployee,
            'file' => UploadedFile::fake()->createWithContent('private.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->upload($staff, $base, $id, 4)->assertConflict();
        $this->upload($staff, $base, $id)->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.revision', 1);
        $this->upload($staff, $base, $id)->assertConflict();
        $evidence = DB::table('leave_request_evidence')->where('leave_request_id', $id)->first();
        $this->assertStringStartsWith('leave-evidence/'.$company.'/'.$id.'/', $evidence->storage_path);
        Storage::disk('minio')->assertExists($evidence->storage_path);

        $self = $this->actingAs($staff)->getJson($base.'/me/workspace')->assertOk()
            ->assertJsonPath('data.requests.0.category', 'medical')
            ->assertJsonPath('data.requests.0.evidence_status', 'pending')
            ->assertJsonPath('data.requests.0.evidence_revision', 1);
        $this->assertArrayNotHasKey('storage_path', $self->json('data.requests.0'));
        $this->get($fileUrl)->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($manager)->get($fileUrl)->assertNotFound();
        $this->actingAs($other)->get($fileUrl)->assertNotFound();
        $this->actingAs($hr)->get($fileUrl)->assertOk();
        $this->get('/api/v1/companies/'.$foreign.'/leave-requests/'.$id.'/evidence/file')->assertNotFound();

        // Evidence cannot silently remain valid after changing the leave dates/type.
        $this->actingAs($staff)->putJson($base.'/me/leave-requests/'.$id, [
            'leave_type_id' => $ordinary, 'planned_started_on' => '2026-11-03',
            'planned_ended_on' => '2026-11-08', 'note' => null, 'revision' => 1,
        ])->assertConflict();
        $this->postJson($base.'/me/leave-requests/'.$id.'/submit', ['revision' => 1])
            ->assertOk()->assertJsonPath('data.status', 'submitted');

        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertUnprocessable();
        $this->actingAs($staff)->postJson($reviewUrl, [
            'revision' => 1, 'decision' => 'verified',
        ])->assertNotFound();
        $this->actingAs($hr)->postJson($reviewUrl, [
            'revision' => 0, 'decision' => 'verified',
        ])->assertUnprocessable();
        $this->postJson($reviewUrl, [
            'revision' => 1, 'decision' => 'verified',
        ])->assertOk()->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.revision', 2);
        $this->postJson($reviewUrl, [
            'revision' => 1, 'decision' => 'verified',
        ])->assertConflict();

        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseCount('employee_leaves', 1);
        $this->actingAs($staff)->getJson($base.'/me/workspace')
            ->assertJsonPath('data.requests.0.evidence_status', 'verified')
            ->assertJsonCount(2, 'data.leave_types');
    }

    public function test_rejected_pdf_can_be_replaced_but_only_before_approval_and_not_by_a_other_employee(): void
    {
        extract($this->fixture());
        $draft = $this->draft($staff, $base, $medical);
        $id = $draft['id'];
        $this->upload($staff, $base, $id)->assertOk();
        $firstPath = DB::table('leave_request_evidence')->where('leave_request_id', $id)->value('storage_path');
        $this->actingAs($staff)->postJson($base.'/me/leave-requests/'.$id.'/submit',
            ['revision' => 1])->assertOk();
        $url = $base.'/leave-requests/'.$id.'/evidence/review';
        $this->actingAs($hr)->postJson($url, ['revision' => 1, 'decision' => 'rejected'])
            ->assertUnprocessable();
        $this->postJson($url, [
            'revision' => 1, 'decision' => 'rejected',
            'comment' => 'Please upload a readable document',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertUnprocessable();
        $this->actingAs($other)->get($base.'/leave-requests/'.$id.'/evidence/file')->assertNotFound();
        $this->upload($staff, $base, $id)->assertOk()
            ->assertJsonPath('data.status', 'pending')->assertJsonPath('data.revision', 3);
        $secondPath = DB::table('leave_request_evidence')->where('leave_request_id', $id)->value('storage_path');
        $this->assertNotEquals($firstPath, $secondPath);
        Storage::disk('minio')->assertExists($secondPath);
        Storage::disk('minio')->assertMissing($firstPath);
        $this->actingAs($hr)->postJson($url, [
            'revision' => 3, 'decision' => 'verified',
        ])->assertOk()->assertJsonPath('data.status', 'verified');
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->upload($staff, $base, $id)->assertConflict();
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.protected_evidence_uploaded',
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'leave.protected_evidence_reviewed',
        ]);
    }

    public function test_rollout_disabled_keeps_historical_approvals_working_and_protected_documents_private(): void
    {
        extract($this->fixture());
        config()->set([
            'hrm.protected_leave_evidence_enabled' => false,
            'hrm.protected_leave_evidence_required' => false,
        ]);
        $draft = $this->draft($staff, $base, $medical);
        $id = $draft['id'];
        $this->upload($staff, $base, $id)->assertNotFound();
        $this->actingAs($staff)->postJson($base.'/me/leave-requests/'.$id.'/submit',
            ['revision' => 1])->assertOk();
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$id.'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertOk();
        $this->assertDatabaseCount('leave_request_evidence', 0);
        config()->set('hrm.protected_leave_evidence_required', true);
        $secondEmployee = User::factory()->create();
        DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $secondEmployee->id, 'role_code' => 'employee',
        ]);
        DB::table('employee_user_links')->insert([
            'company_id' => $company, 'user_id' => $secondEmployee->id,
            'employee_id' => $otherEmployee, 'revision' => 1,
        ]);
        $request = $this->draft($secondEmployee, $base, $medical);
        $this->actingAs($secondEmployee)->postJson($base.'/me/leave-requests/'.$request['id'].'/submit',
            ['revision' => 1])->assertOk();
        $this->actingAs($admin)->postJson($base.'/leave-requests/'.$request['id'].'/decision', [
            'revision' => 2, 'decision' => 'approved',
        ])->assertUnprocessable();
    }
}
