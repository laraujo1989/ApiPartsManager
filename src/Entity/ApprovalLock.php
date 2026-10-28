<?php

namespace App\Entity;

use App\Enum\RequestKind;
use Doctrine\ORM\Mapping as ORM;

/** Evita que dos aprobadores decidan la misma solicitud a la vez. */
#[ORM\Entity]
#[ORM\UniqueConstraint(columns: ['kind', 'entity_id'])]
class ApprovalLock
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20, enumType: RequestKind::class)]
    public RequestKind $kind = RequestKind::Part;

    #[ORM\Column]
    public int $entityId = 0;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $lockedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $lockedAt;

    #[ORM\Column]
    public \DateTimeImmutable $expiresAt;

    public function __construct()
    {
        $this->lockedAt = new \DateTimeImmutable();
        $this->expiresAt = new \DateTimeImmutable('+10 minutes');
    }
}
