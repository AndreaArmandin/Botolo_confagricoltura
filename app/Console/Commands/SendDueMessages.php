<?php

namespace App\Console\Commands;

use App\Enums\MessageStatus;
use App\Jobs\SendMessageJob;
use App\Models\Message;
use Illuminate\Console\Command;

class SendDueMessages extends Command
{
    protected $signature = 'messages:send-due';

    protected $description = 'Mette in coda i messaggi programmati la cui ora e arrivata';

    public function handle(): int
    {
        $due = Message::where('status', MessageStatus::Scheduled)
            ->where('scheduled_at', '<=', now())
            ->get();

        foreach ($due as $message) {
            $message->update(['status' => MessageStatus::Queued]);

            SendMessageJob::dispatch($message);
        }

        $this->info($due->count().' messaggi messi in coda.');

        return self::SUCCESS;
    }
}
