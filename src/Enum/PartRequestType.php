<?php

namespace App\Enum;

enum PartRequestType: string
{
    case Normal = 'normal';
    case Replacement = 'replacement';
    case Modification = 'modification';
}
