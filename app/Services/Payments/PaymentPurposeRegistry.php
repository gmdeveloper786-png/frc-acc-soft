<?php

namespace App\Services\Payments;

use App\Models\PaymentPurpose;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The payment purposes offered on the unified payment screen, read from the
 * configurable payment_purposes table. Each purpose is bound to one protected
 * PaymentBehaviour, which alone decides how the payment is posted.
 */
class PaymentPurposeRegistry
{
    /** Active purposes the user may use for this payment source, in display order. */
    public function availableTo(User $user, string $source): Collection
    {
        return PaymentPurpose::query()->with('debitAccount')->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get()
            ->filter(fn (PaymentPurpose $purpose): bool => in_array($source, $purpose->behaviour->sources(), true) && $user->can($purpose->behaviour->permission($source)))
            ->values();
    }

    public function find(int $id): ?PaymentPurpose
    {
        return PaymentPurpose::query()->with('debitAccount')->where('is_active', true)->find($id);
    }
}
