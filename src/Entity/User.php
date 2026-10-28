<?php

namespace App\Entity;

use App\Enum\UserRole;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
#[ORM\UniqueConstraint(columns: ['username'])]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id, ORM\GeneratedValue, ORM\Column]
    public ?int $id = null;

    #[ORM\Column(length: 120)]
    public string $username = '';

    #[ORM\Column(length: 180, nullable: true)]
    public ?string $email = null;

    #[ORM\Column]
    public string $password = '';

    #[ORM\Column(length: 30, enumType: UserRole::class)]
    public UserRole $role = UserRole::MaintenanceTR;

    #[ORM\Column(length: 160)]
    public string $fullName = '';

    #[ORM\Column(length: 40, nullable: true)]
    public ?string $phone = null;

    #[ORM\Column(nullable: true)]
    public ?string $picture = null;

    #[ORM\Column]
    public bool $mustChangePassword = true;

    #[ORM\Column]
    public bool $isActive = true;

    /** Filas por página preferidas en las tablas. */
    #[ORM\Column(options: ['default' => 15])]
    public int $pageSize = 15;

    #[ORM\Column]
    public bool $disableAutoLogout = false;

    #[ORM\Column]
    public bool $enableUrgentAlerts = true;

    /** @var string[] */
    #[ORM\Column(type: Types::JSON)]
    public array $permissions = [];

    /** Preferencias de apariencia: mode, accent, radius, density, font. */
    #[ORM\Column(type: Types::JSON)]
    public array $appearance = [];

    /** @var Collection<int, Area> */
    #[ORM\ManyToMany(targetEntity: Area::class, inversedBy: 'users')]
    public Collection $areas;

    /** @var Collection<int, Vendor> Proveedores de servicio que este aprobador cubre. */
    #[ORM\ManyToMany(targetEntity: Vendor::class, inversedBy: 'assignedUsers')]
    public Collection $vendors;

    #[ORM\Column]
    public \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;

    public function __construct()
    {
        $this->areas = new ArrayCollection();
        $this->vendors = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER', 'ROLE_'.strtoupper($this->role->value)];
        foreach ($this->permissions as $p) {
            $roles[] = 'PERM_'.$p;
        }

        return array_values(array_unique($roles));
    }

    public function hasPermission(string $permission): bool
    {
        return \in_array($permission, $this->permissions, true);
    }

    /** @return string[] */
    public function plants(): array
    {
        return array_values(array_unique(array_map(fn (Area $a) => $a->plant, $this->areas->toArray())));
    }

    public function eraseCredentials(): void
    {
    }
}
