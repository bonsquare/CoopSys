<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\AccountingService;
use App\Support\Tenant;
use Illuminate\Http\Request;

class JournalController extends Controller
{
    public function __construct(private AccountingService $accounting) {}

    public function index(Request $request)
    {
        $filters = $request->only(['q', 'source', 'from', 'to']);

        $entries = JournalEntry::with('user')->withSum('lines', 'debit')
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('description', 'like', "%$term%")->orWhere('reference', 'like', "%$term%")->orWhere('entry_no', 'like', "%$term%")))
            ->when(in_array($filters['source'] ?? '', ['system', 'manual']), fn ($q) => $q->where('source', $filters['source']))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('entry_date', '<=', $d))
            ->latest('entry_date')->latest('id')->paginate(20)->withQueryString();

        return view('accounting.journal.index', ['entries' => $entries, 'filters' => $filters]);
    }

    public function create()
    {
        return view('accounting.journal.form', ['accounts' => Account::options()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'entry_date' => 'required|date|before_or_equal:today',
            'reference' => 'nullable|string|max:60',
            'description' => 'required|string|max:255',
            'lines' => 'required|array|min:2|max:40',
            'lines.*.account_id' => ['required', Tenant::exists('accounts')],
            'lines.*.debit' => 'nullable|numeric|min:0|max:1000000000',
            'lines.*.credit' => 'nullable|numeric|min:0|max:1000000000',
            'lines.*.memo' => 'nullable|string|max:150',
        ], [
            'lines.required' => 'Add at least two lines.',
            'lines.min' => 'A journal entry needs at least two lines.',
        ]);

        $entry = $this->accounting->postManual($data);

        return redirect()->route('accounting.journal.show', $entry)->with('status', "Journal entry {$entry->entry_no} posted.");
    }

    public function show(JournalEntry $entry)
    {
        $entry->load(['lines.account', 'transaction.collection', 'user', 'original', 'reversal']);

        return view('accounting.journal.show', ['entry' => $entry]);
    }

    public function reverse(Request $request, JournalEntry $entry)
    {
        $data = $request->validate(['reason' => 'required|string|max:150']);
        $reversal = $this->accounting->reverse($entry, $data['reason']);

        return redirect()->route('accounting.journal.show', $reversal)->with('status', "{$entry->entry_no} was reversed by {$reversal->entry_no}.");
    }
}
