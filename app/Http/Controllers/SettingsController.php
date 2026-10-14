<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\AppSetting;
use App\Services\ApprovalSettingsService;
use App\Services\AuditLogger;
use App\Support\AppSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(Request $request, AppSettings $settings, ApprovalSettingsService $approvals): View
    {
        $values = collect(config('settings.fields'))->mapWithKeys(
            fn (array $definition, string $key): array => [$key => $settings->get($key)],
        );

        return view('settings.edit', [
            'groups' => config('settings.groups'),
            'fields' => collect($this->editableFields())->groupBy('group', preserveKeys: true),
            'values' => $values,
            'approvalControls' => $approvals->definitions(),
            'canManageApprovals' => $approvals->mayManage($request->user()),
        ]);
    }

    /**
     * Approval thresholds and policies are excluded: they are changed only
     * through ApprovalSettingsService by a Director.
     *
     * @return array<string, array<string, mixed>>
     */
    private function editableFields(): array
    {
        return collect(config('settings.fields'))->reject(fn (array $definition): bool => $definition['protected'] ?? false)->all();
    }

    public function update(UpdateSettingsRequest $request, AuditLogger $audit): RedirectResponse
    {
        $submitted = $request->validated('settings');
        $definitions = $this->editableFields();
        $changed = [];

        DB::transaction(function () use ($request, $submitted, $definitions, &$changed): void {
            foreach ($definitions as $key => $definition) {
                $inputKey = str_replace('.', '__', $key);
                if ($definition['type'] === 'file') {
                    if (! $request->hasFile("settings.$inputKey")) {
                        continue;
                    }
                    $value = 'storage/'.$request->file("settings.$inputKey")->store('branding', 'public');
                } else {
                    $value = $submitted[$inputKey] ?? '';
                }

                $type = in_array($definition['type'], ['number', 'select'], true) && is_numeric($value)
                    ? (str_contains(implode('|', $definition['rules']), 'integer') ? 'integer' : 'decimal')
                    : 'string';
                $setting = AppSetting::query()->where('key', $key)->firstOrFail();
                if ((string) $setting->value !== (string) $value) {
                    $changed[] = $key;
                    $setting->update(['value' => (string) $value, 'type' => $type]);
                }
            }
        });

        $audit->log('settings.updated', properties: ['changed_keys' => $changed]);

        return back()->with('status', 'Application settings updated successfully.');
    }
}
