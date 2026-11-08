<?php

namespace App\Http\Controllers\Teams;

use App\Http\Controllers\Controller;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Teams\Teams;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * /team-invitations/{token}
 *  - signed in with the invited email → join and open the team
 *  - has an account → log in, then come back here
 *  - no account → register with the invited email (joins automatically)
 */
class AcceptTeamInvitationController extends Controller
{
    public function __invoke(Request $request, string $token): RedirectResponse
    {
        abort_unless(Teams::enabled(), 404);

        $panel = Filament::getPanel('app');
        $invitation = TeamInvitation::findByToken($token);

        if (! $invitation?->isPending()) {
            Notification::make()->danger()->title('This invitation is invalid or has expired.')->send();

            return redirect()->to($request->user() ? $panel->getUrl() : $panel->getLoginUrl());
        }

        $user = $request->user();

        if (! $user) {
            $request->session()->put('team_invitation', $token);

            return User::query()->where('email', $invitation->email)->exists()
                ? redirect()->guest($panel->getLoginUrl())
                : redirect()->to($panel->getRegistrationUrl());
        }

        try {
            $team = app(Teams::class)->accept($invitation, $user);
        } catch (RuntimeException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();

            return redirect()->to($panel->getUrl());
        }

        $request->session()->forget('team_invitation');
        Notification::make()->success()->title("You joined {$team->name}")->send();

        return redirect()->to($panel->getUrl($team));
    }
}
