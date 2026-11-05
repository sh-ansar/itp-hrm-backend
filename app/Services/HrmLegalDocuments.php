<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Public legal documents must be active, explicitly published and valid for
 * the selected country. A translation is NEVER substituted from a different
 * jurisdiction (KZ/GЕ legal terms are not interchangeable).
 */
final class HrmLegalDocuments
{
    public const TYPES = [
        'offer', 'tariff-policy', 'acceptable-use', 'personal-data-consent', 'privacy',
    ];
    public const REQUIRED_TYPES = [
        'offer', 'tariff-policy', 'acceptable-use', 'personal-data-consent', 'privacy',
    ];
    public const LOCALES = ['ru', 'en', 'ka'];
    public const JURISDICTIONS = ['KZ', 'GE'];
    public const ACCEPTANCE = ['accepted', 'acknowledged', 'consented'];

    /** @return array<string,mixed> */
    public static function canonical(array|object $value): array
    {
        $v = (array) $value;
        $operator = $v['operator_snapshot'] ?? null;
        if (is_string($operator)) $operator = json_decode($operator, true);
        return [
            'type' => $v['type'],
            'locale' => $v['locale'],
            'jurisdiction' => $v['jurisdiction'],
            'version' => (int) $v['version'],
            'title' => $v['title'],
            'body' => str_replace("\r\n", "\n", (string) $v['body']),
            'operator' => $operator ?: [],
            'acceptance' => $v['acceptance'],
            'required' => (bool) $v['required'],
            'effective_at' => $v['effective_at'] ?: null,
        ];
    }

    public static function sha256(array|object $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** A source template is not a legally approved version, even if an admin tries to approve it. */
    public static function containsDraftNotice(string $body): bool
    {
        foreach ([
            'проект редакции', 'настоящий текст является адаптированным проектом',
            'настоящая редакция является проектом', 'настоящий текст является проектом',
            'draft for legal review', 'draft for commercial and legal approval',
            'this is a draft', 'remains a draft', 'adapted legal draft',
            'this is a proposed localized text',
            'იურიდიული შემოწმებისთვის მომზადებული პროექტი',
            'ეს არის პროექტი', 'ადაპტირებული პროექტი',
        ] as $marker) {
            if (mb_stripos($body, $marker) !== false) return true;
        }
        return false;
    }

    public static function selectedPublished(string $jurisdiction, string $locale): Collection
    {
        abort_unless(in_array($jurisdiction, self::JURISDICTIONS, true), 422);
        abort_unless(in_array($locale, self::LOCALES, true), 422);
        // Do not fall back from GE legal documents to Kazakhstan templates.
        $fallback = $jurisdiction === 'GE' ? ['en', 'ka', 'ru'] : ['ru', 'en', 'ka'];
        $priority = array_values(array_unique(array_merge([$locale], $fallback)));
        return DB::table('hrm_legal_versions')
            ->where('jurisdiction', $jurisdiction)
            ->where('status', 'published')
            ->whereNotNull('effective_at')
            ->where('effective_at', '<=', now())
            ->get()
            ->groupBy('type')
            ->map(static function (Collection $versions) use ($priority) {
                foreach ($priority as $language) {
                    $available = $versions->where('locale', $language)->sortByDesc('version')->first();
                    if ($available) return $available;
                }
                return null;
            })
            ->filter()
            ->values();
    }

    public static function requiredForRegistration(string $jurisdiction, string $locale): Collection
    {
        $docs = self::selectedPublished($jurisdiction, $locale)
            ->filter(fn (object $d) => in_array($d->type, self::REQUIRED_TYPES, true));
        $present = $docs->pluck('type')->all();
        $missing = array_diff(self::REQUIRED_TYPES, $present);
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'accepted_legal_ids' => 'Required approved documents are not available for '.$jurisdiction.': '.implode(', ', $missing),
            ]);
        }
        return $docs->values();
    }
}
