<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class FixDemoPartnerAccounts extends Command
{
    protected $signature = 'institutions:fix-demo-accounts';

    protected $description = 'Ensure *.demo partner accounts can log in (Active status)';

    public function handle(): int
    {
        $suffix = strtolower((string) config('institution.demo_partner_email_suffix', '.demo'));
        $updated = 0;

        User::query()
            ->where('role', 'partner_company')
            ->whereRaw('LOWER(email) LIKE ?', ['%' . $suffix])
            ->each(function (User $user) use (&$updated) {
                if (strcasecmp((string) $user->status, 'Active') !== 0) {
                    $user->status = 'Active';
                    $user->save();
                    $updated++;
                    $this->line("Activated {$user->email}");
                }
            });

        $this->info("Done. {$updated} demo partner account(s) updated.");

        return self::SUCCESS;
    }
}
