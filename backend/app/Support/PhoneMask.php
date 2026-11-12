<?php

namespace App\Support;

class PhoneMask
{
    public static function mask(string $e164): string
    {
        $digits = preg_replace('/\D+/', '', $e164) ?? '';
        if (strlen($digits) <= 2) {
            return '+**';
        }

        return '+'.str_repeat('*', strlen($digits) - 2).substr($digits, -2);
    }
}
