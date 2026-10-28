<?php

namespace App\Entity;

use App\Enum\RequestKind;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** "Request Changes": campos que el aprobador pidió corregir. */
#[ORM\Entity]
#[ORM\Index(columns: ['kind', 'entity_id'])]
class RequestChange
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 20, enumType: RequestKind::class)]
    public RequestKind $kind = RequestKind::Part;

    #[ORM\Column]
    public int $entityId = 0;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $fields = [];

    #[ORM\Column(type: Types::TEXT)]
    public string $reason = '';

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    public ?User $requestedBy = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public bool $isResolved = false;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
