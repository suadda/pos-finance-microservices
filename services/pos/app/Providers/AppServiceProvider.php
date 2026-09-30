<?php

namespace App\Providers;

use App\Services\TransactionCalculator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TransactionCalculator::class, fn () => new TransactionCalculator(config('pos.tax_rate')));
    }
}
