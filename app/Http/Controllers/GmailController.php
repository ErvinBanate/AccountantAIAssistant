<?php

namespace App\Http\Controllers;

use App\Services\GmailSynchronizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class GmailController extends Controller
{
    public function connect(Request $request)
    {
        abort_unless(config('mailbox.client_id') && config('mailbox.client_secret'), 409);
        $state = Str::random(64);
        $request->session()->put('gmail_oauth', [
            'state' => $state, 'user_id' => $request->user()->id, 'expires' => time() + 600,
        ]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('mailbox.client_id'),
            'redirect_uri' => config('mailbox.redirect_uri'),
            'response_type' => 'code', 'access_type' => 'offline', 'prompt' => 'consent',
            'scope' => 'https://www.googleapis.com/auth/gmail.readonly https://www.googleapis.com/auth/gmail.send',
            'state' => $state,
        ]));
    }

    public function callback(Request $request, GmailSynchronizer $sync)
    {
        $saved = $request->session()->pull('gmail_oauth');
        abort_unless($saved && is_string($request->query('state'))
            && hash_equals($saved['state'], $request->query('state'))
            && $saved['user_id'] === $request->user()->id && $saved['expires'] >= time(), 403);
        if ($request->query('error')) {
            return redirect('/#ConnectGmail')->with('notice', 'Gmail connection was cancelled. You can try again.');
        }
        $request->validate(['code' => ['required', 'string', 'max:4096']]);
        try {
            $tokens = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('mailbox.client_id'), 'client_secret' => config('mailbox.client_secret'),
                'redirect_uri' => config('mailbox.redirect_uri'),
                'grant_type' => 'authorization_code', 'code' => $request->query('code'),
            ])->throw()->json();
            $profile = Http::withToken($tokens['access_token'])->timeout(20)
                ->get('https://gmail.googleapis.com/gmail/v1/users/me/profile')->throw()->json();
            if (empty($tokens['refresh_token']) || empty($profile['emailAddress'])) {
                throw new \RuntimeException('Offline access was not granted.');
            }
            $tokens['expires_at'] = time() + $tokens['expires_in'];
            DB::table('mailbox_connections')->updateOrInsert(['user_id' => $request->user()->id], [
                'email' => $profile['emailAddress'],
                'tokens' => Crypt::encryptString(json_encode($tokens, JSON_THROW_ON_ERROR)),
                'generation' => (string) Str::uuid(), 'sync_state' => null,
                'sync_status' => 'idle', 'sync_error' => null, 'synced_at' => null,
                'sync_requested_at' => null, 'updated_at' => now(), 'created_at' => now(),
            ]);
            $connectionId = DB::table('mailbox_connections')->where('user_id', $request->user()->id)->value('id');
            $sync->request($connectionId);
        } catch (\Throwable $exception) {
            report($exception);

            return redirect('/#ConnectGmail')->with('notice', 'Gmail setup could not finish. Check Settings and retry connecting or syncing.');
        }

        return redirect('/#ConnectGmail')->with('notice', 'Gmail connected. Add approved client contacts to choose which messages sync.');
    }

    public function sync(Request $request, GmailSynchronizer $sync)
    {
        $connection = DB::table('mailbox_connections')->where('user_id', $request->user()->id)->first();
        abort_unless($connection, 409);
        $queued = $sync->request($connection->id);

        return back()->with('notice', $queued
            ? 'Client email sync queued. Messages will appear as batches finish.'
            : ($connection->sync_status === 'waiting_for_clients'
                ? 'Add an active client contact to start syncing.'
                : 'Client sync is already running, or waiting for approved contacts.'));
    }

    public function disconnect(Request $request)
    {
        DB::table('mailbox_connections')->where('user_id', $request->user()->id)->delete();
        $request->session()->forget('gmail_oauth');

        return back()->with('notice', 'Gmail disconnected from this workspace. You can revoke app access in your Google account.');
    }
}
