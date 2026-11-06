<?php

namespace Tests\Feature;

use App\Jobs\SyncGmailMailbox;
use App\Models\User;
use App\Services\ClientDirectory;
use App\Services\GmailSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GmailSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $connectionId;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->owner = User::factory()->create();
        app(ClientDirectory::class)->approveRecords($this->owner->id, [
            ['client' => 'Fixture Client', 'client_email' => 'sender@example.test'],
        ]);
        $this->connectionId = DB::table('mailbox_connections')->insertGetId([
            'user_id' => $this->owner->id, 'email' => 'mailbox@example.test', 'generation' => 'generation-one',
            'tokens' => Crypt::encryptString(json_encode([
                'access_token' => 'mock-access', 'refresh_token' => 'mock-refresh', 'expires_at' => time() + 3600,
            ])), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function step(): void
    {
        app(GmailSynchronizer::class)->runPage($this->connectionId, 'generation-one');
    }

    private function message(string $id, array $labels = ['INBOX'], string $from = 'sender@example.test', string $to = 'mailbox@example.test'): array
    {
        return [
            'id' => $id, 'threadId' => 'thread-'.$id,
            'internalDate' => (string) (1700000000000 + (int) $id * 1000),
            'labelIds' => $labels, 'payload' => [
                'mimeType' => 'text/plain',
                'headers' => [
                    ['name' => 'From', 'value' => $from], ['name' => 'To', 'value' => $to],
                    ['name' => 'Subject', 'value' => 'Message '.$id],
                ],
                'body' => ['data' => base64_encode('A client email with no approved accounting records.')],
            ],
        ];
    }

    public function test_client_history_is_paginated_and_incremental_rounds_use_a_time_overlap(): void
    {
        $round = 0;
        Http::fake([
            '*/users/me/messages/*' => fn ($request) => Http::response($this->message(basename(parse_url($request->url(), PHP_URL_PATH)))),
            '*/users/me/messages*' => function ($request) use (&$round) {
                if (! isset($request['q'])) {
                    return Http::response([]);
                }
                if (($request['pageToken'] ?? '') === 'page-two') {
                    return Http::response(['messages' => array_map(fn ($id) => ['id' => (string) $id], range(11, 15))]);
                }
                if (str_contains($request['q'], ' after:')) {
                    $round++;

                    return Http::response(['messages' => [['id' => '1'], ['id' => (string) (15 + $round)]]]);
                }

                return Http::response([
                    'messages' => array_map(fn ($id) => ['id' => (string) $id], range(1, 10)),
                    'nextPageToken' => 'page-two',
                ]);
            },
        ]);
        $this->actingAs($this->owner)->post('/gmail/sync')->assertRedirect();
        Queue::assertPushed(SyncGmailMailbox::class);
        $this->step();
        $this->assertDatabaseCount('client_requests', 10);
        $this->step();
        $this->assertDatabaseCount('client_requests', 15);
        $state = json_decode(DB::table('mailbox_connections')->value('sync_state'), true);
        $checkpoint = $state['checkpoint'];
        $this->step();
        $this->step();
        $this->assertDatabaseCount('client_requests', 17);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/messages?')
            && str_contains($request['q'], 'from:"sender@example.test"')
            && ! str_contains($request['q'], 'to:')
            && str_contains($request['q'], 'after:'.($checkpoint - 300)));
        $this->withoutVite()->get('/')->assertInertia(fn ($page) => $page
            ->component('Workspace', false)->where('stats.total', 17)
            ->where('mailbox.approved_contacts', 1)->where('requests.0.subject', 'Message 17'));
    }

    public function test_unapproved_sender_headers_are_checked_before_downloading_a_body(): void
    {
        Http::fake([
            '*/users/me/messages/1*' => Http::response($this->message('1', ['INBOX'], '"sender@example.test" <unapproved@example.test>')),
            '*/users/me/messages*' => Http::response(['messages' => [['id' => '1']]]),
        ]);
        $this->step();
        $this->assertDatabaseCount('client_requests', 0);
        Http::assertSent(fn ($request) => ($request['format'] ?? '') === 'metadata');
        Http::assertNotSent(fn ($request) => ($request['format'] ?? '') === 'full');
    }

    public function test_sent_replies_to_approved_contacts_are_excluded(): void
    {
        $sent = $this->message('1', ['SENT'], 'mailbox@example.test', '"Accounts, Client" <sender@example.test>, other@example.test');
        Http::fake([
            '*/users/me/messages/1*' => Http::response($sent),
            '*/users/me/messages*' => Http::response(['messages' => [['id' => '1']]]),
        ]);
        $this->step();
        $this->assertDatabaseCount('client_requests', 0);
        Http::assertNotSent(fn ($request) => ($request['format'] ?? '') === 'full');
    }

    public function test_only_incoming_client_mail_is_imported_and_old_non_client_records_are_hidden(): void
    {
        $messages = [
            '1' => $this->message('1', []), // Archived client email remains eligible.
            '2' => $this->message('2', ['SENT']),
            '3' => $this->message('3', ['DRAFT']),
            '4' => $this->message('4', ['SPAM']),
            '5' => $this->message('5', ['TRASH']),
            '6' => $this->message('6', ['INBOX'], 'unknown@example.test', 'sender@example.test'),
        ];
        Http::fake([
            '*/users/me/messages/*' => fn ($request) => Http::response($messages[basename(parse_url($request->url(), PHP_URL_PATH))]),
            '*/users/me/messages*' => Http::response(['messages' => array_map(fn ($id) => ['id' => (string) $id], range(1, 6))]),
        ]);
        $this->step();
        $this->assertDatabaseCount('client_requests', 1);
        $this->assertCount(1, Http::recorded(fn ($request) => ($request['format'] ?? '') === 'full'));
        $original = DB::table('client_requests')->first();
        foreach (array_slice($messages, 1, null, true) as $id => $message) {
            $details = json_decode($original->details, true);
            $details['sender_email'] = $id == 6 ? 'unknown@example.test' : 'sender@example.test';
            $details['gmail_labels'] = $message['labelIds'];
            DB::table('client_requests')->insert([
                'user_id' => $this->owner->id, 'gmail_message_id' => (string) $id,
                'gmail_mailbox_email' => 'mailbox@example.test', 'details' => json_encode($details),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($this->owner)->withoutVite()->get('/')->assertInertia(fn ($page) => $page
            ->component('Workspace', false)->has('requests', 1)->where('stats.total', 1)
            ->where('mailbox.imported_count', 1));
        $this->assertDatabaseCount('client_requests', 6);
    }

    public function test_no_google_calls_are_made_without_active_contacts(): void
    {
        DB::table('clients')->update(['is_active' => false]);
        Http::fake();
        $this->assertFalse(app(GmailSynchronizer::class)->request($this->connectionId));
        $this->step();
        Http::assertNothingSent();
        $this->assertSame('waiting_for_clients', DB::table('mailbox_connections')->value('sync_status'));
    }

    public function test_a_failed_page_resumes_and_keeps_messages_already_imported(): void
    {
        $failures = 0;
        Http::fake([
            '*/users/me/messages/*' => function ($request) use (&$failures) {
                $id = basename(parse_url($request->url(), PHP_URL_PATH));
                if ($id === '2' && $request['format'] === 'full' && $failures++ === 0) {
                    return Http::response(['error' => 'temporary'], 503);
                }

                return Http::response($this->message($id));
            },
            '*/users/me/messages*' => Http::response(['messages' => [['id' => '1'], ['id' => '2']]]),
        ]);
        try {
            $this->step();
            $this->fail('The failing page should pause.');
        } catch (RequestException) {
            $this->assertDatabaseCount('client_requests', 1);
        }
        $this->step();
        $this->assertDatabaseCount('client_requests', 2);
        $this->assertSame('idle', DB::table('mailbox_connections')->value('sync_status'));
    }

    public function test_reconnecting_during_import_stops_the_old_job(): void
    {
        Http::fake([
            '*/users/me/messages/1*' => function () {
                DB::table('mailbox_connections')->where('id', $this->connectionId)->update([
                    'generation' => 'generation-two', 'sync_status' => 'idle', 'sync_state' => null,
                ]);

                return Http::response($this->message('1'));
            },
            '*/users/me/messages*' => Http::response(['messages' => [['id' => '1']]]),
        ]);
        $this->step();
        $this->assertDatabaseCount('client_requests', 0);
        Http::assertNotSent(fn ($request) => ($request['format'] ?? '') === 'full');
    }

    public function test_large_contact_lists_use_separate_bounded_searches(): void
    {
        $extra = array_map(fn ($id) => ['client' => 'Fixture Client', 'client_email' => "contact{$id}@example.test"], range(1, 10));
        app(ClientDirectory::class)->approveRecords($this->owner->id, $extra);
        Http::fake(['*/users/me/messages*' => Http::response(['messages' => []])]);
        $this->step();
        $this->assertSame('queued', DB::table('mailbox_connections')->value('sync_status'));
        $this->step();
        $this->assertSame('idle', DB::table('mailbox_connections')->value('sync_status'));
        Http::assertSentCount(2);
    }

    public function test_large_result_pages_reuse_ids_and_skip_existing_message_downloads(): void
    {
        Http::fake([
            '*/users/me/messages/*' => fn ($request) => Http::response($this->message(basename(parse_url($request->url(), PHP_URL_PATH)))),
            '*/users/me/messages*' => Http::response([
                'messages' => array_map(fn ($id) => ['id' => (string) $id], range(1, 12)),
            ]),
        ]);
        $this->step();
        $this->assertDatabaseCount('client_requests', 10);
        $this->assertSame('queued', DB::table('mailbox_connections')->value('sync_status'));
        $this->step();
        $this->assertDatabaseCount('client_requests', 12);
        $this->assertCount(1, Http::recorded(fn ($request) => isset($request['q'])));
        $this->step();
        $this->assertDatabaseCount('client_requests', 12);
        $this->assertCount(12, Http::recorded(fn ($request) => ($request['format'] ?? '') === 'full'));
    }

    public function test_mailbox_browsing_is_paginated_and_search_is_workspace_scoped(): void
    {
        foreach (range(1, 55) as $index) {
            DB::table('client_requests')->insert([
                'user_id' => $this->owner->id, 'details' => json_encode([
                    'subject' => 'Message '.$index, 'email' => 'Body '.$index, 'status' => 'Imported',
                    'source' => null, 'draft' => '', 'attachments' => [], 'audit' => [],
                ]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->actingAs($this->owner)->withoutVite()->get('/')->assertInertia(fn ($page) => $page
            ->component('Workspace', false)->has('requests', 50)->where('pagination.total', 55));
        $this->get('/?page=2')->assertInertia(fn ($page) => $page->component('Workspace', false)->has('requests', 5));
        $this->get('/?search=Message%2055')->assertInertia(fn ($page) => $page
            ->component('Workspace', false)->has('requests', 1)->where('requests.0.subject', 'Message 55'));
        $this->actingAs(User::factory()->create())->get('/?request=1')->assertInertia(fn ($page) => $page
            ->component('Workspace', false)->has('requests', 0)->where('selectedRequest', null));
    }
}
