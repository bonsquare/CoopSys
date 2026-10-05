<?php

namespace App\Services;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use Illuminate\Support\Carbon;

/**
 * Financial statements and management reports built from the journal.
 */
class FinancialReportService
{
    public const CASH_GROUP = 'Cash and Bank';

    public function __construct(private AccountingService $accounting, private PortfolioReportService $portfolio) {}

    // ------------------------------------------------------------------
    // Periods
    // ------------------------------------------------------------------

    /** The period to compare with: 'previous' (the one just before) or 'lastyear' (same dates a year earlier). */
    public function comparisonRange(string $from, string $to, string $mode): ?array
    {
        $a = Carbon::parse($from);
        $b = Carbon::parse($to);

        if ($mode === 'lastyear') {
            return [$a->copy()->subYear()->toDateString(), $b->copy()->subYear()->toDateString()];
        }
        if ($mode !== 'previous') {
            return null;
        }

        // Whole months (for example a month, a quarter or a year): step back by the same number of months
        if ($a->isSameDay($a->copy()->startOfMonth()) && $b->isSameDay($b->copy()->endOfMonth())) {
            $months = (int) round(abs($a->diffInMonths($b->copy()->addDay())));
            $start = $a->copy()->subMonths($months);

            return [$start->toDateString(), $start->copy()->addMonths($months)->subDay()->toDateString()];
        }

        $days = (int) round(abs($a->diffInDays($b))) + 1;
        $end = $a->copy()->subDay();

        return [$end->copy()->subDays($days - 1)->toDateString(), $end->toDateString()];
    }

    // ------------------------------------------------------------------
    // Income statement with comparison
    // ------------------------------------------------------------------

    /**
     * Income statement in sections, optionally next to another period.
     * Returns ['sections' => ['income' => [...groups], 'expense' => [...groups]], totals, 'compare' => bool].
     */
    public function incomeStatement(string $from, string $to, ?array $compareRange = null): array
    {
        $current = $this->accounting->incomeStatement($from, $to);
        $previous = $compareRange ? $this->accounting->incomeStatement($compareRange[0], $compareRange[1]) : null;

        $sections = [
            'income' => $this->mergeGroups($current['income'], $previous['income'] ?? []),
            'expense' => $this->mergeGroups($current['expenses'], $previous['expenses'] ?? []),
        ];

        $totalIncome = $current['total_income'];

        return [
            'from' => $from, 'to' => $to, 'compare' => $previous !== null, 'compare_range' => $compareRange,
            'sections' => $sections,
            'total_income' => $totalIncome,
            'total_expenses' => $current['total_expenses'],
            'net' => $current['net'],
            'prev_income' => $previous['total_income'] ?? null,
            'prev_expenses' => $previous['total_expenses'] ?? null,
            'prev_net' => $previous['net'] ?? null,
            'net_change' => $previous ? round($current['net'] - $previous['net'], 2) : null,
            'net_pct' => $previous ? $this->pct($current['net'] - $previous['net'], $previous['net']) : null,
            'income_change' => $previous ? round($totalIncome - $previous['total_income'], 2) : null,
            'income_pct' => $previous ? $this->pct($totalIncome - $previous['total_income'], $previous['total_income']) : null,
            'expense_change' => $previous ? round($current['total_expenses'] - $previous['total_expenses'], 2) : null,
            'expense_pct' => $previous ? $this->pct($current['total_expenses'] - $previous['total_expenses'], $previous['total_expenses']) : null,
        ];
    }

    /** Balance sheet in sections, optionally next to another date. */
    public function balanceSheet(string $asOf, ?string $compareDate = null): array
    {
        $current = $this->accounting->balanceSheet($asOf);
        $previous = $compareDate ? $this->accounting->balanceSheet($compareDate) : null;

        $sections = [
            'asset' => $this->mergeGroups($current['assets'], $previous['assets'] ?? []),
            'liability' => $this->mergeGroups($current['liabilities'], $previous['liabilities'] ?? []),
            'equity' => $this->mergeGroups($current['equity'], $previous['equity'] ?? []),
        ];

        $change = fn ($key) => $previous ? round($current[$key] - $previous[$key], 2) : null;

        return [
            'as_of' => $asOf, 'compare_date' => $compareDate, 'compare' => $previous !== null,
            'sections' => $sections,
            'current' => $current,
            'previous' => $previous,
            'changes' => [
                'assets' => $change('total_assets'), 'liabilities' => $change('total_liabilities'), 'equity' => $change('total_equity'),
                'prior_surplus' => $change('prior_surplus'), 'current_surplus' => $change('current_surplus'),
            ],
        ];
    }

