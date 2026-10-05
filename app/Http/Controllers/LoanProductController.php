<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\LoanProduct;
use App\Services\LoanCalculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class LoanProductController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $category = $request->query('category');
        $status = $request->query('status');

        $products = LoanProduct::withCount(['deductions', 'loans'])
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%$search%")->orWhere('code', 'like', "%$search%")))
            ->when($category, fn ($q) => $q->where('category', $category))
            ->when(in_array($status, ['active', 'inactive']), fn ($q) => $q->where('is_active', $status === 'active'))
            ->orderByDesc('is_active')->orderBy('name')->get();

        return view('loan-products.index', [
            'products' => $products,
            'categories' => LoanProduct::whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')->all(),
            'search' => $search,
            'category' => $category,
            'status' => $status,
        ]);
    }

    public function create()
    {
        return view('loan-products.form', ['product' => new LoanProduct([
            'is_active' => true, 'min_term' => 1, 'max_term' => 12, 'term_unit' => 'months', 'payment_frequency' => 'monthly',
            'interest_type' => 'add_on', 'interest_basis' => 'annual', 'amortization_method' => 'equal_payment', 'auto_schedule' => true,
            'penalty_type' => 'none', 'penalty_basis' => 'per_day', 'approval_level' => 'manager', 'release_method' => 'cash',
            'allow_early_payment' => true, 'early_payment_penalty' => false, 'guarantor_required' => false,
        ]), 'deductions' => collect()]);
    }

    public function store(Request $request)
    {
        $product = DB::transaction(function () use ($request) {
            [$data, $deductions] = $this->validated($request);
            $product = LoanProduct::create($data);
            $product->deductions()->createMany($deductions);

            return $product;
        });
        AuditLog::record('loan_product.created', "{$product->code} {$product->name}");

        return redirect()->route('loan-products.show', $product)->with('status', "Loan product {$product->name} added.");
    }

    public function show(Request $request, LoanProduct $loanProduct, LoanCalculator $calculator)
    {
        $loanProduct->load('deductions')->loadCount('loans');

        // Sample computation tool: defaults to the smallest loan, then whatever the user typed in
        $input = $request->validate([
            'amount' => 'nullable|numeric|min:1|max:100000000',
            'term' => 'nullable|integer|min:1|max:3650',
            'frequency' => ['nullable', Rule::in(array_keys(config('loan_products.frequencies')))],
        ]);
        $amount = (float) ($input['amount'] ?? $loanProduct->min_amount);
        $term = (int) ($input['term'] ?? $loanProduct->min_term);
        $frequency = $input['frequency'] ?? $loanProduct->payment_frequency;

        $warnings = [];
        if ($amount < $loanProduct->min_amount || $amount > $loanProduct->max_amount) {
            $warnings[] = 'This amount is outside the product limits (₱'.number_format($loanProduct->min_amount, 2).' to ₱'.number_format($loanProduct->max_amount, 2).').';
        }
        if ($term < $loanProduct->min_term || $term > $loanProduct->max_term) {
            $warnings[] = "This term is outside the product limits ({$loanProduct->termRange()}).";
        }

        $calc = $calculator->compute($loanProduct, $amount, $term, $frequency);
        if ($calc['total_deductions'] > $amount) {
            $warnings[] = 'The deductions are larger than the loan amount.';
        }

        return view('loan-products.show', [
            'product' => $loanProduct,
            'calc' => $calc,
            'amount' => $amount,
            'term' => $term,
            'frequency' => $frequency,
            'warnings' => $warnings,
        ]);
    }

    public function edit(LoanProduct $loanProduct)
    {
        return view('loan-products.form', ['product' => $loanProduct, 'deductions' => $loanProduct->deductions]);
    }

    public function update(Request $request, LoanProduct $loanProduct)
    {
        DB::transaction(function () use ($request, $loanProduct) {
            [$data, $deductions] = $this->validated($request, $loanProduct);
            $loanProduct->update($data);
            $loanProduct->deductions()->delete();
            $loanProduct->deductions()->createMany($deductions);
        });
        AuditLog::record('loan_product.updated', "{$loanProduct->code} {$loanProduct->name}");

        return redirect()->route('loan-products.show', $loanProduct)->with('status', 'Loan product updated. Existing loans keep the terms they were approved with.');
    }

    /** Stop (or resume) offering a product. Existing loans are not affected. */
    public function toggle(LoanProduct $loanProduct)
    {
        $loanProduct->update(['is_active' => ! $loanProduct->is_active]);
        AuditLog::record('loan_product.status', $loanProduct->code.($loanProduct->is_active ? ' activated' : ' deactivated'));

        return back()->with('status', "{$loanProduct->name} is now ".($loanProduct->is_active ? 'active' : 'inactive').'.');
    }

    /** Only products that were never used can be deleted; the rest can only be deactivated. */
    public function destroy(LoanProduct $loanProduct)
    {
        if ($loanProduct->loans()->exists()) {
            return back()->withErrors(['product' => 'This product has loan records, so it cannot be deleted. Deactivate it instead.']);
        }

        $loanProduct->delete();
        AuditLog::record('loan_product.deleted', "{$loanProduct->code} {$loanProduct->name}");

        return redirect()->route('loan-products.index')->with('status', 'Loan product deleted.');
    }

    /** JSON summary for the live estimate on the loan application form. */
    public function compute(Request $request, LoanProduct $loanProduct, LoanCalculator $calculator)
    {
        $input = $request->validate(['amount' => 'required|numeric|min:1|max:100000000', 'term' => 'required|integer|min:1|max:3650']);
        $calc = $calculator->compute($loanProduct->load('deductions'), (float) $input['amount'], (int) $input['term']);

        return response()->json(collect($calc)->except(['rows', 'deductions'])->all());
    }

    /** Validates the form and returns [product fields, deduction rows], with the dependent fields cleaned up. */
    private function validated(Request $request, ?LoanProduct $product = null): array
    {
        $in = fn (string $list) => Rule::in(array_keys(config("loan_products.$list")));
        $yesNo = 'required|boolean';

        $data = $request->validate([
            // A. Basic information
            'name' => 'required|string|max:80',
            'code' => ['required', 'alpha_dash', 'max:12', Rule::unique('loan_products', 'code')->ignore($product?->id)],
            'category' => 'nullable|string|max:60',
            'description' => 'nullable|string|max:500',
            'is_active' => $yesNo,
            // B. Amount and term
            'min_amount' => 'required|numeric|min:0|max:100000000',
            'max_amount' => 'required|numeric|gte:min_amount|max:100000000',
            'min_term' => 'required|integer|min:1|max:3650',
            'max_term' => 'required|integer|gte:min_term|max:3650',
            'term_unit' => ['required', $in('term_units')],
            'payment_frequency' => ['required', $in('frequencies')],
            // C. Interest and schedule
            'interest_type' => ['required', $in('interest_types')],
            'interest_rate' => 'nullable|numeric|min:0|max:1000',
            'interest_basis' => ['required', $in('interest_bases')],
            'amortization_method' => ['required', $in('amortization_methods')],
            'auto_schedule' => $yesNo,
            // E. Penalties
            'penalty_type' => ['required', $in('penalty_types')],
            'penalty_value' => 'nullable|numeric|min:0|max:100000000',
            'penalty_basis' => ['required', $in('penalty_bases')],
            'grace_days' => 'nullable|integer|min:0|max:365',
            // F. Requirements
            'min_savings' => 'nullable|numeric|min:0|max:100000000',
            'min_shares' => 'nullable|numeric|min:0|max:100000000',
            'guarantor_required' => $yesNo,
            'guarantors_required' => 'nullable|integer|min:0|max:20',
            'required_documents' => 'nullable|array',
            'required_documents.*' => ['string', $in('documents')],
            // G. Approval and release
            'approval_level' => ['required', $in('approval_levels')],
            'release_method' => ['required', $in('release_methods')],
            // H. Other settings
            'allow_early_payment' => $yesNo,
            'early_payment_penalty' => $yesNo,
            'early_payment_penalty_rate' => 'nullable|numeric|min:0|max:100',
            'allow_renewal' => $yesNo,
            'allow_restructuring' => $yesNo,
            // D. Deductions
            'deductions' => 'nullable|array|max:20',
            'deductions.*.name' => 'required|string|max:60',
            'deductions.*.type' => ['required', $in('deduction_types')],
            'deductions.*.value' => 'nullable|numeric|min:0|max:100000000',
            'deductions.*.basis' => ['required', $in('deduction_bases')],
            'deductions.*.timing' => ['required', $in('deduction_timings')],
            'deductions.*.is_required' => $yesNo,
            'deductions.*.is_editable' => $yesNo,
            'deductions.*.is_active' => $yesNo,
        ]);

        // Rules that depend on other fields
        $deductions = [];
        foreach ($data['deductions'] ?? [] as $i => $d) {
            $d['value'] = $d['type'] === 'none' ? 0 : (float) ($d['value'] ?? 0);
            if ($d['type'] === 'percentage' && $d['value'] > 100) {
                throw ValidationException::withMessages(["deductions.$i.value" => 'A percentage deduction cannot be more than 100.']);
            }
            $deductions[] = $d;
        }
        unset($data['deductions']);

        if ($data['penalty_type'] === 'percentage' && (float) ($data['penalty_value'] ?? 0) > 100) {
            throw ValidationException::withMessages(['penalty_value' => 'A percentage penalty cannot be more than 100.']);
        }
        if ($data['guarantor_required'] && (int) ($data['guarantors_required'] ?? 0) < 1) {
            throw ValidationException::withMessages(['guarantors_required' => 'Enter how many guarantors / co-makers are required (at least 1).']);
        }

        $data['interest_rate'] = $data['interest_type'] === 'interest_free' ? 0 : (float) ($data['interest_rate'] ?? 0);
        $data['penalty_value'] = $data['penalty_type'] === 'none' ? 0 : (float) ($data['penalty_value'] ?? 0);
        $data['guarantors_required'] = $data['guarantor_required'] ? (int) $data['guarantors_required'] : 0;
        $data['allow_early_payment'] = (bool) $data['allow_early_payment'];
        $data['early_payment_penalty'] = $data['allow_early_payment'] && $data['early_payment_penalty'];
        $data['early_payment_penalty_rate'] = $data['early_payment_penalty'] ? (float) ($data['early_payment_penalty_rate'] ?? 0) : 0;
        $data['grace_days'] = (int) ($data['grace_days'] ?? 0);
        $data['min_savings'] = (float) ($data['min_savings'] ?? 0);
        $data['min_shares'] = (float) ($data['min_shares'] ?? 0);
        $data['required_documents'] = array_values($data['required_documents'] ?? []);

        return [$data, $deductions];
    }
}
