<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use App\Support\Money;
use Illuminate\Database\Eloquent\Model;

class EodReconciliation extends Model
{
    use SerializesLocalDates;

    public const PENDING = 'pending';

    public const MATCHED = 'matched';

    public const MISMATCH = 'mismatch';

    public const RESOLVED = 'resolved';

    /** Final rows can no longer be re-run. */
    public const FINAL = [self::MATCHED, self::RESOLVED];

    protected $fillable = [
        'outlet_id', 'outlet_code', 'business_date', 'status',
        'pos_cash', 'pos_debit', 'pos_qris', 'fin_cash', 'fin_debit', 'fin_qris',
        'pos_trx_count', 'fin_trx_count', 'cash_variance', 'pos_void_count', 'pos_unsynced_count',
        'mismatch_reasons', 'run_by', 'run_at', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected $appends = ['comparison'];

    protected function casts(): array
    {
        $money = array_fill_keys(['pos_cash', 'pos_debit', 'pos_qris', 'fin_cash', 'fin_debit', 'fin_qris', 'cash_variance'], 'decimal:2');

        return [
            ...$money,
            'outlet_id' => 'integer',
            'business_date' => 'date:Y-m-d',
            'pos_trx_count' => 'integer',
            'fin_trx_count' => 'integer',
            'pos_void_count' => 'integer',
            'pos_unsynced_count' => 'integer',
            'mismatch_reasons' => 'array',
            'run_by' => 'integer',
            'run_at' => 'datetime',
            'resolved_by' => 'integer',
            'resolved_at' => 'datetime',
        ];
    }

    /** POS vs Finance table (difference = POS - Finance) ready for the UI. */
    public function getComparisonAttribute(): array
    {
        $rows = [];
        foreach (['cash', 'debit', 'qris'] as $method) {
            $pos = $this->{"pos_{$method}"} ?? '0.00';
            $fin = $this->{"fin_{$method}"} ?? '0.00';
            $rows[$method] = ['pos' => $pos, 'finance' => $fin, 'diff' => Money::sub($pos, $fin)];
        }
        $rows['trx_count'] = [
            'pos' => (int) $this->pos_trx_count,
            'finance' => (int) $this->fin_trx_count,
            'diff' => (int) $this->pos_trx_count - (int) $this->fin_trx_count,
        ];

        return $rows;
    }
}
