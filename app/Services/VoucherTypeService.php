<?php

namespace App\Services;

use App\Models\User;
use App\Models\VoucherType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherTypeService
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array<string, mixed> $data */
    public function update(VoucherType $voucherType, array $data, User $actor): VoucherType
    {
        abort_unless($actor->can('voucher_types.manage'), 403);

        return DB::transaction(function () use ($voucherType, $data): VoucherType {
            $voucherType = VoucherType::query()->lockForUpdate()->findOrFail($voucherType->id);
            $numberingFields = ['prefix', 'year_format', 'padding', 'reset_period'];
            $numberingChanged = collect($numberingFields)->contains(
                fn (string $field): bool => (string) $voucherType->{$field} !== (string) $data[$field],
            );
            if ($numberingChanged && $voucherType->journals()->whereNotNull('voucher_number')->exists()) {
                throw ValidationException::withMessages(['prefix' => 'Numbering rules cannot be changed after this voucher type has posted journals.']);
            }

            $before = $voucherType->only(['name', ...$numberingFields, 'is_active']);
            $voucherType->update($data);
            $after = $voucherType->fresh()->only(array_keys($before));
            $this->audit->log('voucher_type.updated', $voucherType, ['before' => $before, 'after' => $after]);

            return $voucherType->refresh();
        });
    }
}
