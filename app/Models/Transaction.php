<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'type',
        'amount_minor',
        'currency',
        'description',
        'category_id',
        'counterparty_account_id',
        'fx_rate',
        'fx_base',
        'fx_quote',
        'executed_at'
    ];

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function counterpartyAccount()
    {
        return $this->belongsTo(Account::class, 'counterparty_account_id');
    }
}
