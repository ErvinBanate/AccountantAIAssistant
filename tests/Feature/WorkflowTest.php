<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_scoped_request_can_only_be_sent_after_review_and_approval(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner)->post('/requests', [
            'email' => 'Hello, could you confirm the approved expense total for Harbor Studio for 2026-10?',
        ])->assertRedirect('/');

        $this->actingAs($other)->get('/requests/1/attachment')->assertNotFound();
        $this->post('/requests/1/send')->assertNotFound();

        $this->actingAs($owner)->get('/requests/1/attachment')->assertOk();
        $this->post('/requests/1/send')->assertConflict();
        $this->post('/requests/1/review', ['draft' => 'Reviewed draft'])->assertRedirect('/');
        $this->post('/requests/1/approve', ['draft' => 'Approved draft'])->assertRedirect('/');
        $this->post('/requests/1/send')->assertRedirect('/');
        $this->post('/requests/1/send')->assertConflict();

        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Workspace', false)
            ->where('requests.0.status', 'Sent')
            ->where('requests.0.draft', 'Approved draft')
            ->where('requests.0.source.total', 'PHP 12,500.00')
            ->where('requests.0.audit.6.event', 'Send simulated'));
    }

    public function test_an_unmatched_email_does_not_expose_ledger_data(): void
    {
        $this->actingAs(User::factory()->create())->post('/requests', [
            'email' => 'Please send expenses for Harbor Studio.',
        ])->assertRedirect('/');

        $this->withoutVite()->get('/')->assertOk()->assertInertia(fn ($page) => $page
            ->component('Workspace', false)
            ->where('requests.0.status', 'Missing information')
            ->where('requests.0.source', null));
    }
}
