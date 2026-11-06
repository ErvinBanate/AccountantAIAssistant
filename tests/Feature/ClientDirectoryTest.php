<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClientDirectory;
use App\Services\LedgerWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ClientDirectoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_clients_and_contacts_are_scoped_and_support_multiple_addresses(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post('/clients', [
            'name' => 'Northstar Trading', 'emails' => ['MARA@northstar.example', 'finance@northstar.example'],
        ])->assertRedirect();
        $id = DB::table('clients')->value('id');
        $this->assertSame(['finance@northstar.example', 'mara@northstar.example'], app(ClientDirectory::class)->addresses($owner->id));
        $this->post('/clients', ['name' => 'northstar trading', 'emails' => ['new@example.test']])->assertSessionHasErrors('name');
        $this->post('/clients', ['name' => 'Invalid', 'emails' => ['invalid-address']])->assertSessionHasErrors('emails.0');
        $this->assertDatabaseCount('clients', 1);
        $this->actingAs($other)->post("/clients/{$id}", [
            'emails' => ['foreign@example.test'], 'is_active' => true,
        ])->assertNotFound();
        $this->withoutVite()->get('/')->assertInertia(fn ($page) => $page
            ->component('Workspace', false)->has('clientDirectory', 0));
        $this->actingAs($owner)->post("/clients/{$id}", [
            'emails' => ['finance@northstar.example'], 'is_active' => false,
        ])->assertRedirect();
        $this->assertSame([], app(ClientDirectory::class)->addresses($owner->id));
        $this->assertDatabaseCount('client_contacts', 1);
    }

    public function test_deleting_a_client_is_scoped_removes_contacts_and_invalidates_sync_without_deleting_records(): void
    {
        Queue::fake();
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $directory = app(ClientDirectory::class);
        app(LedgerWorkflow::class)->import($owner->id, 'ledger.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $id = DB::table('clients')->where('name', 'Northstar Trading')->value('id');
        $directory->approveRecords($owner->id, [['client' => 'Shared contact client', 'client_email' => 'mara@northstar.example']]);
        DB::table('mailbox_connections')->insert([
            'user_id' => $owner->id, 'email' => 'mailbox@example.test', 'tokens' => 'unused',
            'generation' => 'old-generation', 'sync_state' => '{"complete":true}',
            'sync_status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('client_requests')->insert([
            'user_id' => $owner->id, 'details' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->actingAs($other)->delete("/clients/{$id}")->assertNotFound();
        $this->assertDatabaseHas('clients', ['id' => $id]);
        $this->actingAs($owner)->delete("/clients/{$id}")->assertRedirect();
        $this->assertDatabaseMissing('clients', ['id' => $id]);
        $this->assertDatabaseMissing('client_contacts', ['client_id' => $id]);
        $this->assertDatabaseCount('workspace_sources', 1);
        $this->assertDatabaseCount('client_requests', 1);
        $this->assertSame(['Shared contact client'], $directory->senderClients($owner->id, 'mara@northstar.example'));
        $connection = DB::table('mailbox_connections')->first();
        $this->assertNotSame('old-generation', $connection->generation);
        $this->assertNull($connection->sync_state);
        $this->delete("/clients/{$id}")->assertNotFound();
        foreach ($directory->clients($owner->id)->pluck('id') as $remainingId) {
            $this->delete("/clients/{$remainingId}")->assertRedirect();
        }
        $this->assertSame([], $directory->addresses($owner->id));
        $this->assertSame('waiting_for_clients', DB::table('mailbox_connections')->value('sync_status'));
    }

    public function test_a_contact_change_invalidates_pending_sync_and_keeps_existing_imports(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->post('/clients', [
            'name' => 'Client', 'emails' => ['old@example.test'],
        ])->assertRedirect();
        $id = DB::table('clients')->value('id');
        DB::table('mailbox_connections')->insert([
            'user_id' => $owner->id, 'email' => 'mailbox@example.test', 'tokens' => 'unused',
            'generation' => 'old-generation', 'sync_state' => '{"complete":true}',
            'sync_status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('client_requests')->insert([
            'user_id' => $owner->id, 'details' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post("/clients/{$id}", [
            'emails' => ['new@example.test'], 'is_active' => true,
        ])->assertRedirect();
        $connection = DB::table('mailbox_connections')->first();
        $this->assertNotSame('old-generation', $connection->generation);
        $this->assertNull($connection->sync_state);
        $this->assertDatabaseCount('client_requests', 1);
        $this->assertSame(['new@example.test'], app(ClientDirectory::class)->addresses($owner->id));
    }

    public function test_new_contacts_use_their_client_ledger_without_authorizing_other_clients(): void
    {
        $owner = User::factory()->create();
        app(LedgerWorkflow::class)->import($owner->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $id = DB::table('clients')->where('name', 'Northstar Trading')->value('id');
        $this->actingAs($owner)->post("/clients/{$id}", [
            'emails' => ['mara@northstar.example', 'assistant@northstar.example'], 'is_active' => true,
        ])->assertRedirect();
        $input = [
            'email' => 'Please send our expenses for 2026-Q3.',
            'sender_email' => 'assistant@northstar.example', 'gmail_message_id' => 'message-new-contact',
        ];
        $allowed = app(LedgerWorkflow::class)->prepare($owner->id, $input);
        $this->assertSame('Draft ready', $allowed['status']);
        $this->assertSame('Northstar Trading', $allowed['source']['client']);
        $denied = app(LedgerWorkflow::class)->prepare($owner->id, [
            ...$input, 'client' => 'Meridian Studio', 'period' => '2026-10',
            'email' => 'Please send Meridian Studio records for 2026-10.',
        ], true);
        $this->assertSame('Missing information', $denied['status']);
        $this->assertNull($denied['source']);
    }

    public function test_shared_contacts_need_client_clarification_before_selecting_financial_records(): void
    {
        $owner = User::factory()->create();
        app(LedgerWorkflow::class)->import($owner->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        foreach (['Northstar Trading', 'Meridian Studio'] as $name) {
            $id = DB::table('clients')->where('name', $name)->value('id');
            $this->actingAs($owner)->post("/clients/{$id}", [
                'emails' => ['shared@example.test'], 'is_active' => true,
            ])->assertRedirect();
        }
        $result = app(LedgerWorkflow::class)->prepare($owner->id, [
            'email' => 'Please send records for 2026-10.',
            'sender_email' => 'shared@example.test', 'gmail_message_id' => 'shared-contact-message',
        ]);
        $this->assertSame('Missing information', $result['status']);
        $this->assertNull($result['source']);
    }
}
