<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/** Estructura de ubicaciones funcionales: Area → Location → EquipmentGroup → Equipment. */
#[ORM\Entity]
#[ORM\Index(columns: ['name'])]
class TreeNode
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    #[ORM\Column(nullable: true)]
    public ?string $description = null;

    #[ORM\Column(length: 30)]
    public string $nodeType = 'Equipment';

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $searchTag = null;

    #[ORM\Column]
    public bool $isActive = true;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'children')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    public ?TreeNode $parent = null;

    /** @var Collection<int, TreeNode> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parent')]
    public Collection $children;

    public function __construct()
    {
        $this->children = new ArrayCollection();
    }
}
