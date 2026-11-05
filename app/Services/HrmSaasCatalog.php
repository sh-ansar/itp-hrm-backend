<?php

namespace App\Services;

/**
 * Product-level HRM SaaS catalog, deliberately independent of Portal catalog.
 *
 * All entries correspond to implemented HRM modules. Company-admin, access
 * controls and settings are not sold as optional security features.
 * Proposals are drafts until an authorized person reviews commercial terms.
 */
final class HrmSaasCatalog
{
    public const MODULES = [
        'employees', 'staffing', 'personnel', 'vacations', 'worktime',
        'documents', 'reporting', 'development', 'roles',
    ];

    public const REQUIRED_MODULES = ['employees', 'roles'];

    public const SOURCE = 'portal-commercial-20261031 / adapted-hrm-20261105';

    public static function modules(array $requested): array
    {
        $unknown = array_diff(array_keys($requested), self::MODULES);
        if ($unknown !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'modules' => 'Unsupported HRM modules: '.implode(', ', $unknown),
            ]);
        }
        $result = array_replace(array_fill_keys(self::MODULES, false), $requested);
        foreach (self::REQUIRED_MODULES as $key) {
            if ($result[$key] !== true) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'modules' => 'The '.$key.' capability is required for every HRM plan.',
                ]);
            }
        }
        return $result;
    }

    /** @return array<int,array<string,mixed>> */
    public static function proposals(): array
    {
        $common = [
            'currency' => 'KZT', 'period_months' => 1,
            'is_public' => true,
            'storage_bytes' => 1073741824,
            'max_file_bytes' => 36700160,
        ];
        return [
            [
                'code' => 'start', 'display_order' => 0,
                'names' => ['ru' => 'Старт', 'en' => 'Start', 'ka' => 'სტარტი'],
                'descriptions' => [
                    'ru' => 'Учёт сотрудников, штатное расписание, кадровые операции и документы.',
                    'en' => 'Employee records, staffing, HR operations and documents.',
                    'ka' => 'თანამშრომლები, საშტატო განრიგი, საკადრო ოპერაციები და დოკუმენტები.',
                ],
                'monthly_price_minor' => 1490000,
                'limits' => ['users' => 2, 'companies' => 1, 'employees' => 50]
                    + array_intersect_key($common, array_flip(['storage_bytes', 'max_file_bytes'])),
                'modules' => [
                    'employees' => true, 'staffing' => true, 'personnel' => true,
                    'vacations' => false, 'worktime' => false,
                    'documents' => true, 'reporting' => false, 'development' => false,
                    'roles' => true,
                ],
            ],
            [
                'code' => 'growth', 'display_order' => 1,
                'names' => ['ru' => 'Рост', 'en' => 'Growth', 'ka' => 'ზრდა'],
                'descriptions' => [
                    'ru' => 'Полный кадровый учёт, отпуска, табели и отчётность.',
                    'en' => 'HR operations, leave schedules, timesheets and reporting.',
                    'ka' => 'საკადრო პროცესები, შვებულებები, სამუშაო დრო და ანგარიშგება.',
                ],
                'monthly_price_minor' => 6990000,
                'limits' => ['users' => 5, 'companies' => 1, 'employees' => 150]
                    + array_intersect_key($common, array_flip(['storage_bytes', 'max_file_bytes'])),
                'modules' => [
                    'employees' => true, 'staffing' => true, 'personnel' => true,
                    'vacations' => true, 'worktime' => true,
                    'documents' => true, 'reporting' => true, 'development' => false,
                    'roles' => true,
                ],
            ],
            [
                'code' => 'business', 'display_order' => 2,
                'names' => ['ru' => 'Бизнес', 'en' => 'Business', 'ka' => 'ბიზნესი'],
                'descriptions' => [
                    'ru' => 'Все модули HRM, включая обучение и кадровый резерв.',
                    'en' => 'All HRM modules, including learning and talent reserve.',
                    'ka' => 'ყველა HRM მოდული, მათ შორის სწავლება და საკადრო რეზერვი.',
                ],
                'monthly_price_minor' => 29990000,
                'limits' => ['users' => 10, 'companies' => 1, 'employees' => 500]
                    + array_intersect_key($common, array_flip(['storage_bytes', 'max_file_bytes'])),
                'modules' => array_fill_keys(self::MODULES, true),
            ],
        ];
    }

    /** @return array<string,mixed> */
    public static function canonicalTerms(array $plan): array
    {
        $terms = [
            'names' => $plan['names'],
            'descriptions' => $plan['descriptions'] ?? [],
            'monthly_price_minor' => (int) $plan['monthly_price_minor'],
            'currency' => $plan['currency'] ?? 'KZT',
            'period_months' => (int) ($plan['period_months'] ?? 1),
            'limits' => $plan['limits'],
            'modules' => self::modules($plan['modules']),
        ];
        return $terms;
    }

    public static function hashTerms(array $terms): string
    {
        return hash('sha256', json_encode($terms, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }
}
