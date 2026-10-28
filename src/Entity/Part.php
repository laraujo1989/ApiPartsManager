<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Parte del catálogo. Unifica las tablas Inventory y LocalMaterial del sistema original:
 * `source` distingue las partes registradas por el flujo de solicitudes de las importadas desde SAP.
 */
#[ORM\Entity]
#[ORM\Index(columns: ['material_number'])]
#[ORM\Index(columns: ['manufacturer_part_number'])]
#[ORM\Index(columns: ['plant'])]
#[ORM\Index(name: 'idx_part_description', columns: ['description'])]
#[ORM\Index(name: 'ft_part_search', columns: ['material_number', 'sap_part_number', 'description', 'manufacturer', 'manufacturer_part_number', 'additional_text', 'replaced_parts_text'], flags: ['fulltext'])]
class Part
{
    public const SOURCE_REGISTERED = 'registered';
    public const SOURCE_IMPORTED = 'imported';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $materialNumber = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $sapPartNumber = null;

    #[ORM\Column(length: 255)]
    public string $description = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $additionalText = null;

    #[ORM\Column(length: 160, nullable: true)]
    public ?string $manufacturer = null;

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $manufacturerPartNumber = null;

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $manufacturerInternalPartNumber = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $binLocation = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?PartClass $partClass = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?SubClass $subClass = null;

    /** Etiquetas de texto para partes importadas que no mapean a una clase del catálogo. */
    #[ORM\Column(length: 120, nullable: true)]
    public ?string $classLabel = null;

    #[ORM\Column(length: 160, nullable: true)]
    public ?string $subClassLabel = null;

    /** @var array<string, string> Valores de las características de la subclase. */
    #[ORM\Column(type: Types::JSON)]
    public array $characteristics = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Vendor $vendor = null;

    #[ORM\Column(length: 200, nullable: true)]
    public ?string $vendorName = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public string $price = '0';

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $leadTimeDays = null;

    #[ORM\Column]
    public bool $stockItem = false;

    #[ORM\Column(options: ['default' => 0])]
    public int $minimumQuantity = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $desiredQuantity = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $currentQuantity = 0;

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $plant = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $storageLocation = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    /** Identificadores de las partes que esta reemplazó, una por línea (acumulativo). Se incluye en la búsqueda. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $replacedPartsText = null;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $images = [];

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $manuals = [];

    /** @var Collection<int, TreeNode> */
    #[ORM\ManyToMany(targetEntity: TreeNode::class)]
    #[ORM\JoinTable(name: 'part_equipment')]
    public Collection $equipments;

    #[ORM\Column(length: 20, options: ['default' => self::SOURCE_REGISTERED])]
    public string $source = self::SOURCE_REGISTERED;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $lastEditedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $deletedAt = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->equipments = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }
}
