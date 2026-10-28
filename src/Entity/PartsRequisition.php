<?php

namespace App\Entity;

use App\Entity\Trait\WorkflowTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['status'])]
class PartsRequisition
{
    use WorkflowTrait;

    public const COST_CENTER = 'CostCenter';
    public const WORK_ORDER = 'WorkOrder';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 255)]
    public string $description = '';

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    /** "Use Same for All": un único Cost Center / Work Order para todas las líneas. */
    #[ORM\Column]
    public bool $useSameCostCenter = true;

    #[ORM\Column(length: 20)]
    public string $costCenterType = self::COST_CENTER;

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $globalCostCenter = null;

    /** @var Collection<int, PartsRequisitionItem> */
    #[ORM\OneToMany(targetEntity: PartsRequisitionItem::class, mappedBy: 'requisition', cascade: ['persist', 'remove'], orphanRemoval: true)]
    public Collection $items;

    // --- Seguimiento de PO (lo completa el almacén) ---
    #[ORM\Column(length: 60, nullable: true)]
    public ?string $po = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $requisitionNumber = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $workOrder = null;

    #[ORM\Column]
    public bool $closed = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $poAssignedAt = null;

    /** Próximo recordatorio automático de seguimiento. */
    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextNotificationAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    public ?string $finalCost = null;

    public function __construct()
    {
        $this->items = new ArrayCollection();
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function total(): float
    {
        return array_sum($this->items->map(fn (PartsRequisitionItem $i) => (float) $i->unitPrice * $i->quantity)->toArray());
    }

    /** Estado visual para el almacén una vez registrada. */
    public function poState(): ?string
    {
        if ($this->status !== \App\Enum\RequestStatus::Registered) {
            return null;
        }

        return $this->closed ? 'closed' : ($this->po ? 'po_issued' : 'po_requested');
    }
}
