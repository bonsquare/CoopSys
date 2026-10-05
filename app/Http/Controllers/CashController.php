<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;

class CashController extends Controller
{
    private const RECEIPT_TYPES = ['loan_payment', 'share_deposit', 'savings_deposit', 'other_receipt'];

    private const DISBURSEMENT_TYPES = ['savings_withdrawal', 'expense'];

    public function receipts()
    {
        return view('cash.index', [
            'mode' => 'receipts',
            'title' => 'Cash Receipts',
            'subtitle' => 'Record money received: loan payments, shares, savings and other income.',
            'formTypes' => $this->labels(self::RECEIPT_TYPES),
            'storeRoute' => route('receipts.store'),
            'options' => Member::options(),
            'rows' => $this->rows('in'),
            'total' => (float) Transaction::completed()->where('direction', 'in')->whereDate('posted_at', now()->toDateString())->sum('amount'),
        ]);
    }

    public function storeReceipt(Request $request, TransactionService $service)
    {
        $tx = $service->post($this->validated($request, self::RECEIPT_TYPES));

        return back()->with('status', "Receipt recorded: {$tx->reference_no}");
    }

    public function disbursements()
    {
        return view('cash.index', [
            'mode' => 'disbursements',
            'title' => 'Cash Disbursements',
            'subtitle' => 'Record money paid out: member withdrawals and expenses. Loan releases are done from Loan Management.',
            'formTypes' => $this->labels(self::DISBURSEMENT_TYPES),
            'storeRoute' => route('disbursements.store'),
            'options' => Member::options(),
            'rows' => $this->rows('out'),
            'total' => (float) Transaction::completed()->where('direction', 'out')->whereDate('posted_at', now()->toDateString())->sum('amount'),
        ]);
    }

    public function storeDisbursement(Request $request, TransactionService $service)
    {
        $tx = $service->post($this->validated($request, self::DISBURSEMENT_TYPES));

        return back()->with('status', "Disbursement recorded: {$tx->reference_no}");
    }

    /** Released loans of one member (used by the loan dropdown on the receipts form). */
    public function memberLoans(Member $member)
    {
        return Loan::where('member_id', $member->id)->where('status', 'released')->where('balance', '>', 0)->get()
            ->map(fn ($l) => ['id' => $l->id, 'label' => $l->loan_no.' — balance ₱'.number_format($l->balance, 2)]);
    }

    private function validated(Request $request, array $types): array
    {
        return $request->validate([
            'type' => 'required|in:'.implode(',', $types),
            'member_id' => 'nullable|exists:members,id',
            'loan_id' => 'nullable|exists:loans,id',
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'remarks' => 'nullable|string|max:255',
        ]);
    }

    private function labels(array $types): array
    {
        return collect($types)->mapWithKeys(fn ($t) => [$t => Transaction::TYPES[$t][0]])->all();
    }

    private function rows(string $direction)
    {
        return Transaction::with(['member', 'user'])->where('direction', $direction)
            ->latest('posted_at')->latest('id')->paginate(12);
    }
}
