<?php

namespace App\Support;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * FilaLaunch options managed in System → Settings.
 *
 * Saved in the `settings` table (key "system") and mirrored to
 * storage/app/filalaunch/settings.json so they can be applied at boot —
 * before panels register — without a database query on every request.
 *
 * Precedence: saved setting → .env → config default. Secrets are stored encrypted
 * with APP_KEY and never sent back to the browser.
 */
class SystemSettings
{
    /**
     * Setting key => [config path, type].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    public const MAP = [
        'app_name' => ['app.name', 'string'],
        'mail_mailer' => ['mail.default', 'string'], // log | smtp
        'mail_host' => ['mail.mailers.smtp.host', 'string'],
        'mail_port' => ['mail.mailers.smtp.port', 'int'],
        'mail_username' => ['mail.mailers.smtp.username', 'string'],
        'mail_password' => ['mail.mailers.smtp.password', 'secret'],
        'mail_scheme' => ['mail.mailers.smtp.scheme', 'string'], // '' (auto/STARTTLS) | smtps
        'mail_from_address' => ['mail.from.address', 'string'],
        'mail_from_name' => ['mail.from.name', 'string'],
        'app_panel' => ['filalaunch.panels.app.enabled', 'bool'],
        'tenancy' => ['filalaunch.tenancy.enabled', 'bool'],
        'tenant_label' => ['filalaunch.tenancy.label', 'string'],
        'tenant_billing' => ['filalaunch.tenancy.billing', 'string'],
        'invite_days' => ['filalaunch.tenancy.invite_days', 'int'],
        'currency' => ['filalaunch.billing.currency', 'string'],
        'grace_days' => ['filalaunch.billing.grace_days', 'int'],
        'trash_days' => ['filalaunch.builder.trash_days', 'int'],
        'builder' => ['filalaunch.builder.mode', 'string'], // auto | on | off
    ];

    /**
     * Apply saved settings to config. Called early in AppServiceProvider::register().
     */
    public static function apply(): void
    {
        $values = static::load();

        foreach (static::MAP as $key => [$path, $type]) {
            if (array_key_exists($key, $values) && $values[$key] !== null && $values[$key] !== '') {
                config([$path => $type === 'secret' ? static::decrypt($values[$key]) : static::cast($values[$key], $type)]);
            }
        }

        // Module Builder: auto = on only in local.
        $mode = config('filalaunch.builder.mode');

        if (in_array($mode, ['on', 'off'], true)) {
            config(['filalaunch.builder.enabled' => $mode === 'on']);
        }

        // Teams live in the customer panel.
        if (config('filalaunch.tenancy.enabled')) {
            config(['filalaunch.panels.app.enabled' => true]);
        }
    }

    /**
     * Current effective values (for the Settings page).
     *
     * @return array<string, mixed>
     */
    public static function current(): array
    {
        $values = [];

        foreach (static::MAP as $key => [$path, $type]) {
            $values[$key] = $type === 'secret' ? null : config($path); // secrets are never sent back to the browser
        }

        $values['builder'] ??= 'auto';

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        $clean = static::stored();

        foreach (static::MAP as $key => [, $type]) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            if ($type === 'secret') {
                // Empty = keep the saved secret.
                if (filled($values[$key])) {
                    $clean[$key] = Crypt::encryptString((string) $values[$key]);
                }

                continue;
            }

            $clean[$key] = static::cast($values[$key], $type);
        }

        DB::table('settings')->updateOrInsert(
            ['key' => 'system'],
            ['value' => json_encode($clean), 'updated_at' => now(), 'created_at' => now()],
        );

        static::writeCache($clean);
        static::apply();
    }

    public static function hasSecret(string $key): bool
    {
        return filled(static::stored()[$key] ?? null);
    }

    /**
     * Raw saved values (secrets still encrypted).
     *
     * @return array<string, mixed>
     */
    protected static function stored(): array
    {
        try {
            $value = DB::table('settings')->where('key', 'system')->value('value');
        } catch (Throwable) {
            return [];
        }

        return $value ? (json_decode($value, true) ?: []) : [];
    }

    protected static function decrypt(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            return null; // APP_KEY changed — re-enter the secret in Settings
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected static function load(): array
    {
        $file = static::cachePath();

        if (is_file($file)) {
            return json_decode((string) file_get_contents($file), true) ?: [];
        }

        // First request after deploy / fresh clone: read the database once and cache it.
        $values = static::stored();
        rescue(fn () => static::writeCache($values), report: false);

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    protected static function writeCache(array $values): void
    {
        File::ensureDirectoryExists(dirname(static::cachePath()));
        File::put(static::cachePath(), json_encode($values, JSON_PRETTY_PRINT));
    }

    public static function forgetCache(): void
    {
        File::delete(static::cachePath());
    }

    protected static function cachePath(): string
    {
        return storage_path('app/filalaunch/settings.json');
    }

    protected static function cast(mixed $value, string $type): mixed
    {
        return match ($type) {
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'int' => (int) $value,
            default => trim((string) $value),
        };
    }
}
