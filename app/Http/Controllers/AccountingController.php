<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsCsv;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Services\FinancialReportService;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** The accounting hub, the trial balance, the ledger and the two main statements. */
class AccountingController extends Controller
{
    use StreamsCsv;

    public function __construct(private AccountingService $accounting, private FinancialReportService $reports) {}

    /** Kept for the Reports page. */
    public static function trialBalance(string $asOf): array
    {
        return app(AccountingService::class)->trialBalance($asOf);
    }

    public function index()
    {
        $today = now()->toDateString();

        return view('accounting.index', [
            'checks' => $this->accounting->checks($today),
            'bs' => $this->accounting->balanceSheet($today),
            'is' => $this->accounting->incomeStatement(now()->startOfYear()->toDateString(), $today),
            'recent' => JournalEntry::with('user')->withSum('lines', 'debit')->latest('entry_date')->latest('id')->limit(8)->get(),
            'year' => now()->year,
            'lockedUntil' => $this->accounting->lockedUntil(),
        ]);
    }

    public function trialBalanceReport(Request $request)
    {
        $input = $request->validate(['as_of' => 'nullable|date', 'hide_zero' => 'nullable|boolean']);
        $asOf = $input['as_of'] ?? now()->toDateString();
        $hideZero = (bool) ($input['hide_zero'] ?? false);
        $tb = $this->accounting->trialBalance($asOf, $hideZero);

        if ($request->query('export') === 'csv') {
            $rows = [['Trial Balance as of '.$asOf], ['Code', 'Account', 'Group', 'Type', 'Debit', 'Credit']];
            foreach ($tb['rows'] as $r) {
                $rows[] = [$r['account']->code, $r['account']->name, $r['account']->groupName(), $r['account']->type, $this->num($r['tb_debit']), $this->num($r['tb_credit'])];
            }
            $rows[] = ['', 'TOTAL', '', '', $this->num($tb['total_debit']), $this->num($tb['total_credit'])];

            return $this->csv("trial-balance-$asOf.csv", $rows);
        }

        return view('accounting.trial-balance', ['tb' => $tb, 'asOf' => $asOf, 'hideZero' => $hideZero]);
    }

    public function incomeStatement(Request $request)
    {
        $input = $request->validate([
            'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from',
            'compare' => 'nullable|in:none,previous,lastyear',
        ]);
        $from = $input['from'] ?? now()->startOfYear()->toDateString();
        $to = $input['to'] ?? now()->toDateString();
        $compare = $input['compare'] ?? 'none';

        $range = $this->reports->comparisonRange($from, $to, $compare);
        $is = $this->reports->incomeStatement($from, $to, $range);

        if ($request->query('export') === 'csv') {
            $head = ['Account', "$from to $to"];
            if ($is['compare']) {
                array_push($head, "{$range[0]} to {$range[1]}", 'Change', 'Change %');
            }
            $rows = [["Income Statement $from to $to"], $head];
            foreach (['income' => 'INCOME', 'expense' => 'EXPENSES'] as $key => $title) {
                $rows[] = [$title];
                foreach ($is['sections'][$key] as $group) {
                    $rows[] = [$group['name']];
                    foreach ($group['lines'] as $l) {
                        $rows[] = $this->statementRow('  '.$l['account']->label(), $l, $is['compare']);
                    }
                    $rows[] = $this->statementRow('Total '.$group['name'], ['current' => $group['current'], 'previous' => $group['previous'], 'change' => $group['change'], 'pct' => $group['pct']], $is['compare']);
                }
                $rows[] = $this->statementRow($key === 'income' ? 'Total income' : 'Total expenses',
                    $key === 'income'
                        ? ['current' => $is['total_income'], 'previous' => $is['prev_income'], 'change' => $is['income_change'], 'pct' => $is['income_pct']]
                        : ['current' => $is['total_expenses'], 'previous' => $is['prev_expenses'], 'change' => $is['expense_change'], 'pct' => $is['expense_pct']], $is['compare']);
            }
            $rows[] = $this->statementRow('NET SURPLUS / (DEFICIT)', ['current' => $is['net'], 'previous' => $is['prev_net'], 'change' => $is['net_change'], 'pct' => $is['net_pct']], $is['compare']);

            return $this->csv("income-statement-$from-to-$to.csv", $rows);
        }

        $today = now();
        $presets = [
            'This month' => [$today->copy()->startOfMonth(), $today->copy()->endOfMonth()],
            'Last month' => [$today->copy()->subMonthNoOverflow()->startOfMonth(), $today->copy()->subMonthNoOverflow()->endOfMonth()],
            'This quarter' => [$today->copy()->startOfQuarter(), $today->copy()->endOfQuarter()],
            'Year to date' => [$today->copy()->startOfYear(), $today],
            'Last year' => [$today->copy()->subYear()->startOfYear(), $today->copy()->subYear()->endOfYear()],
        ];

        return view('accounting.income-statement', ['is' => $is, 'from' => $from, 'to' => $to, 'compare' => $compare, 'presets' => $presets]);
    }

