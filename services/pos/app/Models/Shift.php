<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Shift extends Model
{
    use SerializesLocalDates;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $fillable = [
        'outlet_id', 'cashier_id', 'business_date', 'opened_at', 'closed_at',
        'opening_cash', 'expected_cash', 'actual_cash', 'cash_variance', 'status',
    ];

    protected $hidden = ['open_cashier_guard'];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'cashier_id' => 'integer',
            'business_date' => 'date:Y-m-d',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'cash_variance' => 'decimal:2',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
