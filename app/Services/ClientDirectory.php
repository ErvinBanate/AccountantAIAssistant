<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClientDirectory
{
    public function clients(int $userId)
    {
        return DB::table('clients')->where('user_id', $userId);
    }

    public function contacts(int $userId)
    {
        return DB::table('client_contacts')->join('clients', 'clients.id', '=', 'client_contacts.client_id')
            ->where('clients.user_id', $userId)->where('clients.is_active', true);
    }

    public function addresses(int $userId): array
    {
        return $this->contacts($userId)->distinct()->orderBy('client_contacts.email')
            ->pluck('client_contacts.email')->all();
    }

    public function senderClients(int $userId, string $email): array
    {
        return $this->contacts($userId)->where('client_contacts.email', strtolower(trim($email)))
            ->distinct()->pluck('clients.name')->all();
    }

    public function matches(int $userId, array $message): bool
    {
        return $this->contacts($userId)->where('client_contacts.email', strtolower(trim($message['sender_email'])))->exists();
    }

    public function listing(int $userId)
    {
        $clients = $this->clients($userId)->orderBy('name')->get();
        $contacts = DB::table('client_contacts')->whereIn('client_id', $clients->pluck('id'))
            ->orderBy('email')->get()->groupBy('client_id');

        return $clients->map(fn ($client) => [
            'id' => $client->id, 'name' => $client->name, 'is_active' => (bool) $client->is_active,
            'emails' => ($contacts[$client->id] ?? collect())->pluck('email')->all(),
        ]);
    }

    public function approveRecords(int $userId, array $records): bool
    {
        $changed = false;
        $seen = [];
        foreach ($records as $record) {
            $name = trim($record['client'] ?? '');
            $email = strtolower(trim($record['client_email'] ?? ''));
            if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }
            $key = hash('sha256', mb_strtolower($name));
            $pair = $key.':'.$email;
            if (isset($seen[$pair])) {
                continue;
            }
            $seen[$pair] = true;
            $created = DB::table('clients')->insertOrIgnore([
                'user_id' => $userId, 'name' => $name, 'name_key' => $key,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = $this->clients($userId)->where('name_key', $key)->value('id');
            $contact = DB::table('client_contacts')->insertOrIgnore([
                'client_id' => $id, 'email' => $email, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $changed = $changed || (bool) $created || (bool) $contact;
        }

        return $changed;
    }

    public function restartSync(int $userId): void
    {
        DB::table('mailbox_connections')->where('user_id', $userId)->update([
            'generation' => (string) Str::uuid(), 'sync_state' => null,
            'sync_status' => 'idle', 'sync_error' => null,
            'sync_requested_at' => null, 'updated_at' => now(),
        ]);
        DB::afterCommit(function () use ($userId) {
            $id = DB::table('mailbox_connections')->where('user_id', $userId)->value('id');
            if ($id) {
                app(GmailSynchronizer::class)->request($id);
            }
        });
    }
}
