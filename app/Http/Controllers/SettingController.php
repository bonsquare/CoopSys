<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    private const KEYS = ['coop_name', 'branch_name', 'default_interest', 'par_threshold', 'collection_target', 'min_balance', 'allocation_preset', 'allocation_custom', 'share_withdrawal_policy', 'regular_savings', 'regular_share', 'board_meeting', 'board_note'];

    public function edit()
    {
        $values = [];
        foreach (self::KEYS as $key) {
            $values[$key] = Setting::get($key);
        }

        return view('settings.edit', [
            'values' => $values,
            'logs' => AuditLog::with('user')->latest('id')->limit(30)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'coop_name' => 'required|string|max:120',
            'branch_name' => 'required|string|max:120',
            'default_interest' => 'required|numeric|min:0|max:100',
            'par_threshold' => 'required|numeric|min:0|max:100',
            'collection_target' => 'required|numeric|min:0|max:200',
            'min_balance' => 'required|numeric|min:0|max:100000000',
            'allocation_preset' => ['required', Rule::in(array_keys(config('collections.priority_presets')))],
            'allocation_custom' => 'nullable|array|max:6',
            'allocation_custom.*' => ['string', Rule::in(array_keys(config('collections.priority_items')))],
            'share_withdrawal_policy' => ['required', Rule::in(array_keys(config('collections.share_withdrawal_policies')))],
            'regular_savings' => 'required|numeric|min:0|max:100000000',
            'regular_share' => 'required|numeric|min:0|max:100000000',
            'board_meeting' => 'nullable|date',
            'board_note' => 'nullable|string|max:150',
        ]);

        // The custom order is stored as a comma separated list
        $data['allocation_custom'] = implode(',', array_unique($data['allocation_custom'] ?? []));

        foreach (self::KEYS as $key) {
            Setting::put($key, $data[$key] ?? '');
        }
        AuditLog::record('settings.updated', 'Settings changed');

        return back()->with('status', 'Settings saved.');
    }
}
