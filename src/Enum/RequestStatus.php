<?php

namespace App\Enum;

enum RequestStatus: string
{
    case Pending = 'pending';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Registered = 'registered';
}
