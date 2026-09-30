<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductController
{
    private const PRICE_RULE = ['numeric', 'min:0', 'max:9999999999999.99', 'regex:/^\d+(\.\d{1,2})?$/'];

    /** GET /products?search=&is_active=&page=&per_page= */
    public function index(Request $request): JsonResponse
    {
        $query = Product::query()->orderBy('name');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('sku', 'like', "%{$search}%"));
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        return ApiResponse::paginated($query->paginate(ApiResponse::perPage()));
    }

    public function show(Product $product): JsonResponse
    {
        return ApiResponse::ok($product);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:50', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:200'],
            'price' => ['required', ...self::PRICE_RULE],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        return ApiResponse::created(Product::create($data));
    }

    public function update(Request $request, Product $product): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['sometimes', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($product->id)],
            'name' => ['sometimes', 'string', 'max:200'],
            'price' => ['sometimes', ...self::PRICE_RULE],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        $product->update($data);

        return ApiResponse::ok($product);
    }

    /** DELETE /products/{id} — deactivates (no hard delete: transaction_items keep referencing it). */
    public function destroy(Product $product): JsonResponse
    {
        $product->update(['is_active' => false]);

        return ApiResponse::ok($product);
    }
}
