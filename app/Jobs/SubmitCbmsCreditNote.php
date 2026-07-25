<?php

namespace App\Jobs;

use App\Models\FiscalCreditNote;
use App\Services\CbmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SubmitCbmsCreditNote implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout = 30;

    public function __construct(public int $creditNoteId, public ?string $retryToken = null)
    {
    }

    public function uniqueId(): string
    {
        return $this->creditNoteId . ':' . ($this->retryToken ?: 'automatic');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CbmsService $service): void
    {
        if (!$service->automaticDeliveryReady()) {
            return;
        }

        Cache::lock("cbms:credit-note:{$this->creditNoteId}", 45)->block(5, function () use ($service) {
            $creditNote = FiscalCreditNote::findOrFail($this->creditNoteId);

            if ($creditNote->status !== 'submitted') {
                $service->submitCreditNote($creditNote);
            }
        });
    }
}
