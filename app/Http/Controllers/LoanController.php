<?php

namespace App\Http\Controllers;

use App\Models\Loan;
use App\Models\LoanProduct;
use App\Models\Member;
use App\Services\LoanCalculator;
use App\Services\LoanScheduleService;
use App\Services\LoanService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class LoanController extends Controller
{
    public function __construct(private LoanService $loans) {}

    public function index(Request $request)
    {
        $status = $request->query('status');
        $search = trim((string) $request->query('q'));

        $loans = Loan::with('member')
            ->when($status === 'pastdue', fn ($q) => $q->where('status', 'released')->where('balance', '>', 0)->whereDate('maturity_date', '<', now()->toDateString()))
            ->when(in_array($status, ['pending', 'approved', 'released', 'paid', 'rejected']), fn ($q) => $q->where('status', $status))
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('loan_no', 'like', "%$search%")
                    ->orWhereHas('member', fn ($m) => $m->where('first_name', 'like', "%$search%")->orWhere('last_name', 'like', "%$search%")->orWhere('member_no', 'like', "%$search%"));
            }))
            ->latest('id')->paginate(15)->withQueryString();

        return view('loans.index', compact('loans', 'status', 'search'));
    }

    public function create()
    {
        return view('loans.create', [
            'options' => Member::options(),
            'products' => LoanProduct::where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'member_id' => 'required|exists:members,id',
            'loan_product_id' => 'required|exists:loan_products,id',
            'principal' => 'required|numeric|min:100|max:100000000',
            'term' => 'required|integer|min:1|max:3650',
            'purpose' => 'nullable|string|max:255',
        ]);

        // The product decides the interest, deductions, schedule and the allowed amount / term
        $product = LoanProduct::findOrFail($data['loan_product_id']);

        if (! $product->is_active) {
            throw ValidationException::withMessages(['loan_product_id' => 'This loan product is no longer offered.']);
        }
        if ($data['principal'] < $product->min_amount || $data['principal'] > $product->max_amount) {
            throw ValidationException::withMessages(['principal' => "{$product->name} allows ₱".number_format($product->min_amount, 2).' to ₱'.number_format($product->max_amount, 2).'.']);
        }
        if ($data['term'] < $product->min_term || $data['term'] > $product->max_term) {
            throw ValidationException::withMessages(['term' => "{$product->name} allows {$product->min_term} to {$product->max_term} ".strtolower(LoanProduct::label('term_units', $product->term_unit)).'.']);
        }

        $loan = $this->loans->apply(Member::findOrFail($data['member_id']), $product, (float) $data['principal'], (int) $data['term'], $data['purpose'] ?? null);

        return redirect()->route('loans.show', $loan)->with('status', "Loan application {$loan->loan_no} submitted.");
    }

    public function show(Loan $loan, LoanCalculator $calculator)
    {
        $loan->load(['member', 'product']);

        // Payment schedule: use the stored computation (newer loans) or equal monthly installments (older loans)
        $start = $loan->released_at ?? now();
        $schedule = [];
        if ($loan->schedule) {
            foreach ($loan->schedule as $row) {
                $row['due'] = $calculator->dueDate($start, $loan->payment_frequency, $row['no'], $loan->term_value ?? $loan->term_months, $loan->term_unit);
                $schedule[] = $row;
            }
        } else {
            for ($i = 1; $i <= $loan->term_months; $i++) {
                $schedule[] = ['no' => $i, 'due' => $start->copy()->addMonths($i), 'payment' => $loan->monthly_amortization];
            }
        }

        return view('loans.show', [
            'loan' => $loan,
            'schedule' => $schedule,
            'payments' => $loan->transactions()->where('type', 'loan_payment')->latest('posted_at')->latest('id')->get(),
            'paidTotal' => (float) $loan->transactions()->completed()->where('type', 'loan_payment')->sum('amount'),
        ]);
    }

    /** Loan ledger: schedule, what has been paid toward each part, penalty due and every payment. */
    public function ledger(Loan $loan, LoanScheduleService $schedule)
    {
        $loan->load(['member', 'product']);
        $analysis = $loan->status === 'released' || $loan->status === 'paid' ? $schedule->analyze($loan) : null;

        return view('loans.ledger', [
            'loan' => $loan,
            'a' => $analysis,
            'transactions' => $loan->transactions()->with(['collection', 'user'])->orderBy('posted_at')->orderBy('id')->get(),
        ]);
    }

    public function approve(Loan $loan)
    {
        $this->loans->approve($loan);

        return back()->with('status', "{$loan->loan_no} approved.");
    }

    public function reject(Loan $loan)
    {
        $this->loans->reject($loan);

        return back()->with('status', "{$loan->loan_no} rejected.");
    }

    public function release(Loan $loan)
    {
        $this->loans->release($loan);

        return back()->with('status', "{$loan->loan_no} released. A cash disbursement was recorded.");
    }
}
