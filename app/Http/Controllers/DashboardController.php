<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\Member;
use App\Models\Setting;
use App\Models\Transaction;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /** Turn ?period=today|month|quarter into a date range. */
    public static function periodRange(string $period): array
    {
        return match ($period) {
            'today' => [now()->startOfDay(), now()->endOfDay(), 'Today', 1 / 30],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter(), 'Q'.now()->quarter.' '.now()->year, 3],
            default => [now()->startOfMonth(), now()->endOfMonth(), 'This Month', 1],
        };
    }

    public function index(Request $request)
    {
        $period = in_array($request->query('period'), ['today', 'month', 'quarter']) ? $request->query('period') : 'month';
        [$from, $to, $periodLabel, $months] = self::periodRange($period);

        $sum = fn (string $type, $a = null, $b = null) => (float) Transaction::completed()
            ->where('type', $type)
            ->when($a, fn ($q) => $q->whereBetween('posted_at', [$a, $b]))
            ->sum('amount');

        // Members
        $members = [
            'total' => Member::count(),
            'active' => Member::where('status', 'active')->count(),
            'new' => Member::whereBetween('date_joined', [now()->startOfMonth(), now()->endOfMonth()])->count(),
        ];
        $members['inactive'] = $members['total'] - $members['active'];

        // Share capital
        $subscribed = (float) Member::sum('subscribed_shares');
        $paidUp = (float) array_sum(Transaction::shareBalances());

        // Savings and cash (all time)
        $savings = $sum('savings_deposit') - $sum('savings_withdrawal');
        $cashIn = (float) Transaction::completed()->where('direction', 'in')->sum('amount');
        $cashOut = (float) Transaction::completed()->where('direction', 'out')->sum('amount');

        // Cash movement in the chosen period
        $periodIn = (float) Transaction::completed()->where('direction', 'in')->whereBetween('posted_at', [$from, $to])->sum('amount');
        $periodOut = (float) Transaction::completed()->where('direction', 'out')->whereBetween('posted_at', [$from, $to])->sum('amount');

        // Loans
        $activeLoans = Loan::where('status', 'released')->where('balance', '>', 0);
        $outstanding = (float) (clone $activeLoans)->sum('balance');
        $pastDueAmount = (float) (clone $activeLoans)->whereDate('maturity_date', '<', now()->toDateString())->sum('balance');
        $parRatio = $outstanding > 0 ? round($pastDueAmount / $outstanding * 100, 2) : 0;
        $parLimit = (float) Setting::get('par_threshold');

        $collections = $sum('loan_payment', $from, $to);
        $expected = (float) (clone $activeLoans)->sum('monthly_amortization') * $months;
        $efficiency = $expected > 0 ? min(round($collections / $expected * 100, 1), 999) : 0;

        $stats = [
            'members' => $members,
            'subscribed' => $subscribed,
            'paid_up' => $paidUp,
            'paid_up_pct' => $subscribed > 0 ? round($paidUp / $subscribed * 100, 1) : 0,
            'savings' => $savings,
            'cash' => $cashIn - $cashOut,
            'period_in' => $periodIn,
            'period_out' => $periodOut,
            'releases_ytd' => (float) Loan::whereIn('status', ['released', 'paid'])->whereBetween('released_at', [now()->startOfYear()->toDateString(), now()->endOfYear()->toDateString()])->sum('principal'),
            'active_loans' => (clone $activeLoans)->count(),
            'collections' => $collections,
            'efficiency' => $efficiency,
            'target' => (float) Setting::get('collection_target'),
            'past_due' => $pastDueAmount,
            'par_ratio' => $parRatio,
            'par_ok' => $parRatio < $parLimit,
            'par_limit' => $parLimit,
        ];

        return view('dashboard', [
            'stats' => $stats,
            'period' => $period,
            'periodLabel' => $periodLabel,
            'recent' => Transaction::with(['member', 'user'])->latest('posted_at')->latest('id')->limit(8)->get(),
            'todayCount' => Transaction::completed()->whereDate('posted_at', now()->toDateString())->count(),
            'boardMeeting' => Setting::get('board_meeting'),
            'boardNote' => Setting::get('board_note'),
        ]);
    }
}
