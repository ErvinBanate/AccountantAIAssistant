<?php

namespace App\Services;

use App\Jobs\SyncGmailMailbox;
use Carbon\Carbon;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class GmailSynchronizer
{
    public function __construct(
        private GmailMailbox $gmail,
        private LedgerWorkflow $workflow,
        private ClientDirectory $directory,
    ) {}

    public function request(int $connectionId): bool
    {
        $connection = DB::table('mailbox_connections')->find($connectionId);
        if (! $connection) {
            return false;
        }
        if (! $this->directory->addresses($connection->user_id)) {
            $this->connection($connectionId, $connection->generation)->update([
                'sync_status' => 'waiting_for_clients', 'sync_state' => null, 'sync_error' => null,
            ]);

            return false;
        }
        $claimed = $this->connection($connectionId, $connection->generation)
            ->where(function ($query) {
                $query->whereIn('sync_status', ['idle', 'failed', 'waiting_for_clients'])
                    ->orWhere('sync_requested_at', '<', now()->subMinutes(15));
            })->update([
                'sync_status' => 'queued', 'sync_error' => null,
                'sync_requested_at' => now(), 'updated_at' => now(),
            ]);
        if (! $claimed) {
            return false;
        }
        try {
            SyncGmailMailbox::dispatch($connectionId, $connection->generation);
        } catch (\Throwable $exception) {
            $this->markFailed($connectionId, $connection->generation);
            throw $exception;
        }

        return true;
    }

    public function runPage(int $connectionId, string $generation): void
    {
        $connection = $this->connection($connectionId, $generation)->first();
        if (! $connection) {
            return;
        }
        $lock = Cache::lock('gmail-sync-'.$connection->user_id, 330);
        if (! $lock->get()) {
            SyncGmailMailbox::dispatch($connectionId, $generation)->delay(now()->addSeconds(15));

            return;
        }
        try {
            $addresses = $this->directory->addresses($connection->user_id);
            if (! $addresses) {
                $this->connection($connectionId, $generation)->update([
                    'sync_status' => 'waiting_for_clients', 'sync_state' => null, 'sync_error' => null,
                ]);

                return;
            }
            $queries = array_map(function ($chunk) {
                $terms = [];
                foreach ($chunk as $email) {
                    $quoted = json_encode($email, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                    $terms[] = 'from:'.$quoted;
                }

                return '{'.implode(' ', $terms).'} -in:sent -in:drafts -in:spam -in:trash';
            }, array_chunk($addresses, 10));
            $hash = hash('sha256', 'incoming-only:'.json_encode($addresses, JSON_THROW_ON_ERROR));
            $state = $connection->sync_state ? json_decode($connection->sync_state, true, flags: JSON_THROW_ON_ERROR) : null;
            if (! $state || ($state['filter_hash'] ?? '') !== $hash) {
                $state = ['filter_hash' => $hash, 'since' => null, 'query_index' => 0,
                    'page_token' => null, 'round_started_at' => time(), 'complete' => false];
            } elseif ($state['complete']) {
                // Gmail normal delivery uses its arrival time. Overlap protects round boundaries and retries.
                $state = [...$state, 'since' => max(0, $state['checkpoint'] - 300),
                    'query_index' => 0, 'page_token' => null, 'round_started_at' => time(), 'complete' => false];
            }
            $this->connection($connectionId, $generation)->update([
                'sync_status' => 'syncing', 'sync_requested_at' => now(), 'sync_error' => null,
                'sync_state' => json_encode($state, JSON_THROW_ON_ERROR),
            ]);

            $query = $queries[$state['query_index']];
            if ($state['since'] !== null) {
                $query .= ' after:'.$state['since'];
            }
            $parameters = ['q' => $query, 'includeSpamTrash' => 'true', 'maxResults' => 100];
            if ($state['page_token']) {
                $parameters['pageToken'] = $state['page_token'];
            }
            try {
                $page = $state['pending_page'] ?? $this->gmail->get($connection->user_id, 'messages', $parameters);
            } catch (RequestException $exception) {
                if ($exception->response->status() !== 400 || ! $state['page_token']) {
                    throw $exception;
                }
                $this->connection($connectionId, $generation)->update(['sync_state' => null, 'sync_status' => 'queued']);
                SyncGmailMailbox::dispatch($connectionId, $generation);

                return;
            }
            $items = $page['messages'] ?? [];
            $existing = DB::table('client_requests')->where('user_id', $connection->user_id)
                ->where('gmail_mailbox_email', $connection->email)
                ->whereIn('gmail_message_id', array_column($items, 'id'))->pluck('gmail_message_id')->flip()->all();
            $items = array_values(array_filter($items, fn ($item) => ! isset($existing[$item['id']])));
            $remaining = array_slice($items, 10);
            foreach (array_slice($items, 0, 10) as $item) {
                if (! $this->connection($connectionId, $generation)->exists()) {
                    return;
                }
                $id = $item['id'];
                if (DB::table('client_requests')->where('user_id', $connection->user_id)
                    ->where('gmail_mailbox_email', $connection->email)->where('gmail_message_id', $id)->exists()) {
                    continue;
                }
                try {
                    $path = 'messages/'.rawurlencode($id);
                    $metadata = $this->gmail->get($connection->user_id, $path, ['format' => 'metadata']);
                    if (array_intersect($metadata['labelIds'] ?? [], ['SENT', 'DRAFT', 'SPAM', 'TRASH'])
                        || ! $this->directory->matches($connection->user_id, $this->gmail->message($metadata))) {
                        continue;
                    }
                    if (! $this->connection($connectionId, $generation)->exists()) {
                        return;
                    }
                    $message = $this->gmail->get($connection->user_id, $path, ['format' => 'full']);
                } catch (RequestException $exception) {
                    if ($exception->response->status() === 404) {
                        continue;
                    }
                    throw $exception;
                }
                $input = $this->gmail->message($message);
                if (array_intersect($message['labelIds'] ?? [], ['SENT', 'DRAFT', 'SPAM', 'TRASH'])
                    || ! $this->directory->matches($connection->user_id, $input)) {
                    continue;
                }
                $input['email'] = $input['email'] ?: '(This message has no readable body. Review it in Gmail.)';
                $details = $this->workflow->prepare($connection->user_id, $input);
                $details['mailbox_email'] = $connection->email;
                $details['recipient_email'] = $input['recipient_email'];
                $details['recipient_emails'] = $input['recipient_emails'];
                $details['gmail_labels'] = $message['labelIds'] ?? [];
                $details['direction'] = 'incoming';
                DB::transaction(function () use ($connection, $generation, $id, $message, $details) {
                    if (! $this->connection($connection->id, $generation)->lockForUpdate()->first()) {
                        return;
                    }
                    DB::table('client_requests')->insertOrIgnore([
                        'user_id' => $connection->user_id, 'gmail_message_id' => $id,
                        'gmail_mailbox_email' => $connection->email,
                        'gmail_received_at' => isset($message['internalDate'])
                            ? Carbon::createFromTimestampMs((int) $message['internalDate'])->utc() : now(),
                        'details' => json_encode($details, JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                });
            }
            $state['pending_page'] = $remaining ? [...$page, 'messages' => $remaining] : null;
            if (! $remaining) {
                $state['page_token'] = $page['nextPageToken'] ?? null;
                if (! $state['page_token']) {
                    $state['query_index']++;
                }
            }
            $continue = (bool) $remaining || $state['query_index'] < count($queries);
            $state['complete'] = ! $continue;
            if (! $continue) {
                $state['checkpoint'] = $state['round_started_at'];
            }
            $updated = $this->connection($connectionId, $generation)->update([
                'sync_state' => json_encode($state, JSON_THROW_ON_ERROR), 'sync_status' => $continue ? 'queued' : 'idle',
                'sync_error' => null, 'sync_requested_at' => now(), 'updated_at' => now(),
                'synced_at' => $continue ? $connection->synced_at : now(),
            ]);
            if ($updated && $continue) {
                SyncGmailMailbox::dispatch($connectionId, $generation);
            }
        } catch (\Throwable $exception) {
            $this->markFailed($connectionId, $generation);
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function markFailed(int $id, string $generation): void
    {
        $this->connection($id, $generation)->update([
            'sync_status' => 'failed', 'sync_error' => 'Client email sync paused. Retry or reconnect Gmail in Settings.',
            'updated_at' => now(),
        ]);
    }

    private function connection(int $id, string $generation)
    {
        return DB::table('mailbox_connections')->where('id', $id)->where('generation', $generation);
    }
}
