<?php

namespace App\Filament\Pages;

use App\Support\ModuleBuilder\ModuleBuilder as Builder;
use App\Support\ModuleBuilder\ModuleBuilderException;
use App\Support\ModuleBuilder\ModuleDefinition;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use UnitEnum;

/**
 * Super Admin page that generates a full module (migration, model, factory,
 * seeder, policy, Filament resource, permissions). Same engine as
 * `php artisan filalaunch:make-module`.
 */
class ModuleBuilder extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCubeTransparent;

    protected static string|UnitEnum|null $navigationGroup = 'Developer';

    protected static ?int $navigationSort = 1;

    protected static ?string $title = 'Module Builder';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return Builder::enabled() && (bool) auth()->user()?->isSuperAdmin();
    }

    public function getSubheading(): ?string
    {
        return 'Generate migration, model, factory, seeder and Filament resource. New permissions are created but not assigned.';
    }

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Model')
                    ->schema([
                        TextInput::make('model')
                            ->label('Model name')
                            ->placeholder('Post')
                            ->required()
                            ->maxLength(64)
                            ->live(onBlur: true)
                            ->helperText(function (?string $state): string {
                                $model = Str::of((string) $state)->trim()->singular()->studly()->toString();

                                return $model === ''
                                    ? 'Singular, e.g. Post or BlogPost.'
                                    : "Model App\\Models\\{$model} · table ".Str::snake(Str::pluralStudly($model));
                            }),
                    ]),

                Section::make('Fields')
                    ->description('id, timestamps and foreign keys are added automatically.')
                    ->schema([
                        Repeater::make('fields')
                            ->hiddenLabel()
                            ->schema([
                                TextInput::make('name')
                                    ->placeholder('title')
                                    ->required()
                                    ->regex('/^[a-z][a-z0-9_]*$/')
                                    ->validationMessages(['regex' => 'Use snake_case, e.g. published_at.']),
                                Select::make('type')
                                    ->options(array_combine(ModuleDefinition::FIELD_TYPES, array_map('ucfirst', ModuleDefinition::FIELD_TYPES)))
                                    ->default('string')
                                    ->required()
                                    ->live()
                                    ->native(false),
                                TextInput::make('default')
                                    ->placeholder('none')
                                    ->visible(fn (Get $get): bool => in_array($get('type'), ModuleDefinition::DEFAULTABLE_TYPES, true)),
                                Toggle::make('nullable')
                                    ->inline(false),
                                Toggle::make('unique')
                                    ->inline(false)
                                    ->visible(fn (Get $get): bool => ! in_array($get('type'), ['text', 'json', 'boolean'], true)),
                            ])
                            ->columns(['default' => 2, 'lg' => 5])
                            ->defaultItems(1)
                            ->minItems(1)
                            ->reorderable()
                            ->addActionLabel('Add field'),
                    ]),

                Section::make('Belongs to')
                    ->description('Adds a foreign key to this model, and optionally "has many" / "has one" to the related model.')
                    ->collapsible()
                    ->schema([
                        Repeater::make('relations')
                            ->hiddenLabel()
                            ->schema([
                                Select::make('related')
                                    ->label('Belongs to')
                                    ->options(fn (): array => ModuleDefinition::availableModels())
                                    ->required()
                                    ->searchable()
                                    ->distinct(),
                                Select::make('inverse')
                                    ->label('Related model gets')
                                    ->options(ModuleDefinition::INVERSE_TYPES)
                                    ->default('has_many')
                                    ->required()
                                    ->native(false),
                                Select::make('on_delete')
                                    ->label('When related is deleted')
                                    ->options(ModuleDefinition::ON_DELETE)
                                    ->default('cascade')
                                    ->required()
                                    ->live()
                                    ->afterStateUpdated(fn (?string $state, Set $set) => $state === 'null' ? $set('nullable', true) : null)
                                    ->native(false),
                                Toggle::make('nullable')
                                    ->label('Optional (nullable)')
                                    ->inline(false)
                                    ->disabled(fn (Get $get): bool => $get('on_delete') === 'null')
                                    ->dehydrated(),
                            ])
                            ->columns(['default' => 2, 'lg' => 4])
                            ->defaultItems(0)
                            ->addActionLabel('Add belongs-to'),
                    ]),

                Section::make('Options')
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        Toggle::make('soft_deletes')
                            ->label('Soft deletes')
                            ->helperText('Trash, restore and force delete.'),
                        Toggle::make('factory')
                            ->label('Factory')
                            ->default(true)
                            ->live()
                            ->disabled(fn (Get $get): bool => (bool) $get('seeder'))
                            ->dehydrated(),
                        Toggle::make('policy')
                            ->label('Policy file')
                            ->helperText('Only for custom rules — permissions are checked without it.'),
                        Toggle::make('seeder')
                            ->label('Seeder with fake data')
                            ->helperText('Also registered in DatabaseSeeder.')
                            ->live()
                            ->afterStateUpdated(fn (bool $state, Set $set) => $state ? $set('factory', true) : null),
                        TextInput::make('seed_count')
                            ->label('Records')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(10000)
                            ->default(10)
                            ->visible(fn (Get $get): bool => (bool) $get('seeder')),
                        Toggle::make('run_seeder')
                            ->label('Run seeder now')
                            ->default(true)
                            ->visible(fn (Get $get): bool => (bool) $get('seeder')),
                    ]),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->footer([
                    Actions::make([$this->generateAction()]),
                ]),
        ]);
    }

    protected function generateAction(): Action
    {
        return Action::make('generate')
            ->label('Generate module')
            ->icon(Heroicon::OutlinedSparkles)
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedCubeTransparent)
            ->modalHeading(fn (): string => 'Generate '.($this->definition()->model ?: 'module').'?')
            ->modalDescription(fn (): HtmlString => $this->planHtml())
            ->modalSubmitActionLabel('Generate')
            ->modalWidth('2xl')
            ->action(function (): void {
                $this->form->validate();

                try {
                    $result = app(Builder::class)->build($this->definition());
                } catch (ModuleBuilderException $e) {
                    Notification::make()
                        ->danger()
                        ->title('Module not generated')
                        ->body($this->listHtml($e->errors))
                        ->persistent()
                        ->send();

                    return;
                }

                $notification = Notification::make()
                    ->success()
                    ->title("Module {$this->definition()->model} generated")
                    ->body($this->listHtml([...$result['created'], ...array_map(fn (string $f): string => "Updated {$f}", $result['modified'])]))
                    ->persistent();

                if ($result['url']) {
                    $notification->actions([
                        Action::make('open')->label('Open')->url($result['url'])->button(),
                        Action::make('permissions')->label('Assign permissions')->url(url(filament()->getPanel('admin')->getPath().'/roles'))->link(),
                    ]);
                }

                $notification->send();

                foreach ($result['warnings'] as $warning) {
                    Notification::make()->warning()->title($warning)->persistent()->send();
                }

                $this->form->fill();
            });
    }

    protected function definition(): ModuleDefinition
    {
        return ModuleDefinition::fromArray($this->form->getRawState());
    }

    protected function planHtml(): HtmlString
    {
        $definition = $this->definition();
        $errors = $definition->errors();

        return $errors !== []
            ? new HtmlString('<strong>Please fix first:</strong>'.$this->listHtml($errors)->toHtml())
            : new HtmlString($this->listHtml(app(Builder::class)->plan($definition))->toHtml());
    }

    /**
     * @param  list<string>  $items
     */
    protected function listHtml(array $items): HtmlString
    {
        return new HtmlString(
            '<ul style="list-style:disc;padding-left:1.25rem;text-align:left;margin-top:.5rem">'
            .collect($items)->map(fn (string $item): string => '<li>'.e($item).'</li>')->implode('')
            .'</ul>'
        );
    }
}
