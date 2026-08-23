<?php

namespace App\Domain\Settings\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static mixed  get(string $path, mixed $default = null)
 * @method static bool   bool(string $path, bool $default = false)
 * @method static int    int(string $path, int $default = 0)
 * @method static float  float(string $path, float $default = 0.0)
 * @method static string string(string $path, string $default = '')
 * @method static array  group(string $group)
 * @method static void   set(string $group, string $key, mixed $value, bool $isSecret = false, ?int $userId = null)
 * @method static void   setMany(string $group, array $values, array $secretKeys = [], ?int $userId = null)
 * @method static void   flush()
 * @method static array  all()
 *
 * @see \App\Domain\Settings\SettingsRepository
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \App\Domain\Settings\SettingsRepository::class;
    }
}
