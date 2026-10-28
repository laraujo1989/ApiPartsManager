<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class SubClass
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'subClasses')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PartClass $partClass = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    #[ORM\Column(length: 40)]
    public string $abbreviation = '';

    /** Ej: ABRASIVE:BELT */
    #[ORM\Column(length: 160)]
    public string $code = '';

    #[ORM\Column(nullable: true, length: 500)]
    public ?string $description = null;

    #[ORM\Column]
    public bool $isActive = true;

    /** @var Collection<int, SubClassCharacteristic> */
    #[ORM\OneToMany(targetEntity: SubClassCharacteristic::class, mappedBy: 'subClass', orphanRemoval: true)]
    #[ORM\OrderBy(['sequence' => 'ASC'])]
    public Collection $characteristics;

    public function __construct()
    {
        $this->characteristics = new ArrayCollection();
    }
}
