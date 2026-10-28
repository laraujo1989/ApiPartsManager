<?php

namespace App\Entity\Trait;

use App\Entity\User;
use App\Enum\RequestStatus;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Columnas del flujo Pending → Approved/Rejected → Registered compartidas por los tres tipos de solicitud. */
trait WorkflowTrait
{
    #[ORM\Column(length: 30, enumType: RequestStatus::class)]
    public RequestStatus $status = RequestStatus::Pending;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $requestedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $requestedAt;

    /** @var int[] IDs de usuarios aprobadores. */
    #[ORM\Column(type: Types::JSON)]
    public array $approvers = [];

    #[ORM\Column]
    public bool $isPriority = false;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $approvedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $approvedAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $rejectedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $rejectedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $rejectionReason = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $registeredBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $registeredAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    public ?string $registrationNotes = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $updatedAt = null;

    /** Primer usuario que abrió la solicitud en cada etapa (seguimiento de lectura). */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $viewedBy = null;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $viewedAt = null;

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $viewedAtStage = null;

    public function isEditableByRequester(): bool
    {
        return \in_array($this->status, [RequestStatus::Pending, RequestStatus::ChangesRequested], true);
    }
}
