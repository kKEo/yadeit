<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class GrantOperator extends Command
{
    protected $signature = 'operator:grant {email} {--revoke : Take the operator role away instead}';

    protected $description = 'Make a person an operator of this installation (or take that away)';

    public function handle(): int
    {
        $user = User::query()->where('email', strtolower((string) $this->argument('email')))->where('type', 'human')->first();

        if (! $user) {
            $this->error('No person with that email address.');

            return self::FAILURE;
        }

        $user->forceFill(['is_operator' => ! $this->option('revoke')])->save();
        $this->info($this->option('revoke') ? "{$user->email} is no longer an operator." : "{$user->email} is now an operator.");

        return self::SUCCESS;
    }
}
