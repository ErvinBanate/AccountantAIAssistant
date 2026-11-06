<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AiDrafting;
use App\Services\ClientDirectory;
use App\Services\GmailMailbox;
use App\Services\LedgerWorkflow;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class WorkflowController extends Controller
{
    public function __construct(private LedgerWorkflow $workflow) {}

    public function index(Request $request)
    {
        $user = $request->user();
        $filters = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'search' => ['nullable', 'string', 'max:200'],
            'status' => ['nullable', 'string', 'max:30'],
            'request' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = $user ? $this->workspace($request) : null;
        if ($query && ! empty($filters['search'])) {
            $search = '%'.$filters['search'].'%';
            $query->where(function ($match) use ($search) {
                $match->where('details->client', 'like', $search)
                    ->orWhere('details->sender_email', 'like', $search)
                    ->orWhere('details->subject', 'like', $search)
                    ->orWhere('details->email', 'like', $search);
            });
        }
        if ($query && ! empty($filters['status'])) {
            $query->where('details->status', $filters['status']);
        }
        $page = $query?->orderByRaw('COALESCE(gmail_received_at, created_at) DESC')->orderByDesc('id')->paginate(50);
        $serialize = function ($row) {
            $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            // Attachment content and mailbox tokens stay on the server.
            $details['attachments'] = array_map(function ($file) {
                unset($file['contents']);

                return $file;
            }, $details['attachments']);

            return ['id' => $row->id, 'created_at' => Carbon::parse($row->gmail_received_at ?? $row->created_at)->toIso8601String(), ...$details];
        };
        $requests = $user ? $page->getCollection()->map($serialize) : [];
        $selected = $user && ! empty($filters['request']) ? $this->workspace($request)->find($filters['request']) : null;
        $sources = $user ? $this->workflow->sources($user->id)->latest('id')->get()->map(function ($source) {
            $records = json_decode($source->records, true, flags: JSON_THROW_ON_ERROR);

            return [
                'id' => $source->id, 'name' => $source->name,
                'rows' => count($records), 'clients' => array_values(array_unique(array_column($records, 'client'))),
                'is_demo' => (bool) $source->is_demo, 'created_at' => $source->created_at,
                'updated_at' => $source->updated_at,
            ];
        }) : [];
        $mailbox = $user ? DB::table('mailbox_connections')->where('user_id', $user->id)->first() : null;

        return Inertia::render('Workspace', [
            'signedIn' => (bool) $user,
            'user' => $user ? ['name' => $user->name, 'email' => $user->email] : null,
            'requests' => $requests, 'sources' => $sources,
            'clientDirectory' => $user ? app(ClientDirectory::class)->listing($user->id) : [],
            'selectedRequest' => $selected ? $serialize($selected) : null,
            'reviewRequests' => $user ? $this->workspace($request)->whereIn('details->status', ['Draft ready', 'In review', 'Approved'])->latest('id')->limit(100)->get()->map($serialize) : [],
            'documentRequests' => $user ? $this->workspace($request)->whereJsonLength('details->attachments', '>', 0)->latest('id')->limit(100)->get()->map($serialize) : [],
            'activityRequests' => $user ? $this->workspace($request)->latest('updated_at')->limit(100)->get()->map($serialize) : [],
            'pagination' => $page ? ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()] : null,
            'filters' => ['search' => $filters['search'] ?? '', 'status' => $filters['status'] ?? ''],
            'stats' => $user ? [
                'total' => $this->workspace($request)->count(),
                'review' => $this->workspace($request)->where('details->status', 'In review')->count(),
                'ready' => $this->workspace($request)->where('details->status', 'Draft ready')->count(),
                'missing' => $this->workspace($request)->where('details->status', 'Missing information')->count(),
            ] : null,
            'mailbox' => [
                'configured' => (bool) (config('mailbox.client_id') && config('mailbox.client_secret')),
                'email' => $mailbox?->email, 'synced_at' => $mailbox?->synced_at,
                'sync_status' => $mailbox?->sync_status,
                'sync_error' => $mailbox?->sync_error,
                'imported_count' => $mailbox ? $this->workspace($request)->where('gmail_mailbox_email', $mailbox->email)->count() : 0,
                'approved_contacts' => $user ? count(app(ClientDirectory::class)->addresses($user->id)) : 0,
            ],
            'demoAvailable' => app()->environment('local'),
            'aiAvailable' => (bool) config('ai.key'),
            'notice' => $request->session()->get('notice'),
        ]);
    }

    public function demo(Request $request)
    {
        abort_unless(app()->environment('local'), 404);
        if (! $request->user()) {
            $user = User::create([
                'name' => 'Ervin Banate', 'email' => Str::uuid().'@example.test',
                'password' => Str::random(64),
            ]);
            Auth::login($user);
            $request->session()->regenerate();
        }
        DB::transaction(function () use ($request) {
            if ($this->workspace($request)->exists() || $this->workflow->sources($request->user()->id)->exists()) {
                return;
            }
            $this->workflow->import($request->user()->id, 'Client ledger.csv', file_get_contents(database_path('fixtures/workspace-ledger.csv')), true);
            $samples = [
                ['client' => 'Northstar Trading', 'period' => '2026-Q3', 'subject' => 'Q3 expense breakdown and invoices',
                    'sender_email' => 'mara@northstar.example',
                    'email' => "Hi team,\n\nCould you send us our 2026-Q3 operating expense breakdown for Northstar Trading and supporting records?\n\nThank you,\nMara"],
                ['client' => 'Meridian Studio', 'period' => '2026-10', 'subject' => 'October account statement request',
                    'sender_email' => 'finance@meridian.example',
                    'email' => "Hello,\n\nPlease share the Meridian Studio account statement for 2026-10 so we can reconcile the month.\n\nRegards,\nLea"],
                ['client' => 'Harbor & Co.', 'subject' => 'Request for tax payment records',
                    'sender_email' => 'admin@harbor.example',
                    'email' => "Good morning,\n\nCan you send our Harbor & Co. tax payment records? We need copies for our files.\n\nThanks,\nPaolo"],
                ['client' => 'Pinecrest Retail', 'period' => '2026-10', 'subject' => 'Supplier payment confirmation',
                    'sender_email' => 'accounts@pinecrest.example',
                    'email' => "Hi,\n\nCould you confirm the recorded payment for Apex Supplies for Pinecrest Retail in 2026-10?\n\nThank you,\nNina"],
            ];
            foreach (array_reverse($samples, true) as $index => $sample) {
                $details = $this->workflow->prepare($request->user()->id, $sample);
                if (in_array($index, [0, 3])) {
                    $details['status'] = 'In review';
                    $details['audit'][] = $this->workflow->event($request->user()->id, 'In review');
                }
                $this->insert($request, $details);
            }
        });

        return redirect('/')->with('notice', 'Your private demo workspace is ready.');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    public function ingest(Request $request)
    {
        $input = $request->validate([
            'email' => ['required', 'string', 'max:10000'],
            'subject' => ['nullable', 'string', 'max:200'],
            'sender_email' => ['nullable', 'email', 'max:200'],
            'client' => ['nullable', 'string', 'max:200'],
            'period' => ['nullable', 'regex:/^20\d{2}-(0[1-9]|1[0-2]|Q[1-4])$/'],
        ]);
        $this->insert($request, $this->workflow->prepare($request->user()->id, $input));

        return redirect('/')->with('notice', 'Request prepared for review.');
    }

    public function saveDraft(Request $request, int $id)
    {
        $input = $request->validate(['draft' => ['required', 'string', 'max:10000']]);
        DB::transaction(function () use ($request, $id, $input) {
            $query = $this->workspace($request)->where('id', $id);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            abort_unless(in_array($details['status'], ['Draft ready', 'In review', 'Missing information']), 409);
            $this->edit($request, $details, $input['draft']);
            $this->update($query, $row, $details);
        });

        return back()->with('notice', 'Draft saved.');
    }

    public function aiDraft(Request $request, int $id, AiDrafting $ai)
    {
        abort_unless(config('ai.key'), 409);
        $input = $request->validate(['draft' => ['required', 'string', 'max:10000']]);
        $query = $this->workspace($request)->where('id', $id);
        $row = (clone $query)->first();
        abort_unless($row, 404);
        $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
        abort_unless(in_array($details['status'], ['Draft ready', 'In review']) && ! empty($details['source']['records']), 409);
        $this->workflow->attachment($details);
        try {
            $proposal = $ai->generate([...$details, 'draft' => $input['draft']]);
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages([
                'ai' => 'An AI draft could not be verified. Your saved draft is available. Check the API configuration or try again.',
            ]);
        }
        $this->edit($request, $details, $proposal);
        $details['draft_method'] = 'AI proposal';
        $details['audit'][] = $this->workflow->event($request->user()->id, 'AI proposal prepared')
            + ['model' => config('ai.model'), 'source_rows' => array_column($details['source']['records'], 'row')];
        $this->update($query, $row, $details);

        return ($request->header('X-Inertia') ? back() : redirect('/'))->with('notice', 'AI proposal saved. Verify the figures and wording before approving.');
    }

    public function clarify(Request $request, int $id)
    {
        $input = $request->validate([
            'client' => ['required', 'string', 'max:200'],
            'period' => ['required', 'regex:/^20\d{2}-(0[1-9]|1[0-2]|Q[1-4])$/'],
        ]);
        DB::transaction(function () use ($request, $id, $input) {
            $query = $this->workspace($request)->where('id', $id);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            $previous = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($previous['status'] === 'Missing information', 409);
            $details = $this->workflow->prepare($request->user()->id, [...$previous, ...$input], true);
            $details['audit'] = [...$previous['audit'],
                $this->workflow->event($request->user()->id, 'Clarification recorded') + ['client' => $input['client'], 'period' => $input['period']],
                $this->workflow->event($request->user()->id, $details['status']),
            ];
            $this->update($query, $row, $details);
        });

        return back()->with('notice', 'Client and period checked against approved sources.');
    }

    public function transition(Request $request, int $id, string $action, GmailMailbox $gmail)
    {
        [$from, $to] = match ($action) {
            'review' => ['Draft ready', 'In review'],
            'approve' => ['In review', 'Approved'],
            'send' => ['Approved', 'Sent'],
        };

        // Claim approval once before external delivery. A failed or uncertain send requires manual investigation.
        $delivery = DB::transaction(function () use ($request, $id, $action, $from, $to) {
            $query = $this->workspace($request)->where('id', $id);
            $row = (clone $query)->lockForUpdate()->first();
            abort_unless($row, 404);
            $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
            abort_unless($details['status'] === $from, 409);
            if ($action !== 'send') {
                $input = $request->validate(['draft' => ['required', 'string', 'max:10000']]);
                $this->edit($request, $details, $input['draft']);
            }
            $this->workflow->attachment($details);
            $live = $action === 'send' && ($details['delivery'] ?? '') === 'gmail';
            if ($live) {
                $email = DB::table('mailbox_connections')->where('user_id', $request->user()->id)->value('email');
                abort_unless($email && strcasecmp($email, $details['mailbox_email'] ?? '') === 0, 409);
            }
            $details['status'] = $live ? 'Sending' : $to;
            $event = $live ? 'Gmail send started' : ($action === 'send' ? 'Send simulated' : $to);
            $details['audit'][] = $this->workflow->event($request->user()->id, $event);
            $this->update($query, $row, $details);

            return $live ? $details : null;
        });

        if ($delivery) {
            try {
                $sent = $gmail->send($request->user()->id, $delivery, $this->workflow->attachment($delivery));
                $delivery['status'] = 'Sent';
                $delivery['sent_message_id'] = $sent;
                $delivery['audit'][] = $this->workflow->event($request->user()->id, 'Sent via Gmail');
            } catch (\Throwable $exception) {
                report($exception);
                $delivery['status'] = 'Failed';
                $delivery['audit'][] = $this->workflow->event($request->user()->id, 'Delivery unconfirmed. Check Gmail Sent before any further action.');
            }
            $this->workspace($request)->where('id', $id)->update([
                'details' => json_encode($delivery, JSON_THROW_ON_ERROR), 'updated_at' => now(),
            ]);
        }

        return ($request->header('X-Inertia') ? back() : redirect('/'))->with('notice', 'Request status updated.');
    }

    public function attachment(Request $request, int $id)
    {
        $row = $this->workspace($request)->find($id);
        abort_unless($row, 404);
        $details = json_decode($row->details, true, flags: JSON_THROW_ON_ERROR);
        $contents = $this->workflow->attachment($details);

        return response($contents, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="ledger-excerpt.csv"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function importSource(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv']]);
        $file = $request->file('file');
        $name = preg_replace('/[^\pL\pN ._()-]/u', '_', basename($file->getClientOriginalName()));
        $name = ltrim($name, '-');
        $this->workflow->import($request->user()->id, mb_substr($name, 0, 150), file_get_contents($file->getRealPath()));

        return back()->with('notice', 'Ledger imported and approved for this workspace.');
    }

    public function updateSource(Request $request, int $id)
    {
        abort_unless($this->workflow->sources($request->user()->id)->where('id', $id)->exists(), 404);
        $request->validate(['file' => ['required', 'file', 'max:2048', 'extensions:csv']]);
        $file = $request->file('file');
        $name = preg_replace('/[^\pL\pN ._()-]/u', '_', basename($file->getClientOriginalName()));
        $name = mb_substr(ltrim($name, '-'), 0, 150);
        $this->workflow->import($request->user()->id, $name, file_get_contents($file->getRealPath()), replaceId: $id);

        return back()->with('notice', 'Ledger updated. New drafts use the replacement records. Existing requests keep their saved snapshots.');
    }

    public function deleteSource(Request $request, int $id)
    {
        abort_unless($this->workflow->sources($request->user()->id)->where('id', $id)->delete(), 404);

        return back()->with('notice', 'Ledger removed. Existing request snapshots and registered client contacts have been retained.');
    }

    public function sourceTemplate()
    {
        return response()->download(database_path('fixtures/workspace-ledger.csv'), 'ledger-template.csv');
    }

    private function insert(Request $request, array $details): void
    {
        $this->workspace($request)->insert([
            'user_id' => $request->user()->id, 'details' => json_encode($details, JSON_THROW_ON_ERROR),
            'gmail_message_id' => $details['gmail_message_id'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function edit(Request $request, array &$details, string $draft): void
    {
        if ($details['draft'] !== $draft) {
            $details['audit'][] = $this->workflow->event($request->user()->id, 'Draft edited')
                + ['before' => $details['draft'], 'after' => $draft];
            $details['draft'] = $draft;
        }
    }

    private function update($query, object $row, array $details): void
    {
        $updated = $query->where('details', $row->details)->update([
            'details' => json_encode($details, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);
        abort_unless($updated === 1, 409);
    }

    private function workspace(Request $request)
    {
        $addresses = app(ClientDirectory::class)->addresses($request->user()->id);

        return DB::table('client_requests')->where('user_id', $request->user()->id)
            ->where(function ($query) use ($addresses) {
                $query->whereNull('gmail_message_id')->orWhere(function ($gmail) use ($addresses) {
                    $gmail->whereIn('details->sender_email', $addresses)
                        ->where(function ($direction) {
                            $direction->whereNull('details->direction')->orWhere('details->direction', 'incoming');
                        });
                    foreach (['SENT', 'DRAFT', 'SPAM', 'TRASH'] as $label) {
                        $gmail->whereJsonDoesntContain('details->gmail_labels', $label);
                    }
                });
            });
    }
}
