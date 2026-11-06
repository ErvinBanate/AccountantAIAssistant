<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\LedgerWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class WorkspaceFeaturesTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_can_register_and_sign_back_in(): void
    {
        $this->post('/register', [
            'name' => 'Demo Accountant', 'email' => 'reviewer@example.test',
            'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password',
        ])->assertRedirect('/#ConnectGmail');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
        $this->post('/login', [
            'email' => 'reviewer@example.test', 'password' => 'a-long-test-password',
        ])->assertRedirect('/#ConnectGmail');
        $this->assertAuthenticated();
    }

    public function test_inertia_login_and_registration_use_redirects_without_a_full_browser_reload(): void
    {
        $user = User::factory()->create(['password' => 'a-long-test-password']);
        $this->withHeader('X-Inertia', 'true')->post('/login', [
            'email' => $user->email, 'password' => 'incorrect-password',
        ])->assertSessionHasErrors('email');
        $this->assertGuest();
        $this->post('/login', [
            'email' => $user->email, 'password' => 'a-long-test-password',
        ])->assertRedirect('/')->assertHeaderMissing('X-Inertia-Location');
        $this->assertAuthenticatedAs($user);
        $this->post('/logout');
        DB::table('mailbox_connections')->insert([
            'user_id' => $user->id, 'email' => 'mailbox@example.test', 'tokens' => 'unused',
            'generation' => 'fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->post('/login', [
            'email' => $user->email, 'password' => 'a-long-test-password',
        ])->assertRedirect('/')->assertHeaderMissing('X-Inertia-Location');
        $this->post('/logout');
        $this->post('/register', [
            'name' => 'New Accountant', 'email' => 'new@example.test',
            'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password',
        ])->assertRedirect('/')->assertHeaderMissing('X-Inertia-Location');
        $this->assertAuthenticated();
    }

    public function test_sources_are_scoped_and_drafts_preserve_their_approved_snapshot(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $csv = file_get_contents(database_path('fixtures/workspace-ledger.csv'));
        $this->actingAs($owner)->post('/sources', [
            'file' => UploadedFile::fake()->createWithContent('ledger.csv', $csv),
        ])->assertRedirect();
        $this->post('/requests', [
            'email' => 'Please send Northstar Trading expenses for Q3 2026.',
        ])->assertRedirect('/');
        $original = json_decode(DB::table('client_requests')->value('details'), true);
        $this->assertSame('Draft ready', $original['status']);
        $this->assertCount(3, $original['source']['records']);
        $this->assertSame('PHP 156,900.00', $original['source']['total']);

        $this->post('/requests/1/draft', ['draft' => 'Saved wording'])->assertRedirect();
        $this->assertSame('Saved wording', json_decode(DB::table('client_requests')->value('details'), true)['draft']);
        $this->post('/sources', [
            'file' => UploadedFile::fake()->createWithContent('ledger-v2.csv', str_replace('48500.00', '50000.00', $csv)),
        ])->assertRedirect();
        $this->get('/requests/1/attachment')->assertOk()->assertSee('48500.00')->assertDontSee('50000.00');

        $this->actingAs($other)->get('/requests/1/attachment')->assertNotFound();
        $this->post('/requests/1/draft', ['draft' => 'Unauthorized'])->assertNotFound();
        $this->post('/requests', [
            'email' => 'Please send Northstar Trading expenses for 2026-Q3.',
        ])->assertRedirect('/');
        $this->assertNull(json_decode(DB::table('client_requests')->latest('id')->value('details'), true)['source']);
    }

    public function test_sources_can_be_replaced_and_removed_without_changing_existing_snapshots(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $csv = file_get_contents(database_path('fixtures/workspace-ledger.csv'));
        $workflow = app(LedgerWorkflow::class);
        $workflow->import($owner->id, 'original.csv', $csv);
        $id = DB::table('workspace_sources')->value('id');
        $this->actingAs($owner)->post('/requests', ['email' => 'Northstar Trading expenses for 2026-Q3'])->assertRedirect();
        $this->actingAs($other)->post("/sources/{$id}", [
            'file' => UploadedFile::fake()->createWithContent('replacement.csv', $csv),
        ])->assertNotFound();
        $this->delete("/sources/{$id}")->assertNotFound();
        $this->actingAs($owner)->post("/sources/{$id}", [
            'file' => UploadedFile::fake()->createWithContent('bad.csv', 'invalid'),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseHas('workspace_sources', ['id' => $id, 'name' => 'original.csv']);
        $this->post("/sources/{$id}", [
            'file' => UploadedFile::fake()->createWithContent('replacement.csv', str_replace('48500.00', '50000.00', $csv)),
        ])->assertRedirect();
        $this->assertDatabaseCount('workspace_sources', 1);
        $new = $workflow->prepare($owner->id, ['email' => 'Northstar Trading expenses for 2026-Q3']);
        $this->assertSame('PHP 158,400.00', $new['source']['total']);
        $this->get('/requests/1/attachment')->assertOk()->assertSee('48500.00')->assertDontSee('50000.00');
        $contacts = DB::table('client_contacts')->count();
        $this->delete("/sources/{$id}")->assertRedirect();
        $this->assertDatabaseCount('workspace_sources', 0);
        $this->assertDatabaseCount('client_contacts', $contacts);
        $this->get('/requests/1/attachment')->assertOk()->assertSee('48500.00');
        $missing = $workflow->prepare($owner->id, ['email' => 'Northstar Trading expenses for 2026-Q3']);
        $this->assertNull($missing['source']);
        $this->delete("/sources/{$id}")->assertNotFound();
    }

    public function test_legacy_row_column_attachments_remain_valid_but_changed_records_are_rejected(): void
    {
        $user = User::factory()->create();
        $workflow = app(LedgerWorkflow::class);
        $workflow->import($user->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $details = $workflow->prepare($user->id, ['email' => 'Please send Northstar Trading expenses for 2026-Q3.']);
        $stream = fopen('php://temp', 'r+');
        fputcsv($stream, ['client', 'period', 'description', 'currency', 'amount', 'source', 'row'], escape: '');
        foreach ($details['source']['records'] as $record) {
            fputcsv($stream, [$record['client'], $record['period'], $record['description'],
                $record['currency'], $record['amount'], $record['workbook'], $record['row']], escape: '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        $details['attachments'][0]['contents'] = $contents;
        $details['attachments'][0]['sha256'] = hash('sha256', $contents);
        $this->assertSame($contents, $workflow->attachment($details));
        array_pop($details['source']['records']);
        $this->expectException(HttpException::class);
        $workflow->attachment($details);
    }

    public function test_malformed_ledgers_and_ambiguous_requests_do_not_produce_attachments(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/sources', [
            'file' => UploadedFile::fake()->createWithContent('broken.csv', "client,amount\nExample,100.00"),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('workspace_sources', 0);
        app(LedgerWorkflow::class)->import($user->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $this->post('/requests', [
            'email' => 'Send Northstar Trading and Meridian Studio records for 2026-10 and 2026-Q3.',
        ])->assertRedirect('/');
        $details = json_decode(DB::table('client_requests')->value('details'), true);
        $this->assertSame('Missing information', $details['status']);
        $this->assertSame([], $details['attachments']);
        $this->get('/requests/1/attachment')->assertConflict();
        $this->post('/requests/1/clarify', [
            'client' => 'Northstar Trading', 'period' => '2026-Q3',
        ])->assertRedirect();
        $clarified = json_decode(DB::table('client_requests')->value('details'), true);
        $this->assertSame('Draft ready', $clarified['status']);
        $this->assertSame('Northstar Trading', $clarified['source']['client']);
        $this->assertCount(3, $clarified['source']['records']);
    }

    public function test_missing_period_can_be_clarified_before_review(): void
    {
        $user = User::factory()->create();
        app(LedgerWorkflow::class)->import($user->id, 'approved.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')));
        $this->actingAs($user)->post('/requests', [
            'email' => 'Please send Harbor & Co. tax records.',
        ])->assertRedirect('/');
        $this->post('/requests/1/clarify', [
            'client' => 'Harbor & Co.', 'period' => '2026-10',
        ])->assertRedirect();
        $details = json_decode(DB::table('client_requests')->value('details'), true);
        $this->assertSame('Draft ready', $details['status']);
        $this->assertSame('2026-10', $details['source']['period']);

        $details['attachments'][0]['contents'] .= 'modified';
        DB::table('client_requests')->where('id', 1)->update(['details' => json_encode($details)]);
        $this->post('/requests/1/review', ['draft' => $details['draft']])->assertConflict();
    }
}
