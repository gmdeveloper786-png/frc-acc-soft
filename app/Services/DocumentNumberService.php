<?php

namespace App\Services;

use App\Models\DocumentSequence;
use App\Support\AppSettings;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Numbers non-financial documents. Financial vouchers keep using
 * VoucherNumberService and its own sequences.
 */
class DocumentNumberService
{
    public function __construct(private AppSettings $settings) {}

    /**
     * Allocate the next purchase order number. Must run inside a database
     * transaction so the locked sequence row is released on commit.
     */
    public function nextPurchaseOrderNumber(CarbonInterface $date): string
    {
        $prefix = trim($this->settings->string('numbering.purchase_order_prefix', 'PO'));
        $padding = max(1, min(10, $this->settings->integer('numbering.purchase_order_padding', 5)));
        $yearly = $this->settings->string('numbering.purchase_order_reset', 'yearly') === 'yearly';
        $scopeKey = $yearly ? 'year:'.$date->format('Y') : 'global';

        $lock = fn (): ?DocumentSequence => DocumentSequence::query()->where('document_type', 'purchase_order')->where('scope_key', $scopeKey)->lockForUpdate()->first();
        // Lock the existing row first. Inserting only when it is missing avoids the shared
        // lock an insert on an existing key takes, which deadlocks with the exclusive lock.
        $sequence = $lock();
        if (! $sequence) {
            DB::table('document_sequences')->insertOrIgnore(['document_type' => 'purchase_order', 'scope_key' => $scopeKey, 'next_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $sequence = $lock() ?? throw new \RuntimeException('Purchase order sequence could not be created.');
        }
        $number = $sequence->next_number;
        $sequence->update(['next_number' => $number + 1]);

        return collect([$prefix, $yearly ? $date->format('Y') : null, str_pad((string) $number, $padding, '0', STR_PAD_LEFT)])
            ->filter(fn (?string $part): bool => filled($part))
            ->implode('-');
    }
}
