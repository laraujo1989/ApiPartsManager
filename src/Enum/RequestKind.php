<?php

namespace App\Enum;

/** Los tres tipos de solicitud; se usa en bloqueos, cambios pedidos, notificaciones y logs. */
enum RequestKind: string
{
    case Part = 'part';
    case Requisition = 'requisition';
    case Service = 'service';

    public function approvePermission(): string
    {
        return match ($this) {
            self::Part => Permission::ApprovePartRequests,
            self::Requisition => Permission::ApprovePartsRequisition,
            self::Service => Permission::ApproveService,
        };
    }

    public function registerPermission(): string
    {
        return match ($this) {
            self::Part => Permission::RegisterPartRequests,
            self::Requisition => Permission::RegisterPartsRequisition,
            self::Service => Permission::RegisterService,
        };
    }
}
