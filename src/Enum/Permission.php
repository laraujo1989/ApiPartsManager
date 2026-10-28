<?php

namespace App\Enum;

final class Permission
{
    public const ApprovePartRequests = 'ApprovePartRequests';
    public const ApprovePartsRequisition = 'ApprovePartsRequisition';
    public const ApproveService = 'ApproveService';
    public const RegisterPartRequests = 'RegisterPartRequests';
    public const RegisterPartsRequisition = 'RegisterPartsRequisition';
    public const RegisterService = 'RegisterService';
    public const ManageUsers = 'ManageUsers';
    public const EditInventory = 'EditInventory';
    public const ViewReports = 'ViewReports';
    /** Crear solicitudes propias y usar el carrito. Por defecto solo Maintenance TR. */
    public const CreateRequests = 'CreateRequests';

    /** @return string[] */
    public static function all(): array
    {
        return [
            self::ApprovePartRequests, self::ApprovePartsRequisition, self::ApproveService,
            self::RegisterPartRequests, self::RegisterPartsRequisition, self::RegisterService,
            self::ManageUsers, self::EditInventory, self::ViewReports, self::CreateRequests,
        ];
    }
}
