<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Member;
use App\Support\Tenant;
use Illuminate\Http\Request;

class MemberController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q'));
        $status = $request->query('status');

        $members = Member::query()
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('first_name', 'like', "%$search%")
                    ->orWhere('last_name', 'like', "%$search%")
                    ->orWhere('member_no', 'like', "%$search%")
                    ->orWhere('contact_no', 'like', "%$search%");
            }))
            ->when(in_array($status, ['active', 'inactive']), fn ($q) => $q->where('status', $status))
            ->orderBy('last_name')->orderBy('first_name')
            ->paginate(15)->withQueryString();

        return view('members.index', compact('members', 'search', 'status'));
    }

    public function create()
    {
        return view('members.form', ['member' => new Member(['date_joined' => now()->toDateString(), 'status' => 'active']), 'nextNo' => Member::nextNumber()]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $limit = Tenant::current()?->limit('members');
        if ($limit !== null && Member::count() >= $limit) {
            return back()->withInput()->withErrors(['first_name' => "Your plan allows up to $limit members. Please upgrade your plan to add more."]);
        }

        $data['member_no'] = Member::nextNumber();
        $data['status'] = 'active';

        $member = Member::create($data);
        AuditLog::record('member.created', "{$member->member_no} {$member->full_name}");

        return redirect()->route('members.show', $member)->with('status', 'Member added.');
    }

    public function show(Member $member)
    {
        $member->load(['loans' => fn ($q) => $q->latest()]);
        $transactions = $member->transactions()->latest('posted_at')->latest('id')->limit(15)->get();

        return view('members.show', [
            'member' => $member,
            'transactions' => $transactions,
            'paidUp' => $member->paidUpShares(),
            'savings' => $member->savingsBalance(),
            'loanBalance' => $member->loanBalance(),
        ]);
    }

    /** Everything that ever happened on this member's accounts. */
    public function ledger(Request $request, Member $member)
    {
        $input = $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        $transactions = $member->transactions()->with(['loan', 'collection', 'user'])
            ->when($input['from'] ?? null, fn ($q, $d) => $q->where('posted_at', '>=', $d.' 00:00:00'))
            ->when($input['to'] ?? null, fn ($q, $d) => $q->where('posted_at', '<=', $d.' 23:59:59'))
            ->orderBy('posted_at')->orderBy('id')->get();

        return view('members.ledger', [
            'member' => $member,
            'transactions' => $transactions,
            'loans' => $member->loans()->with('product')->orderBy('id')->get(),
            'savings' => $member->savingsBalance(),
            'shares' => $member->paidUpShares(),
            'loanBalance' => $member->loanBalance(),
            'from' => $input['from'] ?? null,
            'to' => $input['to'] ?? null,
        ]);
    }

    public function edit(Member $member)
    {
        return view('members.form', ['member' => $member, 'nextNo' => $member->member_no]);
    }

    public function update(Request $request, Member $member)
    {
        $member->update($this->validated($request));
        AuditLog::record('member.updated', "{$member->member_no} {$member->full_name}");

        return redirect()->route('members.show', $member)->with('status', 'Member updated.');
    }

    /** Switch a member between active and inactive. */
    public function toggle(Member $member)
    {
        if ($member->status === 'active' && $member->loanBalance() > 0) {
            return back()->withErrors(['member' => 'This member still has an unpaid loan, so they cannot be made inactive.']);
        }

        $member->update(['status' => $member->status === 'active' ? 'inactive' : 'active']);
        AuditLog::record('member.status', "{$member->member_no} is now {$member->status}");

        return back()->with('status', "Member is now {$member->status}.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => 'required|string|max:80',
            'last_name' => 'required|string|max:80',
            'gender' => 'nullable|in:Male,Female',
            'birthdate' => 'nullable|date|before:today',
            'address' => 'nullable|string|max:255',
            'contact_no' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:120',
            'occupation' => 'nullable|string|max:80',
            'date_joined' => 'required|date',
        ]);
    }
}
