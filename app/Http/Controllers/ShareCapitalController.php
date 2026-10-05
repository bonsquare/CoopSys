<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Member;
use App\Models\Transaction;
use App\Services\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ShareCapitalController extends Controller
{
    public function index()
    {
        $paid = Transaction::shareBalances();

        $members = Member::where('status', 'active')->orderBy('last_name')->orderBy('first_name')->get()
            ->each(fn ($m) => $m->paid_up = (float) ($paid[$m->id] ?? 0));

        return view('share.index', [
            'members' => $members,
            'options' => Member::options(),
            'totalSubscribed' => (float) $members->sum('subscribed_shares'),
            'totalPaid' => (float) $members->sum('paid_up'),
        ]);
    }

    /** Receive a share capital payment. */
    public function payment(Request $request, TransactionService $service)
    {
        $data = $request->validate([
            'member_id' => 'required|exists:members,id',
            'amount' => 'required|numeric|min:0.01|max:100000000',
            'remarks' => 'nullable|string|max:255',
        ]);

        $tx = $service->post($data + ['type' => 'share_deposit']);

        return redirect()->route('share.index')->with('status', "Share payment recorded: {$tx->reference_no}");
    }

    /** Set how much share capital a member promises to pay in total. */
    public function subscription(Request $request, Member $member)
    {
        $data = $request->validate(['subscribed_shares' => 'required|numeric|min:0|max:100000000']);

        $paid = $member->paidUpShares();
        if ($data['subscribed_shares'] < $paid) {
            throw ValidationException::withMessages([
                'subscribed_shares' => 'Subscription cannot be lower than what is already paid ('.number_format($paid, 2).').',
            ]);
        }

        $member->update($data);
        AuditLog::record('share.subscription', "{$member->member_no} subscribed ".number_format($data['subscribed_shares'], 2));

        return redirect()->route('share.index')->with('status', "Subscription updated for {$member->full_name}.");
    }

    /** Share capital ledger: contributions and withdrawals with a running balance. */
    public function ledger(Request $request, Member $member)
    {
        $input = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $from = $input['from'] ?? now()->startOfYear()->toDateString();
        $to = $input['to'] ?? now()->toDateString();

        $base = $member->transactions()->completed()->whereIn('type', ['share_deposit', 'share_withdrawal']);
        $opening = (float) (clone $base)->where('posted_at', '<', $from.' 00:00:00')->get()->sum(fn ($t) => $t->signedAmount());
        $rows = (clone $base)->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('posted_at')->orderBy('id')->get();

        $running = $opening;
        $lines = $rows->map(function ($t) use (&$running) {
            $running += $t->signedAmount();

            return ['tx' => $t, 'balance' => round($running, 2)];
        });

        return view('share.ledger', [
            'member' => $member, 'from' => $from, 'to' => $to, 'opening' => $opening, 'lines' => $lines,
            'contributions' => (float) $rows->where('type', 'share_deposit')->sum('amount'),
            'withdrawals' => (float) $rows->where('type', 'share_withdrawal')->sum('amount'),
            'closing' => round($running, 2),
            'subscribed' => (float) $member->subscribed_shares,
        ]);
    }
}
