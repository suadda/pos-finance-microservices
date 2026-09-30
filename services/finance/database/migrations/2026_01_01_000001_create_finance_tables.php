<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $table) {
            $table->string('code', 10)->primary();
            $table->string('name', 100);
            $table->enum('type', ['asset', 'liability', 'revenue']);
            $table->timestamps();
        });

        Schema::create('postings', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 80)->unique(); // {trx_number}:sale | {trx_number}:reversal
            $table->string('trx_number', 50)->index();        // logical ref to db_pos.transactions
            $table->enum('entry_type', ['sale', 'reversal']);
            $table->unsignedBigInteger('outlet_id');           // logical ref to db_pos.outlets
            $table->string('outlet_code', 20);                 // snapshot
            $table->unsignedBigInteger('shift_id');            // logical ref to db_pos.shifts
            $table->date('business_date');
            $table->enum('payment_method', ['cash', 'debit', 'qris']);
            $table->decimal('net_sales_amount', 15, 2);
            $table->decimal('tax_amount', 15, 2);
            $table->decimal('total_amount', 15, 2);
            $table->timestamp('posted_at')->useCurrent();
            $table->timestamps();

            $table->index(['outlet_id', 'business_date', 'entry_type']);
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('posting_id')->constrained('postings')->cascadeOnDelete();
            $table->string('account_code', 10);
            $table->foreign('account_code')->references('code')->on('accounts');
            $table->decimal('debit', 15, 2)->default(0);
            $table->decimal('credit', 15, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('eod_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outlet_id');
            $table->string('outlet_code', 20);
            $table->date('business_date');
            $table->enum('status', ['pending', 'matched', 'mismatch', 'resolved'])->default('pending')->index();
            $table->decimal('pos_cash', 15, 2)->default(0);
            $table->decimal('pos_debit', 15, 2)->default(0);
            $table->decimal('pos_qris', 15, 2)->default(0);
            $table->decimal('fin_cash', 15, 2)->default(0);
            $table->decimal('fin_debit', 15, 2)->default(0);
            $table->decimal('fin_qris', 15, 2)->default(0);
            $table->integer('pos_trx_count')->default(0);
            $table->integer('fin_trx_count')->default(0);
            $table->decimal('cash_variance', 15, 2)->default(0);
            $table->integer('pos_void_count')->default(0);     // informational
            $table->integer('pos_unsynced_count')->default(0); // informational
            $table->json('mismatch_reasons')->nullable();
            $table->unsignedBigInteger('run_by')->nullable();
            $table->timestamp('run_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_note')->nullable();
            $table->timestamps();

            $table->unique(['outlet_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eod_reconciliations');
        Schema::dropIfExists('journal_lines');
        Schema::dropIfExists('postings');
        Schema::dropIfExists('accounts');
    }
};
