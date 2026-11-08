<?php

namespace App\Filament\Pages;

use App\Models\Subscription;
use App\Models\Team;
use App\Support\ModuleBuilder\Concerns\RefreshesAppCaches;
use App\Support\SystemSettings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;
use Throwable;
use UnitEnum;

/**
 * System → Settings: everything a kit user would otherwise put in .env.
 * Saved values override .env (see App\Support\SystemSettings).
 */
class SystemSettingsPage extends Page
{
    use RefreshesAppCaches;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'settings';

    protected static ?string $title = 'Settings';

    public const CURRENCIES = [
        'USD' => 'USD — US dollar', 'EUR' => 'EUR — Euro', 'GBP' => 'GBP — British pound', 'INR' => 'INR — Indian rupee',
        'CAD' => 'CAD — Canadian dollar', 'AUD' => 'AUD — Australian dollar', 'NZD' => 'NZD — New Zealand dollar',
        'SGD' => 'SGD — Singapore dollar', 'AED' => 'AED — UAE dirham', 'SAR' => 'SAR — Saudi riyal', 'CHF' => 'CHF — Swiss franc',
        'SEK' => 'SEK — Swedish krona', 'NOK' => 'NOK — Norwegian krone', 'DKK' => 'DKK — Danish krone', 'PLN' => 'PLN — Polish złoty',
        'JPY' => 'JPY — Japanese yen', 'CNY' => 'CNY — Chinese yuan', 'HKD' => 'HKD — Hong Kong dollar', 'MYR' => 'MYR — Malaysian ringgit',
        'IDR' => 'IDR — Indonesian rupiah', 'PHP' => 'PHP — Philippine peso', 'THB' => 'THB — Thai baht', 'BDT' => 'BDT — Bangladeshi taka',
        'PKR' => 'PKR — Pakistani rupee', 'NPR' => 'NPR — Nepalese rupee', 'LKR' => 'LKR — Sri Lankan rupee', 'ZAR' => 'ZAR — South African rand',
        'NGN' => 'NGN — Nigerian naira', 'KES' => 'KES — Kenyan shilling', 'EGP' => 'EGP — Egyptian pound', 'BRL' => 'BRL — Brazilian real',
        'MXN' => 'MXN — Mexican peso', 'TRY' => 'TRY — Turkish lira',
    ];

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Saved here, applied everywhere — no .env editing needed. Values in .env are only the starting defaults.';
    }

    public function mount(): void
    {
        $this->form->fill(SystemSettings::current());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Tabs::make('settings')
                    ->persistTabInQueryString()
                    ->tabs([
                        $this->generalTab(),
                        $this->mailTab(),
                        $this->customersTab(),
                        $this->billingTab(),
                        $this->developerTab(),
                    ]),
            ]);
    }

    protected function generalTab(): Tab
    {
        return Tab::make('General')
            ->icon(Heroicon::OutlinedGlobeAlt)
            ->schema([
                Section::make('Application')
                    ->description('Shown in the browser title, panel brand and emails.')
                    ->schema([
                        TextInput::make('app_name')
                            ->label('App name')
                            ->required()
                            ->maxLength(60),
                    ]),
            ]);
    }

    protected function mailTab(): Tab
    {
        $smtp = fn (Get $get): bool => $get('mail_mailer') === 'smtp';

        return Tab::make('Mail')
            ->icon(Heroicon::OutlinedEnvelope)
            ->schema([
                Section::make('Delivery')
                    ->description('Verification, password reset and team invitation emails.')
                    ->columns(2)
                    ->schema([
                        Radio::make('mail_mailer')
                            ->label('Send emails with')
                            ->options([
                                'log' => 'Log only (development)',
                                'smtp' => 'SMTP server',
                            ])
                            ->descriptions([
                                'log' => 'Emails are written to storage/logs/laravel.log — nothing is sent.',
                                'smtp' => 'Gmail, Mailgun, Postmark, SES, Brevo, Zoho, your host…',
                            ])
                            ->default('log')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('mail_host')
                            ->label('SMTP host')
                            ->placeholder('smtp.example.com')
                            ->required($smtp)
                            ->visible($smtp),
                        TextInput::make('mail_port')
                            ->label('Port')
                            ->numeric()
                            ->integer()
                            ->placeholder('587')
                            ->required($smtp)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn ($state, Set $set) => (int) $state === 465 ? $set('mail_scheme', 'smtps') : null)
                            ->visible($smtp),
                        TextInput::make('mail_username')
                            ->label('Username')
                            ->autocomplete('off')
                            ->visible($smtp),
                        TextInput::make('mail_password')
                            ->label('Password')
                            ->password()
                            ->revealable()
                            ->autocomplete('new-password')
                            ->placeholder(fn (): string => SystemSettings::hasSecret('mail_password') ? '•••••••• saved — leave empty to keep' : '')
                            ->helperText('Stored encrypted.')
                            ->visible($smtp),
                        Select::make('mail_scheme')
                            ->label('Encryption')
                            ->options(['' => 'STARTTLS / auto (port 587, 25, 2525)', 'smtps' => 'SSL/TLS (port 465)'])
                            ->selectablePlaceholder(false)
                            ->default('')
                            ->visible($smtp),
                    ]),
                Section::make('Sender')
                    ->columns(2)
                    ->schema([
                        TextInput::make('mail_from_address')
                            ->label('From address')
                            ->email()
                            ->required(),
                        TextInput::make('mail_from_name')
                            ->label('From name')
                            ->required()
                            ->maxLength(60),
                    ]),
            ]);
    }

    protected function customersTab(): Tab
    {
        $teamsExist = rescue(fn (): bool => Team::query()->exists(), false, report: false);

        return Tab::make('Customers & teams')
            ->icon(Heroicon::OutlinedUserGroup)
            ->schema([
                Section::make('Customer panel')
                    ->description('A separate panel at /app for your customers. When on, /admin is for staff only.')
                    ->schema([
                        Toggle::make('app_panel')
                            ->label('Enable customer panel (/app)')
                            ->live()
                            ->disabled(fn (Get $get): bool => (bool) $get('tenancy'))
                            ->dehydrated()
                            ->helperText(fn (Get $get): string => $get('tenancy')
                                ? 'Required while teams are on.'
                                : 'Staff keep /admin access with the Super Admin role or the admin.access permission (the seeded admin role has it). Everyone else is sent to /app.'),
                    ]),
                Section::make('Teams')
                    ->description('Customers work in teams inside the customer panel. Data in team modules is isolated per team.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('tenancy')
                            ->label('Enable teams')
                            ->live()
                            ->afterStateUpdated(fn (bool $state, Set $set) => $state ? $set('app_panel', true) : null)
                            ->helperText($teamsExist
                                ? 'Teams already exist — turning this off hides them but keeps their data.'
                                : 'Every new customer gets a personal team on sign-up and can invite others.')
                            ->columnSpanFull(),
                        TextInput::make('tenant_label')
                            ->label('Call them')
                            ->placeholder('Team')
                            ->helperText('Singular, e.g. Team, Workspace, Organization, Company.')
                            ->required()
                            ->maxLength(30)
                            ->alphaDash()
                            ->visible(fn (Get $get): bool => (bool) $get('tenancy')),
                        TextInput::make('invite_days')
                            ->label('Invitations expire after')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(60)
                            ->suffix('days')
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('tenancy')),
                        Radio::make('tenant_billing')
                            ->label('Who buys plans?')
                            ->options([
                                'team' => 'The team (recommended)',
                                'user' => 'Each user',
                            ])
                            ->descriptions([
                                'team' => 'Owners and admins buy a plan once; every member gets its access.',
                                'user' => 'Every user buys their own plan, whatever team they are in.',
                            ])
                            ->helperText(fn (): ?string => rescue(fn (): bool => Subscription::query()->exists(), false, report: false)
                                ? 'Existing subscriptions keep their owner — switching only changes who buys from now on.'
                                : null)
                            ->required()
                            ->visible(fn (Get $get): bool => (bool) $get('tenancy'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    protected function billingTab(): Tab
    {
        return Tab::make('Billing')
            ->icon(Heroicon::OutlinedBanknotes)
            ->schema([
                Section::make('Money')
                    ->description('Plan types, bank details and gateways are under Billing → Billing settings.')
                    ->columns(2)
                    ->schema([
                        Select::make('currency')
                            ->label('Default currency')
                            ->options(self::CURRENCIES)
                            ->searchable()
                            ->required()
                            ->helperText('Used for new plans. Existing plans keep their own currency.'),
                        TextInput::make('grace_days')
                            ->label('Grace period')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(60)
                            ->suffix('days')
                            ->required()
                            ->helperText('Access continues this long after a paid period ends, so customers can renew.'),
                    ]),
            ]);
    }

    protected function developerTab(): Tab
    {
        return Tab::make('Developer')
            ->icon(Heroicon::OutlinedCommandLine)
            ->schema([
                Section::make('Module Builder')
                    ->description('Generates code into this app. Keep it off on a live server unless you deploy from it.')
                    ->columns(2)
                    ->schema([
                        Radio::make('builder')
                            ->label('Module Builder & Modules page')
                            ->options([
                                'auto' => 'Automatic — on in local, off elsewhere',
                                'on' => 'Always on',
                                'off' => 'Always off',
                            ])
                            ->required(),
                        TextInput::make('trash_days')
                            ->label('Keep deleted modules in trash')
                            ->numeric()
                            ->integer()
                            ->minValue(0)
                            ->maxValue(365)
                            ->suffix('days')
                            ->required()
                            ->helperText('0 = delete permanently, no trash.'),
                    ]),
                Text::make('Environment: '.app()->environment().' · Debug: '.(config('app.debug') ? 'on' : 'off').' — these stay in .env (APP_ENV, APP_DEBUG).')
                    ->color('gray'),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('save')
                ->footer([
                    Actions::make([
                        Action::make('save')->label('Save settings')->submit('save'),
                        $this->testMailAction(),
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        if (! empty($data['tenancy'])) {
            $data['app_panel'] = true;
        }

        $before = SystemSettings::current();
        SystemSettings::save($data);

        // Panels / resources may have appeared or disappeared.
        $warnings = $this->refreshAppCaches();

        Notification::make()->success()->title('Settings saved')->send();

        foreach ($warnings as $warning) {
            Notification::make()->warning()->title($warning)->persistent()->send();
        }

        if (! $before['app_panel'] && config('filalaunch.panels.app.enabled')) {
            Notification::make()
                ->info()
                ->title('Customer panel is on')
                ->body('Customers now sign in at /'.config('filalaunch.panels.app.path').'. Give staff the admin.access permission to keep /admin.')
                ->persistent()
                ->send();
        }

        // Full reload so the sidebar and panels pick up the change.
        $this->redirect(static::getUrl(['tab' => request()->query('tab')]));
    }

    protected function testMailAction(): Action
    {
        return Action::make('testMail')
            ->label('Send test email')
            ->icon(Heroicon::OutlinedPaperAirplane)
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading('Send a test email')
            ->modalDescription(fn (): string => 'Uses the saved mail settings (save first). Sends to '.auth()->user()->email.'.')
            ->action(function (): void {
                try {
                    Mail::raw('This is a test email from '.config('app.name').'. Your mail settings work.', function ($message): void {
                        $message->to(auth()->user()->email)->subject(config('app.name').' — test email');
                    });
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Email not sent')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(config('mail.default') === 'log' ? 'Written to the log' : 'Test email sent')
                    ->body(config('mail.default') === 'log' ? 'Mail is set to “Log only” — check storage/logs/laravel.log.' : 'Check the inbox of '.auth()->user()->email.'.')
                    ->send();
            });
    }
}
