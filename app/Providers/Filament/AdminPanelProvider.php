<?php

namespace App\Providers\Filament;

use App\Http\Middleware\AdminIpAllowlist;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\StaffIdleTimeout;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\FontProviders\LocalFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
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
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->path(config('portal.admin_path', 'admin'))
            ->login()
            ->passwordReset()
            // TOTP is mandatory for every staff account: users without it are
            // forced through set-up before any admin page loads.
            ->multiFactorAuthentication(
                [AppAuthentication::make()->recoverable()],
                isRequired: true,
            )
            ->brandName('WaveTeam Admin')
            ->defaultAvatarProvider(\App\Filament\AvatarProviders\InitialsAvatarProvider::class)
            ->brandLogo(asset('images/waveteam-lockup.png'))
            ->darkModeBrandLogo(asset('images/waveteam-lockup-dark.png'))
            ->brandLogoHeight('2.25rem')
            ->favicon(asset('images/favicon.png'))
            ->colors([
                // Explicit scales so shade 600 (buttons, links) is the exact logo
                // colour; Color::hex() would generate a lighter auto-palette.
                // Same values as the @theme tokens in resources/css/app.css.
                'primary' => [ // WaveTeam navy #0F58A2
                    50 => '#eef5fc', 100 => '#d6e6f7', 200 => '#aecdee', 300 => '#7aaee2', 400 => '#3f86cf',
                    500 => '#1a6bb8', 600 => '#0f58a2', 700 => '#0d4783', 800 => '#0c3a6a', 900 => '#0a2c50', 950 => '#061a31',
                ],
                'info' => [ // WaveTeam teal #5CC5C8
                    50 => '#effafa', 100 => '#d5f2f3', 200 => '#b0e6e7', 300 => '#84d6d8', 400 => '#5cc5c8',
                    500 => '#3aa9ad', 600 => '#2d878b', 700 => '#286d71', 800 => '#255a5d', 900 => '#224b4e', 950 => '#0f2f31',
                ],
                'success' => [ // WaveTeam green #7BC443
                    50 => '#f3faec', 100 => '#e3f3d3', 200 => '#c8e7aa', 300 => '#a5d677', 400 => '#7bc443',
                    500 => '#64a932', 600 => '#4d8625', 700 => '#3d6720', 800 => '#33531e', 900 => '#2c461d', 950 => '#14260b',
                ],
                'warning' => Color::Amber,
                'danger' => Color::Rose,
                'gray' => Color::Slate,
            ])
            ->font('Inter Variable, Noto Sans Sinhala Variable, Noto Sans Tamil Variable', provider: LocalFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->navigationGroups([
                'Applicants',
                'Recruitment',
                'Clients & Services',
                'Workforce',
                'Reports',
                'Administration',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->middleware([
                AdminIpAllowlist::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                // Before auth: a deactivated user's session is terminated, not just refused.
                EnsureAccountActive::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                StaffIdleTimeout::class,
            ]);
    }
}
