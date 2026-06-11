<?php

namespace App\Console\Commands;

use App\Services\MailDeliveryService;
use Illuminate\Console\Command;

class DiagnoseMailCommand extends Command
{
    protected $signature = 'mail:diagnose {--send= : Optional email address for a live send test}';

    protected $description = 'Diagnose SMTP configuration and connectivity for XAMPP and cPanel';

    public function handle(MailDeliveryService $mail): int
    {
        $diagnosis = $mail->diagnose();

        $this->info('Mail configuration');
        $this->table(
            ['Setting', 'Value'],
            [
                ['Mailer', (string) ($diagnosis['mailer'] ?? '')],
                ['Host (active)', (string) ($diagnosis['host'] ?? '')],
                ['Host (.env file)', (string) ($diagnosis['env_file_host'] ?? 'n/a')],
                ['Port', (string) ($diagnosis['port'] ?? '')],
                ['Scheme', (string) ($diagnosis['scheme'] ?? '')],
                ['Username', (string) ($diagnosis['username'] ?? '')],
                ['From', (string) ($diagnosis['from'] ?? '')],
                ['EHLO domain', (string) ($diagnosis['local_domain'] ?? '')],
                ['Verify peer', ($diagnosis['verify_peer'] ?? true) ? 'true' : 'false'],
            ]
        );

        $connection = $diagnosis['connection'] ?? [];
        $status = (string) ($connection['status'] ?? 'unknown');

        if ($status === 'ok') {
            $this->info('SMTP probe: OK — ' . ($connection['message'] ?? ''));
        } else {
            $this->error('SMTP probe: ' . strtoupper($status));
            $this->line($connection['message'] ?? 'Unknown error');
        }

        if (!empty($diagnosis['warnings'])) {
            $this->newLine();
            $this->warn('Warnings:');
            foreach ($diagnosis['warnings'] as $warning) {
                $this->line('  • ' . $warning);
            }
        }

        $sendTo = $this->option('send');
        if ($sendTo) {
            $this->newLine();
            $this->info('Sending test email to ' . $sendTo . ' ...');
            $result = $mail->sendTest($sendTo);

            if ($result['ok']) {
                $this->info($result['message']);
            } else {
                $this->error($result['error'] ?? 'Send failed');

                return self::FAILURE;
            }
        }

        return ($connection['status'] ?? '') === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
