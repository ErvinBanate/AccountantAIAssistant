<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiDraftingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_ai_proposal_preserves_sources_and_requires_review(): void
    {
        config(['ai.key' => 'test-api-key']);
        $user = User::factory()->create();
        $this->actingAs($user)->post('/requests', [
            'email' => 'Please share Harbor Studio expenses for 2026-10.',
        ])->assertRedirect('/');
        $details = json_decode(DB::table('client_requests')->value('details'), true);
        $proposal = 'Hello, the approved expenses for Harbor Studio in 2026-10 are PHP 12,500.00. Please review the attached ledger excerpt.';
        $responses = Http::sequence()->push([
            'status' => 'completed', 'output' => [[
                'type' => 'message', 'content' => [[
                    'type' => 'output_text', 'text' => json_encode(['draft' => $proposal, 'cited_rows' => [2]]),
                ]],
            ]],
        ])->push([
            'status' => 'completed', 'output' => [[
                'type' => 'message', 'content' => [[
                    'type' => 'output_text', 'text' => json_encode(['draft' => 'Unsupported amount PHP 999999.99.', 'cited_rows' => [2]]),
                ]],
            ]],
        ]);
        Http::fake(['api.openai.com/*' => $responses]);
        $this->post('/requests/1/ai-draft', ['draft' => $details['draft']])->assertRedirect('/');
        $updated = json_decode(DB::table('client_requests')->value('details'), true);
        $this->assertSame($proposal, $updated['draft']);
        $this->assertSame('Draft ready', $updated['status']);
        $this->assertSame($details['source'], $updated['source']);
        $this->assertSame($details['attachments'], $updated['attachments']);

        $this->post('/requests/1/ai-draft', ['draft' => $proposal])->assertSessionHasErrors('ai');
        $this->assertSame($proposal, json_decode(DB::table('client_requests')->value('details'), true)['draft']);
    }
}
