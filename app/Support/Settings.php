<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Settings saved from the admin panel (settings table). Values are JSON;
 * fields listed as secret are stored encrypted with the app key.
 */
class Settings
{
    /** @var array<string, mixed> */
    protected array $cache = [];

    public function get(string $key, mixed $default = null): mixed
    {
        if (! array_key_exists($key, $this->cache)) {
            $this->cache[$key] = rescue(fn () => Setting::query()->where('key', $key)->value('value'), null, report: false);

            if (is_string($this->cache[$key])) {
                $this->cache[$key] = json_decode($this->cache[$key], true);
            }
        }

        return $this->cache[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->cache[$key] = $value;
    }

    /**
     * @param  list<string>  $secret  fields to decrypt
     * @return array<string, mixed>
     */
    public function group(string $key, array $secret = []): array
    {
        $values = (array) $this->get($key, []);

        foreach ($secret as $field) {
            if (filled($values[$field] ?? null)) {
                $values[$field] = rescue(fn () => Crypt::decryptString($values[$field]), null, report: false);
            }
        }

        return $values;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  list<string>  $secret  fields to encrypt
     */
    public function setGroup(string $key, array $values, array $secret = []): void
    {
        foreach ($secret as $field) {
            if (filled($values[$field] ?? null)) {
                $values[$field] = Crypt::encryptString((string) $values[$field]);
            }
        }

        $this->set($key, $values);
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    public static function tableExists(): bool
    {
        try {
            return Schema::hasTable('settings');
        } catch (Throwable) {
            return false;
        }
    }
}
