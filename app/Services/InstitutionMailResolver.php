<?php

namespace App\Services;

use App\Models\PlatformInstitution;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class InstitutionMailResolver
{
    public function institutionUsesCustomSmtp(?PlatformInstitution $institution): bool
    {
        if (!$institution) {
            return false;
        }

        return (bool) $institution->mail_use_custom
            && trim((string) ($institution->mail_host ?? '')) !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function smtpConfigForInstitution(?PlatformInstitution $institution): array
    {
        if (!$this->institutionUsesCustomSmtp($institution)) {
            return (array) config('mail.mailers.smtp', []);
        }

        $encryption = strtolower(trim((string) ($institution->mail_encryption ?? '')));
        $port = (int) ($institution->mail_port ?: 465);
        $scheme = $encryption === 'ssl' || $port === 465 ? 'smtps' : 'smtp';

        $password = $this->decryptPassword($institution->mail_password);

        return [
            'transport' => 'smtp',
            'scheme' => $scheme,
            'host' => (string) $institution->mail_host,
            'port' => $port,
            'username' => $institution->mail_username,
            'password' => $password,
            'timeout' => (int) config('mail.mailers.smtp.timeout', 30),
            'local_domain' => $institution->mail_ehlo_domain
                ?: $this->domainFromEmail($institution->mail_from_address)
                ?: config('mail.mailers.smtp.local_domain'),
            'verify_peer' => config('mail.mailers.smtp.verify_peer', true),
        ];
    }

    public function fromForInstitution(?PlatformInstitution $institution): array
    {
        if ($this->institutionUsesCustomSmtp($institution)) {
            $address = trim((string) ($institution->mail_from_address ?? ''));
            $name = trim((string) ($institution->mail_from_name ?? '')) ?: $institution->name;

            if ($address !== '') {
                return ['address' => $address, 'name' => $name];
            }
        }

        return [
            'address' => (string) config('mail.from.address'),
            'name' => (string) config('mail.from.name'),
        ];
    }

    public function sendForInstitution(
        ?int $platformInstitutionId,
        string $to,
        Mailable $mailable,
        array $context = [],
    ): bool {
        $institution = $platformInstitutionId
            ? PlatformInstitution::find($platformInstitutionId)
            : null;

        try {
            if ($this->institutionUsesCustomSmtp($institution)) {
                $mailerKey = 'institution_' . $institution->id;
                Config::set("mail.mailers.{$mailerKey}", $this->smtpConfigForInstitution($institution));
                $from = $this->fromForInstitution($institution);
                $mailable->from($from['address'], $from['name']);
                Mail::mailer($mailerKey)->to($to)->send($mailable);
            } else {
                Mail::to($to)->send($mailable);
            }

            Log::info('Institution email sent', array_merge($context, [
                'to' => $to,
                'institution_id' => $platformInstitutionId,
                'custom_smtp' => $this->institutionUsesCustomSmtp($institution),
            ]));

            return true;
        } catch (\Throwable $e) {
            Log::error('Institution email failed', array_merge($context, [
                'to' => $to,
                'institution_id' => $platformInstitutionId,
                'error' => $e->getMessage(),
            ]));

            return false;
        }
    }

    public function encryptPassword(?string $plain): ?string
    {
        $plain = trim((string) $plain);
        if ($plain === '') {
            return null;
        }

        return Crypt::encryptString($plain);
    }

    public function decryptPassword(?string $stored): ?string
    {
        $stored = trim((string) $stored);
        if ($stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (\Throwable) {
            return $stored;
        }
    }

    private function domainFromEmail(?string $email): ?string
    {
        $email = trim((string) $email);
        if ($email === '' || !str_contains($email, '@')) {
            return null;
        }

        return substr(strrchr($email, '@'), 1) ?: null;
    }
}
