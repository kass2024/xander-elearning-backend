<?php

namespace App\Services;

use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Transport\Dsn;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransportFactory;

class MailDeliveryService
{
    public function sendTo(string $to, Mailable $mailable, array $context = []): bool
    {
        return $this->attempt(function () use ($to, $mailable) {
            Mail::to($to)->send($mailable);
        }, array_merge($context, ['to' => $to, 'type' => 'mailable']));
    }

    public function sendToForInstitution(
        ?int $platformInstitutionId,
        string $to,
        Mailable $mailable,
        array $context = [],
    ): bool {
        return app(InstitutionMailResolver::class)->sendForInstitution(
            $platformInstitutionId,
            $to,
            $mailable,
            array_merge($context, ['type' => 'mailable']),
        );
    }

    public function sendView(string $view, array $data, callable $callback, array $context = []): bool
    {
        return $this->attempt(function () use ($view, $data, $callback) {
            Mail::send($view, $data, $callback);
        }, array_merge($context, ['type' => 'view', 'view' => $view]));
    }

    public function sendRaw(string $body, callable $callback, array $context = []): bool
    {
        return $this->attempt(function () use ($body, $callback) {
            Mail::raw($body, $callback);
        }, array_merge($context, ['type' => 'raw']));
    }

    /**
     * @return array{ok: bool, message?: string, error?: string, diagnosis?: array<string, mixed>}
     */
    public function sendTest(string $to): array
    {
        try {
            Mail::send('emails.welcome', ['student' => null, 'password' => null], function ($message) use ($to) {
                $message->to($to)
                    ->subject('Test email from ' . config('app.name'));
            });

            return [
                'ok' => true,
                'message' => 'Test email sent to ' . $to,
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => $e->getMessage(),
                'diagnosis' => $this->diagnose(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function diagnose(): array
    {
        $smtp = config('mail.mailers.smtp');
        $envFileHost = $this->readDotEnvValue('MAIL_HOST');
        $activeHost = $smtp['host'] ?? null;
        $warnings = [];

        if ($envFileHost && $activeHost && $envFileHost !== $activeHost) {
            $warnings[] = "MAIL_HOST in .env ({$envFileHost}) is overridden by a process environment variable ({$activeHost}). Remove MAIL_HOST from your shell or Cursor terminal settings.";
        }

        if ($activeHost && str_starts_with($activeHost, 'mail.') && filter_var($smtp['verify_peer'] ?? true, FILTER_VALIDATE_BOOL)) {
            $warnings[] = 'Using mail.yourdomain.com often causes SSL certificate errors on shared cPanel hosting. Prefer MAIL_HOST=xanderglobalscholars.com or set MAIL_VERIFY_PEER=false for local XAMPP.';
        }

        if (($smtp['local_domain'] ?? '') === 'localhost') {
            $warnings[] = 'SMTP EHLO domain is localhost. Set MAIL_EHLO_DOMAIN=xanderglobalscholars.com in .env.';
        }

        $connection = $this->probeSmtp();

        if (($connection['status'] ?? '') === 'auth_failed') {
            $warnings[] = 'SMTP authentication failed (535). In cPanel → Email Accounts, confirm admission@xanderglobalscholars.com exists and reset its password to match MAIL_PASSWORD in .env.';
        }

        return [
            'mailer' => config('mail.default'),
            'host' => $activeHost,
            'port' => $smtp['port'] ?? null,
            'scheme' => $smtp['scheme'] ?? null,
            'username' => $smtp['username'] ?? null,
            'from' => config('mail.from.address'),
            'local_domain' => $smtp['local_domain'] ?? null,
            'verify_peer' => $smtp['verify_peer'] ?? true,
            'env_file_host' => $envFileHost,
            'connection' => $connection,
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function probeSmtp(): array
    {
        $mailer = config('mail.default');

        if (in_array($mailer, ['log', 'array'], true)) {
            return [
                'status' => 'skipped',
                'message' => 'Active mailer is "' . $mailer . '" — no SMTP connection attempted.',
            ];
        }

        $smtp = config('mail.mailers.smtp');
        $scheme = $smtp['scheme'] ?? ((int) ($smtp['port'] ?? 0) === 465 ? 'smtps' : 'smtp');
        $options = [];

        if (array_key_exists('verify_peer', $smtp)) {
            $options['verify_peer'] = $smtp['verify_peer'] ? 'true' : 'false';
        }

        if (!empty($smtp['local_domain'])) {
            $options['local_domain'] = $smtp['local_domain'];
        }

        try {
            $factory = new EsmtpTransportFactory();
            $transport = $factory->create(new Dsn(
                $scheme,
                (string) ($smtp['host'] ?? '127.0.0.1'),
                $smtp['username'] ?? null,
                $smtp['password'] ?? null,
                isset($smtp['port']) ? (int) $smtp['port'] : null,
                $options
            ));

            $transport->start();
            $transport->stop();

            return [
                'status' => 'ok',
                'message' => 'Connected and authenticated successfully.',
            ];
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $status = 'error';

            if (str_contains($message, '535') || stripos($message, 'authentication') !== false) {
                $status = 'auth_failed';
            } elseif (str_contains($message, 'certificate') || str_contains($message, 'Peer certificate')) {
                $status = 'ssl_failed';
            } elseif (str_contains($message, 'Connection could not be established')) {
                $status = 'connection_failed';
            }

            return [
                'status' => $status,
                'message' => $message,
            ];
        }
    }

    private function attempt(callable $send, array $context): bool
    {
        try {
            $send();
            Log::info('Email sent successfully', $context);

            return true;
        } catch (\Throwable $e) {
            Log::error('Email send failed', array_merge($context, [
                'error' => $e->getMessage(),
            ]));

            return false;
        }
    }

    private function readDotEnvValue(string $key): ?string
    {
        $path = base_path('.env');

        if (!is_readable($path)) {
            return null;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_starts_with($line, $key . '=')) {
                continue;
            }

            return trim(substr($line, strlen($key) + 1), " \t\"'");
        }

        return null;
    }
}
