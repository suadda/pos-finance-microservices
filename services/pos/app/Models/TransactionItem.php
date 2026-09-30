<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use Illuminate\Database\Eloquent\Model;

class TransactionItem extends Model
{
    use SerializesLocalDates;

    protected $fillable = ['transaction_id', 'product_id', 'sku', 'product_name', 'quantity', 'unit_price', 'subtotal'];

    protected function casts(): array
    {
        return ['quantity' => 'integer', 'unit_price' => 'decimal:2', 'subtotal' => 'decimal:2'];
    }
}
