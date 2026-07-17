<?php

namespace App\Http\Controllers\Billing;

use App\Enums\UserRole;
use App\Helpers\BillHelper;
use App\Helpers\PDFHelper;
use App\Http\Controllers\Controller;
use App\Models\Bill;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use App\Services\AuditLogger;

class BillController extends Controller
{
    public function __construct()
    {
        $this->middleware('stepup')->only('destroy');
    }

    public function getBillsByDate(Request $request)
    {
        $request->merge([
            'includeDeleted' => $request->boolean('includeDeleted'),
            'onlyDeleted' => $request->boolean('onlyDeleted'),
        ]);

        $validated = $request->validate([
            'startDate' => ['required', 'date'],
            'endDate' => ['required', 'date', 'after_or_equal:startDate'],
            'includeDeleted' => ['nullable', 'boolean'],
            'onlyDeleted' => ['nullable', 'boolean'],
        ]);

        $startDate = Carbon::parse($validated['startDate'])->startOfDay();
        $endDate = Carbon::parse($validated['endDate'])->endOfDay();
        $includeDeleted = $request->boolean('includeDeleted');
        $onlyDeleted = $request->boolean('onlyDeleted');

        /** @var \App\Models\User $user */
        $user = auth()->user();

        $billsQuery = Bill::with(['table', 'sourceTable'])
            ->where('status', 'closed')
            ->whereBetween('created_at', [$startDate, $endDate])
            ->orderBy('created_at', 'desc');

        if ($user->hasPermission(UserRole::Admin)) {
            if ($onlyDeleted) {
                $billsQuery->onlyTrashed();
            } elseif ($includeDeleted) {
                $billsQuery->withTrashed();
            }
        }

        $bills = $billsQuery->get();
        $totalSales = $bills->sum('grand_total');

        $html = '';

        foreach ($bills as $index => $bill) {
            $data = view('components.bill-component', compact('bill', 'index'))->render();
            $html .= $data;
        }

        return response()->json([
            'status' => 'success',
            'bills' => $html,
            'totalSales' => $totalSales,
        ]);
    }

    public function viewBill($id)
    {
        $bill = Bill::withTrashed()
            ->with(['table', 'sourceTable', 'orders.orderDetails.menu'])
            ->findOrFail($id);

        return view('admin.bills.view', compact('bill'));
    }


    public function StreamBillToBrowser($id)
    {
        $bill = Bill::where('id', $id)->firstOrFail();

        if (!$bill->isLocked()) {
            abort(422, 'Finalize the bill before opening the tax invoice.');
        }

        $billId = $bill->bill_id;
        $fileName = 'bill_' . $billId . '.pdf';
        $filePath = 'bills/' . $fileName;

        if (!Storage::exists($filePath)) {
            PDFHelper::saveBillToDisk($id);
        }

        $fileContent = Storage::get($filePath);

        $headers = [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . basename($filePath) . '"',
        ];

        // Stream the file to the browser
        return response($fileContent, 200, $headers);
    }

    public function previewBill($id)
    {
        $bill = Bill::with(['table', 'sourceTable', 'orders.orderDetails.menu'])
            ->findOrFail($id);

        if (!$bill->isLocked()) {
            abort(422, 'Finalize the bill before opening the tax invoice.');
        }

        $orderDetails = BillHelper::getBillOrders($bill->id);

        return view('admin.bills.print', compact('bill', 'orderDetails'));
    }

    public function destroy($billid, AuditLogger $audit)
    {
        abort_unless(auth()->user()?->canAdministerApplication(), 403, 'Only an administrator or owner can remove an open bill.');

        $bill = Bill::find($billid);

        if (!$bill) {
            return redirect()->route('admin.bills.index')->with('danger', 'Bill not found.');
        }

        if ($bill->isLocked() || $bill->status === 'closed') {
            return redirect()->route('admin.bills.index')->with('danger', 'Finalized or closed bills cannot be deleted.');
        }

        $bill->delete();
        $audit->record('bill_deleted', 'billing', [
            'subject_type' => Bill::class,
            'subject_id' => $bill->id,
            'before' => $bill->only(['id', 'bill_id', 'invoice_no', 'table_id', 'status', 'locked_at', 'printed_at']),
            'metadata' => ['summary' => "Open bill #{$bill->id} deleted."],
        ]);

        return redirect()->route('admin.bills.index')->with('success', 'Bill deleted successfully.');
    }
}
