<?php

namespace App\Models;

use App\Models\Concerns\SerializesLocalDates;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use SerializesLocalDates;

    public const CASH = '1101';

    public const BANK_DEBIT = '1102';

    public const QRIS_RECEIVABLE = '1103';

    public const VAT_PAYABLE = '2101';

    public const SALES = '4101';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = ['code', 'name', 'type'];
}
