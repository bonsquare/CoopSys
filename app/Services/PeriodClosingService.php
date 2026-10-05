<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\JournalEntry;
use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Year-end closing. Closing a year moves its surplus (or deficit) into Retained Surplus with one
 * closing entry dated the last day of the year, and locks the books up to that date so nothing
 * can be posted into the year afterwards. Reopening reverses the closing entry and lifts the lock.
 */
class PeriodClosingService
{
    private const RETAINED = '3020';

    public function __construct(private AccountingService $accounting) {}

    /** One row per fiscal year that has entries: year, net surplus, whether it is closed, and whether it can be closed. */
    public function years(): array
    {
        $first = JournalEntry::min('entry_date');
        if (! $first) {
            return [];
        }

        $rows = [];
        $previousOpen = false;
        foreach (range(Carbon::parse($first)->year, now()->year) as $year) {
            $closing = $this->closingEntry($year);
            $net = $this->accounting->incomeStatement("$year-01-01", "$year-12-31")['net'];
            $ended = Carbon::create($year, 12, 31)->endOfDay()->lt(now());

            $rows[] = [
                'year' => $year,
                'net' => $net,
                'closed' => $closing !== null,
                'closing' => $closing,
                // a year can be closed once it has ended, and only after the years before it
                'can_close' => $closing === null && $ended && ! $previousOpen,
            ];
            $previousOpen = $previousOpen || ($closing === null && $ended);
        }

        return $rows;
    }

    public function closingEntry(int $year): ?JournalEntry
    {
        return JournalEntry::where('source', 'closing')->whereNull('reversal_of')->where('status', 'posted')
            ->whereYear('entry_date', $year)->latest('id')->first();
    }

    /** What closing the year would do: each income and expense account to clear, and the surplus. */
    public function preview(int $year): array
    {
        $totals = $this->accounting->totals("$year-01-01", "$year-12-31", true);
        $lines = [];
        $net = 0.0;

        foreach (Account::whereIn('type', ['income', 'expense'])->orderBy('code')->get() as $account) {
            $debit = $totals[$account->id]['debit'] ?? 0;
            $credit = $totals[$account->id]['credit'] ?? 0;
            $balance = round($debit - $credit, 2);   // a debit balance (positive) is cleared with a credit
            if (abs($balance) < 0.005) {
                continue;
            }
            $lines[] = ['account' => $account, 'debit' => $balance < 0 ? -$balance : 0.0, 'credit' => $balance > 0 ? $balance : 0.0];
            $net -= $balance;   // income (credit balances) add to the surplus, expenses take away
        }

        return ['year' => $year, 'lines' => $lines, 'net' => round($net, 2)];
    }

    public function close(int $year): JournalEntry
    {
        $years = collect($this->years())->keyBy('year');
        if (! $years->has($year) || ! $years[$year]['can_close']) {
            $this->fail('year', 'This year cannot be closed yet. A year can be closed once it has ended, and after the earlier years are closed.');
        }

        $preview = $this->preview($year);

        return DB::transaction(function () use ($year, $preview) {
            $entry = JournalEntry::create([
                'entry_no' => $this->nextNumber("CL-$year"),
                'entry_date' => "$year-12-31",
                'reference' => "Year-end closing $year",
                'description' => "Closing entries for $year: net ".($preview['net'] >= 0 ? 'surplus' : 'deficit').' moved to Retained Surplus',
                'source' => 'closing',
                'status' => 'posted',
                'user_id' => auth()->id(),
            ]);

            foreach ($preview['lines'] as $line) {
                $entry->lines()->create(['account_id' => $line['account']->id, 'debit' => $line['debit'], 'credit' => $line['credit'], 'memo' => 'Closed to Retained Surplus']);
            }
            if (abs($preview['net']) >= 0.005) {
                $entry->lines()->create([
                    'account_id' => Account::byCode(self::RETAINED)->id,
                    'debit' => $preview['net'] < 0 ? -$preview['net'] : 0,
                    'credit' => $preview['net'] > 0 ? $preview['net'] : 0,
                    'memo' => "Net result for $year",
                ]);
            }

            // Nothing can be posted into the closed year any more
            $lock = $this->accounting->lockedUntil();
            $end = Carbon::create($year, 12, 31)->startOfDay();
            if (! $lock || $lock->lt($end)) {
                Setting::put('books_locked_until', $end->toDateString());
            }

            AuditLog::record('period.closed', "Year $year closed, net ".number_format($preview['net'], 2));

            return $entry->load('lines');
        });
    }

    /** Reopen the most recently closed year. */
    public function reopen(int $year): JournalEntry
    {
        $entry = $this->closingEntry($year);
        if (! $entry) {
            $this->fail('year', "$year is not closed.");
        }
        $later = collect($this->years())->where('year', '>', $year)->where('closed', true)->isNotEmpty();
        if ($later) {
            $this->fail('year', 'A later year is closed. Reopen the latest closed year first.');
        }

        return DB::transaction(function () use ($entry, $year) {
            // Lift the lock to the end of the year before, so the reversal can be dated inside the year
            $previous = Carbon::create($year - 1, 12, 31)->toDateString();
            $stillClosed = $this->closingEntry($year - 1);
            Setting::put('books_locked_until', $stillClosed ? $previous : '');

            $reversal = JournalEntry::create([
                'entry_no' => $this->nextNumber($entry->entry_no.'-R'),
                'entry_date' => "$year-12-31",
                'reference' => "Reopen $year",
                'description' => "Reversal of the closing entries for $year",
                'source' => 'closing',
                'status' => 'posted',
                'reversal_of' => $entry->id,
                'user_id' => auth()->id(),
            ]);
            foreach ($entry->lines as $line) {
                $reversal->lines()->create(['account_id' => $line->account_id, 'debit' => $line->credit, 'credit' => $line->debit, 'memo' => $line->memo]);
            }
            $entry->update(['status' => 'reversed']);

            AuditLog::record('period.reopened', "Year $year reopened");

            return $reversal;
        });
    }

    /** A number that has not been used yet (a year closed, reopened and closed again gets CL-2026-2). */
    private function nextNumber(string $base): string
    {
        $number = $base;
        $n = 2;
        while (JournalEntry::where('entry_no', $number)->exists()) {
            $number = $base.'-'.$n++;
        }

        return $number;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
