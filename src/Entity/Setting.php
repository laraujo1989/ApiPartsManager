<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Configuración global clave/valor (nombre de la organización, planta por defecto, apariencia por defecto…). */
#[ORM\Entity]
class Setting
{
    #[ORM\Id, ORM\Column(name: 'setting_key', length: 80)]
    public string $key = '';

    #[ORM\Column(type: Types::JSON)]
    public mixed $value = null;
}
