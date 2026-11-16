<?php

declare(strict_types=1);

namespace Cms\Analytics;

use DateTimeZone;
use Throwable;

/**
 * A visitor's country, from the time zone their browser is set to: Europe/Brussels is Belgium. No IP address
 * is looked up and no database of addresses is kept. Zones that name no country (UTC, Etc/GMT+2) tell nothing.
 */
final class Countries
{
    /** @var array<string, ?string> */
    private static array $known = [];

    public static function fromTimeZone(?string $zone): ?string
    {
        $zone = trim((string) $zone);

        if ($zone === '' || strlen($zone) > 64 || ! preg_match('#^[A-Za-z]+(/[A-Za-z0-9_+-]+){1,2}$#', $zone)) {
            return null;
        }

        if (! array_key_exists($zone, self::$known)) {
            try {
                $code = (new DateTimeZone($zone))->getLocation()['country_code'] ?? null;
                self::$known[$zone] = is_string($code) && preg_match('/^[A-Z]{2}$/', $code) ? $code : null;
            } catch (Throwable) {
                self::$known[$zone] = null;
            }
        }

        return self::$known[$zone];
    }
}
