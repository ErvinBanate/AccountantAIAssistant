<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LedgerWorkflow
{
    public function sources(int $userId)
    {
        return DB::table('workspace_sources')->where('user_id', $userId);
    }

    public function parseCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $contents));
        rewind($stream);
        $header = fgetcsv($stream, escape: '');
        $required = ['client', 'client_email', 'period', 'description', 'currency', 'amount'];
        if ($header !== $required) {
            fclose($stream);
            throw ValidationException::withMessages(['file' => 'Use the template columns in the same order.']);
        }
        $records = [];
        $line = 1;
        while (($values = fgetcsv($stream, escape: '')) !== false) {
            $line++;
            if ($values === [null]) {
                continue;
            }
            if (count($values) !== count($header)) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => "Row {$line} has an incorrect number of columns."]);
            }
            $record = array_combine($header, array_map('trim', $values));
            $valid = $record['client'] !== '' && $record['description'] !== ''
                && filter_var($record['client_email'], FILTER_VALIDATE_EMAIL)
                && preg_match('/^20\d{2}-(0[1-9]|1[0-2]|Q[1-4])$/', $record['period'])
                && preg_match('/^[A-Z]{3}$/', $record['currency'])
                && preg_match('/^-?\d{1,10}\.\d{2}$/', $record['amount']);
            foreach ($record as $value) {
                $valid = $valid && mb_check_encoding($value, 'UTF-8')
                    && strlen($value) <= 500
                    && ! preg_match('/^[=+@]/', $value)
                    && ! preg_match('/[\x00-\x1F\x7F]/', $value);
            }
            $valid = $valid && ! preg_match('/^-/', $record['client'].$record['description']);
            if (! $valid || count($records) >= 5000) {
                fclose($stream);
                throw ValidationException::withMessages(['file' => "Check row {$line}. Use valid client email, period, currency, and an amount with two decimal places. Maximum 5,000 rows."]);
            }
            $records[] = $record + ['row' => $line];
        }
        fclose($stream);
        if (! $records) {
            throw ValidationException::withMessages(['file' => 'The file has no records.']);
        }

        return $records;
    }

    public function import(int $userId, string $name, string $contents, bool $demo = false, ?int $replaceId = null): void
    {
        $records = $this->parseCsv($contents);
        DB::transaction(function () use ($userId, $name, $records, $demo, $replaceId) {
            if ($replaceId !== null) {
                abort_unless($this->sources($userId)->where('id', $replaceId)->lockForUpdate()->first(), 404);
            }
            $directory = app(ClientDirectory::class);
            $changed = $directory->approveRecords($userId, $records);
            $values = [
                'user_id' => $userId,
                'name' => $name,
                'records' => json_encode($records, JSON_THROW_ON_ERROR),
                'is_demo' => $demo,
                'updated_at' => now(),
            ];
            if ($replaceId === null) {
                $this->sources($userId)->insert($values + ['created_at' => now()]);
            } else {
                $this->sources($userId)->where('id', $replaceId)->update($values);
            }
            if ($changed) {
                $directory->restartSync($userId);
            }
        });
    }

    public function prepare(int $userId, array $input, bool $confirmed = false): array
    {
        $sources = $this->sources($userId)->orderByDesc('updated_at')->orderByDesc('id')->get();
        $matches = [];
        foreach ($sources as $source) {
            foreach (json_decode($source->records, true, flags: JSON_THROW_ON_ERROR) as $record) {
                $matches[] = $record + ['source_id' => $source->id, 'workbook' => $source->name];
            }
        }
        // Keep legacy local sample requests working before sources have been imported.
        if ($sources->isEmpty() && empty($input['gmail_message_id']) && app()->environment('local', 'testing')
            && ! app(ClientDirectory::class)->clients($userId)->exists()) {
            $path = database_path('fixtures/demo-ledger.csv');
            if (is_file($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES);
                $legacy = str_getcsv($lines[1] ?? '', escape: '');
                if (count($legacy) === 4 && $legacy[0] === 'Harbor Studio'
                    && $legacy[1] === '2026-10' && $legacy[2] === 'PHP'
                    && preg_match('/^\d{1,10}\.\d{2}$/', $legacy[3])) {
                    $matches[] = [
                        'client' => $legacy[0], 'period' => $legacy[1], 'currency' => $legacy[2],
                        'amount' => $legacy[3], 'description' => 'Approved expenses',
                        'row' => 2, 'source_id' => null, 'workbook' => 'demo-ledger.csv',
                        'client_email' => 'studio@harbor.example',
                    ];
                }
            }
        }

        $text = ($input['subject'] ?? '')."\n".$input['email'];
        $senderClients = ! empty($input['gmail_message_id'])
            ? app(ClientDirectory::class)->senderClients($userId, $input['sender_email'] ?? '') : [];
        $clientNames = [];
        foreach ([...array_column($matches, 'client'), ...$senderClients] as $name) {
            $clientNames[mb_strtolower($name)] = $name;
        }
        $clients = array_values(array_filter($clientNames, fn ($name) => preg_match(
            '/(?<![\pL\pN])'.preg_quote($name, '/').'(?![\pL\pN])/iu', $text
        )));
        $client = $input['client'] ?? null;
        if (! $client && ! $clients && ! empty($input['gmail_message_id'])) {
            if (count($senderClients) === 1) {
                $client = $senderClients[0];
            }
        }
        if (! $client && count($clients) === 1) {
            $client = $clients[0];
        }
        $periods = $this->periods($text);
        $period = $input['period'] ?? null;
        if (! $period && count($periods) === 1) {
            $period = $periods[0];
        }

        $unambiguous = $client && $period && ($confirmed || (
            count($clients) <= 1 && count($periods) <= 1
            && (! $clients || mb_strtolower($clients[0]) === mb_strtolower($client))
            && (! $periods || $periods[0] === $period)
        ));
        $records = $unambiguous ? array_values(array_filter($matches, fn ($record) => mb_strtolower($record['client']) === mb_strtolower($client) && $record['period'] === $period
            && (empty($input['gmail_message_id'])
                || in_array(mb_strtolower($record['client']), array_map('mb_strtolower', $senderClients), true))
        )) : [];

        // The newest approved import is used as a whole, avoiding duplicate rows across versions.
        if ($records) {
            $sourceId = $records[0]['source_id'];
            $records = array_values(array_filter($records, fn ($record) => $record['source_id'] === $sourceId));
        }

        $currencies = array_unique(array_column($records, 'currency'));
        $status = $records && count($currencies) === 1 ? 'Draft ready' : 'Missing information';
        $source = null;
        $attachment = [];
        $draft = "Hello,\n\nPlease confirm the client name, reporting period (YYYY-MM or YYYY-Q1), and the records you need so we can identify the approved source.\n\nRegards,\nAccounting";
        if ($status === 'Draft ready') {
            $first = $records[0];
            $source = [
                'workbook' => $first['workbook'], 'sheet' => 'Ledger', 'row' => $first['row'],
                'client' => $client, 'period' => $period, 'records' => $records,
                'total' => $first['currency'].' '.$this->formatMinor(array_sum(array_map(
                    fn ($record) => $this->toMinor($record['amount']), $records
                ))),
                'transformation' => 'Amounts copied from the cited rows. Totals use integer minor units.',
            ];
            $lines = array_map(fn ($record) => $record['description'].': '.$record['currency'].' '.$this->formatMinor($this->toMinor($record['amount'])),
                $records
            );
            $draft = "Hello,\n\nThe approved records for {$client} for {$period} show:\n\n"
                .implode("\n", $lines)."\n\nPlease find the cited ledger excerpt attached.\n\nRegards,\nAccounting";
            $csv = $this->csv($records);
            $attachment = [[
                'name' => 'ledger-excerpt.csv', 'sha256' => hash('sha256', $csv),
                'contents' => $csv, 'client' => $client, 'period' => $period,
            ]];
        }

        return [
            'subject' => $input['subject'] ?? 'Client record request',
            'sender_email' => $input['sender_email'] ?? '',
            'client' => $client ?? 'Unidentified client',
            'period' => $period,
            'email' => $input['email'], 'status' => $status,
            'source' => $source, 'draft' => $draft, 'attachments' => $attachment,
            'delivery' => empty($input['gmail_message_id']) ? 'simulation' : 'gmail',
            'gmail_message_id' => $input['gmail_message_id'] ?? null,
            'gmail_thread_id' => $input['gmail_thread_id'] ?? null,
            'message_id' => $input['message_id'] ?? null,
            'audit' => [$this->event($userId, 'New'), $this->event($userId, $status)],
        ];
    }

    public function attachment(array $details): string
    {
        $file = $details['attachments'][0] ?? null;
        abort_unless($details['source'] && $file, 409);
        if (isset($file['contents'])) {
            abort_unless(
                $file['client'] === $details['source']['client']
                && $file['period'] === $details['source']['period']
                && hash_equals($file['sha256'], hash('sha256', $file['contents']))
                && $details['source']['records']
                && ($file['contents'] === $this->csv($details['source']['records'])
                    || $file['contents'] === $this->csv($details['source']['records'], includeRow: true)),
                409
            );

            return $file['contents'];
        }
        $path = database_path('fixtures/demo-ledger.csv');
        abort_unless(is_file($path), 409);
        $contents = file_get_contents($path);
        abort_unless(hash_equals($file['sha256'], hash('sha256', $contents)), 409);

        return $contents;
    }

    public function event(int $userId, string $event): array
    {
        return ['event' => $event, 'actor' => $userId, 'at' => now()->toIso8601String()];
    }

    private function csv(array $records, bool $includeRow = false): string
    {
        $stream = fopen('php://temp', 'r+');
        $header = ['client', 'period', 'description', 'currency', 'amount', 'source'];
        fputcsv($stream, $includeRow ? [...$header, 'row'] : $header, escape: '');
        foreach ($records as $record) {
            $values = [
                $record['client'], $record['period'], $record['description'],
                $record['currency'], $record['amount'], $record['workbook'],
            ];
            fputcsv($stream, $includeRow ? [...$values, $record['row']] : $values, escape: '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents;
    }

    private function periods(string $text): array
    {
        preg_match_all('/\b20\d{2}-(?:0[1-9]|1[0-2]|Q[1-4])\b/i', $text, $matches);
        $periods = array_map('strtoupper', $matches[0]);
        preg_match_all('/\b(Q[1-4])\s+(20\d{2})\b/i', $text, $quarters, PREG_SET_ORDER);
        foreach ($quarters as $quarter) {
            $periods[] = $quarter[2].'-'.strtoupper($quarter[1]);
        }
        $months = ['january', 'february', 'march', 'april', 'may', 'june',
            'july', 'august', 'september', 'october', 'november', 'december'];
        preg_match_all('/\b('.implode('|', $months).')\s+(20\d{2})\b/i', $text, $namedMonths, PREG_SET_ORDER);
        foreach ($namedMonths as $month) {
            $periods[] = sprintf('%s-%02d', $month[2], array_search(strtolower($month[1]), $months, true) + 1);
        }

        return array_values(array_unique($periods));
    }

    private function toMinor(string $amount): int
    {
        $negative = str_starts_with($amount, '-');
        [$whole, $fraction] = explode('.', ltrim($amount, '-'));
        $minor = (int) $whole * 100 + (int) $fraction;

        return $negative ? -$minor : $minor;
    }

    private function formatMinor(int $amount): string
    {
        $absolute = abs($amount);

        return ($amount < 0 ? '-' : '').number_format(intdiv($absolute, 100)).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }
}
