<?php

namespace App\Http\Resources\Payment;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment method, as the mobile app sees it.
 *
 * **This resource is why enabling a provider needs no app release.** Label,
 * description, logo, tint and the fields to collect all come from here, so the
 * app renders a method it has never heard of. The old sheet hardcoded a
 * `PaymentProviderId` union plus icon and colour maps, which meant a new
 * provider was an EAS build.
 *
 * What is deliberately absent: credentials, the driver class, the mode, and the
 * merchant-borne fee. A client has no use for any of them and every one is a
 * detail an attacker would enjoy.
 *
 * @mixin \App\Models\PaymentProvider
 */
class PaymentMethodResource extends JsonResource
{
    public function __construct($resource, private int $amount = 0, private int $walletBalance = 0)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $fee = $this->feeOn($this->amount);
        $bearerIsClient = $this->fee_bearer === 'client';

        return [
            'code' => $this->code,
            'label' => $this->label,
            'description' => $this->description,
            'logo_url' => $this->logoUrl(),
            'color' => $this->brand_color,

            // Derived from the descriptor rather than sent as a flag, so the
            // app has one source of truth about what to ask for.
            'fields' => $this->fields ?: [],
            'requires_phone' => collect($this->fields ?: [])
                ->contains(fn ($f) => ($f['key'] ?? null) === 'phone'),
            'phone_prefixes' => $this->phone_prefixes ?: [],

            /*
             * The rails behind an aggregator.
             *
             * Empty for every direct provider, which is what keeps them
             * rendering exactly as before. Yabetoo returns MTN and Airtel here,
             * and the sheet shows THOSE rather than the aggregator, because
             * nobody in Brazzaville thinks of themselves as paying by Yabetoo.
             */
            'options' => $this->resolvedOptions(),

            /*
             * Only shown when the CLIENT bears it. A merchant-borne fee is
             * Mova's cost of doing business; surfacing it would invite "why am
             * I being charged 2% for MTN?" about money the client never pays.
             */
            'fee_amount' => $bearerIsClient ? $fee : 0,
            'total_amount' => $this->totalFor($this->amount),

            'min_amount' => (int) $this->min_amount,
            'max_amount' => $this->max_amount ? (int) $this->max_amount : null,

            /*
             * Mova Credit is the one method whose availability depends on the
             * client rather than the amount. Offering a zero balance would be
             * a row that can only ever fail.
             */
            'balance' => $this->code === 'mova_credit' ? $this->walletBalance : null,
            'available' => $this->code === 'mova_credit'
                ? $this->walletBalance > 0
                : true,
        ];
    }
}
