<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Historial de actividad (auditoría y "History" de cada entidad). */
#[ORM\Entity]
#[ORM\Index(columns: ['entity_type', 'entity_id'])]
#[ORM\Index(columns: ['created_at'])]
class ActivityLog
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $user = null;

    #[ORM\Column(length: 60)]
    public string $action = '';

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $entityType = null;

    #[ORM\Column(nullable: true)]
    public ?int $entityId = null;

    #[ORM\Column(type: Types::TEXT)]
    public string $description = '';

    /** @var array<string, mixed>|null Diff antes/después, cuando aplica. */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    public ?array $changes = null;

    #[ORM\Column(length: 45, nullable: true)]
    public ?string $ip = null;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
