<?php

namespace App\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class Vendor
{
    public const TYPE_PARTS = 1;
    public const TYPE_SERVICE = 2;

    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 200)]
    public string $name = '';

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $vendorNumber = null;

    #[ORM\Column(options: ['default' => 1])]
    public int $type = self::TYPE_PARTS;

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $email = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $phone = null;

    #[ORM\Column(nullable: true)]
    public ?string $address = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $city = null;

    #[ORM\Column(length: 100, nullable: true)]
    public ?string $country = null;

    #[ORM\Column(length: 80, nullable: true)]
    public ?string $contractNumber = null;

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $incoterms = null;

    #[ORM\Column(length: 10, nullable: true)]
    public ?string $currency = null;

    #[ORM\Column(length: 60, nullable: true)]
    public ?string $purchasingGroup = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2)]
    public string $minValue = '0';

    /** Campos SAP adicionales (POrg, PayT, Salespers, …) que el sistema original guardaba como columnas. */
    #[ORM\Column(type: Types::JSON)]
    public array $extra = [];

    #[ORM\Column]
    public bool $isActive = true;

    #[ORM\Column]
    public bool $createdFromRequest = false;

    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'managedVendors')]
    public ?Vendor $handler = null;

    /** @var Collection<int, Vendor> */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'handler')]
    public Collection $managedVendors;

    /** @var Collection<int, User> Aprobadores obligatorios para solicitudes de servicio de este proveedor. */
    #[ORM\ManyToMany(targetEntity: User::class, mappedBy: 'vendors')]
    public Collection $assignedUsers;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    public function __construct()
    {
        $this->managedVendors = new ArrayCollection();
        $this->assignedUsers = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }
}
