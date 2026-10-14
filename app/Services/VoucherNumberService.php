<?php

namespace App\Services;

use App\Models\FiscalYear;
use App\Models\VoucherSequence;
use App\Models\VoucherType;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoucherNumberService
{
    public function next(VoucherType $type, FiscalYear $year): string
    {
        if (! $type->is_active) {
            throw ValidationException::withMessages(['voucher_type_id' => 'The voucher type is inactive.']);
        }

        $scopeKey = $type->reset_period === 'yearly' ? 'fiscal-year:'.$year->id : 'global';
        $lock = fn (): ?VoucherSequence => VoucherSequence::query()->where('voucher_type_id', $type->id)->where('scope_key', $scopeKey)->lockForUpdate()->first();
        // Lock the existing row first. Inserting only when it is missing avoids the shared
        // lock an insert on an existing key takes, which deadlocks with the exclusive lock.
        $sequence = $lock();
        if (! $sequence) {
            DB::table('voucher_sequences')->insertOrIgnore([
                'voucher_type_id' => $type->id, 'scope_key' => $scopeKey,
                'fiscal_year_id' => $type->reset_period === 'yearly' ? $year->id : null, 'next_number' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $sequence = $lock() ?? throw new \RuntimeException('Voucher sequence could not be created.');
        }
        $number = $sequence->next_number;
        $sequence->update(['next_number' => $number + 1]);

        $yearPart = match ($type->year_format) {
            'none' => null,
            'y' => $year->start_date->format('y'),
            default => $year->start_date->format('Y'),
        };

        return collect([$type->prefix, $yearPart, str_pad((string) $number, $type->padding, '0', STR_PAD_LEFT)])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode('-');
    }
}
