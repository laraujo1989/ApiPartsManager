<?php

namespace App\Entity;

use App\Entity\Trait\WorkflowTrait;
use App\Enum\RequestStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['status'])]
class ServiceRequest
{
    use WorkflowTrait;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $description = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Vendor $vendor = null;

    #[ORM\Column(length: 20)]
    public string $costCenterType = PartsRequisition::COST_CENTER;

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $costCenter = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    public ?string $quotedPrice = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $notes = null;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $quoteDocuments = [];

    // --- Seguimiento de PO ---
    #[ORM\Column(length: 60, nullable: true)]
    public ?string $po = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $requisitionNumber = null;

    #[ORM\Column]
    public bool $closed = false;

    #[ORM\Column]
    public bool $completed = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $poAssignedAt = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $nextNotificationAt = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 14, scale: 2, nullable: true)]
    public ?string $finalCost = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTimeImmutable();
    }

    public function poState(): ?string
    {
        if ($this->status !== RequestStatus::Registered) {
            return null;
        }

        return $this->closed ? 'closed' : ($this->po ? 'po_issued' : 'po_requested');
    }
}
