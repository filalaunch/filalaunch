<?php

namespace App\Filament\Pages\Auth;

use App\Models\Role;
use App\Models\TeamInvitation;
use App\Support\Teams\Teams;
use Filament\Auth\Pages\Register as BaseRegister;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use SensitiveParameter;

class Register extends BaseRegister
{
    public function mount(): void
    {
        parent::mount();

        // Coming from a team invitation link: prefill the invited email.
        if ($invitation = $this->pendingInvitation()) {
            $this->form->fill(['email' => $invitation->email]);
        }
    }

    /**
     * Create the user and assign the default role (config: filalaunch.roles.default).
     * With teams on: join the inviting team, or get a personal team.
     * Runs inside Filament's registration transaction.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRegistration(#[SensitiveParameter] array $data): Model
    {
        $user = parent::handleRegistration($data);

        $user->assignRole(Role::findOrCreate(config('filalaunch.roles.default'), 'web'));

        if (Teams::enabled()) {
            $invitation = $this->pendingInvitation();

            if ($invitation && Str::lower($invitation->email) === Str::lower($user->email)) {
                app(Teams::class)->accept($invitation, $user);
                session()->forget('team_invitation');
            } else {
                app(Teams::class)->createPersonal($user);
            }
        }

        return $user;
    }

    protected function pendingInvitation(): ?TeamInvitation
    {
        if (! Teams::enabled() || ! ($token = session('team_invitation'))) {
            return null;
        }

        $invitation = TeamInvitation::findByToken($token);

        return $invitation?->isPending() ? $invitation : null;
    }
}
