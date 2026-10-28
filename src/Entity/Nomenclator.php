<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Nomenclator
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    /** Tipo de lista controlada: work_order, order, … */
    #[ORM\Column(length: 40)]
    public string $type = '';

    #[ORM\Column(length: 60)]
    public string $abbreviation = '';

    #[ORM\Column(length: 200)]
    public string $longName = '';

    #[ORM\Column]
    public bool $isActive = true;
}
