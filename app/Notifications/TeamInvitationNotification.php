<?php

namespace App\Notifications;

use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TeamInvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public TeamInvitation $invitation, public string $token) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $team = $this->invitation->team;
        $label = strtolower(Team::label());
        $inviter = $this->invitation->inviter?->name ?? 'Someone';

        return (new MailMessage)
            ->subject("You're invited to join {$team->name} on ".config('app.name'))
            ->greeting('Hello!')
            ->line("{$inviter} invited you to join the {$label} **{$team->name}** as {$this->invitation->role->getLabel()}.")
            ->action('Accept invitation', route('team-invitations.accept', $this->token))
            ->line('The link expires on '.$this->invitation->expires_at->toFormattedDateString().'. If you don\'t have an account yet, you can create one with this email address.')
            ->line('If you weren\'t expecting this invitation, you can ignore this email.');
    }
}
