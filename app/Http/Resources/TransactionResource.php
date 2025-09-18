<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TransactionResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $decimals = $this->currency === 'JPY' ? 0 : 2;
        $factor   = 10 ** $decimals;

        return [
            'id' => $this->id,
            'account_id' => $this->account_id,
            'type' => $this->type,
            'amount_minor' => (int) $this->amount_minor,
            'amount' => round($this->amount_minor / $factor, $decimals),
            'currency' => $this->currency,
            'description' => $this->description,
            'category_id' => $this->category_id,
            'counterparty_account_id' => $this->counterparty_account_id,
            'fx_rate' => $this->fx_rate ? (float) $this->fx_rate : null,
            'fx_base' => $this->fx_base,
            'fx_quote' => $this->fx_quote,
            'executed_at' => $this->executed_at,
            'account' => new AccountResource($this->whenLoaded('account')),
            'category' => new CategoryResource($this->whenLoaded('category')),
        ];
    }
}
