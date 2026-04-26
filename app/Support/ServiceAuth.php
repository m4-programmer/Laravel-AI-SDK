<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Port of `automative_assistant/src/auth/service_auth.py` ::ServiceAuth
 * (date-based HMAC, same as FastAPI `require_service_auth`).
 */
class ServiceAuth
{
    public static function getDateString(?DateTimeInterface $date = null): string
    {
        if ($date === null) {
            return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d');
        }

        return DateTimeImmutable::createFromInterface($date)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d');
    }

    public static function generateToken(string $secretKey, ?DateTimeInterface $date = null): string
    {
        $dateStr = self::getDateString($date);
        $message = $dateStr.$secretKey;

        return hash_hmac('sha256', $message, $secretKey);
    }

    public static function validateToken(string $token, string $secretKey, bool $allowPreviousDay = true): bool
    {
        if ($token === '' || $secretKey === '') {
            return false;
        }

        $expectedToday = self::generateToken($secretKey);
        if (hash_equals(strtolower($expectedToday), strtolower($token))) {
            return true;
        }

        if ($allowPreviousDay) {
            $yesterday = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('-1 day');
            $expectedYesterday = self::generateToken($secretKey, $yesterday);
            if (hash_equals(strtolower($expectedYesterday), strtolower($token))) {
                return true;
            }
        }

        return false;
    }
}