    /**
     * Group statement lines by their report group. Each line carries the current and the previous amount.
     * Returns [ ['name' => ..., 'lines' => [...], 'current' => x, 'previous' => y|null, ...], ... ] in account code order.
     */
    private function mergeGroups(array $current, array $previous): array
    {
        $lines = [];
        foreach ($current as $l) {
            $lines[$l['account']->id] = ['account' => $l['account'], 'current' => $l['amount'], 'previous' => 0.0];
        }
        foreach ($previous as $l) {
            $lines[$l['account']->id] ??= ['account' => $l['account'], 'current' => 0.0, 'previous' => 0.0];
            $lines[$l['account']->id]['previous'] = $l['amount'];
        }

        $hasPrevious = $previous !== [];
        $groups = [];
        foreach (collect($lines)->sortBy(fn ($l) => $l['account']->code) as $line) {
            $line['change'] = round($line['current'] - $line['previous'], 2);
            $line['pct'] = $this->pct($line['change'], $line['previous']);
            $groups[$line['account']->groupName()][] = $line;
        }

        $out = [];
        foreach ($groups as $name => $groupLines) {
            $current = round(collect($groupLines)->sum('current'), 2);
            $previous = round(collect($groupLines)->sum('previous'), 2);
            $out[] = [
                'name' => $name, 'lines' => $groupLines, 'current' => $current, 'previous' => $previous,
                'change' => round($current - $previous, 2), 'pct' => $this->pct($current - $previous, $previous), 'has_previous' => $hasPrevious,
            ];
        }

        return $out;
    }

    /** Percentage change, or null when there is nothing to compare with. */
    public function pct(float $change, float $base): ?float
    {
        return abs($base) < 0.005 ? null : round($change / abs($base) * 100, 1);
    }

    // ------------------------------------------------------------------
    // Cash flow (direct method)
    // ------------------------------------------------------------------

    /** Section of the cash flow statement for the account on the other side of a cash entry. */
    public function cashFlowCategory(Account $account): string
    {
        if ($override = config("accounting.cash_flow.{$account->code}")) {
            return $override;
        }

        return match ($account->type) {
            'income', 'expense' => 'operating',
            'asset' => 'investing',
            'equity' => 'financing',
            default => 'operating',
        };
    }

    public function cashAccountIds(): array
    {
        return Account::where('report_group', self::CASH_GROUP)->pluck('id')->all();
    }

    public function cashFlow(string $from, string $to): array
    {
        $cashIds = $this->cashAccountIds();
        $entries = JournalEntry::with('lines.account')
            ->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)->get();

        $buckets = [];   // account id => net cash effect
        foreach ($entries as $entry) {
            if (! $entry->lines->contains(fn ($l) => in_array($l->account_id, $cashIds))) {
                continue;
            }
            foreach ($entry->lines as $line) {
                if (in_array($line->account_id, $cashIds)) {
                    continue;
                }
                // A credit on the other side means cash came in; a debit means cash went out
                $buckets[$line->account_id] = ($buckets[$line->account_id] ?? 0) + (float) $line->credit - (float) $line->debit;
            }
        }

        $sections = ['operating' => [], 'investing' => [], 'financing' => []];
        foreach (Account::whereIn('id', array_keys($buckets))->orderBy('code')->get() as $account) {
            $amount = round($buckets[$account->id], 2);
            if (abs($amount) < 0.005) {
                continue;
            }
            $sections[$this->cashFlowCategory($account)][] = ['account' => $account, 'amount' => $amount];
        }

        $totals = array_map(fn ($lines) => round(collect($lines)->sum('amount'), 2), $sections);
        $net = round(array_sum($totals), 2);

        $cashAt = function (string $date) use ($cashIds) {
            $t = $this->accounting->totals(null, $date);
            $sum = 0.0;
            foreach ($cashIds as $id) {
                $sum += ($t[$id]['debit'] ?? 0) - ($t[$id]['credit'] ?? 0);
            }

            return round($sum, 2);
        };

        $opening = $cashAt(Carbon::parse($from)->subDay()->toDateString());
        $closing = $cashAt($to);

