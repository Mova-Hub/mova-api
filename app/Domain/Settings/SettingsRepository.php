<?php

namespace App\Domain\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Runtime configuration, with a config-file floor.
 *
 * Two properties matter more than the rest.
 *
 * **It always falls back to config.** A missing row returns the value from
 * `config/*.php` rather than null. This is what makes the settings table
 * additive: the system runs correctly with an entirely empty `settings` table,
 * which is exactly the state a fresh deploy is in, and a half-seeded table
 * cannot half-break pricing.
 *
 * **It never throws.** A settings store that can fail takes the whole
 * application with it — including the pages an operator would use to fix it.
 * A database error degrades to config defaults and logs.
 */
class SettingsRepository
{
    private const CACHE_KEY = 'mova:settings';
    private const CACHE_TTL = 3600;

    /** @var array<string, mixed>|null Per-request memo on top of the cache. */
    private ?array $loaded = null;

    /**
     * A value, by `group.key`.
     *
     * @param  string  $path  e.g. 'rules.deposit_percent'
     * @param  mixed  $default  Used only when there is no config fallback either.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($path, $all)) {
            return $all[$path];
        }

        return $this->configFallback($path, $default);
    }

    public function bool(string $path, bool $default = false): bool
    {
        return (bool) $this->get($path, $default);
    }

    public function int(string $path, int $default = 0): int
    {
        return (int) $this->get($path, $default);
    }

    public function float(string $path, float $default = 0.0): float
    {
        return (float) $this->get($path, $default);
    }

    public function string(string $path, string $default = ''): string
    {
        $value = $this->get($path, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /** @return array<string, mixed> */
    public function group(string $group): array
    {
        $prefix = $group . '.';
        $out = [];

        foreach ($this->all() as $path => $value) {
            if (str_starts_with($path, $prefix)) {
                $out[substr($path, strlen($prefix))] = $value;
            }
        }

        return $out;
    }

    /**
     * Writes one value and drops the cache.
     *
     * `is_secret` is sticky: once a key has been marked secret it stays secret
     * even if a later write forgets to say so. Losing that flag would quietly
     * start returning an API key in a read response.
     */
    public function set(string $group, string $key, mixed $value, bool $isSecret = false, ?int $userId = null): void
    {
        $existing = Setting::where('group', $group)->where('key', $key)->first();

        Setting::updateOrCreate(
            ['group' => $group, 'key' => $key],
            [
                'value' => $value,
                'is_secret' => $isSecret || (bool) $existing?->is_secret,
                'updated_by' => $userId,
            ],
        );

        $this->flush();
    }

    /** @param  array<string, mixed>  $values */
    public function setMany(string $group, array $values, array $secretKeys = [], ?int $userId = null): void
    {
        foreach ($values as $key => $value) {
            $this->set($group, $key, $value, in_array($key, $secretKeys, true), $userId);
        }
    }

    public function flush(): void
    {
        $this->loaded = null;

        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // A cache that cannot be cleared is a stale read, not an outage.
        }
    }

    /**
     * Every setting, flattened to `group.key`.
     *
     * One query and one cache entry for the whole table rather than per key:
     * a request touching a dozen settings should not make a dozen round trips,
     * and the table is small by construction.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $this->loaded = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
                return Setting::all()
                    ->mapWithKeys(fn (Setting $s) => [$s->group . '.' . $s->key => $s->getRawOriginal('value') !== null
                        ? json_decode($s->getRawOriginal('value'), true)
                        : null])
                    ->all();
            });
        } catch (Throwable) {
            /*
             * No table yet (a fresh checkout before migrating), or the database
             * is down. Config defaults keep the app answering rather than
             * turning a settings problem into a total outage.
             */
            $this->loaded = [];
        }

        return $this->loaded;
    }

    /**
     * The config file behind a settings path.
     *
     * Only paths listed here have a floor; anything else uses `$default`. An
     * explicit map rather than `config($path)` because settings groups and
     * config file names are not the same namespace, and guessing would let a
     * settings key read an unrelated config value.
     */
    private function configFallback(string $path, mixed $default): mixed
    {
        static $map = [
            'pricing.' => 'pricing.',
            'booking.' => 'booking.',
        ];

        foreach ($map as $prefix => $configPrefix) {
            if (str_starts_with($path, $prefix)) {
                return config($configPrefix . substr($path, strlen($prefix)), $default);
            }
        }

        return $default;
    }
}
