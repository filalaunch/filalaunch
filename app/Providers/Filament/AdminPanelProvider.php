<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\MyPlan;
use App\Http\Middleware\RedirectCustomersToAppPanel;
use App\Support\Permissions\PermissionSync;
use App\Support\Teams\Teams;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
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
        // With the customer panel on, sign-up and "My plan" move to /app and /admin is staff-only.
        $customersHere = ! Teams::appPanelEnabled();

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->when($customersHere, fn (Panel $panel) => $panel
                ->registration(Register::class)
                ->userMenuItems([
                    'my-plan' => Action::make('myPlan')
                        ->label('My plan')
                        ->icon(Heroicon::OutlinedCreditCard)
                        ->url(fn (): string => MyPlan::getUrl()),
                ]))
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ])
            // Fail loudly if an ability has no policy method (instead of silently allowing).
            ->strictAuthorization()
            // Auto-create "{resource}.{ability}" permissions for new resources (never assigns).
            ->bootUsing(fn (Panel $panel) => app(PermissionSync::class)->ensureSynced($panel->getId()))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                RedirectCustomersToAppPanel::class, // Filament Authenticate + customers → /app
            ]);
    }
}