        return [
            'sections' => $sections, 'totals' => $totals, 'net' => $net,
            'opening' => $opening, 'closing' => $closing,
            'agrees' => abs($opening + $net - $closing) < 0.005,
        ];
    }

    // ------------------------------------------------------------------
    // Statement of changes in equity
    // ------------------------------------------------------------------

    public function equityChanges(string $from, string $to): array
    {
        $before = Carbon::parse($from)->subDay()->toDateString();
        $atStart = $this->accounting->totals(null, $before);
        $inPeriod = $this->accounting->totals($from, $to);

        $rows = [];
        foreach (Account::where('type', 'equity')->orderBy('code')->get() as $account) {
            $opening = $account->balanceOf($atStart[$account->id]['debit'] ?? 0, $atStart[$account->id]['credit'] ?? 0);
            $added = round($inPeriod[$account->id]['credit'] ?? 0, 2);
            $reduced = round($inPeriod[$account->id]['debit'] ?? 0, 2);
            if (abs($opening) < 0.005 && $added == 0 && $reduced == 0) {
                continue;
            }
            $rows[] = ['label' => $account->name, 'opening' => $opening, 'added' => $added, 'reduced' => $reduced, 'closing' => round($opening + $added - $reduced, 2)];
        }

        // Surplus that has not been moved into an equity account yet
        $surplusAt = function (string $date) {
            $t = $this->accounting->totals(null, $date);
            $net = 0.0;
            foreach (Account::whereIn('type', ['income', 'expense'])->get() as $a) {
                $amount = $a->balanceOf($t[$a->id]['debit'] ?? 0, $t[$a->id]['credit'] ?? 0);
                $net += $a->type === 'income' ? $amount : -$amount;
            }

            return round($net, 2);
        };
        $surplusOpening = $surplusAt($before);
        $surplusClosing = $surplusAt($to);
        $periodNet = $this->accounting->incomeStatement($from, $to)['net'];
        $closedOut = round($surplusClosing - $surplusOpening - $periodNet, 2);   // moved to retained surplus by year-end closing

        $surplus = [
            'label' => 'Surplus not yet closed to equity',
            'opening' => $surplusOpening,
            'net' => $periodNet,
            'closed' => $closedOut,
            'closing' => $surplusClosing,
        ];

        $openingTotal = round(collect($rows)->sum('opening') + $surplusOpening, 2);
        $closingTotal = round(collect($rows)->sum('closing') + $surplusClosing, 2);

        return [
            'rows' => $rows, 'surplus' => $surplus,
            'opening_total' => $openingTotal, 'closing_total' => $closingTotal,
            'agrees' => abs($closingTotal - $this->accounting->balanceSheet($to)['total_equity']) < 0.005,
        ];
    }

    // ------------------------------------------------------------------
    // Trend and ratios
    // ------------------------------------------------------------------

    /** Income, expenses and net for each of the last $months months, oldest first. */
    public function monthlyTrend(int $months = 12, ?Carbon $end = null): array
    {
        $end = ($end ?? now())->copy()->startOfMonth();
        $trend = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $start = $end->copy()->subMonths($i);
            $is = $this->accounting->incomeStatement($start->toDateString(), $start->copy()->endOfMonth()->toDateString());
            $trend[] = ['month' => $start->format('M Y'), 'short' => $start->format('M'), 'income' => $is['total_income'], 'expenses' => $is['total_expenses'], 'net' => $is['net']];
        }

        return $trend;
    }

    /** Key figures for management. Each: label, value, kind (percent|ratio|amount), note. */
    public function ratios(?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $bs = $this->accounting->balanceSheet($asOf);
        $is = $this->accounting->incomeStatement(Carbon::parse($asOf)->startOfYear()->toDateString(), $asOf);
        $aging = $this->portfolio->aging();

        $line = function (string $code) use ($bs) {
            foreach (array_merge($bs['assets'], $bs['liabilities'], $bs['equity']) as $l) {
                if ($l['account']->code === $code) {
                    return $l['amount'];
                }
            }

            return 0.0;
        };
        $div = fn ($a, $b) => abs($b) < 0.005 ? null : round($a / $b * 100, 1);

        $cash = round($line('1010') + $line('1011'), 2);
        $loans = $line('1020');
        $deposits = $line('2010');

        return [
            ['group' => 'Earnings', 'label' => 'Net surplus margin', 'value' => $div($is['net'], $is['total_income']), 'kind' => 'percent', 'note' => 'Net surplus this year as a share of income.'],
            ['group' => 'Earnings', 'label' => 'Cost-to-income ratio', 'value' => $div($is['total_expenses'], $is['total_income']), 'kind' => 'percent', 'note' => 'Expenses for every 100 of income. Lower is better.'],
            ['group' => 'Earnings', 'label' => 'Return on assets', 'value' => $div($is['net'], $bs['total_assets']), 'kind' => 'percent', 'note' => 'Net surplus this year as a share of total assets (not annualized).'],
            ['group' => 'Earnings', 'label' => 'Interest income on loans', 'value' => $div($this->interestIncome($is), $loans), 'kind' => 'percent', 'note' => 'Interest income this year as a share of loans receivable (not annualized).'],
            ['group' => 'Capital', 'label' => 'Capital-to-assets', 'value' => $div($bs['total_equity'], $bs['total_assets']), 'kind' => 'percent', 'note' => 'Equity as a share of total assets. Higher means a stronger cushion.'],
            ['group' => 'Capital', 'label' => 'Total equity', 'value' => $bs['total_equity'], 'kind' => 'amount', 'note' => 'Share capital, reserves and surplus.'],
            ['group' => 'Liquidity and funding', 'label' => 'Loans-to-deposits', 'value' => $div($loans, $deposits), 'kind' => 'percent', 'note' => 'Loans receivable for every 100 of member deposits.'],
            ['group' => 'Liquidity and funding', 'label' => 'Liquidity (cash to deposits)', 'value' => $div($cash, $deposits), 'kind' => 'percent', 'note' => 'Cash and bank balances for every 100 of member deposits.'],
            ['group' => 'Liquidity and funding', 'label' => 'Loans-to-assets', 'value' => $div($loans, $bs['total_assets']), 'kind' => 'percent', 'note' => 'How much of the assets is lent out.'],
            ['group' => 'Loan quality', 'label' => 'Portfolio at risk, over 30 days', 'value' => $aging['par'][30], 'kind' => 'percent', 'note' => 'Share of the loan book that is more than 30 days past due.'],
            ['group' => 'Loan quality', 'label' => 'Portfolio at risk, over 90 days', 'value' => $aging['par'][90], 'kind' => 'percent', 'note' => 'Share of the loan book that is more than 90 days past due.'],
            ['group' => 'Loan quality', 'label' => 'Suggested loan loss allowance', 'value' => $aging['total_allowance'], 'kind' => 'amount', 'note' => 'From the aging report. Not booked automatically.'],
        ];
    }

    private function interestIncome(array $is): float
    {
        foreach ($is['income'] as $l) {
            if ($l['account']->code === '4010') {
                return $l['amount'];
            }
        }

        return 0.0;
    }

    // ------------------------------------------------------------------
    // General journal and cash book
    // ------------------------------------------------------------------

    public function generalJournal(string $from, string $to, ?string $source = null): array
    {
        $entries = JournalEntry::with(['lines.account', 'user'])
            ->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to)
            ->when(in_array($source, ['manual', 'system', 'closing']), fn ($q) => $q->where('source', $source))
            ->orderBy('entry_date')->orderBy('id')->get();

        return [
            'entries' => $entries,
            'debits' => round($entries->sum(fn ($e) => $e->lines->sum('debit')), 2),
            'credits' => round($entries->sum(fn ($e) => $e->lines->sum('credit')), 2),
        ];
    }

    /** Every movement in the cash and bank accounts, with a running balance. */
    public function cashBook(string $from, string $to): array
    {
        $cashIds = $this->cashAccountIds();
        $before = $this->accounting->totals(null, Carbon::parse($from)->subDay()->toDateString());
        $opening = 0.0;
        foreach ($cashIds as $id) {
            $opening += ($before[$id]['debit'] ?? 0) - ($before[$id]['credit'] ?? 0);
        }
        $opening = round($opening, 2);

        $lines = JournalLine::with(['entry', 'account'])->whereIn('account_id', $cashIds)
            ->whereHas('entry', fn ($q) => $q->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to))
            ->get()->sortBy(fn ($l) => [$l->entry->entry_date->toDateString(), $l->entry->id, $l->id])->values();

        $running = $opening;
        $rows = $lines->map(function ($line) use (&$running) {
            $running = round($running + (float) $line->debit - (float) $line->credit, 2);

            return ['line' => $line, 'balance' => $running];
        });

        return [
            'opening' => $opening, 'rows' => $rows, 'closing' => $running,
            'receipts' => round($lines->sum('debit'), 2), 'payments' => round($lines->sum('credit'), 2),
        ];
    }
}
