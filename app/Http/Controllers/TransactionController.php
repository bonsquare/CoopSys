<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function index(Request $request)
    {
        $base = $this->filtered($request);

        $totals = [
            'in' => (float) (clone $base)->completed()->where('direction', 'in')->sum('amount'),
            'out' => (float) (clone $base)->completed()->where('direction', 'out')->sum('amount'),
        ];

        return view('transactions.index', [
            'transactions' => $base->with(['member', 'user'])->latest('posted_at')->latest('id')->paginate(20)->withQueryString(),
            'totals' => $totals,
            'types' => collect(Transaction::TYPES)->map(fn ($t) => $t[0])->all(),
            'filters' => $request->only(['q', 'type', 'status', 'from', 'to']),
        ]);
    }

    public function show(Transaction $transaction)
    {
        $transaction->load(['member', 'loan', 'user', 'journalEntry.lines.account']);

        return view('transactions.show', ['t' => $transaction]);
    }

    /** Printable transaction slip. */
    public function receipt(Transaction $transaction)
    {
        $transaction->load(['member', 'loan', 'user']);

        return view('transactions.receipt', ['t' => $transaction]);
    }

    public function void(Request $request, Transaction $transaction, TransactionService $service)
    {
        $data = $request->validate(['reason' => 'required|string|max:150']);
        $service->void($transaction, $data['reason']);

        return redirect()->route('transactions.show', $transaction)->with('status', 'Transaction voided and the accounting entry was reversed.');
    }

    /** Download the (filtered) ledger as CSV, which Excel opens directly. */
    public function export(Request $request)
    {
        $query = $this->filtered($request);

        // The dashboard sends ?period=today|month|quarter
        if ($request->filled('period')) {
            [$from, $to] = DashboardController::periodRange($request->query('period'));
            $query->whereBetween('posted_at', [$from, $to]);
        }

        $rows = $query->with(['member', 'user'])->orderBy('posted_at')->orderBy('id')->get();

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // so Excel reads the peso sign and accents correctly
            fputcsv($out, ['Reference', 'Date', 'Member No.', 'Member', 'Type', 'Direction', 'Amount', 'Status', 'Handled By', 'Remarks']);
            foreach ($rows as $t) {
                fputcsv($out, [
                    $t->reference_no, $t->posted_at->format('Y-m-d H:i'), $t->member?->member_no, $t->member?->full_name,
                    $t->label(), strtoupper($t->direction), number_format($t->amount, 2, '.', ''), $t->status, $t->user?->name, $t->remarks,
                ]);
            }
            fclose($out);
        }, 'ledger-'.now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function filtered(Request $request): Builder
    {
        $q = trim((string) $request->query('q'));

        return Transaction::query()
            ->when($q, fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('reference_no', 'like', "%$q%")
                    ->orWhere('remarks', 'like', "%$q%")
                    ->orWhereHas('member', fn ($m) => $m->where('first_name', 'like', "%$q%")->orWhere('last_name', 'like', "%$q%")->orWhere('member_no', 'like', "%$q%"));
            }))
            ->when(array_key_exists($request->query('type'), Transaction::TYPES), fn ($query) => $query->where('type', $request->query('type')))
            ->when(in_array($request->query('status'), ['completed', 'voided']), fn ($query) => $query->where('status', $request->query('status')))
            ->when($request->query('from'), fn ($query, $from) => $query->where('posted_at', '>=', $from.' 00:00:00'))
            ->when($request->query('to'), fn ($query, $to) => $query->where('posted_at', '<=', $to.' 23:59:59'));
    }
}
