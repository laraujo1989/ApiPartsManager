<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Index(columns: ['user_id', 'is_read'])]
class Notification
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?User $user = null;

    #[ORM\Column(length: 200)]
    public string $title = '';

    #[ORM\Column(type: Types::TEXT)]
    public string $message = '';

    /** approval | registration | system | followup */
    #[ORM\Column(length: 30)]
    public string $type = 'system';

    #[ORM\Column(length: 20, nullable: true)]
    public ?string $relatedKind = null;

    #[ORM\Column(nullable: true)]
    public ?int $relatedId = null;

    #[ORM\Column]
    public bool $isRead = false;

    #[ORM\Column]
    public bool $isUrgent = false;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $readAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
