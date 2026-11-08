<?php

namespace App\Providers\Filament;

use App\Filament\App\Tenancy\EditTeam;
use App\Filament\App\Tenancy\RegisterTeam;
use App\Filament\Pages\Auth\Register;
use App\Filament\Pages\MyPlan;
use App\Models\Team;
use App\Support\Permissions\PermissionSync;
use App\Support\Teams\Teams;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\AccountWidget;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Customer panel (/app). Registered only when enabled in System → Settings.
 * With teams on, it becomes multi-tenant: /app/{team}/…
 */
class AppPanelProvider extends PanelProvider
{
    public function register(): void
    {
        if (! Teams::appPanelEnabled()) {
            return; // nothing is loaded when the customer panel is off
        }

        parent::register();
    }

    public function panel(Panel $panel): Panel
    {
        $planLabel = Teams::teamBilling() ? Teams::label().' plan' : 'My plan';

        return $panel
            ->id('app')
            ->path((string) config('filalaunch.panels.app.path', 'app'))
            ->login()
            ->registration(Register::class)
            ->passwordReset()
            ->emailVerification()
            ->profile(isSimple: false)
            ->userMenuItems([
                'my-plan' => Action::make('myPlan')
                    ->label($planLabel)
                    ->icon(Heroicon::OutlinedCreditCard)
                    ->url(fn (): string => MyPlan::getUrl()),
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ])
            ->when(Teams::enabled(), fn (Panel $panel) => $panel
                ->tenant(Team::class, slugAttribute: 'slug', ownershipRelationship: 'team')
                ->tenantRegistration(RegisterTeam::class)
                ->tenantProfile(EditTeam::class))
            ->strictAuthorization()
            ->bootUsing(fn (Panel $panel) => app(PermissionSync::class)->ensureSynced($panel->getId()))
            ->colors([
                'primary' => Color::Amber,
            ])
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->pages([
                Dashboard::class,
                MyPlan::class,
            ])
            ->widgets([
                AccountWidget::class,
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
                Authenticate::class,
            ]);
    }
}
