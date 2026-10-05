<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\StreamsCsv;
use App\Services\FinancialReportService;
use App\Services\PortfolioReportService;
use Illuminate\Http\Request;

/** The management reports: cash flow, equity, loan aging, schedules, journal, cash book and highlights. */
class AccountingReportController extends Controller
{
    use StreamsCsv;

    public function __construct(private FinancialReportService $reports, private PortfolioReportService $portfolio) {}

    public function hub()
    {
        return view('accounting.reports.hub');
    }

    public function cashFlow(Request $request)
    {
        [$from, $to] = $this->period($request);
        $cf = $this->reports->cashFlow($from, $to);

        if ($request->query('export') === 'csv') {
            $rows = [["Cash Flow Statement $from to $to"], ['Item', 'Amount']];
            foreach (['operating' => 'Operating activities', 'investing' => 'Investing activities', 'financing' => 'Financing activities'] as $key => $title) {
                $rows[] = [strtoupper($title)];
                foreach ($cf['sections'][$key] as $l) {
                    $rows[] = ['  '.$l['account']->label(), $this->num($l['amount'])];
                }
                $rows[] = ["Net cash from $key activities", $this->num($cf['totals'][$key])];
            }
            array_push($rows, ['NET CHANGE IN CASH', $this->num($cf['net'])], ['Cash at the start', $this->num($cf['opening'])], ['Cash at the end', $this->num($cf['closing'])]);

            return $this->csv("cash-flow-$from-to-$to.csv", $rows);
        }

        return view('accounting.reports.cash-flow', ['cf' => $cf, 'from' => $from, 'to' => $to]);
    }

    public function equity(Request $request)
    {
        [$from, $to] = $this->period($request);
        $eq = $this->reports->equityChanges($from, $to);

        if ($request->query('export') === 'csv') {
            $rows = [["Statement of Changes in Equity $from to $to"], ['Item', 'Opening', 'Additions', 'Reductions', 'Closing']];
            foreach ($eq['rows'] as $r) {
                $rows[] = [$r['label'], $this->num($r['opening']), $this->num($r['added']), $this->num($r['reduced']), $this->num($r['closing'])];
            }
            $s = $eq['surplus'];
            $rows[] = [$s['label'], $this->num($s['opening']), 'Net for the period '.$this->num($s['net']), 'Closed to equity '.$this->num($s['closed']), $this->num($s['closing'])];
            $rows[] = ['TOTAL EQUITY', $this->num($eq['opening_total']), '', '', $this->num($eq['closing_total'])];

            return $this->csv("changes-in-equity-$from-to-$to.csv", $rows);
        }

        return view('accounting.reports.equity', ['eq' => $eq, 'from' => $from, 'to' => $to]);
    }

    public function highlights()
    {
        return view('accounting.reports.highlights', [
            'ratios' => collect($this->reports->ratios())->groupBy('group'),
            'trend' => $this->reports->monthlyTrend(12),
            'year' => now()->year,
        ]);
    }

    public function aging(Request $request)
    {
        $aging = $this->portfolio->aging();

        if ($request->query('export') === 'csv') {
            $rows = [['Loan Portfolio Aging as of '.$aging['as_of']], ['Bucket', 'Loans', 'Book balance', 'Overdue amount', 'Allowance %', 'Suggested allowance']];
            foreach ($aging['buckets'] as $b) {
                $rows[] = [$b['label'], $b['count'], $this->num($b['balance']), $this->num($b['overdue']), $b['rate'], $this->num($b['allowance'])];
            }
            $rows[] = ['TOTAL', $aging['total_count'], $this->num($aging['total_balance']), $this->num($aging['total_overdue']), '', $this->num($aging['total_allowance'])];
            $rows[] = [];
            $rows[] = ['Loan', 'Member', 'Product', 'Days past due', 'Bucket', 'Book balance', 'Overdue amount', 'Penalty due'];
            foreach ($aging['loans'] as $d) {
                $rows[] = [$d['loan']->loan_no, $d['loan']->member->full_name, $d['loan']->product?->name, $d['days'], $d['bucket'], $this->num($d['book']), $this->num($d['overdue']), $this->num($d['penalty'])];
            }

            return $this->csv('loan-aging-'.$aging['as_of'].'.csv', $rows);
        }

        return view('accounting.reports.aging', ['aging' => $aging]);
    }

