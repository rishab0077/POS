<?php

namespace App\Jobs;

use App\Models\CbmsSubmission;
use App\Services\CbmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

class SubmitCbmsInvoice implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 0;
    public int $timeout = 30;

    public function __construct(public int $submissionId, public ?string $retryToken = null)
    {
    }

    public function uniqueId(): string
    {
        return $this->submissionId . ':' . ($this->retryToken ?: 'automatic');
    }

    public function backoff(): array
    {
        return [60, 300, 900, 3600];
    }

    public function handle(CbmsService $service): void
    {
        Cache::lock("cbms:submission:{$this->submissionId}", 45)->block(5, function () use ($service) {
            $submission = CbmsSubmission::findOrFail($this->submissionId);

            if ($submission->status !== 'submitted') {
                $service->submit($submission);
            }
        });
    }
}
