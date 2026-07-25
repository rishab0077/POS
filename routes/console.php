<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use App\Jobs\SubmitCbmsInvoice;
use App\Jobs\SubmitCbmsCreditNote;
use App\Models\CbmsSubmission;
use App\Models\FiscalCreditNote;
use App\Services\BusinessConfigurationService;
use App\Services\CbmsService;
use App\Services\Operations\SystemStatusService;

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
    if (!app(CbmsService::class)->automaticDeliveryReady()) {
        $this->warn('Automatic CBMS delivery is disabled, unconfigured, or paused for acceptance.');
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

Artisan::command('cbms:preflight', function (BusinessConfigurationService $business, SystemStatusService $operations) {
    $details = $business->details();
    $url = (string) config('services.cbms.url');
    $status = $operations->status();
    $checks = [
        'Legal restaurant name is configured' => filled($details['name'])
            && !in_array(strtoupper(trim($details['name'])), ['RESTAURANT POS', 'TO BE UPDATED'], true),
        'Legal restaurant address is configured' => filled($details['address'])
            && !str_contains(strtoupper($details['address']), 'TO BE UPDATED'),
        'Seller PAN contains exactly nine digits' => preg_match('/^\d{9}$/', (string) $details['tax_registration']) === 1,
        'CBMS endpoint is the official HTTPS host' => parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_HOST) === 'cbapi.ird.gov.np',
        'CBMS username is configured' => filled(config('services.cbms.username')),
        'CBMS password is configured' => filled(config('services.cbms.password')),
        'Queue is asynchronous' => !in_array(config('queue.default'), ['sync', 'null'], true),
        'Database migrations are current' => data_get($status, 'migrations.ok') === true,
        'Scheduler heartbeat is current' => data_get($status, 'scheduler.ok') === true,
        'Application and database clocks agree' => data_get($status, 'clock.ok') === true,
        'CBMS outbox tables are available' => data_get($status, 'cbms.available') === true,
        'CBMS outbox has no unresolved records' => data_get($status, 'cbms.outstanding') === 0,
        'Application timezone is Asia/Kathmandu' => config('app.timezone') === 'Asia/Kathmandu',
        'Debug mode is disabled' => !config('app.debug'),
    ];

    foreach ($checks as $label => $passed) {
        $this->line(sprintf('[%s] %s', $passed ? 'PASS' : 'FAIL', $label));
    }

    $this->line('[INFO] CBMS delivery is ' . (config('services.cbms.enabled') ? 'enabled' : 'disabled'));
    $this->line('[INFO] CBMS acceptance mode is ' . (config('services.cbms.acceptance_mode') ? 'enabled' : 'disabled'));

    return in_array(false, $checks, true) ? 1 : 0;
})->purpose('Check whether this installation is ready for controlled CBMS acceptance');

Artisan::command('cbms:accept
    {type : invoice or credit-note}
    {id : CBMS submission or fiscal credit-note ID}
    {--confirm= : Must equal SEND TO IRD}', function (CbmsService $cbms, SystemStatusService $operations) {
    if (!config('services.cbms.acceptance_mode')) {
        $this->error('CBMS acceptance mode is not enabled.');
        return 1;
    }

    if (!$cbms->ready()) {
        $this->error('CBMS is disabled or credentials are missing.');
        return 1;
    }

    if (parse_url((string) config('services.cbms.url'), PHP_URL_HOST) !== 'cbapi.ird.gov.np') {
        $this->error('Controlled acceptance requires the official IRD CBMS host.');
        return 1;
    }

    if ($this->option('confirm') !== 'SEND TO IRD') {
        $this->error('Refusing live submission without --confirm="SEND TO IRD".');
        return 1;
    }

    $type = (string) $this->argument('type');
    $record = match ($type) {
        'invoice' => CbmsSubmission::find($this->argument('id')),
        'credit-note' => FiscalCreditNote::find($this->argument('id')),
        default => null,
    };

    if (!$record || !in_array($type, ['invoice', 'credit-note'], true)) {
        $this->error('The requested acceptance record was not found.');
        return 1;
    }

    if ($record->status === 'submitted') {
        $this->error('The requested record is already submitted.');
        return 1;
    }

    if ((int) data_get($operations->cbmsStatus(), 'outstanding') !== 1) {
        $this->error('Controlled acceptance requires exactly one unresolved CBMS record.');
        return 1;
    }

    $label = $type === 'invoice'
        ? 'invoice ' . $record->snapshot->invoice_no
        : 'credit note ' . $record->credit_note_no;
    $this->warn("Sending exactly one {$label} to IRD CBMS.");

    try {
        if ($type === 'invoice') {
            $cbms->submit($record);
        } else {
            $cbms->submitCreditNote($record);
        }
    } catch (\Throwable) {
        $this->error('IRD acceptance failed. Inspect the CBMS dashboard before retrying.');
        return 1;
    }

    $record->refresh();
    if ($record->response_code !== '200') {
        $this->error("IRD returned {$record->response_code}; controlled acceptance requires response 200.");
        return 1;
    }

    $this->info("IRD accepted {$label} with response 200.");
    $this->line('Verify it in the CBMS External Portal Sales Register Sync report.');
    return 0;
})->purpose('Send exactly one controlled invoice or credit note to IRD');
