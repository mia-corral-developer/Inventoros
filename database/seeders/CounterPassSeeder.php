<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Sets known passwords for the QA counter users so they can be logged in
 * from a browser to exercise the multi-round capture flow.
 * Re-runnable.
 */
class CounterPassSeeder extends Seeder
{
    public function run(): void
    {
        $counterPass = getenv('QA_COUNTER_PASS') ?: 'Contador2026*';

        foreach (['c1@contador.lab', 'c2@contador.lab', 'c3@contador.lab'] as $email) {
            $u = User::where('email', $email)->first();
            if (! $u) {
                $this->command?->warn("MISSING {$email}");
                continue;
            }
            $u->password = Hash::make($counterPass);
            $u->email_verified_at = $u->email_verified_at ?? now();
            $u->save();
            $this->command?->info("OK {$email} id={$u->id} hash_ok=".(Hash::check($counterPass, $u->password) ? 'yes' : 'no'));
        }

        $edu = User::where('email', 'edwin@whitelabel.lat')->first();
        if ($edu) {
            $edu->password = Hash::make('Operario2026*');
            $edu->save();
            $this->command?->info("OK edwin@whitelabel.lat id={$edu->id}");
        } else {
            $this->command?->warn('MISSING edwin@whitelabel.lat');
        }
    }
}