    public function schedules(Request $request)
    {
        $schedules = $this->portfolio->schedules();

        if ($request->query('export') === 'csv') {
            $rows = [['Subsidiary schedules as of '.$schedules['as_of']]];
            foreach (['loans', 'savings', 'shares'] as $key) {
                $s = $schedules[$key];
                $rows[] = [];
                $rows[] = [$s['title']];
                $rows[] = ['Reference', 'Name', 'Detail', 'Amount'];
                foreach ($s['rows'] as $r) {
                    $rows[] = [$r['ref'], $r['name'], $r['extra'], $this->num($r['amount'])];
                }
                $rows[] = ['', 'TOTAL OF THE ACCOUNTS', '', $this->num($s['total'])];
                $rows[] = ['', "LEDGER (account {$s['code']})", '', $this->num($s['control'])];
                $rows[] = ['', 'DIFFERENCE', '', $this->num($s['difference'])];
            }

            return $this->csv('schedules-'.$schedules['as_of'].'.csv', $rows);
        }

        return view('accounting.reports.schedules', ['schedules' => $schedules]);
    }

    public function generalJournal(Request $request)
    {
        [$from, $to] = $this->period($request);
        $source = $request->query('source');
        $journal = $this->reports->generalJournal($from, $to, $source);

        if ($request->query('export') === 'csv') {
            $rows = [["General Journal $from to $to"], ['Date', 'Entry', 'Description', 'Account', 'Memo', 'Debit', 'Credit']];
            foreach ($journal['entries'] as $e) {
                foreach ($e->lines as $i => $l) {
                    $rows[] = [$i === 0 ? $e->entry_date->format('Y-m-d') : '', $i === 0 ? $e->number() : '', $i === 0 ? $e->description : '', $l->account->label(), $l->memo, $this->num($l->debit), $this->num($l->credit)];
                }
            }
            $rows[] = ['', '', '', '', 'TOTAL', $this->num($journal['debits']), $this->num($journal['credits'])];

            return $this->csv("general-journal-$from-to-$to.csv", $rows);
        }

        return view('accounting.reports.general-journal', ['journal' => $journal, 'from' => $from, 'to' => $to, 'source' => $source]);
    }

    public function cashBook(Request $request)
    {
        [$from, $to] = $this->period($request);
        $book = $this->reports->cashBook($from, $to);

        if ($request->query('export') === 'csv') {
            $rows = [["Cash Book $from to $to"], ['Date', 'Entry', 'Particulars', 'Account', 'Receipts', 'Payments', 'Balance']];
            $rows[] = ['', '', 'Opening balance', '', '', '', $this->num($book['opening'])];
            foreach ($book['rows'] as $r) {
                $l = $r['line'];
                $rows[] = [$l->entry->entry_date->format('Y-m-d'), $l->entry->number(), $l->entry->description, $l->account->name, $this->num($l->debit), $this->num($l->credit), $this->num($r['balance'])];
            }
            $rows[] = ['', '', 'TOTAL', '', $this->num($book['receipts']), $this->num($book['payments']), $this->num($book['closing'])];

            return $this->csv("cash-book-$from-to-$to.csv", $rows);
        }

        return view('accounting.reports.cash-book', ['book' => $book, 'from' => $from, 'to' => $to]);
    }

    /** from / to from the request: the current month by default. */
    private function period(Request $request): array
    {
        $input = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        return [$input['from'] ?? now()->startOfMonth()->toDateString(), $input['to'] ?? now()->toDateString()];
    }
}
