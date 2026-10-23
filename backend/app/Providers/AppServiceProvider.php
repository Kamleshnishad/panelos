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
        // The Laravel wrapper ships with font subsetting OFF, which embeds whole
        // DejaVu font files (~0.7 MB) in every PDF. On, a PDF carries only the glyphs
        // it uses (PB-022).
        config(['dompdf.options.enable_font_subsetting' => true]);
    }
}
