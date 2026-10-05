<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Loan;
use App\Models\Setting;
use App\Models\Transaction;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The books: trial balance, income statement, balance sheet, general ledger,
 * manual journal entries and the checks that the ledger agrees with the member records.
 *
 * The books are kept on a cash basis: interest and fees are recorded as income when they are
 * collected (or deducted at release), not when they fall due.
 */
class AccountingService
{
    // ------------------------------------------------------------------
    // Balances
    // ------------------------------------------------------------------

    /**
     * Total debits and credits per account for entries dated $from (optional) to $to.
     * Year-end closing entries can be left out ($excludeClosing), as the income statement does.
     * Returns [account_id => ['debit' => x, 'credit' => y]].
     */
    public function totals(?string $from, string $to, bool $excludeClosing = false): array
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->whereDate('journal_entries.entry_date', '<=', $to)
            ->when(Tenant::id(), fn ($q, $id) => $q->where('journal_entries.organization_id', $id))
            ->when($from, fn ($q) => $q->whereDate('journal_entries.entry_date', '>=', $from))
            ->when($excludeClosing, fn ($q) => $q->where('journal_entries.source', '!=', 'closing'))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id as id, sum(journal_lines.debit) as d, sum(journal_lines.credit) as c')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[$row->id] = ['debit' => round((float) $row->d, 2), 'credit' => round((float) $row->c, 2)];
        }

        return $totals;
    }

    // ------------------------------------------------------------------
    // Closed periods
    // ------------------------------------------------------------------

    /** Nothing can be posted on or before this date (set when a year is closed). */
    public function lockedUntil(): ?Carbon
    {
        $value = Setting::get('books_locked_until');

        return $value ? Carbon::parse($value)->startOfDay() : null;
    }

    /** Refuse a posting that falls in a closed period. */
    public function assertOpen($date): void
    {
        $lock = $this->lockedUntil();
        if ($lock && Carbon::parse($date)->startOfDay()->lte($lock)) {
            $this->fail('entry_date', 'The books are closed up to '.$lock->format('M d, Y').'. Choose a later date, or ask for the year to be reopened.');
        }
    }

    // ------------------------------------------------------------------
    // Trial balance
    // ------------------------------------------------------------------

    public function trialBalance(string $asOf, bool $hideZero = false): array
    {
        $totals = $this->totals(null, $asOf);
        $rows = [];

        foreach (Account::orderBy('code')->get() as $account) {
            $debit = $totals[$account->id]['debit'] ?? 0.0;
            $credit = $totals[$account->id]['credit'] ?? 0.0;
            $net = round($debit - $credit, 2);

            if ($hideZero && abs($net) < 0.005 && $debit == 0 && $credit == 0) {
                continue;
            }

            $rows[] = [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'tb_debit' => $net > 0 ? $net : 0.0,
                'tb_credit' => $net < 0 ? -$net : 0.0,
            ];
        }

        $rows = collect($rows);
        $debit = round($rows->sum('tb_debit'), 2);
        $credit = round($rows->sum('tb_credit'), 2);

        return ['rows' => $rows, 'total_debit' => $debit, 'total_credit' => $credit, 'balanced' => abs($debit - $credit) < 0.005];
    }

    // ------------------------------------------------------------------
    // Income statement
    // ------------------------------------------------------------------

    public function incomeStatement(string $from, string $to): array
    {
        $totals = $this->totals($from, $to, true);
        $income = [];
        $expenses = [];

        foreach (Account::whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $account) {
            $amount = $account->balanceOf($totals[$account->id]['debit'] ?? 0, $totals[$account->id]['credit'] ?? 0);
            if (abs($amount) < 0.005 && ! isset($totals[$account->id])) {
                continue;
            }
            $line = ['account' => $account, 'amount' => $amount];
            $account->type === 'income' ? $income[] = $line : $expenses[] = $line;
        }

        $totalIncome = round(collect($income)->sum('amount'), 2);
        $totalExpenses = round(collect($expenses)->sum('amount'), 2);

        return [
            'income' => $income,
            'expenses' => $expenses,
            'total_income' => $totalIncome,
            'total_expenses' => $totalExpenses,
            'net' => round($totalIncome - $totalExpenses, 2),
        ];
    }

    // ------------------------------------------------------------------
    // Balance sheet
    // ------------------------------------------------------------------

    /**
     * Assets = Liabilities + Equity. Equity includes the surplus that has not been closed into an
     * equity account: earlier years' surplus and the current year's surplus so far.
     */
    public function balanceSheet(string $asOf): array
    {
        $yearStart = Carbon::parse($asOf)->startOfYear()->toDateString();
        $totals = $this->totals(null, $asOf);

        $sections = ['asset' => [], 'liability' => [], 'equity' => []];
        foreach (Account::whereIn('type', array_keys($sections))->orderBy('code')->get() as $account) {
            $amount = $account->balanceOf($totals[$account->id]['debit'] ?? 0, $totals[$account->id]['credit'] ?? 0);
            if (abs($amount) < 0.005 && ! isset($totals[$account->id])) {
                continue;   // never used
            }
            $sections[$account->type][] = ['account' => $account, 'amount' => $amount];
        }

        // Surplus of earlier years (everything before this year) and of this year so far
        $before = $this->totals(null, Carbon::parse($yearStart)->subDay()->toDateString());
        $thisYear = $this->totals($yearStart, $asOf);
        $surplus = function (array $set) {
            $net = 0.0;
            foreach (Account::whereIn('type', ['income', 'expense'])->get() as $a) {
                $amount = $a->balanceOf($set[$a->id]['debit'] ?? 0, $set[$a->id]['credit'] ?? 0);
                $net += $a->type === 'income' ? $amount : -$amount;
            }

            return round($net, 2);
        };
        $prior = $surplus($before);
        $current = $surplus($thisYear);

        $assets = round(collect($sections['asset'])->sum('amount'), 2);
        $liabilities = round(collect($sections['liability'])->sum('amount'), 2);
        $equityAccounts = round(collect($sections['equity'])->sum('amount'), 2);
        $equity = round($equityAccounts + $prior + $current, 2);

        return [
            'assets' => $sections['asset'],
            'liabilities' => $sections['liability'],
            'equity' => $sections['equity'],
            'prior_surplus' => $prior,
            'current_surplus' => $current,
            'total_assets' => $assets,
            'total_liabilities' => $liabilities,
            'total_equity' => $equity,
            'total_liabilities_equity' => round($liabilities + $equity, 2),
            'balanced' => abs($assets - ($liabilities + $equity)) < 0.005,
            'year_start' => $yearStart,
        ];
    }

    // ------------------------------------------------------------------
    // General ledger
    // ------------------------------------------------------------------

    /** One account's history with a running balance in the account's own direction. */
    public function ledger(Account $account, string $from, string $to): array
    {
        $before = DB::table('journal_lines')->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_lines.account_id', $account->id)->whereDate('journal_entries.entry_date', '<', $from)
            ->when(Tenant::id(), fn ($q, $id) => $q->where('journal_entries.organization_id', $id))
            ->selectRaw('sum(journal_lines.debit) as d, sum(journal_lines.credit) as c')->first();
        $opening = $account->balanceOf((float) ($before->d ?? 0), (float) ($before->c ?? 0));

        $lines = JournalLine::with('entry')->where('account_id', $account->id)
            ->whereHas('entry', fn ($q) => $q->whereDate('entry_date', '>=', $from)->whereDate('entry_date', '<=', $to))
            ->get()->sortBy(fn ($l) => [$l->entry->entry_date->toDateString(), $l->entry->id, $l->id])->values();

        $running = $opening;
        $rows = $lines->map(function ($line) use ($account, &$running) {
            $running = round($running + $account->balanceOf((float) $line->debit, (float) $line->credit), 2);

            return ['line' => $line, 'balance' => $running];
        });

        return [
            'opening' => $opening,
            'rows' => $rows,
            'debits' => round($lines->sum('debit'), 2),
            'credits' => round($lines->sum('credit'), 2),
            'closing' => $running,
        ];
    }

    // ------------------------------------------------------------------
    // Manual journal entries
    // ------------------------------------------------------------------

    /**
     * Post a manual journal entry. $data: entry_date, reference?, description, lines[] (account_id, debit, credit, memo?).
     * The entry must balance. Once posted it is never edited: it can only be reversed.
     */
    public function postManual(array $data): JournalEntry
    {
        $lines = collect($data['lines'] ?? [])->map(fn ($l) => [
            'account_id' => (int) $l['account_id'],
            'debit' => round((float) ($l['debit'] ?? 0), 2),
            'credit' => round((float) ($l['credit'] ?? 0), 2),
            'memo' => $l['memo'] ?? null,
        ])->filter(fn ($l) => $l['debit'] > 0 || $l['credit'] > 0)->values();

        if ($lines->count() < 2) {
            $this->fail('lines', 'A journal entry needs at least two lines with an amount.');
        }
        foreach ($lines as $line) {
            if ($line['debit'] > 0 && $line['credit'] > 0) {
                $this->fail('lines', 'A line cannot have both a debit and a credit. Use two lines.');
            }
        }

        $inactive = Account::whereIn('id', $lines->pluck('account_id'))->where('is_active', false)->pluck('name');
        if ($inactive->isNotEmpty()) {
            $this->fail('lines', 'These accounts are inactive: '.$inactive->implode(', ').'.');
        }

        $debits = round($lines->sum('debit'), 2);
        $credits = round($lines->sum('credit'), 2);
        if (abs($debits - $credits) > 0.004) {
            $this->fail('lines', 'The entry does not balance: debits are '.number_format($debits, 2).' and credits are '.number_format($credits, 2).'.');
        }

        $this->assertOpen($data['entry_date']);

        return DB::transaction(function () use ($data, $lines) {
            $entry = JournalEntry::create([
                'entry_no' => JournalEntry::nextNumber(),
                'entry_date' => $data['entry_date'],
                'reference' => trim((string) ($data['reference'] ?? '')) ?: 'Manual entry',
                'description' => $data['description'],
                'source' => 'manual',
                'status' => 'posted',
                'user_id' => auth()->id(),
            ]);

            foreach ($lines as $line) {
                $entry->lines()->create($line);
            }

            AuditLog::record('journal.posted', "{$entry->entry_no}: {$entry->description}");

            return $entry->load('lines');
        });
    }

    /** Reverse a manual entry with a new entry that swaps every debit and credit. */
    public function reverse(JournalEntry $entry, string $reason): JournalEntry
    {
        if (! $entry->isManual()) {
            $this->fail('entry', 'Only manual entries can be reversed here. To undo a transaction, cancel it from its own screen.');
        }
        if ($entry->status !== 'posted' || $entry->reversal_of) {
            $this->fail('entry', 'This entry cannot be reversed again.');
        }

        $this->assertOpen(now());

        return DB::transaction(function () use ($entry, $reason) {
            $reversal = JournalEntry::create([
                'entry_no' => JournalEntry::nextNumber(),
                'entry_date' => now()->toDateString(),
                'reference' => $entry->entry_no,
                'description' => "Reversal of {$entry->entry_no}: $reason",
                'source' => 'manual',
                'status' => 'posted',
                'reversal_of' => $entry->id,
                'user_id' => auth()->id(),
            ]);

            foreach ($entry->lines as $line) {
                $reversal->lines()->create([
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'memo' => $line->memo,
                ]);
            }

            $entry->update(['status' => 'reversed']);
            AuditLog::record('journal.reversed', "{$entry->entry_no} reversed by {$reversal->entry_no}: $reason");

            return $reversal;
        });
    }

    // ------------------------------------------------------------------
    // Checks: does the ledger agree with the member records?
    // ------------------------------------------------------------------

    /** Each check: label, ledger amount, record amount, ok. */
    public function checks(?string $asOf = null): array
    {
        $asOf ??= now()->toDateString();
        $totals = $this->totals(null, $asOf);
        $bal = function (string $code) use ($totals) {
            $account = Account::where('code', $code)->first();

            return $account ? $account->balanceOf($totals[$account->id]['debit'] ?? 0, $totals[$account->id]['credit'] ?? 0) : 0.0;
        };

        $tb = $this->trialBalance($asOf);
        $bs = $this->balanceSheet($asOf);

        $sum = fn (string $type) => (float) Transaction::completed()->where('type', $type)->whereDate('posted_at', '<=', $asOf)->sum('amount');
        $cashRecords = round(
            (float) Transaction::completed()->where('direction', 'in')->whereDate('posted_at', '<=', $asOf)->sum('amount')
            - (float) Transaction::completed()->where('direction', 'out')->whereDate('posted_at', '<=', $asOf)->sum('amount'), 2);
        // Manual entries to the cash accounts (opening balances, bank charges, transfers) are legitimate
        // and have no cash transaction behind them, so they are part of what the cash accounts should hold.
        $manualCash = (float) DB::table('journal_lines')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.source', 'manual')
            ->when(Tenant::id(), fn ($q, $id) => $q->where('journal_entries.organization_id', $id))
            ->whereIn('journal_lines.account_id', Account::whereIn('code', ['1010', '1011'])->pluck('id'))
            ->whereDate('journal_entries.entry_date', '<=', $asOf)
            ->selectRaw('coalesce(sum(journal_lines.debit) - sum(journal_lines.credit), 0) as net')->value('net');
        $cashRecords = round($cashRecords + $manualCash, 2);

        $loansOutstanding = round((float) Loan::where('status', 'released')->selectRaw('sum(principal - principal_paid + fees_added - fees_paid) as t')->value('t'), 2);

        $checks = [
            ['Debits equal credits (trial balance)', $tb['total_debit'], $tb['total_credit'], $tb['balanced']],
            ['Assets equal liabilities plus equity (balance sheet)', $bs['total_assets'], $bs['total_liabilities_equity'], $bs['balanced']],
            ['Cash on hand and in bank agree with cash transactions and manual cash entries', round($bal('1010') + $bal('1011'), 2), $cashRecords, null],
            ['Savings deposits agree with members\' savings', $bal('2010'), round($sum('savings_deposit') - $sum('savings_withdrawal'), 2), null],
            ['Share capital agrees with members\' paid-up shares', $bal('3010'), round($sum('share_deposit') - $sum('share_withdrawal'), 2), null],
            ['Loans receivable agree with the loan accounts', $bal('1020'), $loansOutstanding, null],
        ];

        return array_map(fn ($c) => [
            'label' => $c[0], 'ledger' => $c[1], 'records' => $c[2],
            'ok' => $c[3] ?? abs($c[1] - $c[2]) < 0.005,
        ], $checks);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
