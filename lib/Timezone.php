<?php

function appTimezoneName(?string $timezone, string $fallback = 'Europe/Chisinau'): string
{
    $timezone = trim((string)$timezone);
    if ($timezone === '') {
        $timezone = $fallback;
    }

    $aliases = [
        'Europe/Kiev' => 'Europe/Kyiv',
    ];
    $timezone = $aliases[$timezone] ?? $timezone;

    try {
        return (new DateTimeZone($timezone))->getName();
    } catch (Throwable $e) {
        try {
            return (new DateTimeZone($fallback))->getName();
        } catch (Throwable $fallbackError) {
            return 'UTC';
        }
    }
}

function appDateTimeZone(?string $timezone, string $fallback = 'Europe/Chisinau'): DateTimeZone
{
    return new DateTimeZone(appTimezoneName($timezone, $fallback));
}