    public function balanceSheet(Request $request)
    {
        $input = $request->validate(['as_of' => 'nullable|date', 'compare' => 'nullable|in:none,yearend,lastyear,lastmonth']);
        $asOf = $input['as_of'] ?? now()->toDateString();
        $compare = $input['compare'] ?? 'none';

        $date = Carbon::parse($asOf);
        $compareDate = match ($compare) {
            'yearend' => $date->copy()->startOfYear()->subDay()->toDateString(),
            'lastyear' => $date->copy()->subYear()->toDateString(),
            'lastmonth' => $date->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            default => null,
        };
        $sheet = $this->reports->balanceSheet($asOf, $compareDate);

        if ($request->query('export') === 'csv') {
            $withPrevious = $sheet['compare'];
            $rows = [["Balance Sheet as of $asOf"], $withPrevious ? ['Account', $asOf, $compareDate, 'Change', 'Change %'] : ['Account', $asOf]];
            foreach (['asset' => 'ASSETS', 'liability' => 'LIABILITIES', 'equity' => 'EQUITY'] as $key => $title) {
                $rows[] = [$title];
                foreach ($sheet['sections'][$key] as $group) {
                    $rows[] = [$group['name']];
                    foreach ($group['lines'] as $l) {
                        $rows[] = $this->statementRow('  '.$l['account']->label(), $l, $withPrevious);
                    }
                }
                if ($key === 'equity') {
                    $rows[] = $this->statementRow('Surplus of earlier years (not yet closed)', ['current' => $sheet['current']['prior_surplus'], 'previous' => $sheet['previous']['prior_surplus'] ?? null, 'change' => $sheet['changes']['prior_surplus'], 'pct' => null], $withPrevious);
                    $rows[] = $this->statementRow('Surplus of the current year to date', ['current' => $sheet['current']['current_surplus'], 'previous' => $sheet['previous']['current_surplus'] ?? null, 'change' => $sheet['changes']['current_surplus'], 'pct' => null], $withPrevious);
                }
                $total = ['asset' => 'total_assets', 'liability' => 'total_liabilities', 'equity' => 'total_equity'][$key];
                $rows[] = $this->statementRow('Total '.strtolower($title), ['current' => $sheet['current'][$total], 'previous' => $sheet['previous'][$total] ?? null, 'change' => $sheet['changes'][['asset' => 'assets', 'liability' => 'liabilities', 'equity' => 'equity'][$key]], 'pct' => null], $withPrevious);
            }
            $rows[] = $this->statementRow('TOTAL LIABILITIES AND EQUITY', ['current' => $sheet['current']['total_liabilities_equity'], 'previous' => $sheet['previous']['total_liabilities_equity'] ?? null, 'change' => null, 'pct' => null], $withPrevious);

            return $this->csv("balance-sheet-$asOf.csv", $rows);
        }

        return view('accounting.balance-sheet', ['sheet' => $sheet, 'asOf' => $asOf, 'compare' => $compare, 'compareDate' => $compareDate]);
    }

    public function ledger(Request $request)
    {
        $input = $request->validate(['account' => ['nullable', Tenant::exists('accounts')], 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);
        $account = Account::find($input['account'] ?? null) ?? Account::orderBy('code')->first();
        $from = $input['from'] ?? now()->startOfYear()->toDateString();
        $to = $input['to'] ?? now()->toDateString();
        $ledger = $this->accounting->ledger($account, $from, $to);

        if ($request->query('export') === 'csv') {
            $rows = [["General Ledger: {$account->label()} $from to $to"], ['Date', 'Entry', 'Description', 'Memo', 'Debit', 'Credit', 'Balance']];
            $rows[] = ['', '', 'Opening balance', '', '', '', $this->num($ledger['opening'])];
            foreach ($ledger['rows'] as $r) {
                $l = $r['line'];
                $rows[] = [$l->entry->entry_date->format('Y-m-d'), $l->entry->number(), $l->entry->description, $l->memo, $this->num($l->debit), $this->num($l->credit), $this->num($r['balance'])];
            }

            return $this->csv("ledger-{$account->code}.csv", $rows);
        }

        return view('accounting.ledger', ['account' => $account, 'accounts' => Account::options(false), 'ledger' => $ledger, 'from' => $from, 'to' => $to]);
    }

    /** One statement line as a CSV row: label, amount, and (when comparing) previous, change and %. */
    private function statementRow(string $label, array $values, bool $compare): array
    {
        $row = [$label, $this->num($values['current'])];
        if ($compare) {
            $row[] = $values['previous'] === null ? '' : $this->num($values['previous']);
            $row[] = ($values['change'] ?? null) === null ? '' : $this->num($values['change']);
            $row[] = ($values['pct'] ?? null) === null ? '' : $values['pct'];
        }

        return $row;
    }
}
