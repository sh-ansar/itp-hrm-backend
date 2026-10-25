<?php

namespace Tests\Feature;

use App\Domain\Audit\HrAuditAction;
use App\Domain\Audit\HrAuditWriter;
use App\Domain\Notifications\WorkflowOutbox;
use App\Jobs\QueuedResetPassword;
use App\Jobs\SendWorkflowNotification;
use App\Mail\WorkflowUpdateMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkflowNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config()->set('hrm.workflow_notifications_enabled', true);
        $actor = User::factory()->create()->refresh();
        $recipient = User::factory()->create()->refresh();
        $outsider = User::factory()->create()->refresh();
        $company = (string) Str::uuid();
        DB::table('companies')->insert(['id' => $company, 'name' => 'Synthetic notification company']);
        foreach ([[$actor, 'hr_specialist'], [$recipient, 'company_admin']] as [$user, $role]) {
            DB::table('company_user_roles')->insert(['company_id' => $company, 'user_id' => $user->id, 'role_code' => $role]);
        }
        app(HrAuditWriter::class)->record($actor, $company, HrAuditAction::PersonnelActionCompleted,
            null, ['reason' => 'PRIVATE MUST NEVER APPEAR IN EMAIL']);
        $item = DB::table('hrm_notifications')->first();
        return compact('actor', 'recipient', 'outsider', 'company', 'item');
    }

    public function test_transactional_outbox_deduplicates_and_never_sends_from_domain_transaction(): void
    {
        Mail::fake();
        extract($this->fixture());
        $this->assertDatabaseCount('hrm_notifications', 1);
        app(WorkflowOutbox::class)->append($item->audit_event_id, $actor, $company,
            'personnel.action_completed', []);
        $this->assertDatabaseCount('hrm_notifications', 1);
        Mail::assertNothingSent();
        try {
            DB::transaction(function () use ($actor, $company): void {
                app(HrAuditWriter::class)->record($actor, $company, HrAuditAction::PersonnelActionCompleted);
                throw new \RuntimeException('synthetic rollback');
            });
        } catch (\RuntimeException) {}
        $this->assertDatabaseCount('hrm_notifications', 1);
        $this->assertDatabaseCount('hr_audit_events', 1);
        $this->assertStringNotContainsString('PRIVATE', json_encode((array) $item));
    }

    public function test_real_leave_submission_notifies_only_scoped_manager_then_hr_and_linked_subject(): void
    {
        Mail::fake();
        extract($this->fixture());
        config()->set(['hrm.leave_writes_enabled' => true, 'hrm.leave_manager_approval_enabled' => true]);
        $employee = (string) Str::uuid(); $department = (string) Str::uuid(); $position = (string) Str::uuid();
        $manager = User::factory()->create()->refresh();
        $foreignManager = User::factory()->create()->refresh();
        DB::table('departments')->insert(['id' => $department, 'company_id' => $company, 'name' => 'QA Engineering']);
        DB::table('positions')->insert(['id' => $position, 'company_id' => $company, 'name' => 'QA Engineer']);
        DB::table('employees')->insert(['id' => $employee, 'company_id' => $company,
            'personnel_number' => 'NOTIFY-001', 'first_name' => 'Synthetic', 'last_name' => 'Worker']);
        DB::table('employee_assignments')->insert(['id' => (string) Str::uuid(), 'employee_id' => $employee,
            'department_id' => $department, 'position_id' => $position, 'valid_from' => '2020-01-01']);
        foreach ([$manager, $foreignManager] as $user) DB::table('company_user_roles')->insert([
            'company_id' => $company, 'user_id' => $user->id, 'role_code' => 'department_manager']);
        DB::table('department_access_scopes')->insert(['company_id' => $company,
            'user_id' => $manager->id, 'department_id' => $department]);
        DB::table('company_user_roles')->insert(['company_id' => $company, 'user_id' => $outsider->id, 'role_code' => 'employee']);
        DB::table('employee_user_links')->insert(['company_id' => $company, 'user_id' => $outsider->id,
            'employee_id' => $employee, 'revision' => 1]);
        $base = "/api/v1/companies/{$company}";
        $type = $this->actingAs($recipient)->postJson($base.'/leave-types', ['code' => 'ordinary',
            'name' => 'QA Annual', 'balance_method' => 'calendar_days', 'annual_days' => 24])->assertCreated()->json('data.id');
        $id = $this->actingAs($actor)->postJson($base.'/leave-requests', ['employee_id' => $employee,
            'leave_type_id' => $type, 'planned_started_on' => '2027-05-01', 'planned_ended_on' => '2027-05-05',
            'note' => 'PRIVATE HEALTH DETAIL'])->assertCreated()->json('data.id');
        $this->postJson($base.'/leave-requests/'.$id.'/submit', ['revision' => 1])->assertOk();
        $this->assertSame([$manager->id], DB::table('hrm_notifications')->where('event', 'leave.request_submitted')->pluck('user_id')->all());
        $this->actingAs($manager)->postJson($base.'/leave-requests/'.$id.'/manager-decision',
            ['revision' => 2, 'decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('hrm_notifications', ['event' => 'leave.request_manager_approved', 'user_id' => $recipient->id]);
        $this->actingAs($recipient)->postJson($base.'/leave-requests/'.$id.'/decision',
            ['revision' => 3, 'decision' => 'approved'])->assertOk();
        $this->assertDatabaseHas('hrm_notifications', ['event' => 'leave.request_approved', 'user_id' => $outsider->id]);
        $this->assertDatabaseMissing('hrm_notifications', ['event' => 'leave.request_approved', 'user_id' => $foreignManager->id]);
        $this->assertStringNotContainsString('PRIVATE HEALTH', json_encode(DB::table('hrm_notifications')->get()));
        Mail::assertNothingSent();
    }

    public function test_transport_acceptance_and_duplicate_job_are_idempotent(): void
    {
        Mail::fake();
        extract($this->fixture());
        $job = new SendWorkflowNotification($item->id);
        $job->handle(app(WorkflowOutbox::class));
        $job->handle(app(WorkflowOutbox::class));
        Mail::assertSent(WorkflowUpdateMail::class, 1);
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'accepted', 'attempts' => 1]);
        $this->assertDatabaseHas('hrm_notification_deliveries', ['notification_id' => $item->id,
            'attempt' => 1, 'result' => 'transport_accepted']);
        Mail::assertSent(WorkflowUpdateMail::class, fn ($mail) => $mail->hasTo($recipient->email)
            && $mail->correlationId === $item->correlation_id);
    }

    public function test_failed_transport_has_bounded_retry_and_safe_error_journal(): void
    {
        extract($this->fixture());
        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(new \RuntimeException('PRIVATE SMTP CREDENTIALS'));
        $job = new SendWorkflowNotification($item->id);
        try { $job->handle(app(WorkflowOutbox::class)); $this->fail('Expected a retryable transport error'); }
        catch (\RuntimeException $error) { $this->assertStringNotContainsString('PRIVATE', $error->getMessage()); }
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'pending', 'attempts' => 1]);
        $this->assertDatabaseHas('hrm_notification_deliveries', ['notification_id' => $item->id,
            'result' => 'transport_failed', 'error_code' => 'RuntimeException']);
        $this->assertStringNotContainsString('PRIVATE', json_encode(DB::table('hrm_notification_deliveries')->get()));
        Mail::shouldReceive('getDefaultDriver')->andReturn('array');
        Mail::fake();
        $job->handle(app(WorkflowOutbox::class));
        Mail::assertNothingSent();
        $this->travel(31)->seconds();
        $job->handle(app(WorkflowOutbox::class));
        Mail::assertSent(WorkflowUpdateMail::class, 1);
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'accepted', 'attempts' => 2]);
    }

    public function test_revoked_membership_and_email_preference_cancel_unsent_deliveries(): void
    {
        Mail::fake();
        extract($this->fixture());
        DB::table('company_user_roles')->where('user_id', $recipient->id)->delete();
        (new SendWorkflowNotification($item->id))->handle(app(WorkflowOutbox::class));
        Mail::assertNothingSent();
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'cancelled', 'attempts' => 0]);
        $this->actingAs($recipient)->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/me/notifications/'.$item->id.'/read')->assertNotFound();
    }

    public function test_own_inbox_is_tenant_safe_read_is_idempotent_and_preferences_stop_delivery(): void
    {
        Mail::fake();
        extract($this->fixture());
        $this->actingAs($outsider)->getJson('/api/v1/me/notifications')->assertOk()->assertJsonCount(0, 'data');
        $this->putJson('/api/v1/me/notifications/'.$item->id.'/read')->assertNotFound();
        $this->actingAs($recipient)->getJson('/api/v1/me/notifications')->assertOk()
            ->assertJsonPath('meta.unread', 1)->assertJsonCount(1, 'data')
            ->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.employee_id');
        $this->putJson('/api/v1/me/notifications/'.$item->id.'/read')->assertOk();
        $this->putJson('/api/v1/me/notifications/'.$item->id.'/read')->assertOk();
        $this->getJson('/api/v1/me/notifications')->assertOk()->assertJsonPath('meta.unread', 0);
        DB::table('user_preferences')->insert(['user_id' => $recipient->id, 'notification_channels' => '{"email":false,"in_app":true}',
            'notification_rules' => '{}', 'locale' => 'ka', 'revision' => 1]);
        (new SendWorkflowNotification($item->id))->handle(app(WorkflowOutbox::class));
        Mail::assertNothingSent();
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'cancelled']);
    }

    public function test_worker_lease_and_retry_limit_prevent_parallel_or_unbounded_delivery(): void
    {
        Mail::fake();
        extract($this->fixture());
        DB::table('hrm_notifications')->where('id', $item->id)->update(['mail_state' => 'sending',
            'attempts' => 8, 'locked_until' => now()->addMinute(), 'lease_token' => (string) Str::uuid()]);
        $job = new SendWorkflowNotification($item->id);
        $job->handle(app(WorkflowOutbox::class));
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'sending']);
        $this->travel(61)->seconds();
        $job->handle(app(WorkflowOutbox::class));
        Mail::assertNothingSent();
        $this->assertDatabaseHas('hrm_notifications', ['id' => $item->id, 'mail_state' => 'failed', 'attempts' => 8]);
        $this->assertDatabaseHas('hrm_notification_deliveries', ['notification_id' => $item->id, 'result' => 'worker_lease_expired']);
    }

    public function test_four_language_templates_minimize_private_content_and_use_stable_message_id(): void
    {
        foreach (['ru', 'kk', 'en', 'ka'] as $locale) {
            $id = (string) Str::uuid(); $correlation = (string) Str::uuid();
            $mail = new WorkflowUpdateMail($id, $correlation, $locale);
            $text = $mail->render();
            $this->assertStringContainsString('https://platform.itpmining.kz/hrm', $text);
            $this->assertStringContainsString($correlation, $text);
            $this->assertStringNotContainsString('PRIVATE', $text);
            $this->assertSame($id.'@hrm.itpmining.kz', $mail->headers()->messageId);
        }
    }

    public function test_queued_reset_never_sends_superseded_expired_or_suspended_tokens(): void
    {
        extract($this->fixture());
        $broker = \Illuminate\Support\Facades\Password::broker();
        $old = $broker->createToken($recipient);
        $job = new QueuedResetPassword($old);
        $this->assertTrue($job->shouldSend($recipient, 'mail'));
        $latest = $broker->createToken($recipient);
        $this->assertFalse($job->shouldSend($recipient, 'mail'));
        $job = new QueuedResetPassword($latest);
        $this->travel(61)->minutes();
        $this->assertFalse($job->shouldSend($recipient, 'mail'));
        $recipient->account_status = \App\Domain\Identity\AccountState::Suspended;
        $this->assertFalse($job->shouldSend($recipient, 'mail'));
    }

    public function test_reset_queue_is_encrypted_and_dispatcher_refuses_synchronous_transport(): void
    {
        extract($this->fixture());
        config()->set('queue.default', 'sync');
        $this->artisan('hrm:notifications:dispatch')->assertFailed();
        Notification::fake();
        config()->set('queue.default', 'database');
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($recipient);
        $recipient->sendPasswordResetNotification($token);
        Notification::assertSentTo($recipient, QueuedResetPassword::class,
            fn ($notification) => $notification instanceof \Illuminate\Contracts\Queue\ShouldBeEncrypted
                && $notification->queue === 'hrm-notifications');
    }
}
