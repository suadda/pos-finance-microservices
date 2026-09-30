<?php

namespace Database\Seeders;

use App\Models\Outlet;
use App\Models\Product;
use Illuminate\Database\Seeder;

/**
 * Demo outlets & products. Idempotent. Outlet ids are fixed because db_auth.users.outlet_id refers to them.
 * Products with prices 100.000 / 50.000 / 200.000 reproduce test scenario S1 exactly.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $outlets = [
            ['id' => 1, 'code' => 'BDG', 'name' => 'Outlet Bandung', 'address' => 'Jl. Asia Afrika No. 1, Bandung'],
            ['id' => 2, 'code' => 'GRT', 'name' => 'Outlet Garut', 'address' => 'Jl. Ahmad Yani No. 10, Garut'],
            ['id' => 3, 'code' => 'SKB', 'name' => 'Outlet Sukabumi', 'address' => 'Jl. Siliwangi No. 5, Sukabumi'],
            ['id' => 4, 'code' => 'TSM', 'name' => 'Outlet Tasikmalaya', 'address' => 'Jl. HZ Mustofa No. 7, Tasikmalaya'],
        ];
        Outlet::unguarded(function () use ($outlets) {
            foreach ($outlets as $outlet) {
                Outlet::firstOrCreate(['id' => $outlet['id']], [...$outlet, 'is_active' => true]);
            }
        });

        $products = [
            ['sku' => 'PKT-100K', 'name' => 'Paket Hampers Premium', 'price' => '100000.00'],
            ['sku' => 'PKT-50K', 'name' => 'Paket Snack Box', 'price' => '50000.00'],
            ['sku' => 'PKT-200K', 'name' => 'Paket Parsel Lebaran', 'price' => '200000.00'],
            ['sku' => 'KOP-001', 'name' => 'Kopi Susu Gula Aren', 'price' => '18000.00'],
            ['sku' => 'TEH-001', 'name' => 'Teh Melati Botol', 'price' => '8500.00'],
            ['sku' => 'ROT-001', 'name' => 'Roti Bakar Coklat Keju', 'price' => '22000.00'],
            ['sku' => 'AIR-600', 'name' => 'Air Mineral 600ml', 'price' => '5000.00'],
            ['sku' => 'MIE-001', 'name' => 'Mie Goreng Spesial', 'price' => '27500.00'],
            ['sku' => 'OLD-001', 'name' => 'Produk Lama (nonaktif)', 'price' => '12000.00', 'is_active' => false],
        ];
        foreach ($products as $product) {
            Product::firstOrCreate(['sku' => $product['sku']], [...$product, 'is_active' => $product['is_active'] ?? true]);
        }
    }
}
