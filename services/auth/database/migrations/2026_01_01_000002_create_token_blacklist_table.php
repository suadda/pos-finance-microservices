<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Revoked tokens (refresh tokens on logout/rotation, access tokens on logout), keyed by JWT "jti".
        Schema::create('token_blacklist', function (Blueprint $table) {
            $table->string('jti', 64)->primary();
            $table->enum('token_type', ['access', 'refresh']);
            $table->unsignedBigInteger('user_id')->index();
            $table->timestamp('expires_at')->index(); // rows can be pruned after this moment
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_blacklist');
    }
};
