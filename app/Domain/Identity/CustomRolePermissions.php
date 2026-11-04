<?php

namespace App\Domain\Identity;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A custom company role can only RESTRICT a canonical server role.
 * These templates do not replace Laravel's existing employee-level,
 * department, document, protected-medical, and workflow authorizers.
 */
final class CustomRolePermissions
{
    private const RESERVED = ['roles.interface', 'roles.assign'];
    private const ALWAYS_SELF = ['settings.view', 'self_service.view'];

    public function __construct(private readonly CompanyCapabilities $policy)
    {
    }

    /** @return array<string, bool> */
    public function forBase(CompanyRole $base): array
    {
        return $this->policy->forRole($base);
    }

    /** @param array<string, mixed> $requested @return array<string, bool> */
    public function normalize(CompanyRole $base, array $requested): array
    {
        if ($base === CompanyRole::CompanyAdmin) {
            throw ValidationException::withMessages(['base_role' => 'Administrator privileges cannot be delegated.']);
        }

        $upperBound = $this->forBase($base);
        foreach ($requested as $code => $enabled) {
            if (!is_string($code) || !array_key_exists($code, $upperBound)) {
                throw ValidationException::withMessages(['capabilities' => 'Unknown permission code: '.$code]);
            }
            if (!is_bool($enabled)) {
                throw ValidationException::withMessages(['capabilities' => 'Permission values must be booleans.']);
            }
            if ($enabled && (!$upperBound[$code] || in_array($code, self::RESERVED, true))) {
                throw ValidationException::withMessages(['capabilities' => 'Permission exceeds the base role: '.$code]);
            }
        }

        $normalized = [];
        foreach ($upperBound as $code => $permitted) {
            $normalized[$code] = in_array($code, self::ALWAYS_SELF, true)
                ? $permitted
                : ($permitted && !in_array($code, self::RESERVED, true) && ($requested[$code] ?? false) === true);
        }

        return $normalized;
    }

    /** A feature flag turned off later also removes an old template's access. */
    public function effective(CompanyRole $base, mixed $stored): array
    {
        $allowed = $this->forBase($base);
        $configured = is_string($stored) ? json_decode($stored, true) : $stored;
        if (!is_array($configured)) {
            $configured = [];
        }
        foreach ($allowed as $code => $enabled) {
            $allowed[$code] = in_array($code, self::ALWAYS_SELF, true)
                ? $enabled
                : ($enabled && !in_array($code, self::RESERVED, true) && ($configured[$code] ?? false) === true);
        }

        return $allowed;
    }

    public function assignedTemplate(int $userId, string $companyId): ?object
    {
        $membership = DB::table('company_user_roles')
            ->where('user_id', $userId)
            ->where('company_id', $companyId)
            ->first(['role_code', 'custom_role_id']);

        if ($membership === null || $membership->custom_role_id === null) {
            return null;
        }

        $template = DB::table('company_role_templates')
            ->where('id', $membership->custom_role_id)
            ->where('company_id', $companyId)
            ->first();

        if ($template === null || $template->base_role !== $membership->role_code
            || CompanyRole::tryFrom($membership->role_code) === null
            || $membership->role_code === CompanyRole::CompanyAdmin->value) {
            throw new AuthorizationException('Invalid company role assignment.');
        }

        return $template;
    }
}
