<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\CollectionLine;
use App\Models\Loan;
use App\Models\Member;
use App\Models\Transaction;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public const REPORTS = [
        'members' => 'Member masterlist',
        'share_capital' => 'Share capital per member',
        'savings' => 'Savings balances',
        'loan_portfolio' => 'Loan portfolio',
        'past_due' => 'Past due loans',
        'collections' => 'Loan collections (date range)',
        'cash_flow' => 'Daily cash flow (date range)',
        'daily_collection' => 'Daily collection report (date range)',
        'collection_by_type' => 'Collection summary by type (date range)',
        'cashier_summary' => 'Cashier collection summary (date range)',
        'penalty_collection' => 'Penalty collection report (date range)',
        'cancelled_receipts' => 'Cancelled receipts report (date range)',
        'trial_balance' => 'Trial balance (as of "to" date)',
    ];

    public function index(Request $request)
    {
        [$report, $from, $to] = $this->params($request);

        return view('reports.index', [
            'reports' => self::REPORTS,
            'report' => $report,
            'from' => $from,
            'to' => $to,
            'data' => $this->build($report, $from, $to),
        ]);
    }

    public function export(Request $request)
    {
        [$report, $from, $to] = $this->params($request);
        $data = $this->build($report, $from, $to);

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [$data['title']]);
            fputcsv($out, $data['headers']);
            foreach ($data['rows'] as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, "report-$report-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function params(Request $request): array
    {
        $report = array_key_exists($request->query('report'), self::REPORTS) ? $request->query('report') : 'members';
        $from = $request->query('from') ?: now()->startOfMonth()->toDateString();
        $to = $request->query('to') ?: now()->toDateString();

        return [$report, $from, $to];
    }

    /** Returns ['title', 'headers', 'rows', 'money' => column numbers shown as pesos]. */
    private function build(string $report, string $from, string $to): array
    {
        $n = fn ($v) => number_format((float) $v, 2);

        switch ($report) {
            case 'share_capital':
                $paid = Transaction::shareBalances();
                $rows = Member::orderBy('last_name')->get()->map(fn ($m) => [
                    $m->member_no, $m->full_name, $n($m->subscribed_shares), $n($paid[$m->id] ?? 0), $n($m->subscribed_shares - ($paid[$m->id] ?? 0)),
                ])->all();

                return ['title' => 'Share capital per member', 'headers' => ['Member No.', 'Name', 'Subscribed', 'Paid-up', 'Unpaid'], 'rows' => $rows, 'money' => [2, 3, 4]];

            case 'savings':
                $bal = [];
                foreach (Transaction::completed()->whereIn('type', ['savings_deposit', 'savings_withdrawal'])->selectRaw('member_id, type, sum(amount) t')->groupBy('member_id', 'type')->get() as $r) {
                    $bal[$r->member_id] = ($bal[$r->member_id] ?? 0) + ($r->type === 'savings_deposit' ? $r->t : -$r->t);
                }
                $rows = Member::orderBy('last_name')->get()->map(fn ($m) => [$m->member_no, $m->full_name, $n($bal[$m->id] ?? 0)])->all();

                return ['title' => 'Savings balances', 'headers' => ['Member No.', 'Name', 'Balance'], 'rows' => $rows, 'money' => [2]];

            case 'loan_portfolio':
                $rows = Loan::with('member')->orderBy('loan_no')->get()->map(fn ($l) => [
                    $l->loan_no, $l->member->full_name, ucfirst($l->status), $n($l->principal), $n($l->total_payable), $n($l->balance), $l->maturity_date?->format('Y-m-d') ?? '',
                ])->all();

                return ['title' => 'Loan portfolio', 'headers' => ['Loan No.', 'Member', 'Status', 'Principal', 'Total payable', 'Balance', 'Maturity'], 'rows' => $rows, 'money' => [3, 4, 5]];

            case 'past_due':
                $rows = Loan::with('member')->where('status', 'released')->where('balance', '>', 0)->whereDate('maturity_date', '<', now()->toDateString())->get()->map(fn ($l) => [
                    $l->loan_no, $l->member->full_name, $l->maturity_date->format('Y-m-d'), $l->maturity_date->diffInDays(now()).' days', $n($l->balance),
                ])->all();

                return ['title' => 'Past due loans', 'headers' => ['Loan No.', 'Member', 'Matured', 'Overdue', 'Balance'], 'rows' => $rows, 'money' => [4]];

            case 'collections':
                $rows = Transaction::with('member')->completed()->where('type', 'loan_payment')
                    ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('posted_at')->get()->map(fn ($t) => [
                        $t->reference_no, $t->posted_at->format('Y-m-d'), $t->member?->full_name, $t->loan?->loan_no, $n($t->amount),
                    ])->all();
                $total = array_sum(array_map(fn ($r) => (float) str_replace(',', '', $r[4]), $rows));
                $rows[] = ['', '', '', 'TOTAL', $n($total)];

                return ['title' => "Loan collections $from to $to", 'headers' => ['Reference', 'Date', 'Member', 'Loan', 'Amount'], 'rows' => $rows, 'money' => [4]];

            case 'cash_flow':
                $days = Transaction::completed()->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])
                    ->selectRaw('date(posted_at) d, direction, sum(amount) t')->groupBy('d', 'direction')->orderBy('d')->get()->groupBy('d');
                $rows = [];
                $in = $out = 0;
                foreach ($days as $d => $set) {
                    $i = (float) ($set->firstWhere('direction', 'in')->t ?? 0);
                    $o = (float) ($set->firstWhere('direction', 'out')->t ?? 0);
                    $in += $i;
                    $out += $o;
                    $rows[] = [$d, $n($i), $n($o), $n($i - $o)];
                }
                $rows[] = ['TOTAL', $n($in), $n($out), $n($in - $out)];

                return ['title' => "Daily cash flow $from to $to", 'headers' => ['Date', 'Cash In', 'Cash Out', 'Net'], 'rows' => $rows, 'money' => [1, 2, 3]];

            case 'daily_collection':
                $receipts = Collection::with(['member', 'collector'])->where('status', 'posted')
                    ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('posted_at')->get();
                $rows = $receipts->map(fn ($c) => [
                    $c->receipt_no, $c->posted_at->format('Y-m-d H:i'), $c->member->full_name, $c->typeLabel(),
                    config('coop.payment_methods.'.$c->payment_method, $c->payment_method), $c->direction === 'in' ? 'Received' : 'Paid out', $n($c->total_amount), $c->collector?->name,
                ])->all();
                $rows[] = ['TOTAL', '', '', '', '', 'Received', $n($receipts->where('direction', 'in')->sum('total_amount')), ''];
                $rows[] = ['TOTAL', '', '', '', '', 'Paid out', $n($receipts->where('direction', 'out')->sum('total_amount')), ''];

                return ['title' => "Daily collection report $from to $to", 'headers' => ['Receipt', 'Posted', 'Member', 'Type', 'Method', 'Direction', 'Amount', 'Collected by'], 'rows' => $rows, 'money' => [6]];

            case 'collection_by_type':
                $lines = CollectionLine::with('collection')->whereHas('collection', fn ($q) => $q->where('status', 'posted')->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59']))->get();
                $rows = $lines->groupBy('allocation_type')->map(fn ($group, $type) => [
                    config("collections.allocations.$type.label", $type),
                    config("collections.allocations.$type.direction") === 'out' ? 'Paid out' : 'Received',
                    $group->count(), $n($group->sum('amount')),
                ])->values()->all();
                $rows[] = ['TOTAL received', '', $lines->filter(fn ($l) => ! $l->isOut())->count(), $n($lines->filter(fn ($l) => ! $l->isOut())->sum('amount'))];
                $rows[] = ['TOTAL paid out', '', $lines->filter(fn ($l) => $l->isOut())->count(), $n($lines->filter(fn ($l) => $l->isOut())->sum('amount'))];

                return ['title' => "Collection summary by type $from to $to", 'headers' => ['Type', 'Direction', 'Lines', 'Amount'], 'rows' => $rows, 'money' => [3]];

            case 'cashier_summary':
                $receipts = Collection::with('collector')->where('status', 'posted')->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->get();
                $rows = $receipts->groupBy('collected_by')->map(function ($group) use ($n) {
                    $in = $group->where('direction', 'in')->sum('total_amount');
                    $out = $group->where('direction', 'out')->sum('total_amount');

                    return [$group->first()->collector?->name ?? 'Unknown', $group->count(), $n($in), $n($out), $n($in - $out)];
                })->values()->all();
                $in = $receipts->where('direction', 'in')->sum('total_amount');
                $out = $receipts->where('direction', 'out')->sum('total_amount');
                $rows[] = ['TOTAL', $receipts->count(), $n($in), $n($out), $n($in - $out)];

                return ['title' => "Cashier collection summary $from to $to", 'headers' => ['Cashier', 'Receipts', 'Received', 'Paid out', 'Net cash'], 'rows' => $rows, 'money' => [2, 3, 4]];

            case 'penalty_collection':
                $txs = Transaction::with(['member', 'loan', 'collection', 'user'])->completed()->where('type', 'loan_penalty')
                    ->whereBetween('posted_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('posted_at')->get();
                $rows = $txs->map(fn ($t) => [
                    $t->posted_at->format('Y-m-d'), $t->collection?->receipt_no ?? $t->reference_no, $t->member?->full_name, $t->loan?->loan_no, $n($t->amount), $t->user?->name,
                ])->all();
                $rows[] = ['TOTAL', '', '', '', $n($txs->sum('amount')), ''];

                return ['title' => "Penalty collection report $from to $to", 'headers' => ['Date', 'Receipt', 'Member', 'Loan', 'Penalty collected', 'Collected by'], 'rows' => $rows, 'money' => [4]];

            case 'cancelled_receipts':
                $cancelled = Collection::with(['member', 'collector', 'canceller'])->where('status', 'cancelled')
                    ->whereBetween('cancelled_at', [$from.' 00:00:00', $to.' 23:59:59'])->orderBy('cancelled_at')->get();
                $rows = $cancelled->map(fn ($c) => [
                    $c->receipt_no, $c->member->full_name, $c->typeLabel(), $n($c->total_amount), $c->posted_at ? 'Posted, then reversed' : 'Draft',
                    $c->collector?->name, $c->canceller?->name, $c->cancelled_at->format('Y-m-d H:i'), $c->cancel_reason,
                ])->all();

                return ['title' => "Cancelled receipts $from to $to", 'headers' => ['Receipt', 'Member', 'Type', 'Amount', 'Was', 'Collected by', 'Cancelled by', 'Cancelled at', 'Reason'], 'rows' => $rows, 'money' => [3]];

            case 'trial_balance':
                $tb = AccountingController::trialBalance($to);
                $rows = $tb['rows']->map(fn ($r) => [$r['account']->code, $r['account']->name, $n($r['tb_debit']), $n($r['tb_credit'])])->all();
                $rows[] = ['', 'TOTAL', $n($tb['total_debit']), $n($tb['total_credit'])];

                return ['title' => "Trial balance as of $to", 'headers' => ['Code', 'Account', 'Debit', 'Credit'], 'rows' => $rows, 'money' => [2, 3]];

            default: // members
                $rows = Member::orderBy('last_name')->get()->map(fn ($m) => [
                    $m->member_no, $m->full_name, $m->contact_no, $m->email, $m->date_joined->format('Y-m-d'), ucfirst($m->status),
                ])->all();

                return ['title' => 'Member masterlist', 'headers' => ['Member No.', 'Name', 'Contact', 'Email', 'Joined', 'Status'], 'rows' => $rows, 'money' => []];
        }
    }
}
