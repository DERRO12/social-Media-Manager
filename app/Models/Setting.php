<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Key/value settings the admin edits in the UI. Secrets are stored encrypted with APP_KEY.
 * Keys are dotted, e.g. "billing.trial_days", "youtube.client_secret".
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'is_encrypted'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }

    private const CACHE_KEY = 'app-settings';

    /** All settings as [key => decrypted value]. */
    public static function allValues(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            try {
                if (! Schema::hasTable('settings')) {
                    return [];
                }
            } catch (Throwable) {
                return []; // no database yet (e.g. during install)
            }

            return static::all()->mapWithKeys(function (Setting $s) {
                try {
                    return [$s->key => $s->is_encrypted && $s->value !== null ? Crypt::decryptString($s->value) : $s->value];
                } catch (Throwable) {
                    return [$s->key => null]; // APP_KEY changed; value can't be read
                }
            })->all();
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = static::allValues()[$key] ?? null;

        return $value === null || $value === '' ? $default : $value;
    }

    public static function put(string $key, mixed $value, bool $encrypt = false): void
    {
        $value = $value === null || $value === '' ? null : (string) $value;

        static::updateOrCreate(['key' => $key], [
            'value' => $encrypt && $value !== null ? Crypt::encryptString($value) : $value,
            'is_encrypted' => $encrypt,
        ]);

        Cache::forget(self::CACHE_KEY);
    }

    /** Every non-empty setting under a prefix, e.g. group('youtube') → ['client_id' => '...']. */
    public static function group(string $prefix): array
    {
        return collect(static::allValues())
            ->filter(fn ($v, $k) => str_starts_with($k, "{$prefix}.") && $v !== null && $v !== '')
            ->mapWithKeys(fn ($v, $k) => [substr($k, strlen($prefix) + 1) => $v])
            ->all();
    }
}
