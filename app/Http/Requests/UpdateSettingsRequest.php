<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('settings.update') === true;
    }

    public function rules(): array
    {
        // Protected approval controls are never accepted by the general settings form.
        return collect(config('settings.fields'))
            ->reject(fn (array $definition): bool => $definition['protected'] ?? false)
            ->mapWithKeys(fn (array $definition, string $key): array => ['settings.'.str_replace('.', '__', $key) => $definition['rules']])
            ->all();
    }
}
