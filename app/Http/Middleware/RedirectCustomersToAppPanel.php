<?php

namespace App\Http\Middleware;

use App\Support\Teams\Teams;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Filament's Authenticate for /admin. With the customer panel on, a signed-in
 * non-staff user goes to /app instead of a 403.
 *
 * (Extends Authenticate because Laravel always runs auth middleware first.)
 */
class RedirectCustomersToAppPanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $user = Filament::auth()->user();

        if ($user && Teams::appPanelEnabled() && method_exists($user, 'isStaff') && ! $user->isStaff()) {
            throw new HttpResponseException(redirect()->to(Filament::getPanel('app')->getUrl()));
        }

        parent::authenticate($request, $guards);
    }
}
