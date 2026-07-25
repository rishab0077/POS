<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SubmitCbmsInvoice;
use App\Jobs\SubmitCbmsCreditNote;
use App\Models\CbmsSubmission;
use App\Models\FiscalCreditNote;
use App\Services\AuditLogger;
use App\Services\CbmsService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CbmsSubmissionController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only('retry', 'issueCreditNote', 'retryCreditNote');
    }

    public function index(Request $request, CbmsService $cbms)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:pending,submitting,failed,submitted'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $query = CbmsSubmission::with('snapshot.bill', 'snapshot.items', 'snapshot.creditNotes.items');

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['search'])) {
            $search = trim($filters['search']);
            $query->whereHas('snapshot', fn ($query) => $query
                ->where('invoice_no', 'like', "%{$search}%")
                ->orWhere('buyer_name', 'like', "%{$search}%")
                ->orWhere('buyer_pan', 'like', "%{$search}%"));
        }

        $counts = CbmsSubmission::query()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $submissions = $query->latest()->paginate(25)->withQueryString();
        $creditNotes = FiscalCreditNote::with('snapshot', 'items', 'transactions')->latest('issued_at')->limit(25)->get();

        return view('admin.cbms.index', [
            'submissions' => $submissions,
            'counts' => collect(['pending', 'submitting', 'failed', 'submitted'])
                ->mapWithKeys(fn ($status) => [$status => (int) ($counts[$status] ?? 0)]),
            'ready' => $cbms->ready(),
            'enabled' => (bool) config('services.cbms.enabled'),
            'creditNotes' => $creditNotes,
        ]);
    }

    public function retry(CbmsSubmission $cbmsSubmission, CbmsService $cbms, AuditLogger $audit)
    {
        abort_unless($cbms->automaticDeliveryReady(), 409, 'Automatic CBMS delivery is unavailable during acceptance mode.');
        abort_unless($cbmsSubmission->status === 'failed', 409, 'Only failed CBMS submissions can be retried.');

        $before = $cbmsSubmission->only(['id', 'status', 'attempts', 'response_code', 'last_error']);
        $cbmsSubmission->update(['status' => 'pending', 'last_error' => null]);
        SubmitCbmsInvoice::dispatch($cbmsSubmission->id, (string) Str::uuid());

        $audit->record('cbms_submission_manual_retry', 'tax_compliance', [
            'subject' => $cbmsSubmission,
            'before' => $before,
            'after' => $cbmsSubmission->fresh()->only(['id', 'status', 'attempts', 'response_code', 'last_error']),
            'metadata' => [
                'summary' => "CBMS submission #{$cbmsSubmission->id} queued for manual retry.",
                'invoice_no' => $cbmsSubmission->snapshot->invoice_no,
            ],
        ]);

        return redirect()->route('admin.cbms.index')->with('success', 'CBMS invoice queued for retry.');
    }

    public function issueCreditNote(
        Request $request,
        CbmsSubmission $cbmsSubmission,
        CbmsService $cbms,
        AuditLogger $audit
    ) {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
            'refund_method' => ['required', 'in:cash,card,esewa,fonepay,khalti,credit'],
            'refund_reference' => ['nullable', 'string', 'max:100'],
            'items' => ['required', 'array'],
            'items.*' => ['nullable', 'numeric', 'min:0'],
        ]);

        $creditNote = $cbms->issueCreditNote(
            $cbmsSubmission->snapshot,
            trim($data['reason']),
            $data['refund_method'],
            $request->user(),
            $data['items'],
            $data['refund_reference'] ?? null
        );

        $audit->record('fiscal_credit_note_issued', 'tax_compliance', [
            'subject' => $creditNote,
            'after' => $creditNote->only([
                'id', 'fiscal_invoice_snapshot_id', 'credit_note_no', 'reason',
                'refund_method', 'total_sales', 'operator_id', 'status',
            ]),
            'metadata' => [
                'summary' => "Credit note {$creditNote->credit_note_no} issued.",
                'invoice_no' => $cbmsSubmission->snapshot->invoice_no,
            ],
        ]);

        return redirect()->route('admin.cbms.index')->with(
            'success',
            "Credit note {$creditNote->credit_note_no} issued and queued for CBMS."
        );
    }

    public function retryCreditNote(
        FiscalCreditNote $fiscalCreditNote,
        CbmsService $cbms,
        AuditLogger $audit
    ) {
        abort_unless($cbms->automaticDeliveryReady(), 409, 'Automatic CBMS delivery is unavailable during acceptance mode.');
        abort_unless($fiscalCreditNote->status === 'failed', 409, 'Only failed credit notes can be retried.');

        $before = $fiscalCreditNote->only(['id', 'status', 'attempts', 'response_code', 'last_error']);
        $fiscalCreditNote->update(['status' => 'pending', 'last_error' => null]);
        SubmitCbmsCreditNote::dispatch($fiscalCreditNote->id, (string) Str::uuid());

        $audit->record('cbms_credit_note_manual_retry', 'tax_compliance', [
            'subject' => $fiscalCreditNote,
            'before' => $before,
            'after' => $fiscalCreditNote->fresh()->only(['id', 'status', 'attempts', 'response_code', 'last_error']),
            'metadata' => [
                'summary' => "CBMS credit note #{$fiscalCreditNote->id} queued for manual retry.",
                'credit_note_no' => $fiscalCreditNote->credit_note_no,
            ],
        ]);

        return redirect()->route('admin.cbms.index')->with('success', 'CBMS credit note queued for retry.');
    }

    public function printCreditNote(FiscalCreditNote $fiscalCreditNote)
    {
        $fiscalCreditNote->load('snapshot', 'items', 'transactions');

        return view('admin.cbms.credit-note', ['creditNote' => $fiscalCreditNote]);
    }
}
