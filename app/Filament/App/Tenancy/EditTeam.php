<?php

namespace App\Filament\App\Tenancy;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Support\Teams\Teams;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;
use Throwable;

/**
 * Team settings: name & URL, members, invitations, ownership, leave / delete.
 * Members can see it; owners and admins manage it.
 *
 * @property Team $tenant
 */
class EditTeam extends EditTenantProfile implements HasTable
{
    use InteractsWithTable;

    public static function getLabel(): string
    {
        return Teams::label().' settings';
    }

    public static function canView(Model $tenant): bool
    {
        return (bool) auth()->user()?->canAccessTenant($tenant);
    }

    protected function canManage(): bool
    {
        return auth()->user()->canManageTeam($this->tenant);
    }

    protected function isOwner(): bool
    {
        return $this->tenant->owner_id === auth()->id();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(Teams::label())
                    ->columns(2)
                    ->disabled(fn (): bool => ! $this->canManage())
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(100),
                        TextInput::make('slug')
                            ->label('URL')
                            ->prefix(url(config('filalaunch.panels.app.path', 'app')).'/')
                            ->required()
                            ->alphaDash()
                            ->maxLength(100)
                            ->rule(fn (): Unique => Rule::unique('teams', 'slug')->ignore($this->tenant->getKey())),
                    ]),
            ]);
    }

    public function save(): void
    {
        abort_unless($this->canManage(), 403);

        parent::save();
    }

    protected function getFormActions(): array
    {
        return $this->canManage() ? parent::getFormActions() : [];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFormContentComponent(),
            EmbeddedTable::make(),
        ]);
    }

    // ── Members & invitations ────────────────────────────────────────

    public function table(Table $table): Table
    {
        return $table
            ->heading('Members')
            ->description(fn (): string => $this->canManage()
                ? 'Owners and admins manage members, invitations and billing. Members use the '.Str::lower(Teams::label()).'\'s modules.'
                : 'Ask an owner or admin to invite people or change roles.')
            ->records(fn (): array => $this->memberRecords())
            ->paginated(false)
            ->columns([
                TextColumn::make('name')
                    ->weight('medium')
                    ->description(fn (array $record): string => $record['email']),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => TeamRole::from($state)->getLabel())
                    ->color(fn (string $state): string => TeamRole::from($state)->getColor()),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => $state === 'Member' ? 'success' : 'warning')
                    ->description(fn (array $record): ?string => $record['note']),
            ])
            ->headerActions([
                Action::make('invite')
                    ->label('Invite member')
                    ->icon(Heroicon::OutlinedEnvelope)
                    ->visible(fn (): bool => $this->canManage())
                    ->modalWidth('lg')
                    ->modalSubmitActionLabel('Send invitation')
                    ->schema([
                        TextInput::make('email')->email()->required(),
                        Select::make('role')->options(TeamRole::assignable())->default(TeamRole::Member->value)->required(),
                    ])
                    ->action(fn (array $data) => $this->attempt(
                        fn () => app(Teams::class)->invite($this->tenant, $data['email'], TeamRole::from($data['role']), auth()->user()),
                        "Invitation sent to {$data['email']}",
                    )),
                Action::make('transfer')
                    ->label('Transfer ownership')
                    ->icon(Heroicon::OutlinedKey)
                    ->color('gray')
                    ->visible(fn (): bool => $this->isOwner() && $this->tenant->members()->count() > 1)
                    ->schema([
                        Select::make('user_id')
                            ->label('New owner')
                            ->options(fn (): array => $this->tenant->members()->whereKeyNot(auth()->id())->pluck('name', 'users.id')->all())
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription('You become an admin. The new owner can delete the '.Str::lower(Teams::label()).'.')
                    ->action(fn (array $data) => $this->attempt(
                        fn () => app(Teams::class)->transferOwnership($this->tenant, User::query()->findOrFail($data['user_id'])),
                        'Ownership transferred',
                    )),
                Action::make('leave')
                    ->label('Leave')
                    ->icon(Heroicon::OutlinedArrowRightStartOnRectangle)
                    ->color('gray')
                    ->visible(fn (): bool => ! $this->isOwner())
                    ->requiresConfirmation()
                    ->action(function (): void {
                        $this->attempt(fn () => app(Teams::class)->leave($this->tenant, auth()->user()), 'You left '.$this->tenant->name, Filament::getPanel('app')->getUrl());
                    }),
                Action::make('delete')
                    ->label('Delete '.Str::lower(Teams::label()))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->visible(fn (): bool => $this->isOwner())
                    ->requiresConfirmation()
                    ->modalDescription('All of this '.Str::lower(Teams::label()).'\'s data is deleted permanently.')
                    ->schema([
                        TextInput::make('confirm')
                            ->label('Type the '.Str::lower(Teams::label()).' name to confirm')
                            ->required()
                            ->in(fn (): array => [$this->tenant->name]),
                    ])
                    ->action(function (): void {
                        $this->attempt(fn () => app(Teams::class)->delete($this->tenant), Teams::label().' deleted', Filament::getPanel('app')->getUrl());
                    }),
            ])
            ->recordActions([
                Action::make('role')
                    ->label('Change role')
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $this->canManage() && $record['type'] === 'member' && $record['role'] !== TeamRole::Owner->value)
                    ->fillForm(fn (array $record): array => ['role' => $record['role']])
                    ->schema([Select::make('role')->options(TeamRole::assignable())->required()])
                    ->action(fn (array $record, array $data) => $this->attempt(
                        fn () => app(Teams::class)->changeRole($this->tenant, User::query()->findOrFail($record['id']), TeamRole::from($data['role'])),
                        'Role updated',
                    )),
                Action::make('remove')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->visible(fn (array $record): bool => $this->canManage() && $record['type'] === 'member' && $record['role'] !== TeamRole::Owner->value && $record['id'] !== auth()->id())
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->attempt(
                        fn () => app(Teams::class)->remove($this->tenant, User::query()->findOrFail($record['id'])),
                        'Member removed',
                    )),
                Action::make('resend')
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->color('gray')
                    ->visible(fn (array $record): bool => $this->canManage() && $record['type'] === 'invitation')
                    ->action(fn (array $record) => $this->attempt(
                        fn () => app(Teams::class)->resend(TeamInvitation::query()->where('team_id', $this->tenant->id)->findOrFail($record['id'])),
                        'Invitation sent again',
                    )),
                Action::make('revoke')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (array $record): bool => $this->canManage() && $record['type'] === 'invitation')
                    ->requiresConfirmation()
                    ->action(fn (array $record) => $this->attempt(
                        fn () => app(Teams::class)->revoke(TeamInvitation::query()->where('team_id', $this->tenant->id)->findOrFail($record['id'])),
                        'Invitation revoked',
                    )),
            ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function memberRecords(): array
    {
        $records = [];

        foreach ($this->tenant->members()->orderBy('name')->get() as $member) {
            $records["member-{$member->id}"] = [
                'key' => "member-{$member->id}",
                'type' => 'member',
                'id' => $member->id,
                'name' => $member->name.($member->id === auth()->id() ? ' (you)' : ''),
                'email' => $member->email,
                'role' => $member->pivot->role,
                'status' => 'Member',
                'note' => null,
            ];
        }

        if ($this->canManage()) {
            foreach ($this->tenant->invitations()->pending()->latest()->get() as $invitation) {
                $records["invitation-{$invitation->id}"] = [
                    'key' => "invitation-{$invitation->id}",
                    'type' => 'invitation',
                    'id' => $invitation->id,
                    'name' => '—',
                    'email' => $invitation->email,
                    'role' => $invitation->role->value,
                    'status' => 'Invited',
                    'note' => 'Expires '.$invitation->expires_at->toFormattedDateString(),
                ];
            }
        }

        return $records;
    }

    protected function attempt(callable $callback, string $success, ?string $redirect = null): void
    {
        try {
            $callback();
            Notification::make()->success()->title($success)->send();

            if ($redirect) {
                $this->redirect($redirect);
            }
        } catch (Throwable $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }
    }
}
