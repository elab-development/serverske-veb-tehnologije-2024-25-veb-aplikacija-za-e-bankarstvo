<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
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
            'user_id' => $this->user_id,
            'number'  => $this->number,
            'currency'  => $this->currency,
            'balance_minor' => (int) $this->balance_minor,
            'balance' => round($this->balance_minor / $factor, $decimals),
            'name'  => $this->name,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at'  => $this->updated_at?->toISOString(),
            'user' => new UserResource($this->whenLoaded('user')),
            'transactions_count' => $this->whenCounted('transactions'),
        ];
    }
}
