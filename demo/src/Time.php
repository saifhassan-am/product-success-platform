<?php

final class Time
{
    private static ?int $frozen = null;

    /** Lets the seed script compute state "as of" a moment. */
    public static function freeze(?int $ts): void
    {
        self::$frozen = $ts;
    }

    public static function now(): int
    {
        return self::$frozen ?? time();
    }

    public static function iso(?int $ts = null): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $ts ?? self::now());
    }

    public static function ts(?string $iso): ?int
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $ts = strtotime($iso);
        return $ts === false ? null : $ts;
    }

    public static function parseOrFail(string $iso): int
    {
        $ts = self::ts($iso);
        if ($ts === null) {
            throw new InvalidArgumentException("Invalid timestamp: $iso");
        }
        return $ts;
    }

    public static function week(int $ts): string
    {
        return gmdate('o-\WW', $ts);
    }

    public static function days(int $from, ?int $to = null): float
    {
        return (($to ?? self::now()) - $from) / 86400;
    }
}
