<?php

namespace App\Services;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class GmailMailbox
{
    public function tokens(int $userId): array
    {
        $connection = DB::table('mailbox_connections')->where('user_id', $userId)->first();
        abort_unless($connection, 409, 'Connect Gmail in Settings first.');
        $tokens = json_decode(Crypt::decryptString($connection->tokens), true, flags: JSON_THROW_ON_ERROR);
        if ($tokens['expires_at'] <= time() + 60) {
            $refreshed = Http::asForm()->timeout(20)->post('https://oauth2.googleapis.com/token', [
                'client_id' => config('mailbox.client_id'),
                'client_secret' => config('mailbox.client_secret'),
                'refresh_token' => $tokens['refresh_token'],
                'grant_type' => 'refresh_token',
            ])->throw()->json();
            $tokens['access_token'] = $refreshed['access_token'];
            $tokens['refresh_token'] = $refreshed['refresh_token'] ?? $tokens['refresh_token'];
            $tokens['expires_at'] = time() + $refreshed['expires_in'];
            DB::table('mailbox_connections')->where('id', $connection->id)->where('generation', $connection->generation)->update([
                'tokens' => Crypt::encryptString(json_encode($tokens, JSON_THROW_ON_ERROR)),
                'updated_at' => now(),
            ]);
        }

        return $tokens;
    }

    public function get(int $userId, string $path, array $query = []): array
    {
        $tokens = $this->tokens($userId);

        return Http::withToken($tokens['access_token'])->timeout(20)
            ->get('https://gmail.googleapis.com/gmail/v1/users/me/'.$path, $query)
            ->throw()->json();
    }

    public function message(array $message): array
    {
        $headers = [];
        foreach ($message['payload']['headers'] ?? [] as $header) {
            $headers[strtolower($header['name'])] = $header['value'];
        }
        $from = $headers['from'] ?? '';
        try {
            $sender = Address::create($from)->getAddress();
        } catch (\Throwable) {
            $sender = '';
        }
        $recipients = [];
        foreach (str_getcsv($headers['to'] ?? '', escape: '') as $recipient) {
            try {
                $recipients[] = strtolower(Address::create($recipient ?? '')->getAddress());
            } catch (\Throwable) {
                // Invalid address fragments do not establish a client match.
            }
        }

        return [
            'email' => mb_substr(mb_scrub($this->body($message['payload'] ?? []), 'UTF-8'), 0, 10000),
            'subject' => mb_substr(mb_decode_mimeheader($headers['subject'] ?? 'Client email'), 0, 200),
            'sender_email' => $sender,
            'recipient_email' => mb_substr(mb_decode_mimeheader($headers['to'] ?? ''), 0, 500),
            'recipient_emails' => array_values(array_unique($recipients)),
            'gmail_message_id' => $message['id'],
            'gmail_thread_id' => $message['threadId'] ?? null,
            'message_id' => $headers['message-id'] ?? null,
        ];
    }

    public function send(int $userId, array $details, string $attachment): string
    {
        $connection = DB::table('mailbox_connections')->where('user_id', $userId)->first();
        abort_unless($connection && filter_var($details['sender_email'], FILTER_VALIDATE_EMAIL)
            && strcasecmp($connection->email, $details['mailbox_email'] ?? '') === 0, 409);
        // Recheck sender and thread against Gmail before sending the approved response.
        $original = $this->message($this->get($userId, 'messages/'.rawurlencode($details['gmail_message_id']), ['format' => 'full']));
        abort_unless(
            strcasecmp($original['sender_email'], $details['sender_email']) === 0
            && $original['gmail_thread_id'] === $details['gmail_thread_id'],
            409
        );
        $subject = preg_replace('/[\r\n]+/', ' ', $original['subject']);
        $message = (new Email)->from($connection->email)->to($original['sender_email'])
            ->subject(preg_match('/^Re:/i', $subject) ? $subject : 'Re: '.$subject)
            ->text($details['draft'])->attach($attachment, 'ledger-excerpt.csv', 'text/csv');
        $messageId = trim($original['message_id'] ?? '', '<>');
        if (filter_var($messageId, FILTER_VALIDATE_EMAIL)) {
            $message->getHeaders()->addIdHeader('In-Reply-To', $messageId);
            $message->getHeaders()->addIdHeader('References', $messageId);
        }
        $tokens = $this->tokens($userId);
        $result = Http::withToken($tokens['access_token'])->timeout(30)
            ->post('https://gmail.googleapis.com/gmail/v1/users/me/messages/send', [
                'raw' => rtrim(strtr(base64_encode($message->toString()), '+/', '-_'), '='),
                'threadId' => $original['gmail_thread_id'],
            ])->throw()->json();

        if (empty($result['id'])) {
            throw new \RuntimeException('Gmail did not confirm delivery.');
        }

        return $result['id'];
    }

    private function body(array $part): string
    {
        if (! empty($part['filename'])) {
            return '';
        }
        if (($part['mimeType'] ?? '') === 'text/plain' && ! empty($part['body']['data'])) {
            return $this->decode($part['body']['data']);
        }
        foreach ($part['parts'] ?? [] as $child) {
            $body = $this->body($child);
            if ($body !== '') {
                return $body;
            }
        }
        if (($part['mimeType'] ?? '') === 'text/html' && ! empty($part['body']['data'])) {
            return html_entity_decode(strip_tags($this->decode($part['body']['data'])), ENT_QUOTES | ENT_HTML5);
        }

        return '';
    }

    private function decode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'), true) ?: '';
    }
}
