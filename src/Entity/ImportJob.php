<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/** Historial de cargas masivas (manuales o automáticas desde n8n/SAP). */
#[ORM\Entity]
#[ORM\Index(columns: ['created_at'])]
class ImportJob
{
    public const VENDORS_PARTS = 'vendors_parts';
    public const VENDORS_SERVICE = 'vendors_service';
    public const MATERIALS = 'materials';
    public const NOMENCLATURE = 'nomenclature';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 30)]
    public string $type = '';

    #[ORM\Column(length: 255)]
    public string $filename = '';

    /** Usuario que lo lanzó; null si lo envió una automatización con API key. */
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?User $user = null;

    #[ORM\Column(length: 20)]
    public string $source = 'manual';

    #[ORM\Column(options: ['default' => 0])]
    public int $created = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $updated = 0;

    #[ORM\Column(options: ['default' => 0])]
    public int $skipped = 0;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $errors = [];

    #[ORM\Column(options: ['default' => true])]
    public bool $success = true;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }
}
