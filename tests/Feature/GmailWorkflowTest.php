<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClientDirectory;
use App\Services\GmailMailbox;
use App\Services\GmailSynchronizer;
use App\Services\LedgerWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GmailWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function drain(User $user): void
    {
        for ($page = 0; $page < 10; $page++) {
            $connection = DB::table('mailbox_connections')->where('user_id', $user->id)->first();
            app(GmailSynchronizer::class)->runPage($connection->id, $connection->generation);
            if (DB::table('mailbox_connections')->where('id', $connection->id)->value('sync_status') === 'idle') {
                return;
            }
        }
        $this->fail('Sync did not finish.');
    }

    private function connection(User $user): void
    {
        DB::table('mailbox_connections')->insert([
            'user_id' => $user->id, 'email' => 'accounting@example.test', 'generation' => 'test-generation',
            'tokens' => Crypt::encryptString(json_encode([
                'access_token' => 'test-gmail-token', 'refresh_token' => 'test-refresh',
                'expires_at' => time() + 3600,
            ])), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function incoming(string $sender = 'mara@northstar.example'): array
    {
        return [
            'id' => 'message-123', 'threadId' => 'thread-123',
            'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => $sender],
                    ['name' => 'Subject', 'value' => 'Q3 2026 expense records'],
                    ['name' => 'Message-ID', 'value' => '<original@example.test>'],
                ],
                'body' => ['data' => rtrim(strtr(base64_encode('Please share our expenses for Q3 2026.'), '+/', '-_'), '=')],
            ],
        ];
    }

    public function test_sync_is_idempotent_and_gmail_send_requires_approval(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        app(LedgerWorkflow::class)->import($user->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['historyId' => '100']),
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response(['historyId' => '200', 'history' => []]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'sent-123']),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/message-123*' => Http::response($this->incoming()),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response(['messages' => [['id' => 'message-123']]]),
        ]);

        $this->actingAs($user)->post('/gmail/sync')->assertRedirect();
        $this->drain($user);
        $this->post('/gmail/sync')->assertRedirect();
        $this->drain($user);
        $this->assertDatabaseCount('client_requests', 1);
        $details = json_decode(DB::table('client_requests')->value('details'), true);
        $this->assertSame('Draft ready', $details['status']);
        $this->assertSame('Northstar Trading', $details['client']);

        $this->post('/requests/1/send')->assertConflict();
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/send'));
        $this->post('/requests/1/review', ['draft' => $details['draft']])->assertRedirect('/');
        $this->post('/requests/1/approve', ['draft' => $details['draft']])->assertRedirect('/');
        $this->post('/requests/1/send')->assertRedirect('/');
        $this->post('/requests/1/send')->assertConflict();
        $this->assertSame('Sent', json_decode(DB::table('client_requests')->value('details'), true)['status']);
        $sendCalls = Http::recorded(fn ($request) => str_ends_with($request->url(), '/send'));
        $this->assertCount(1, $sendCalls);
        $raw = base64_decode(strtr($sendCalls->first()[0]['raw'], '-_', '+/'));
        $this->assertStringContainsString('To: mara@northstar.example', $raw);
        $this->assertStringContainsString('ledger-excerpt.csv', $raw);
        $this->withoutVite()->get('/')->assertOk()->assertDontSee('test-gmail-token');
    }

    public function test_an_unknown_sender_cannot_match_client_records_and_oauth_checks_state(): void
    {
        $user = User::factory()->create();
        app(LedgerWorkflow::class)->import($user->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $details = app(LedgerWorkflow::class)->prepare($user->id, [
            'email' => 'Please send Northstar Trading records for 2026-Q3.',
            'sender_email' => 'unknown@example.test', 'gmail_message_id' => 'untrusted-message',
        ]);
        $this->assertSame('Missing information', $details['status']);
        $this->assertNull($details['source']);
        $spoofed = app(GmailMailbox::class)->message($this->incoming('"mara@northstar.example" <unknown@example.test>'));
        $this->assertSame('unknown@example.test', $spoofed['sender_email']);
        $this->actingAs($user)->withSession(['gmail_oauth' => [
            'state' => 'expected', 'user_id' => $user->id, 'expires' => time() + 600,
        ]])->get('/gmail/callback?state=wrong&code=example')->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_failed_delivery_is_recorded_and_not_automatically_retried(): void
    {
        $user = User::factory()->create();
        $this->connection($user);
        app(LedgerWorkflow::class)->import($user->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $details = app(LedgerWorkflow::class)->prepare($user->id, [
            'email' => 'Please share Northstar Trading records for 2026-Q3.',
            'sender_email' => 'mara@northstar.example', 'gmail_message_id' => 'message-123',
            'gmail_thread_id' => 'thread-123',
        ]);
        $details['status'] = 'Approved';
        $details['mailbox_email'] = 'accounting@example.test';
        DB::table('client_requests')->insert([
            'user_id' => $user->id, 'details' => json_encode($details),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Http::fake([
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['error' => 'unavailable'], 503),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages/message-123*' => Http::response($this->incoming()),
        ]);
        $this->actingAs($user)->post('/requests/1/send')->assertRedirect('/');
        $this->assertSame('Failed', json_decode(DB::table('client_requests')->value('details'), true)['status']);
        $this->post('/requests/1/send')->assertConflict();
        $this->assertCount(1, Http::recorded(fn ($request) => str_ends_with($request->url(), '/send')));
    }

    public function test_oauth_saves_encrypted_tokens_and_refreshes_expired_access(): void
    {
        $user = User::factory()->create();
        app(ClientDirectory::class)->approveRecords($user->id, [
            ['client' => 'Fixture Client', 'client_email' => 'fixture@example.test'],
        ]);
        config(['mailbox.client_id' => 'test-client', 'mailbox.client_secret' => 'test-secret']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::sequence()
                ->push(['access_token' => 'short-lived', 'refresh_token' => 'offline-token', 'expires_in' => 1])
                ->push(['access_token' => 'renewed', 'expires_in' => 3600]),
            'https://gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'accounting@example.test', 'historyId' => '100']),
            'https://gmail.googleapis.com/gmail/v1/users/me/history*' => Http::response(['historyId' => '200', 'history' => []]),
            'https://gmail.googleapis.com/gmail/v1/users/me/messages*' => Http::response(['messages' => []]),
        ]);
        $this->actingAs($user)->withSession(['gmail_oauth' => [
            'state' => 'expected', 'user_id' => $user->id, 'expires' => time() + 600,
        ]])->get('/gmail/callback?state=expected&code=authorization-code')
            ->assertRedirect('/#ConnectGmail')->assertSessionMissing('gmail_oauth');
        $stored = DB::table('mailbox_connections')->value('tokens');
        $this->assertStringNotContainsString('short-lived', $stored);
        $this->assertSame('offline-token', json_decode(Crypt::decryptString($stored), true)['refresh_token']);
        $this->post('/gmail/sync')->assertRedirect();
        $this->drain($user);
        Http::assertSent(fn ($request) => $request->url() === 'https://oauth2.googleapis.com/token'
            && $request['grant_type'] === 'refresh_token');
        $tokens = json_decode(Crypt::decryptString(DB::table('mailbox_connections')->value('tokens')), true);
        $this->assertSame('renewed', $tokens['access_token']);
        $this->assertSame('offline-token', $tokens['refresh_token']);
    }
}
