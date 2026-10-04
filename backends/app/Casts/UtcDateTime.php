<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A datetime column whose stored wall time is UTC, whatever the app timezone.
 *
 * The default 'datetime' cast writes a Carbon's wall time unconverted and
 * reads it back in the app timezone (Asia/Colombo in production), so a column
 * filled from exchange epoch times read 5.5h early. This cast stores UTC wall
 * time and reads it as UTC, so ->timestamp, ->getTimestampMs() and JSON are the
 * true instant. Query bindings bypass casts: bind UTC Carbons (->utc()).
 */
class UtcDateTime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof DateTimeInterface
            ? Carbon::instance($value)->utc()
            : Carbon::parse((string) $value, 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $instant = $value instanceof DateTimeInterface
            ? CarbonImmutable::instance($value)
            : (is_numeric($value) ? CarbonImmutable::createFromTimestamp((int) $value, 'UTC') : CarbonImmutable::parse((string) $value, 'UTC'));

        return $instant->utc()->format('Y-m-d H:i:s');
    }
}
