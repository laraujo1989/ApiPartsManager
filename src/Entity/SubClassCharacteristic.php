<?php

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class SubClassCharacteristic
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'characteristics')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?SubClass $subClass = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    #[ORM\Column(length: 40)]
    public string $abbreviation = '';

    #[ORM\Column(length: 60)]
    public string $charCode = '';

    #[ORM\Column]
    public int $sequence = 0;

    #[ORM\Column(nullable: true)]
    public ?string $description = null;

    #[ORM\Column]
    public bool $isActive = true;
}
