<?php declare(strict_types=1);

namespace Kniebes\IoAtmosphere\Client;

class Tid
{
    private const string ALPHABET = '234567abcdefghijklmnopqrstuvwxyz';
    private const string PATTERN = '/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/';
    private const int LENGTH = 13;
    private const int CLOCK_ID_BITS = 10;
    private const int MICROSECONDS_PER_SECOND = 1_000_000;

    public static function fromTimestamp(int $unixTimestamp): string
    {
        $microseconds = $unixTimestamp * self::MICROSECONDS_PER_SECOND;
        $clockId = random_int(0, (1 << self::CLOCK_ID_BITS) - 1);
        $value = ($microseconds << self::CLOCK_ID_BITS) | $clockId;

        $tid = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $tid = self::ALPHABET[$value & 31].$tid;
            $value >>= 5;
        }

        return $tid;
    }

    public static function now(): string
    {
        return self::fromTimestamp(time());
    }

    public static function isValid(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }
}
