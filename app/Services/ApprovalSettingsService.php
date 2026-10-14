<?php

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Approval thresholds and approval policies decide who may authorize spending,
 * so only a Director may change them. The general settings screen never
 * writes these keys.
 */
class ApprovalSettingsService
{
    public function __construct(private ApprovalAuthorityService $authority, private AuditLogger $audit) {}

    /** @return array<string, array<string, mixed>> Protected setting definitions keyed by setting key. */
    public function definitions(): array
    {
        return collect(config('settings.fields'))->filter(fn (array $definition): bool => $definition['protected'] ?? false)->all();
    }

    public function mayManage(User $actor): bool
    {
        return $actor->can('approval_settings.manage') && $this->authority->holdsDirectorRole($actor);
    }

    /**
     * @param  array<string, mixed>  $values  Submitted values keyed by setting key.
     * @return list<string> Keys whose value changed.
     */
    public function update(array $values, ?string $reason, User $actor): array
    {
        abort_unless($this->mayManage($actor), 403, 'Only a Director may change approval thresholds and policies.');

        $definitions = $this->definitions();
        // Setting keys contain dots, which the validator would read as nesting.
        $field = fn (string $key): string => str_replace('.', '__', $key);
        $input = collect($definitions)->mapWithKeys(fn (array $definition, string $key): array => [$field($key) => $values[$key] ?? null]);
        $checked = Validator::make(
            [...$input->all(), 'reason' => $reason],
            [...collect($definitions)->mapWithKeys(fn (array $definition, string $key): array => [$field($key) => $definition['rules']])->all(), 'reason' => ['required', 'string', 'min:10', 'max:1000']],
            [], collect($definitions)->mapWithKeys(fn (array $definition, string $key): array => [$field($key) => $definition['label']])->all(),
        )->validate();
        $validated = collect($definitions)->mapWithKeys(fn (array $definition, string $key): array => [$key => $checked[$field($key)]])->all() + ['reason' => $checked['reason']];

        return DB::transaction(function () use ($validated, $definitions): array {
            $changed = [];
            foreach (array_keys($definitions) as $key) {
                $setting = AppSetting::query()->where('key', $key)->lockForUpdate()->first();
                if (! $setting) {
                    throw ValidationException::withMessages([$key => 'This approval setting is not installed.']);
                }
                $new = (string) $validated[$key];
                if ((string) $setting->value === $new) {
                    continue;
                }
                $old = (string) $setting->value;
                $setting->update(['value' => $new]);
                // The audit row itself carries the actor and the timestamp.
                $this->audit->log('approval_settings.changed', $setting, ['key' => $key, 'old_value' => $old, 'new_value' => $new, 'reason' => trim($validated['reason'])]);
                $changed[] = $key;
            }

            return $changed;
        });
    }
}
