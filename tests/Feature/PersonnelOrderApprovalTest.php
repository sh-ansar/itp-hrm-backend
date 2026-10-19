<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Internal order review is not a legal electronic signature or payroll action. */
final class PersonnelOrderApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";

    private function fixture(): array
    {
        Storage::fake('minio');
        config()->set([
            'hrm.document_writes_enabled' => true,
            'hrm.order_approvals_enabled' => true,
        ]);

        $company = (string) Str::uuid();
        $foreign = (string) Str::uuid();
        $employee = (string) Str::uuid();
        $foreignEmployee = (string) Str::uuid();
        $hr = User::factory()->create();
        $admin = User::factory()->create();
        $admin2 = User::factory()->create();
        $manager = User::factory()->create();
        $staff = User::factory()->create();
        DB::table('companies')->insert([
            ['id' => $company, 'name' => 'Private synthetic company'],
            ['id' => $foreign, 'name' => 'Foreign synthetic company'],
        ]);
        DB::table('employees')->insert([
            ['id' => $employee, 'company_id' => $company,
                'personnel_number' => 'ORD-1', 'first_name' => 'Order', 'last_name' => 'Subject'],
            ['id' => $foreignEmployee, 'company_id' => $foreign,
                'personnel_number' => 'ORD-2', 'first_name' => 'Foreign', 'last_name' => 'Subject'],
        ]);
        foreach ([[$hr, 'hr_specialist'], [$admin, 'company_admin'],
            [$admin2, 'company_admin'], [$manager, 'department_manager'],
            [$staff, 'employee']] as [$user, $role]) {
            DB::table('company_user_roles')->insert([
                'company_id' => $company, 'user_id' => $user->id, 'role_code' => $role,
            ]);
        }
        DB::table('employee_user_links')->insert([
            'company_id' => $company, 'user_id' => $staff->id,
            'employee_id' => $employee, 'revision' => 1,
        ]);
        $base = '/api/v1/companies/'.$company;
        return compact('company', 'foreign', 'employee', 'foreignEmployee',
            'hr', 'admin', 'admin2', 'manager', 'staff', 'base');
    }

    private function draft(User $actor, string $base, string $employee,
        bool $numbered = true, string $type = 'order'): array
    {
        return $this->actingAs($actor)->postJson($base.'/documents', [
            'employee_id' => $employee,
            'document_type' => $type,
            'title' => 'Internal synthetic HR order',
            'document_number' => $numbered ? 'ORD-2026-004' : null,
            'issued_on' => '2026-10-19',
        ])->assertCreated()->json('data');
    }

    private function attach(User $actor, string $base, string $id, int $revision = 1): void
    {
        $this->actingAs($actor)->post($base.'/documents/'.$id.'/file', [
            'revision' => $revision,
            'file' => UploadedFile::fake()->createWithContent('fake-internal.pdf', self::PDF),
        ], ['Accept' => 'application/json'])->assertOk();
    }

    public function test_order_can_be_submitted_then_independently_approved_and_only_then_released(): void
    {
        extract($this->fixture());
        $document = $this->draft($hr, $base, $employee);
        $id = $document['id'];
        $submit = $base.'/documents/'.$id.'/submit';
        $review = $base.'/documents/'.$id.'/decision';
        $share = $base.'/documents/'.$id.'/employee-access';

        $this->actingAs($hr)->postJson($submit, ['revision' => 1])->assertUnprocessable();
        $this->attach($hr, $base, $id);
        $this->actingAs($hr)->putJson($share, ['revision' => 2, 'employee_visible' => true])
            ->assertUnprocessable();
        $this->actingAs($manager)->postJson($submit, ['revision' => 2])->assertNotFound();
        $this->actingAs($hr)->postJson($submit, ['revision' => 2, 'status' => 'approved'])
            ->assertUnprocessable();
        $this->postJson($submit, ['revision' => 2])
            ->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.revision', 3);
        $this->postJson($submit, ['revision' => 2])->assertConflict();
        $this->actingAs($hr)->postJson($review, [
            'revision' => 3, 'decision' => 'approved',
        ])->assertNotFound();
        $this->actingAs($admin)->postJson($review, [
            'revision' => 2, 'decision' => 'approved',
        ])->assertConflict();
        $this->postJson($review, [
            'revision' => 3, 'decision' => 'approved',
        ])->assertOk()->assertJsonPath('data.status', 'approved')
            ->assertJsonPath('data.revision', 4);
        $this->postJson($review, [
            'revision' => 4, 'decision' => 'rejected', 'comment' => 'Cannot change an approved order',
        ])->assertConflict();

        // Approval only changes document workflow metadata; never employee assignments.
        $this->assertDatabaseCount('employee_assignments', 0);
        $this->assertDatabaseCount('personnel_actions', 0);
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $id, 'status' => 'approved', 'created_by_user_id' => $hr->id,
            'submitted_by_user_id' => $hr->id, 'reviewed_by_user_id' => $admin->id,
            'revision' => 4, 'employee_visible' => false,
        ]);
        $this->actingAs($staff)->getJson($base.'/me/documents')->assertJsonCount(0, 'data');
        $this->actingAs($admin)->putJson($share, [
            'revision' => 4, 'employee_visible' => true,
        ])->assertOk()->assertJsonPath('data.revision', 5);
        $this->actingAs($staff)->getJson($base.'/me/documents')
            ->assertOk()->assertJsonCount(1, 'data');
        $this->get($base.'/me/documents/'.$id.'/file')->assertOk();
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.order_submitted',
        ]);
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.order_approved',
        ]);
        $this->assertDatabaseCount('personnel_actions', 0);
    }

    public function test_unverified_and_cross_tenant_approvals_are_denied_and_feature_gate_is_explicit(): void
    {
        extract($this->fixture());
        $document = $this->draft($hr, $base, $employee);
        $id = $document['id'];
        $submit = $base.'/documents/'.$id.'/submit';
        $review = $base.'/documents/'.$id.'/decision';
        config()->set('hrm.order_approvals_enabled', false);
        $this->actingAs($hr)->postJson($submit, ['revision' => 1])->assertNotFound();
        $this->actingAs($admin)->postJson($review, [
            'revision' => 1, 'decision' => 'approved',
        ])->assertNotFound();
        config()->set('hrm.order_approvals_enabled', true);

        $this->actingAs($hr)->postJson($submit, ['revision' => 1])->assertUnprocessable();
        $this->attach($hr, $base, $id);
        $this->actingAs($admin)->postJson($review, [
            'revision' => 2, 'decision' => 'approved',
        ])->assertConflict();
        $this->actingAs($staff)->postJson($submit, ['revision' => 2])->assertNotFound();
        $this->actingAs($hr)->postJson("/api/v1/companies/{$foreign}/documents/{$id}/submit",
            ['revision' => 2])->assertNotFound();
        $this->actingAs($admin)->postJson('/api/v1/companies/'.(string) Str::uuid().'/documents/'.$id.'/decision',
            ['revision' => 2, 'decision' => 'approved'])->assertNotFound();

        // A legacy row with no author is not eligible for an invented historical approval.
        DB::table('personnel_documents')->where('id', $id)->update(['created_by_user_id' => null]);
        $this->actingAs($hr)->postJson($submit, ['revision' => 2])->assertUnprocessable();
        $this->assertDatabaseHas('personnel_documents', ['id' => $id, 'status' => 'draft']);
        $this->assertDatabaseCount('hr_audit_events', 2); // draft creation + PDF upload only
    }

    public function test_rejection_requires_reason_and_is_terminal_without_employee_distribution(): void
    {
        extract($this->fixture());
        $doc = $this->draft($hr, $base, $employee);
        $id = $doc['id'];
        $this->attach($hr, $base, $id);
        $this->actingAs($hr)->postJson($base.'/documents/'.$id.'/submit', [
            'revision' => 2,
        ])->assertOk();
        $review = $base.'/documents/'.$id.'/decision';
        $this->actingAs($admin)->postJson($review, [
            'revision' => 3, 'decision' => 'rejected',
        ])->assertUnprocessable();
        $this->postJson($review, [
            'revision' => 3, 'decision' => 'rejected', 'comment' => 'bad',
        ])->assertUnprocessable();
        $this->postJson($review, [
            'revision' => 3, 'decision' => 'rejected',
            'comment' => 'Order needs corrected documentary basis',
        ])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->postJson($review, [
            'revision' => 4, 'decision' => 'approved',
        ])->assertConflict();
        $this->actingAs($hr)->postJson($base.'/documents/'.$id.'/submit', [
            'revision' => 4,
        ])->assertConflict();
        $this->actingAs($admin)->putJson($base.'/documents/'.$id.'/employee-access', [
            'revision' => 4, 'employee_visible' => true,
        ])->assertUnprocessable();
        $this->actingAs($staff)->getJson($base.'/me/documents')->assertJsonCount(0, 'data');
        $this->assertDatabaseHas('hr_audit_events', [
            'company_id' => $company, 'action' => 'personnel.order_rejected',
        ]);
    }

    public function test_four_eyes_blocks_creator_and_own_subject_even_for_company_admins(): void
    {
        extract($this->fixture());
        $doc = $this->draft($admin, $base, $employee);
        $id = $doc['id'];
        $this->attach($admin, $base, $id);
        $this->actingAs($admin)->postJson($base.'/documents/'.$id.'/submit', [
            'revision' => 2,
        ])->assertOk();
        $this->postJson($base.'/documents/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertForbidden();
        // One-to-one employee linking is an invariant: reassign the subject
        // from the synthetic staff account to the independent administrator.
        DB::table('employee_user_links')->where('company_id', $company)
            ->where('employee_id', $employee)->delete();
        // Independent admin also cannot decide about their own employee record.
        DB::table('employee_user_links')->insert([
            'company_id' => $company, 'user_id' => $admin2->id,
            'employee_id' => $employee, 'revision' => 1,
        ]);
        $this->actingAs($admin2)->postJson($base.'/documents/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertForbidden();
        DB::table('employee_user_links')->where('user_id', $admin2->id)->delete();
        $this->postJson($base.'/documents/'.$id.'/decision', [
            'revision' => 3, 'decision' => 'approved',
        ])->assertOk();
        $this->assertDatabaseHas('personnel_documents', [
            'id' => $id, 'reviewed_by_user_id' => $admin2->id, 'status' => 'approved',
        ]);
    }

    public function test_only_numbered_dated_orders_with_pdf_can_be_submitted_not_contracts(): void
    {
        extract($this->fixture());
        $missing = $this->draft($hr, $base, $employee, false);
        $this->attach($hr, $base, $missing['id']);
        $this->actingAs($hr)->postJson($base.'/documents/'.$missing['id'].'/submit', [
            'revision' => 2,
        ])->assertUnprocessable();
        $contract = $this->draft($hr, $base, $employee, true, 'contract');
        $this->attach($hr, $base, $contract['id']);
        $this->postJson($base.'/documents/'.$contract['id'].'/submit', [
            'revision' => 2,
        ])->assertNotFound();
        $this->actingAs($admin)->getJson($base.'/documents?document_type=order')
            ->assertOk()->assertJsonPath('meta.order_approvals_enabled', true)
            ->assertJsonPath('meta.can_review_orders', true);
        $this->actingAs($hr)->getJson($base.'/documents?document_type=order')
            ->assertOk()->assertJsonPath('meta.can_review_orders', false);
    }
}
