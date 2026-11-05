<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Idempotent, review-only transfer of ITP Portal commercial reference terms
 * into HRM's own catalog. NO automatic publication or live billing.
 */
final class HrmCommercialBootstrap
{
    public static function prepare(int $actor): array
    {
        abort_unless(HrmPlatformAccess::allows($actor, 'platform.manage'), 403);
        return DB::transaction(static function () use ($actor): array {
            DB::table('users')->where('id', $actor)->lockForUpdate()->first();
            $counts = ['plans_created' => 0, 'legal_created' => 0, 'plans_skipped' => 0, 'legal_skipped' => 0];
            foreach (HrmSaasCatalog::proposals() as $item) {
                if (DB::table('hrm_plan_versions')->where('code', $item['code'])->exists()) {
                    $counts['plans_skipped']++;
                    continue;
                }
                $terms = HrmSaasCatalog::canonicalTerms([
                    ...$item, 'currency' => 'KZT', 'period_months' => 1,
                ]);
                $id = DB::table('hrm_plan_versions')->insertGetId([
                    'code' => $item['code'],
                    'version' => 1,
                    'names' => json_encode($item['names'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'descriptions' => json_encode($item['descriptions'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'monthly_price_minor' => $item['monthly_price_minor'],
                    'currency' => 'KZT', 'period_months' => 1,
                    'limits' => json_encode($item['limits'], JSON_THROW_ON_ERROR),
                    'modules' => json_encode($terms['modules'], JSON_THROW_ON_ERROR),
                    'display_order' => $item['display_order'],
                    'sha256' => HrmSaasCatalog::hashTerms($terms),
                    'status' => 'draft', 'is_public' => true,
                    'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                HrmPlatformAccess::audit($actor, null, 'plan.adapted-draft', 'plan:'.$id, [
                    'origin' => HrmSaasCatalog::SOURCE, 'code' => $item['code'],
                    'status' => 'draft', 'publication' => 'requires commercial approval',
                ]);
                $counts['plans_created']++;
            }

            $names = [
                'ru' => [
                    'offer' => 'Публичная оферта ITP Кадры',
                    'tariff-policy' => 'Тарифная политика ITP Кадры',
                    'acceptable-use' => 'Правила допустимого использования ITP Кадры',
                    'personal-data-consent' => 'Согласие пользователя на обработку персональных данных ITP Кадры',
                    'privacy' => 'Политика конфиденциальности ITP Кадры',
                ],
                'en' => [
                    'offer' => 'ITP HRM Public Offer',
                    'tariff-policy' => 'ITP HRM Tariff Policy',
                    'acceptable-use' => 'ITP HRM Acceptable Use Rules',
                    'personal-data-consent' => 'ITP HRM Account Holder Data Consent',
                    'privacy' => 'ITP HRM Privacy Policy',
                ],
                'ka' => [
                    'offer' => 'ITP HRM საჯარო შეთავაზება',
                    'tariff-policy' => 'ITP HRM სატარიფო პოლიტიკა',
                    'acceptable-use' => 'ITP HRM გამოყენების წესები',
                    'personal-data-consent' => 'ITP HRM მომხმარებლის პერსონალურ მონაცემთა თანხმობა',
                    'privacy' => 'ITP HRM კონფიდენციალურობის პოლიტიკა',
                ],
            ];
            $actions = [
                'offer' => 'accepted', 'tariff-policy' => 'accepted',
                'acceptable-use' => 'accepted', 'personal-data-consent' => 'consented',
                'privacy' => 'acknowledged',
            ];
            $supplier = DB::table('hrm_invoice_profiles')->where('scope', 'supplier')
                ->whereNull('company_id')->first();
            $operator = $supplier ? (json_decode($supplier->details, true) ?: []) : [];
            foreach ($names as $locale => $types) {
                foreach ($types as $type => $title) {
                    $path = resource_path('legal/hrm/'.$locale.'-KZ/'.$type.'.txt');
                    if (!File::exists($path)) throw new \RuntimeException('HRM legal template missing: '.$path);
                    $body = str_replace("\r\n", "\n", trim(File::get($path)));
                    if (mb_strlen($body) < 1000) {
                        throw new \RuntimeException('HRM legal draft is too short: '.$path);
                    }
                    if (str_contains($body, 'https://platform.itpmining.kz/') && !str_contains($body, '/hrm/')) {
                        throw new \RuntimeException('HRM legal draft has an invalid service URL: '.$path);
                    }
                    if (DB::table('hrm_legal_versions')
                        ->where('type', $type)->where('locale', $locale)
                        ->where('jurisdiction', 'KZ')->exists()) {
                        $counts['legal_skipped']++;
                        continue;
                    }
                    $value = [
                        'type' => $type, 'locale' => $locale, 'jurisdiction' => 'KZ',
                        'version' => 1, 'title' => $title, 'body' => $body,
                        'operator_snapshot' => $operator,
                        'acceptance' => $actions[$type], 'required' => true, 'effective_at' => null,
                    ];
                    $sha = HrmLegalDocuments::sha256($value);
                    $id = DB::table('hrm_legal_versions')->insertGetId([
                        ...$value,
                        'operator_snapshot' => json_encode($operator, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                        'sha256' => $sha,
                        'status' => 'draft', 'created_by' => $actor,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                    HrmPlatformAccess::audit($actor, null, 'legal.adapted-draft', 'legal:'.$id, [
                        'origin' => 'Portal legal reference RU-KZ, HRM adaptation 2026-11-05',
                        'jurisdiction' => 'KZ', 'locale' => $locale,
                        'sha256' => $sha, 'requires_counsel_review' => true,
                    ]);
                    $counts['legal_created']++;
                }
            }
            return $counts;
        }, 3);
    }
}
