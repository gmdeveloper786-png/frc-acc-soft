<?php

namespace App\Http\Controllers;

use App\Services\ApprovalSettingsService;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalSettingsController extends Controller
{
    public function edit(Request $request, ApprovalSettingsService $service, AppSettings $settings): View
    {
        abort_unless($service->mayManage($request->user()), 403, 'Only a Director may change approval thresholds and policies.');
        $fields = $service->definitions();

        return view('settings.approval-controls', [
            'fields' => $fields,
            'values' => collect($fields)->map(fn (array $definition, string $key): mixed => $settings->get($key)),
        ]);
    }

    public function update(Request $request, ApprovalSettingsService $service): RedirectResponse
    {
        $values = collect($service->definitions())->mapWithKeys(fn (array $definition, string $key): array => [$key => $request->input('settings.'.str_replace('.', '__', $key))])->all();
        $changed = $service->update($values, $request->input('reason'), $request->user());

        return back()->with('status', $changed === [] ? 'No approval control was changed.' : count($changed).' approval control(s) updated and audited.');
    }
}
