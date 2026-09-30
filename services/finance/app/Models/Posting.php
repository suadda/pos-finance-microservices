<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Posting extends Model
{
    use SerializesLocalDates;

    public const SALE = 'sale';

    public const REVERSAL = 'reversal';

    protected $fillable = [
        'idempotency_key', 'trx_number', 'entry_type', 'outlet_id', 'outlet_code', 'shift_id',
        'business_date', 'payment_method', 'net_sales_amount', 'tax_amount', 'total_amount', 'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'shift_id' => 'integer',
            'business_date' => 'date:Y-m-d',
            'net_sales_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'posted_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('id');
    }
}
