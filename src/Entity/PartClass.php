<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PartClass
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    #[ORM\Column(nullable: true, length: 500)]
    public ?string $description = null;

    #[ORM\Column]
    public bool $isActive = true;

    /** @var Collection<int, SubClass> */
    #[ORM\OneToMany(targetEntity: SubClass::class, mappedBy: 'partClass')]
    public Collection $subClasses;

    public function __construct()
    {
        $this->subClasses = new ArrayCollection();
    }
}
