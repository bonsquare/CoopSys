<?php

namespace App\Http\Controllers;

use App\Services\AccountingService;
use App\Services\PeriodClosingService;
use Illuminate\Http\Request;

class ClosingController extends Controller
{
    public function __construct(private PeriodClosingService $closing, private AccountingService $accounting) {}

    public function index(Request $request)
    {
        $years = $this->closing->years();
        $preview = $request->filled('preview') ? $this->closing->preview((int) $request->query('preview')) : null;

        return view('accounting.closing', [
            'years' => $years,
            'preview' => $preview,
            'lockedUntil' => $this->accounting->lockedUntil(),
            'latestClosed' => collect($years)->where('closed', true)->last(),
        ]);
    }

    public function close(int $year)
    {
        $entry = $this->closing->close($year);

        return redirect()->route('accounting.closing')->with('status', "Year $year closed with entry {$entry->entry_no}. The books are locked up to Dec 31, $year.");
    }

    public function reopen(int $year)
    {
        $this->closing->reopen($year);

        return redirect()->route('accounting.closing')->with('status', "Year $year reopened.");
    }
}
