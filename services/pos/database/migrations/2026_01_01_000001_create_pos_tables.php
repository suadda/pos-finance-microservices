<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outlets', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name', 100);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('sku', 50)->unique();
            $table->string('name', 200)->index();
            $table->decimal('price', 15, 2);
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->unsignedBigInteger('cashier_id')->index(); // logical ref to db_auth.users
            $table->date('business_date');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 15, 2);
            $table->decimal('expected_cash', 15, 2)->nullable();
            $table->decimal('actual_cash', 15, 2)->nullable();
            $table->decimal('cash_variance', 15, 2)->nullable();
            $table->enum('status', ['open', 'closed'])->default('open');
            $table->timestamps();

            // DB-level guarantee of "one open shift per cashier", safe against concurrent requests:
            // the generated column is the cashier id only while the shift is open (NULLs never collide).
            $table->unsignedBigInteger('open_cashier_guard')
                ->nullable()
                ->storedAs("(CASE WHEN `status` = 'open' THEN `cashier_id` ELSE NULL END)")
                ->unique();

            $table->index(['outlet_id', 'business_date']);
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->string('trx_number', 50)->unique();
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->string('outlet_code', 20); // snapshot
            $table->foreignId('shift_id')->constrained('shifts');
            $table->unsignedBigInteger('cashier_id')->index();
            $table->date('business_date');
            $table->enum('status', ['pending', 'paid', 'void'])->default('pending');
            $table->decimal('subtotal', 15, 2);
            $table->decimal('discount_amount', 15, 2)->default(0);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('grand_total', 15, 2);
            $table->enum('payment_method', ['cash', 'debit', 'qris'])->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->decimal('change_amount', 15, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->unsignedBigInteger('voided_by')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->enum('finance_sync_status', ['none', 'pending', 'synced', 'failed'])->default('none')->index();
            $table->timestamp('finance_synced_at')->nullable();
            $table->text('finance_last_error')->nullable();
            $table->timestamps();

            $table->index(['outlet_id', 'business_date', 'status']);
            $table->index(['shift_id', 'status']);
        });

        Schema::create('transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('transactions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->string('sku', 50);           // snapshot
            $table->string('product_name', 200); // snapshot
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 15, 2); // snapshot of products.price
            $table->decimal('subtotal', 15, 2);
            $table->timestamps();
        });

        // Per outlet per business day counter for TRX/{OUTLET}/{YYYYMMDD}/{NNNN} (see TrxNumberGenerator).
        Schema::create('trx_sequences', function (Blueprint $table) {
            $table->foreignId('outlet_id')->constrained('outlets');
            $table->date('business_date');
            $table->unsignedInteger('last_number');
            $table->primary(['outlet_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trx_sequences');
        Schema::dropIfExists('transaction_items');
        Schema::dropIfExists('transactions');
        Schema::dropIfExists('shifts');
        Schema::dropIfExists('products');
        Schema::dropIfExists('outlets');
    }
};
