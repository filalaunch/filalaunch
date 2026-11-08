<?php

namespace App\Filament\Resources\Teams\Tables;

use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Teams\Teams;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class TeamsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->with(['owner', 'subscriptions' => fn ($q) => $q->active()->with('plan')])
                ->withCount('members'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->weight('medium')
                    ->description(fn (Team $record): string => $record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('owner.name')
                    ->label('Owner')
                    ->description(fn (Team $record): ?string => $record->owner?->email)
                    ->placeholder('—'),
                TextColumn::make('members_count')
                    ->label('Members')
                    ->numeric()
                    ->sortable(),
                TextColumn::make('plans')
                    ->label('Active plan')
                    ->badge()
                    ->state(fn (Team $record): array => $record->subscriptions->pluck('plan.name')->filter()->unique()->values()->all())
                    ->placeholder('None')
                    ->visible(fn (): bool => Teams::teamBilling()),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->since()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('members')
                    ->icon(Heroicon::OutlinedUsers)
                    ->color('gray')
                    ->modalHeading(fn (Team $record): string => "{$record->name} — members")
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->schema([
                        RepeatableEntry::make('members')
                            ->hiddenLabel()
                            ->state(fn (Team $record): array => $record->members()->orderBy('name')->get()
                                ->map(fn (User $user): array => [
                                    'name' => $user->name,
                                    'email' => $user->email,
                                    'role' => $record->roleOf($user),
                                ])->all())
                            ->schema([
                                TextEntry::make('name')->hiddenLabel()->weight('medium'),
                                TextEntry::make('email')->hiddenLabel()->color('gray'),
                                TextEntry::make('role')->hiddenLabel()->badge(),
                            ])
                            ->columns(3),
                        RepeatableEntry::make('invitations')
                            ->label('Pending invitations')
                            ->state(fn (Team $record): array => $record->invitations()->pending()->get()
                                ->map(fn (TeamInvitation $invitation): array => [
                                    'email' => $invitation->email,
                                    'role' => $invitation->role,
                                    'expires' => $invitation->expires_at?->diffForHumans(),
                                ])->all())
                            ->schema([
                                TextEntry::make('email')->hiddenLabel(),
                                TextEntry::make('role')->hiddenLabel()->badge(),
                                TextEntry::make('expires')->hiddenLabel()->color('gray')->prefix('expires '),
                            ])
                            ->columns(3)
                            ->visible(fn (Team $record): bool => $record->invitations()->pending()->exists()),
                    ]),
                DeleteAction::make()
                    ->modalDescription(fn (Team $record): string => 'Deletes the '.strtolower(Teams::label()).', its memberships, invitations, subscriptions and every row it owns in team modules. This can\'t be undone.')
                    ->using(function (Team $record): bool {
                        try {
                            app(Teams::class)->delete($record);
                        } catch (RuntimeException $e) {
                            Notification::make()->danger()->title('Not deleted')->body($e->getMessage())->send();

                            return false;
                        }

                        return true;
                    }),
            ]);
    }
}
