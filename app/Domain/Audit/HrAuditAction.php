<?php

namespace App\Domain\Audit;

enum HrAuditAction: string
{
    case RoleGranted = 'company.role_granted';
    case RoleChanged = 'company.role_changed';
    case RoleRevoked = 'company.role_revoked';
    case EmployeesListed = 'personnel.employees_listed';
}
