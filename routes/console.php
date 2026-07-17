<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\SubmitCbmsInvoice;
use App\Jobs\SubmitCbmsCreditNote;
use App\Models\CbmsSubmission;
use App\Models\FiscalCreditNote;
use App\Services\CbmsService;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('cbms:dispatch', function () {
    if (!app(CbmsService::class)->ready()) {
        $this->warn('CBMS is disabled or credentials are missing.');
        return 1;
    }

    $count = 0;
    CbmsSubmission::where('status', '!=', 'submitted')->chunkById(100, function ($submissions) use (&$count) {
        foreach ($submissions as $submission) {
            SubmitCbmsInvoice::dispatch($submission->id);
            $count++;
        }
    });

    FiscalCreditNote::where('status', '!=', 'submitted')->chunkById(100, function ($creditNotes) use (&$count) {
        foreach ($creditNotes as $creditNote) {
            SubmitCbmsCreditNote::dispatch($creditNote->id);
            $count++;
        }
    });

    $this->info("Queued {$count} CBMS invoice or credit-note submission(s).");
    return 0;
})->purpose('Queue pending CBMS invoices for submission');
