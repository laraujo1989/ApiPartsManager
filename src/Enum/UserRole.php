<?php

namespace App\Enum;

enum UserRole: string
{
    case MaintenanceTR = 'maintenance_tr';
    case MaintenanceLead = 'maintenance_lead';
    case StoreroomAssociate = 'storeroom_associate';
    case Manager = 'manager';
    case SuperAdmin = 'super_admin';

    public function label(): string
    {
        return match ($this) {
            self::MaintenanceTR => 'Maintenance TR',
            self::MaintenanceLead => 'Maintenance Lead',
            self::StoreroomAssociate => 'Storeroom Associate',
            self::Manager => 'Manager',
            self::SuperAdmin => 'Super Admin',
        };
    }

    /** Permisos sugeridos al crear un usuario con este rol (el admin puede ajustarlos). */
    public function defaultPermissions(): array
    {
        return match ($this) {
            self::MaintenanceTR => [Permission::CreateRequests],
            self::MaintenanceLead => [Permission::ApprovePartRequests, Permission::ApprovePartsRequisition, Permission::ApproveService],
            self::StoreroomAssociate => [Permission::RegisterPartRequests, Permission::RegisterPartsRequisition, Permission::RegisterService, Permission::EditInventory],
            self::Manager => [Permission::ApprovePartRequests, Permission::ApprovePartsRequisition, Permission::ApproveService, Permission::EditInventory, Permission::ManageUsers, Permission::ViewReports],
            self::SuperAdmin => array_values(array_diff(Permission::all(), [Permission::CreateRequests])),
        };
    }

    /** Roles que editan los campos sensibles de una parte (Material Number, stock, equipos…). */
    public function canEditSensitiveInventory(): bool
    {
        return $this === self::StoreroomAssociate || $this === self::SuperAdmin;
    }
}
