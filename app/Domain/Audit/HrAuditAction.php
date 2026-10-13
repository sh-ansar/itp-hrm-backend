<?php

namespace App\Domain\Audit;

enum HrAuditAction: string
{
    case RoleGranted = 'company.role_granted';
    case RoleChanged = 'company.role_changed';
    case RoleRevoked = 'company.role_revoked';
    case PersonnelEmployeeCreated = 'personnel.employee_created';
    case PersonnelEmployeeUpdated = 'personnel.employee_updated';
    case PersonnelProfileRecordCreated = 'personnel.profile_record_created';
    case PersonnelProfileRecordUpdated = 'personnel.profile_record_updated';
    case PersonnelAssignmentCreated = 'personnel.assignment_created';
    case PersonnelAssignmentUpdated = 'personnel.assignment_updated';
    case PersonnelAssignmentTransitioned = 'personnel.assignment_transitioned';
    case EmployeesListed = 'personnel.employees_listed';
    case StaffingPlanCreated = 'staffing.plan_created';
    case StaffingPlanUpdated = 'staffing.plan_updated';
    case StaffingPlanLineCreated = 'staffing.plan_line_created';
    case StaffingPlanLineUpdated = 'staffing.plan_line_updated';
    case StaffingPlanApproved = 'staffing.plan_approved';
    case DepartmentCreated = 'organization.department_created';
    case DepartmentUpdated = 'organization.department_updated';
    case DepartmentDeleted = 'organization.department_deleted';
    case PositionCreated = 'organization.position_created';
    case PositionUpdated = 'organization.position_updated';
    case PositionDeleted = 'organization.position_deleted';
    case PersonnelActionCompleted = 'personnel.action_completed';
    case PersonnelDocumentDraftCreated = 'personnel.document_draft_created';
    case PersonnelDocumentsListed = 'personnel.documents_listed';
}
