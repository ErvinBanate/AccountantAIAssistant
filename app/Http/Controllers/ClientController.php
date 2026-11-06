<?php

namespace App\Http\Controllers;

use App\Services\ClientDirectory;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientController extends Controller
{
    public function __construct(private ClientDirectory $directory) {}

    public function store(Request $request)
    {
        $input = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'emails' => ['required', 'array', 'min:1', 'max:100'],
            'emails.*' => ['required', 'email:rfc', 'max:254', 'distinct:ignore_case'],
        ]);
        $name = trim($input['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a client name.']);
        }
        try {
            DB::transaction(function () use ($request, $input, $name) {
                $id = DB::table('clients')->insertGetId([
                    'user_id' => $request->user()->id, 'name' => $name, 'name_key' => hash('sha256', mb_strtolower($name)),
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $this->saveContacts($id, $input['emails']);
                $this->directory->restartSync($request->user()->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => 'This client is already registered in your workspace.']);
        }

        return back()->with('notice', 'Client saved. Past and new messages from their contacts are eligible for sync.');
    }

    public function update(Request $request, int $id)
    {
        $input = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:100'],
            'emails.*' => ['required', 'email:rfc', 'max:254', 'distinct:ignore_case'],
            'is_active' => ['required', 'boolean'],
        ]);
        DB::transaction(function () use ($request, $id, $input) {
            $client = $this->directory->clients($request->user()->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($client, 404);
            $oldEmails = DB::table('client_contacts')->where('client_id', $id)->orderBy('email')->pluck('email')->all();
            $newEmails = array_map(fn ($email) => strtolower(trim($email)), $input['emails']);
            sort($newEmails);
            if ($oldEmails === $newEmails && (bool) $client->is_active === (bool) $input['is_active']) {
                return;
            }
            DB::table('client_contacts')->where('client_id', $id)->delete();
            $this->saveContacts($id, $input['emails']);
            $this->directory->clients($request->user()->id)->where('id', $id)->update([
                'is_active' => $input['is_active'], 'updated_at' => now(),
            ]);
            $this->directory->restartSync($request->user()->id);
        });

        return back()->with('notice', 'Client contacts and sync approval updated.');
    }

    public function destroy(Request $request, int $id)
    {
        DB::transaction(function () use ($request, $id) {
            $client = $this->directory->clients($request->user()->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($client, 404);
            $this->directory->clients($request->user()->id)->where('id', $id)->delete();
            $this->directory->restartSync($request->user()->id);
        });

        return back()->with('notice', 'Client and contacts deleted. Uploaded ledgers and saved request snapshots have been retained.');
    }

    public function history(Request $request)
    {
        $this->directory->restartSync($request->user()->id);

        return back()->with('notice', 'Past client messages have been queued for matching.');
    }

    private function saveContacts(int $id, array $emails): void
    {
        foreach ($emails as $email) {
            DB::table('client_contacts')->insert([
                'client_id' => $id, 'email' => strtolower(trim($email)), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
