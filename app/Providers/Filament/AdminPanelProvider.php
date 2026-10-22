<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName(fn () => $this->getBrandName())
            ->brandLogo(fn () => $this->getBrandLogoHtml())
            ->favicon(fn () => $this->getFavicon())
            ->sidebarCollapsibleOnDesktop()
            ->colors([
                'primary' => Color::Amber,
            ])
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make()
                     ->label('العمليات'),
                \Filament\Navigation\NavigationGroup::make()
                     ->label('الفوترة والتحصيل'),
                \Filament\Navigation\NavigationGroup::make()
                     ->label('إدارة المشتركين'),
                \Filament\Navigation\NavigationGroup::make()
                     ->label('إدارة النظام'),
            ])
            ->renderHook(
                \Filament\View\PanelsRenderHook::HEAD_END,
                fn (): string => \Illuminate\Support\Facades\Blade::render('<style>
                    input[type="number"], input[type="tel"], input[type="email"], input[type="password"] { 
                        direction: ltr !important; 
                        text-align: left !important; 
                    }
                    /* For the phone input plugin container specifically if needed */
                    .iti { direction: ltr; }
                    .fi-logo {
                        height: auto !important;
                    }
                </style>
                <link rel="manifest" href="{{ asset(\'manifest.json?v=4\') }}">
                <meta name="theme-color" content="#f59e0b">
                <link rel="apple-touch-icon" href="{{ asset(\'icon-192x192.png?v=4\') }}">
                <script>
                    if ("serviceWorker" in navigator) {
                        window.addEventListener("load", function() {
                            navigator.serviceWorker.register("{{ asset(\'sw.js\') }}").then(function(registration) {
                                console.log("ServiceWorker registration successful");
                            }, function(err) {
                                console.log("ServiceWorker registration failed: ", err);
                            });
                        });
                    }
                </script>')
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    protected function getBrandName(): string
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return 'Water Project';
            }
            return \App\Models\Setting::get('invoice_project_name_ar') ?: \App\Models\Setting::get('invoice_project_name_en') ?: 'Water Project';
        } catch (\Exception $e) {
            return 'Water Project';
        }
    }

    protected function getBrandLogoHtml(): \Illuminate\Support\HtmlString
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return new \Illuminate\Support\HtmlString("<div class='text-xl font-bold'>Water Project</div>");
            }
            $logo = \App\Models\Setting::get('invoice_logo');
            $name = $this->getBrandName();
            $isLoginPage = str_contains(request()->path(), 'login');
            
            $logoHeight = $isLoginPage ? '40px' : '32px';
            $textSize = $isLoginPage ? 'text-xl' : 'text-md';
            
            if ($logo) {
                $fullPath = storage_path('app/private/' . $logo);
                if (!file_exists($fullPath)) {
                    $fullPath = storage_path('app/public/' . $logo);
                }
                
                if (file_exists($fullPath)) {
                    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
                    $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/' . $ext);
                    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
                    
                    $dividerHtml = $isLoginPage ? "<div style='width: 100%; border-top: 1px solid rgba(156, 163, 175, 0.3); margin-top: 16px; margin-bottom: 4px;'></div>" : "";
                    
                    return new \Illuminate\Support\HtmlString("
                        <div style='display: flex; flex-direction: column; align-items: center; width: 100%;'>
                            <div style='display: flex; flex-direction: row; align-items: center; justify-content: center; gap: 8px;'>
                                <img src='{$logoBase64}' alt='Logo' style='height: {$logoHeight}; width: auto; max-height: {$logoHeight};' />
                                <span class='{$textSize} font-bold tracking-tight text-gray-950 dark:text-white'>{$name}</span>
                            </div>
                            {$dividerHtml}
                        </div>
                    ");
                }
            }
            
            $dividerHtml = $isLoginPage ? "<div style='width: 100%; border-top: 1px solid rgba(156, 163, 175, 0.3); margin-top: 16px; margin-bottom: 4px;'></div>" : "";
            
            return new \Illuminate\Support\HtmlString("
                <div style='display: flex; flex-direction: column; align-items: center; width: 100%;'>
                    <span class='{$textSize} font-bold tracking-tight text-gray-950 dark:text-white'>{$name}</span>
                    {$dividerHtml}
                </div>
            ");
        } catch (\Exception $e) {
            return new \Illuminate\Support\HtmlString("<div class='text-xl font-bold'>Water Project</div>");
        }
    }

    private function getFavicon(): ?string
    {
        try {
            if (!\Illuminate\Support\Facades\Schema::hasTable('settings')) {
                return null;
            }
            $favicon = \App\Models\Setting::get('site_favicon');
            if ($favicon) {
                $fullPath = storage_path('app/private/' . $favicon);
                if (!file_exists($fullPath)) {
                    $fullPath = storage_path('app/public/' . $favicon);
                }
                
                if (file_exists($fullPath)) {
                    $ext = pathinfo($fullPath, PATHINFO_EXTENSION);
                    $mime = $ext === 'png' ? 'image/png' : ($ext === 'svg' ? 'image/svg+xml' : 'image/' . $ext);
                    return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($fullPath));
                }
            }
        } catch (\Exception $e) {
            return asset('icon-192x192.png?v=4');
        }
        return asset('icon-192x192.png?v=4');
    }
}
