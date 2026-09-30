<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Demo accounts (one per role, plus a second outlet's staff). Idempotent: safe on every container start.
 * Outlet ids match the POS seeder: 1 = BDG, 2 = GRT, 3 = SKB, 4 = TSM.
 */
class DatabaseSeeder extends Seeder
{
    public const PASSWORD = 'password123';

    public function run(): void
    {
        $users = [
            ['name' => 'Super Admin', 'email' => 'superadmin@demo.test', 'role' => 'superadmin', 'outlet_id' => null],
            ['name' => 'Staff Finance', 'email' => 'staff.finance@demo.test', 'role' => 'staff_finance', 'outlet_id' => null],
            ['name' => 'Manager Finance', 'email' => 'manager.finance@demo.test', 'role' => 'manager_finance', 'outlet_id' => null],
        ];
        foreach ([1 => ['bdg', 'Bandung'], 2 => ['grt', 'Garut'], 3 => ['skb', 'Sukabumi'], 4 => ['tsm', 'Tasikmalaya']] as $outletId => [$code, $city]) {
            $users[] = ['name' => "Kasir {$city}", 'email' => "kasir.{$code}@demo.test", 'role' => 'kasir', 'outlet_id' => $outletId];
            $users[] = ['name' => "Supervisor {$city}", 'email' => "supervisor.{$code}@demo.test", 'role' => 'supervisor_pos', 'outlet_id' => $outletId];
        }

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                [...$user, 'password' => self::PASSWORD, 'is_active' => true],
            );
        }
    }
}
