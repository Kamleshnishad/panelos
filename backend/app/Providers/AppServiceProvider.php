<?php

namespace App\Providers;

use App\Support\IndicPdf;
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

        // Same wrapper, plus Gujarati / Devanagari text (PB-084).
        $this->app->bind('dompdf.wrapper', fn ($app) => new IndicPdf($app['dompdf'], $app['config'], $app['files'], $app['view']));
    }
}
