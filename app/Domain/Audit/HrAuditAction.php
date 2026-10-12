<?php

namespace App\Domain\Audit;

enum HrAuditAction: string
{
    case RoleGranted = 'company.role_granted';
    case RoleChanged = 'company.role_changed';
    case RoleRevoked = 'company.role_revoked';
    case PersonnelProfileRecordCreated = 'personnel.profile_record_created';
    case PersonnelProfileRecordUpdated = 'personnel.profile_record_updated';
    case PersonnelAssignmentCreated = 'personnel.assignment_created';
    case PersonnelAssignmentUpdated = 'personnel.assignment_updated';
    case EmployeesListed = 'personnel.employees_listed';
}
