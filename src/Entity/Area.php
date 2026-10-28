<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Area
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $name = '';

    #[ORM\Column(nullable: true)]
    public ?string $description = null;

    /** Planta o sitio al que pertenece el área. */
    #[ORM\Column(length: 80)]
    public string $plant = '';

    #[ORM\Column]
    public bool $isActive = true;

    /** @var Collection<int, User> */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'areas')]
    public Collection $users;

    public function __construct()
    {
        $this->users = new ArrayCollection();
    }
}
