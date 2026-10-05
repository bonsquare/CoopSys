<?php

namespace App\Http\Controllers;

use App\Models\Collection;
use App\Models\Member;
use App\Models\User;
use App\Services\CollectionService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CollectionController extends Controller
{
    public function __construct(private CollectionService $service) {}

    /** The register of receipts. */
    public function index(Request $request)
    {
        $filters = $request->only(['q', 'status', 'type', 'from', 'to', 'by']);

        $collections = Collection::with(['member', 'collector'])
            ->when($filters['q'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w->where('receipt_no', 'like', "%$term%")
                ->orWhereHas('member', fn ($m) => $m->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")->orWhere('member_no', 'like', "%$term%"))))
            ->when(array_key_exists($filters['status'] ?? '', config('collections.statuses')), fn ($q) => $q->where('status', $filters['status']))
            ->when(array_key_exists($filters['type'] ?? '', config('collections.types')), fn ($q) => $q->where('collection_type', $filters['type']))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('collection_date', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('collection_date', '<=', $d))
            ->when($filters['by'] ?? null, fn ($q, $id) => $q->where('collected_by', $id))
            ->latest('id')->paginate(20)->withQueryString();

        return view('collections.index', [
            'collections' => $collections,
            'filters' => $filters,
            'users' => User::orderBy('name')->pluck('name', 'id')->all(),
        ]);
    }

    /** The teller workspace: a new receipt, or an existing one (a draft can be edited, the rest are read-only). */
    public function create(Request $request)
    {
        $collection = $request->filled('draft') ? Collection::with(['lines', 'member'])->findOrFail($request->query('draft')) : null;

        if ($collection && ! $collection->isDraft()) {
            return redirect()->route('collections.show', $collection);
        }

        return $this->workspace($collection);
    }

    /** A posted or cancelled receipt, shown in the same workspace but read-only. */
    public function show(Collection $collection)
    {
        $collection->load(['lines.loan', 'member', 'collector', 'canceller']);

        return $collection->isDraft() ? redirect()->route('collections.create', ['draft' => $collection->id]) : $this->workspace($collection);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'collection_id' => 'nullable|exists:collections,id',
            'action' => 'required|in:draft,post',
            'member_id' => 'required|exists:members,id',
            'collection_type' => ['required', Rule::in(array_keys(config('collections.types')))],
            'collection_date' => 'required|date|before_or_equal:today',
            'payment_method' => ['required', Rule::in(array_keys(config('coop.payment_methods')))],
            'payment_ref' => 'nullable|string|max:60',
            'receipt_no' => 'nullable|string|max:40',
            'total_amount' => 'required|numeric|min:0|max:1000000000',
            'cash_tendered' => 'nullable|numeric|min:0|max:1000000000',
            'remarks' => 'nullable|string|max:255',
            'lines' => 'required|array|min:1|max:30',
            'lines.*.allocation_type' => ['required', Rule::in(array_keys(config('collections.allocations')))],
            'lines.*.loan_id' => 'nullable|exists:loans,id',
            'lines.*.amount' => 'required|numeric|min:0.01|max:1000000000',
            'lines.*.due_amount' => 'nullable|numeric|min:0',
        ], [
            'lines.required' => 'Add at least one line to the payment breakdown.',
            'lines.*.amount.required' => 'Every breakdown line needs an amount.',
        ]);

        $existing = ! empty($data['collection_id']) ? Collection::findOrFail($data['collection_id']) : null;
        $collection = $this->service->saveDraft($data, $existing);

        if ($data['action'] === 'draft') {
            return redirect()->route('collections.create', ['draft' => $collection->id])->with('status', "Draft {$collection->receipt_no} saved.");
        }

        try {
            $collection = $this->service->post($collection);
        } catch (ValidationException $e) {
            // The draft is kept so nothing the teller typed is lost
            return redirect()->route('collections.create', ['draft' => $collection->id])->withErrors($e->errors());
        }

        return redirect()->route('collections.show', $collection)->with('status', "Receipt {$collection->receipt_no} posted.");
    }

    public function cancel(Request $request, Collection $collection)
    {
        $data = $request->validate(['reason' => 'required|string|max:150']);

        // Reversing a posted receipt needs the reverse permission; a draft can be cancelled by whoever can collect
        $level = $collection->isDraft() ? 'manage' : 'reverse';
        abort_unless($request->user()->canAccess('collections', $level), 403, 'Your role cannot do this.');

        $this->service->cancel($collection, $data['reason']);

        return redirect()->route('collections.show', $collection)->with('status', "Receipt {$collection->receipt_no} cancelled.");
    }

    public function receipt(Collection $collection)
    {
        $collection->load(['lines.loan', 'member', 'collector', 'canceller']);

        return view('collections.receipt', ['c' => $collection]);
    }

    /** Type-ahead search for the member box. */
    public function members(Request $request)
    {
        $term = trim((string) $request->query('q'));

        return Member::query()
            ->when($term !== '', fn ($q) => $q->where(fn ($w) => $w->where('first_name', 'like', "%$term%")->orWhere('last_name', 'like', "%$term%")->orWhere('member_no', 'like', "%$term%")->orWhere('contact_no', 'like', "%$term%")))
            ->orderBy('last_name')->orderBy('first_name')->limit(10)->get()
            ->map(fn ($m) => ['id' => $m->id, 'label' => "{$m->member_no} — {$m->last_name}, {$m->first_name}", 'status' => $m->status]);
    }

    public function overview(Member $member)
    {
        return response()->json($this->service->overview($member));
    }

    /** Suggested payment breakdown for Auto Allocate. */
    public function suggest(Request $request)
    {
        $data = $request->validate([
            'member_id' => 'required|exists:members,id',
            'amount' => 'required|numeric|min:0.01|max:1000000000',
            'type' => ['required', Rule::in(array_keys(config('collections.types')))],
            'loan_id' => 'nullable|exists:loans,id',
        ]);

        return response()->json($this->service->suggest(Member::findOrFail($data['member_id']), (float) $data['amount'], $data['type'], $data['loan_id'] ?? null));
    }

    private function workspace(?Collection $collection)
    {
        $user = auth()->user();

        return view('collections.workspace', [
            'collection' => $collection,
            'readonly' => $collection && ! $collection->isDraft(),
            'nextReceipt' => $collection?->receipt_no ?? Collection::nextReceiptNumber(),
            'canManage' => $user->canAccess('collections', 'manage'),
            'canReverse' => $user->canAccess('collections', 'reverse'),
        ]);
    }
}
