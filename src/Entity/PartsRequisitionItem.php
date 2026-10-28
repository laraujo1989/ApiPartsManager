<?php

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class PartsRequisitionItem
{
    public const SOURCE_INVENTORY = 'inventory';
    public const SOURCE_PART_REQUEST = 'part_request';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public ?PartsRequisition $requisition = null;

    #[ORM\Column(length: 20)]
    public string $sourceType = self::SOURCE_MANUAL;

    #[ORM\Column(nullable: true)]
    public ?int $sourceId = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $materialNumber = null;

    #[ORM\Column(length: 255)]
    public string $description = '';

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $manufacturerPartNumber = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public string $unitPrice = '0';

    #[ORM\Column(options: ['default' => 1])]
    public int $quantity = 1;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $quoteNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    public ?string $quoteDocument = null;

    /** Solo se usa si la requisición no usa "Use Same for All". */
    #[ORM\Column(length: 20)]
    public string $costCenterType = PartsRequisition::COST_CENTER;

    #[ORM\Column(length: 120, nullable: true)]
    public ?string $costCenter = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    public ?Vendor $vendor = null;
}
