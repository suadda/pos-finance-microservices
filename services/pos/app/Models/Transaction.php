<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Transaction extends Model
{
    use SerializesLocalDates;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const SYNC_NONE = 'none';

    public const SYNC_PENDING = 'pending';

    public const SYNC_SYNCED = 'synced';

    public const SYNC_FAILED = 'failed';

    public const METHODS = ['cash', 'debit', 'qris'];

    protected $fillable = [
        'trx_number', 'outlet_id', 'outlet_code', 'shift_id', 'cashier_id', 'business_date', 'status',
        'subtotal', 'discount_amount', 'tax_amount', 'grand_total',
        'payment_method', 'paid_amount', 'change_amount', 'paid_at',
        'void_reason', 'voided_by', 'voided_at',
        'finance_sync_status', 'finance_synced_at', 'finance_last_error',
    ];

    protected $attributes = [
        'status' => self::PENDING,
        'finance_sync_status' => self::SYNC_NONE,
    ];

    protected function casts(): array
    {
        return [
            'outlet_id' => 'integer',
            'shift_id' => 'integer',
            'cashier_id' => 'integer',
            'voided_by' => 'integer',
            'business_date' => 'date:Y-m-d',
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
            'finance_synced_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(TransactionItem::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** subtotal - discount_amount (the amount credited to 4101 Penjualan). */
    public function netSales(): string
    {
        return Money::sub($this->subtotal, $this->discount_amount);
    }

    /** Paid/void transactions with at least one event not yet acknowledged by Finance. */
    public function scopeUnsynced(Builder $query): Builder
    {
        return $query->whereIn('status', [self::PAID, self::VOID])
            ->where('finance_sync_status', '!=', self::SYNC_SYNCED);
    }

    /**
     * Unsynced transactions whose missing events make Finance's net ledger differ from POS
     * (this is what "unsynced_count" reports):
     *  - paid, sale not acknowledged                         -> Finance is short by grand_total
     *  - void, sale acknowledged (finance_synced_at set) but
     *    reversal not                                        -> Finance overstates by grand_total
     * A void whose sale never reached Finance nets to zero either way, so it is not counted;
     * it is still flagged failed/pending and re-sent (sale + reversal) on resync for a complete audit trail.
     */
    public function scopeUnsyncedAffectingLedger(Builder $query): Builder
    {
        return $query->unsynced()->where(fn (Builder $q) => $q
            ->where('status', self::PAID)
            ->orWhereNotNull('finance_synced_at'));
    }
}
