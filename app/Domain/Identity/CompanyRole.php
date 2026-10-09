<?php

namespace App\Domain\Identity;

/**
 * Initial read-access roles. Product authorization rules still require approval.
 * Adding an enum case does not automatically grant access.
 */
enum CompanyRole: string
{
    case CompanyAdmin = 'company_admin';
    case HrSpecialist = 'hr_specialist';
    case DepartmentManager = 'department_manager';
    case Employee = 'employee';
}
