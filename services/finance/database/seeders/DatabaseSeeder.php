<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/** Chart of accounts used by the journal rules. Idempotent. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            ['code' => '1101', 'name' => 'Kas', 'type' => 'asset'],
            ['code' => '1102', 'name' => 'Bank - Kartu Debit', 'type' => 'asset'],
            ['code' => '1103', 'name' => 'Piutang QRIS', 'type' => 'asset'],
            ['code' => '2101', 'name' => 'Utang PPN', 'type' => 'liability'],
            ['code' => '4101', 'name' => 'Penjualan', 'type' => 'revenue'],
        ];

        foreach ($accounts as $account) {
            Account::updateOrCreate(['code' => $account['code']], $account);
        }
    }
}
