<?php

use App\Enums\WorkspaceRole;
use App\Models\AuditEvent;
use App\Models\OutgoingEmail;
use App\Models\User;
use App\Notifications\Announcement;
use App\Services\Outbox;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

function sentMail(): array
{
    return Mail::mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
}

beforeEach(function () {
    $this->operator = User::factory()->create(['is_operator' => true, 'email' => 'operator@example.com']);
});

it('keeps the operator-only mail screens away from everyone else', function () {
    [, $owner] = workspaceWith();

    $this->actingAs($owner)->getJson('/api/v1/admin/mail')->assertNotFound();
    $this->postJson('/api/v1/admin/broadcasts', ['subject' => 'x', 'body' => 'y', 'audience' => 'everyone', 'confirm_recipients' => 1])->assertNotFound();
});

it('logs system mail without its text', function () {
    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'operator@example.com'])->assertSuccessful();

    $row = $this->actingAs($this->operator)->getJson('/api/v1/admin/mail')
        ->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.to.email', 'operator@example.com')
        ->assertJsonPath('data.0.status', 'sent')
        ->assertJsonPath('counts.sent', 1)
        ->json('data.0');

    expect($row['kind'])->toBe('ResetPassword');
    $this->getJson("/api/v1/admin/mail/{$row['id']}")->assertOk()->assertJsonPath('data.body', null);
});

it('previews who an announcement reaches', function () {
    [$workspace, $owner] = workspaceWith();
    $member = addMember($workspace);
    addMember($workspace)->forceFill(['email_notifications' => false])->save();
    addMember($workspace)->forceFill(['suspended_at' => now()])->save();
    addMember($workspace)->forceFill(['email_verified_at' => null])->save();
    User::factory()->agent($workspace, $owner)->create();
    [$paid] = workspaceWith(WorkspaceRole::Admin);
    $paid->forceFill(['plan' => 'team'])->save();

    $count = fn (array $filter) => $this->actingAs($this->operator)
        ->postJson('/api/v1/admin/broadcasts/preview', $filter)->assertOk()->json('data.recipients');

    // The operator, the owner, the member and the admin of the other workspace.
    expect($count(['audience' => 'everyone']))->toBe(4)
        ->and($count(['audience' => 'everyone', 'important' => true]))->toBe(5)
        ->and($count(['audience' => 'owners']))->toBe(1)
        ->and($count(['audience' => 'admins']))->toBe(2)
        ->and($count(['audience' => 'everyone', 'plan' => 'team']))->toBe(1);

    $this->postJson('/api/v1/admin/broadcasts/preview', ['audience' => 'owners'])->assertJsonPath('data.sample', [$owner->email]);
    $this->postJson('/api/v1/admin/broadcasts/preview', ['audience' => 'nobody'])->assertUnprocessable();
    expect($member->email)->not->toBe($owner->email);
});

it('queues an announcement only when the confirmed number still holds, then sends it', function () {
    [$workspace, $owner] = workspaceWith();
    $member = addMember($workspace);
    $draft = ['subject' => 'Maintenance on Sunday', 'body' => "We will be **offline** for an hour.\n\n- from 10:00\n- <script>alert(1)</script>", 'audience' => 'everyone'];

    $this->actingAs($this->operator)->postJson('/api/v1/admin/broadcasts', [...$draft, 'confirm_recipients' => 2])
        ->assertStatus(409)->assertJsonPath('error.code', 'recipients_changed')->assertJsonPath('error.details.recipients', 3);
    expect(OutgoingEmail::query()->count())->toBe(0);

    $id = $this->postJson('/api/v1/admin/broadcasts', [...$draft, 'confirm_recipients' => 3])
        ->assertCreated()->assertJsonPath('data.recipients', 3)->assertJsonPath('data.waiting', 3)->json('data.id');

    $this->getJson('/api/v1/admin/mail?status=waiting')->assertJsonCount(3, 'data')->assertJsonPath('counts.waiting', 3);
    expect(sentMail())->toBe([])
        ->and(AuditEvent::query()->where('action', 'operator.broadcast_sent')->value('details'))
        ->toBe(['subject' => 'Maintenance on Sunday', 'audience' => 'everyone', 'confirm_recipients' => 3]);

    // One message by hand, the rest by the scheduled command.
    $first = OutgoingEmail::query()->where('to_email', $member->email)->firstOrFail();
    $this->getJson("/api/v1/admin/mail/{$first->id}")->assertJsonPath('data.body', $draft['body']);
    $this->postJson("/api/v1/admin/mail/{$first->id}/send")->assertOk()->assertJsonPath('data.status', 'sent');
    $this->postJson("/api/v1/admin/mail/{$first->id}/send")->assertStatus(409);

    $this->artisan('mail:send-waiting')->expectsOutput('Sent 2, failed 0.')->assertSuccessful();

    $mail = sentMail();
    expect($mail)->toHaveCount(3)
        ->and($mail[0]->getTo()[0]->getAddress())->toBe($member->email)
        ->and($mail[0]->getSubject())->toBe('Maintenance on Sunday')
        ->and($mail[0]->getHtmlBody())->toContain('offline</strong>')->toContain('from 10:00</li>')->toContain('&lt;script&gt;')->not->toContain('<script>')
        // Announcements are tracked by their own rows, not logged a second time.
        ->and(OutgoingEmail::query()->count())->toBe(3);

    $this->getJson('/api/v1/admin/broadcasts')->assertOk()
        ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.sent', 3)->assertJsonPath('data.0.waiting', 0);
    $this->getJson("/api/v1/admin/mail?broadcast={$id}&q=".urlencode($owner->email))->assertJsonCount(1, 'data');
});

it('retries a failing message and then leaves it failed', function () {
    workspaceWith();
    $this->actingAs($this->operator)->postJson('/api/v1/admin/broadcasts', ['subject' => 'Hi', 'body' => 'Text', 'audience' => 'owners', 'confirm_recipients' => 1])->assertCreated();
    $email = OutgoingEmail::query()->firstOrFail();

    Notification::shouldReceive('sendNow')->andThrow(new RuntimeException('Connection refused'));

    $this->postJson("/api/v1/admin/mail/{$email->id}/send")->assertStatus(502)->assertJsonPath('error.code', 'mail_failed');
    expect($email->refresh()->status)->toBe('waiting')->and($email->attempts)->toBe(1)->and($email->error)->toBe('Connection refused');

    app(Outbox::class)->sendWaiting(10);
    app(Outbox::class)->sendWaiting(10);
    expect($email->refresh()->status)->toBe('failed')->and($email->attempts)->toBe(3);

    // A failed message no longer goes out by itself.
    expect(app(Outbox::class)->sendWaiting(10))->toBe([0, 0]);
});

it('deletes a waiting message so that it is never sent', function () {
    workspaceWith();
    $this->actingAs($this->operator)->postJson('/api/v1/admin/broadcasts', ['subject' => 'Hi', 'body' => 'Text', 'audience' => 'owners', 'confirm_recipients' => 1])->assertCreated();
    $email = OutgoingEmail::query()->firstOrFail();

    $this->deleteJson("/api/v1/admin/mail/{$email->id}")->assertNoContent();
    $this->deleteJson("/api/v1/admin/mail/{$email->id}")->assertNotFound();

    $this->artisan('mail:send-waiting')->expectsOutput('Sent 0, failed 0.');
    expect(sentMail())->toBe([])->and(Announcement::safeMarkdown('a < b & c')->toHtml())->toBe('a &lt; b &amp; c');
});
