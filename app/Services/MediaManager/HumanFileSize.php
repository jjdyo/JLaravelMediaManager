<?php

namespace app\Services\MediaManager;

class HumanFileSize
{
    public static function parseToBytes(string $value): int
    {
        $v = trim(strtolower($value));
        if ($v === '') return 0;
        if (preg_match('/^([0-9]+)(kb|mb|gb|b)?$/i', $v, $m)) {
            $num = (int) $m[1];
            $unit = strtolower($m[2] ?? 'b');
            return match ($unit) {
                'kb' => $num * 1024,
                'mb' => $num * 1024 * 1024,
                'gb' => $num * 1024 * 1024 * 1024,
                default => $num,
            };
        }
        // Fallback try with space, e.g., "5 MB"
        $v = preg_replace('/\s+/', '', $v ?? '');
        if (preg_match('/^([0-9]+)(kb|mb|gb|b)$/i', $v, $m)) {
            $num = (int) $m[1];
            $unit = strtolower($m[2] ?? 'b');
            return match ($unit) {
                'kb' => $num * 1024,
                'mb' => $num * 1024 * 1024,
                'gb' => $num * 1024 * 1024 * 1024,
                default => $num,
            };
        }
        return 0;
    }
}
