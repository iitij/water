<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \Illuminate\Support\Number::useLocale('ar-u-nu-latn');
        \Filament\Tables\Table::configureUsing(function (\Filament\Tables\Table $table) {
            $table->defaultNumberLocale('ar-u-nu-latn');
        });
    }
}
