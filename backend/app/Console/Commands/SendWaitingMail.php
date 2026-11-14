<?php

namespace App\Console\Commands;

use App\Services\Outbox;
use Illuminate\Console\Command;

class SendWaitingMail extends Command
{
    protected $signature = 'mail:send-waiting {--limit=100 : How many messages to send in one run}';

    protected $description = 'Send the announcement emails that wait in the outbox';

    public function handle(Outbox $outbox): int
    {
        [$sent, $failed] = $outbox->sendWaiting(max(1, (int) $this->option('limit')));
        $this->info("Sent {$sent}, failed {$failed}.");

        return self::SUCCESS;
    }
}
