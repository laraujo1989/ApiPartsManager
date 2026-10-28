<?php

namespace App\Entity;

use App\Entity\Trait\WorkflowTrait;
use App\Enum\PartRequestType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['status'])]
class PartRequest
{
    use WorkflowTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20, enumType: PartRequestType::class)]
    public PartRequestType $type = PartRequestType::Normal;

    #[ORM\Column(length: 255)]
    public string $shortDescription = '';

    #[ORM\Column(length: 160, nullable: true)]
    public ?string $manufacturer = null;

    #[ORM\Column(length: 120)]
    public string $manufacturerPartNumber = '';

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $manufacturerInternalPartNumber = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $additionalText = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?PartClass $partClass = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?SubClass $subClass = null;

    /** @var array<string, string> */
    #[ORM\Column(type: Types::JSON)]
    public array $characteristics = [];

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Vendor $vendor = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public string $price = '0';

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $quoteNumber = null;

    /** @var string[] Rutas de archivos de cotización. */
    #[ORM\Column(type: Types::JSON)]
    public array $quotes = [];

    #[ORM\Column]
    public bool $stockItem = false;

    #[ORM\Column(options: ['default' => 0])]
    public int $minimumQuantity = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $desiredQuantity = 0;

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $leadTimeDays = null;

    /** @var Collection<int, TreeNode> */
    #[ORM\ManyToMany(targetEntity: TreeNode::class)]
    #[ORM\JoinTable(name: 'part_request_equipment')]
    public Collection $equipments;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $images = [];

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $manuals = [];

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    /** Parte existente que se modifica (type = modification) o que se reemplaza (type = replacement). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Part $targetPart = null;

    /** Otra parte que esta solicitud, además, dejará obsoleta (modification + "also replaces"). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Part $alsoReplacesPart = null;

    /** Texto generado por el servidor que resume el reemplazo o la modificación; se muestra como aviso. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $changeNote = null;

    /** Parte creada o actualizada al registrar. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Part $resultingPart = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $binLocation = null;

    #[ORM\Column]
    public bool $alsoCreateRequisition = false;

    public function __construct()
    {
        $this->equipments = new ArrayCollection();
        $this->requestedAt = new \DateTimeImmutable();
    }
}
