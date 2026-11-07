<?php

namespace App\Filament\Pages;

use App\Enums\PlanType;
use App\Support\Billing\Billing;
use App\Support\Settings;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Plan types in use, bank transfer details, and payment gateways (coming soon).
 */
class BillingSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 6;

    protected static ?string $title = 'Billing settings';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    public function mount(): void
    {
        $billing = app(Billing::class);

        $this->form->fill([
            'plan_types' => $billing->enabledTypes(),
            'bank' => $billing->bankTransfer(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Plan types')
                    ->description('Use only what your SaaS needs — disabled types are hidden from the plan form and from customers.')
                    ->schema([
                        CheckboxList::make('plan_types')
                            ->hiddenLabel()
                            ->options(collect(PlanType::cases())->mapWithKeys(fn (PlanType $type): array => [$type->value => $type->getLabel()])->all())
                            ->descriptions(collect(PlanType::cases())->mapWithKeys(fn (PlanType $type): array => [$type->value => $type->getDescription()])->all())
                            ->required()
                            ->minItems(1)
                            ->columns(3),
                        Text::make('Currency: '.Billing::currency().' (set FILALAUNCH_CURRENCY in .env) · Grace period: '.Billing::graceDays().' days after a period ends.'),
                    ]),

                Section::make('Bank transfer')
                    ->description('Customers pay to this account and upload the receipt. You approve the payment under Billing → Payments.')
                    ->columns(2)
                    ->schema([
                        Toggle::make('bank.enabled')
                            ->label('Accept bank transfers')
                            ->live()
                            ->columnSpanFull(),
                        TextInput::make('bank.account_name')
                            ->label('Account holder name')
                            ->required(fn (Get $get): bool => (bool) $get('bank.enabled')),
                        TextInput::make('bank.account_number')
                            ->label('Account number / IBAN')
                            ->required(fn (Get $get): bool => (bool) $get('bank.enabled')),
                        TextInput::make('bank.bank_name')
                            ->label('Bank name')
                            ->required(fn (Get $get): bool => (bool) $get('bank.enabled')),
                        TextInput::make('bank.routing_code')
                            ->label('IFSC / SWIFT / routing code'),
                        TextInput::make('bank.branch')
                            ->label('Branch / bank address'),
                        Textarea::make('bank.instructions')
                            ->label('Instructions for customers')
                            ->rows(3)
                            ->placeholder('Write the payment reference in the transfer description.')
                            ->columnSpanFull(),
                    ]),

                Section::make('Payment gateways')
                    ->description('Online payments are coming in a future update. Bank transfer is available today.')
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])
                            ->schema(collect(Billing::GATEWAYS)->map(fn (array $gateway, string $key): Section => Section::make($gateway['label'])
                                ->description('Coming soon')
                                ->icon(Heroicon::OutlinedClock)
                                ->compact()
                                ->disabled()
                                ->schema([
                                    Toggle::make("gateways.{$key}.enabled")->label('Enabled'),
                                    ...collect($gateway['fields'])->map(fn (string $label, string $field): TextInput => TextInput::make("gateways.{$key}.{$field}")
                                        ->label($label)
                                        ->password(in_array($field, $gateway['secret'], true)))
                                        ->values()
                                        ->all(),
                                ]))->values()->all()),
                    ]),
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
                    ]),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $settings = app(Settings::class);

        $settings->set('billing.plan_types', array_values($data['plan_types']));
        $settings->setGroup('billing.bank_transfer', array_intersect_key((array) $data['bank'], array_flip(Billing::BANK_FIELDS)));

        Notification::make()->success()->title('Billing settings saved')->send();
    }
}
