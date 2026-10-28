<?php

namespace App\Domain\Personnel;

use Illuminate\Validation\ValidationException;

/**
 * Search labels are metadata, not an access-control grant.
 * Normalization is deterministic and independent of SQL backend.
 */
final class DocumentMetadata
{
    public const SECTIONS = [
        'personal', 'organization', 'employment', 'schedule', 'development', 'documents',
    ];

    /** @param array<int,string> $tags */
    public static function tags(array $tags): array
    {
        $normalized = [];
        $seen = [];
        foreach ($tags as $raw) {
            $value = trim(preg_replace('/\s+/u', ' ', $raw));
            if ($value === '' || mb_strlen($value) > 40
                || preg_match('/^[\pL\pN][\pL\pN \-]*$/u', $value) !== 1) {
                throw ValidationException::withMessages([
                    'tags' => 'Tags must be readable words (up to 40 characters), letters, digits, spaces or hyphens.',
                ]);
            }
            $key = mb_strtolower($value);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $normalized[] = $value;
            }
        }
        return $normalized;
    }

    public static function decode(mixed $value): array
    {
        if ($value === null) return [];
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
    }
}
