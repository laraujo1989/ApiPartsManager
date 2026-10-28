<?php

namespace App\Controller\Api;

use App\Entity\Area;
use App\Entity\Part;
use App\Entity\User;
use App\Enum\UserRole;
use App\Service\ActivityLogger;
use App\Service\Notifier;
use App\Service\Presenter;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api')]
class AuthController extends ApiController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Presenter $presenter,
        private SettingsService $settings,
        private UserPasswordHasherInterface $hasher,
        private ActivityLogger $logger,
    ) {
    }

    /** El firewall json_login autentica; si llegamos aquí, las credenciales fueron válidas. */
    #[Route('/login', name: 'api_login', methods: ['POST'])]
    public function login(): JsonResponse
    {
        $user = $this->me();
        $user->lastLoginAt = new \DateTimeImmutable();
        $this->logger->log($user, 'auth.login', $user->fullName.' signed in', 'user', $user->id);
        $this->em->flush();

        return $this->json($this->session());
    }

    #[Route('/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): never
    {
        throw new \LogicException('Handled by the firewall.');
    }

    #[Route('/me', methods: ['GET'])]
    public function meAction(): JsonResponse
    {
        return $this->json($this->session());
    }

    private function session(): array
    {
        $cfg = $this->settings->all();

        return [
            'user' => $this->presenter->user($this->me()),
            'config' => [
                'orgName' => $cfg['org_name'],
                'defaultPlant' => $cfg['default_plant'],
                'sessionTimeoutMinutes' => $cfg['session_timeout_minutes'],
                'defaultAppearance' => $cfg['default_appearance'],
            ],
        ];
    }

    /** Perfil y preferencias propias (incluye la apariencia por usuario). */
    #[Route('/me', methods: ['PATCH'])]
    public function updateMe(Request $request): JsonResponse
    {
        $u = $this->me();
        $d = $this->body($request);
        foreach (['fullName', 'email', 'phone'] as $f) {
            if (\array_key_exists($f, $d)) {
                $u->$f = trim((string) $d[$f]) ?: ($f === 'fullName' ? $u->fullName : null);
            }
        }
        foreach (['pageSize' => 'int', 'disableAutoLogout' => 'bool', 'enableUrgentAlerts' => 'bool'] as $f => $t) {
            if (\array_key_exists($f, $d)) {
                $u->$f = $t === 'int' ? min(100, max(5, (int) $d[$f])) : (bool) $d[$f];
            }
        }
        if (\array_key_exists('picture', $d)) {
            // Solo un avatar estándar o un archivo propio subido con scope "user".
            $pic = (string) $d['picture'];
            if ($pic !== '' && !preg_match('#^(/avatars/[a-z]+\.png|/api/files/user/[A-Za-z0-9._-]+)$#', $pic)) {
                return $this->json(['error' => 'Invalid picture.'], 422);
            }
            $u->picture = $pic ?: null;
        }
        if (isset($d['appearance']) && \is_array($d['appearance'])) {
            $u->appearance = $this->cleanAppearance($d['appearance']);
        }
        $this->em->flush();

        return $this->json($this->session());
    }

    private function cleanAppearance(array $a): array
    {
        $out = [];
        if (\in_array($a['mode'] ?? null, ['light', 'dark', 'system', 'contrast'], true)) {
            $out['mode'] = $a['mode'];
        }
        if (isset($a['accent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $a['accent'])) {
            $out['accent'] = $a['accent'];
        }
        if (\in_array($a['radius'] ?? null, ['none', 'sm', 'md', 'lg', 'full'], true)) {
            $out['radius'] = $a['radius'];
        }
        if (\in_array($a['density'] ?? null, ['compact', 'standard', 'comfortable'], true)) {
            $out['density'] = $a['density'];
        }
        if (\in_array($a['font'] ?? null, ['inter', 'system', 'mono'], true)) {
            $out['font'] = $a['font'];
        }

        return $out;
    }

    #[Route('/auth/change-password', methods: ['POST'])]
    public function changePassword(Request $request): JsonResponse
    {
        $u = $this->me();
        $d = $this->body($request);
        if (!$this->hasher->isPasswordValid($u, (string) ($d['currentPassword'] ?? ''))) {
            return $this->json(['error' => 'Current password is incorrect.'], 422);
        }
        if ($err = $this->passwordRule((string) ($d['newPassword'] ?? ''))) {
            return $this->json(['error' => $err], 422);
        }
        $u->password = $this->hasher->hashPassword($u, $d['newPassword']);
        $u->mustChangePassword = false;
        $this->logger->log($u, 'auth.password_changed', $u->fullName.' changed their password', 'user', $u->id);
        $this->em->flush();

        return $this->json($this->session());
    }

    public static function passwordRuleStatic(string $p): ?string
    {
        if (\strlen($p) < 8) {
            return 'Password must be at least 8 characters.';
        }
        if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) {
            return 'Password must contain letters and numbers.';
        }

        return null;
    }

    private function passwordRule(string $p): ?string
    {
        return self::passwordRuleStatic($p);
    }

    /** Auto-registro: la cuenta nace inactiva, rol Maintenance TR y sin permisos. */
    #[Route('/auth/register', methods: ['POST'])]
    public function register(Request $request, Notifier $notifier): JsonResponse
    {
        $d = $this->body($request);
        $username = trim((string) ($d['username'] ?? ''));
        $fullName = trim((string) ($d['fullName'] ?? ''));
        if ($username === '' || $fullName === '') {
            return $this->json(['error' => 'Full name and username are required.'], 422);
        }
        if ($err = $this->passwordRule((string) ($d['password'] ?? ''))) {
            return $this->json(['error' => $err], 422);
        }
        if ($this->em->getRepository(User::class)->findOneBy(['username' => $username])) {
            return $this->json(['error' => 'That username is already taken.'], 422);
        }
        $u = new User();
        $u->username = $username;
        $u->fullName = $fullName;
        $u->email = trim((string) ($d['email'] ?? '')) ?: null;
        $u->phone = trim((string) ($d['phone'] ?? '')) ?: null;
        $u->role = UserRole::MaintenanceTR;
        $u->isActive = false;
        $u->mustChangePassword = false;
        $u->password = $this->hasher->hashPassword($u, $d['password']);
        if (!empty($d['areaId']) && ($a = $this->em->find(Area::class, (int) $d['areaId']))) {
            $u->areas->add($a);
        }
        $this->em->persist($u);
        $this->em->flush();
        $notifier->notify($notifier->usersWithPermission('ManageUsers'), 'New account pending activation', $fullName.' ('.$username.') registered and is waiting to be activated.', 'system', null, $u->id);
        $this->logger->log(null, 'auth.registered', $fullName.' self-registered', 'user', $u->id);
        $this->em->flush();

        return $this->json(['ok' => true], 201);
    }

    #[Route('/public/config', methods: ['GET'])]
    public function publicConfig(): JsonResponse
    {
        $cfg = $this->settings->all();
        $areas = $this->em->getRepository(Area::class)->findBy(['isActive' => true], ['name' => 'ASC']);

        return $this->json([
            'orgName' => $cfg['org_name'],
            'defaultAppearance' => $cfg['default_appearance'],
            'areas' => array_map(fn (Area $a) => ['id' => $a->id, 'name' => $a->name, 'plant' => $a->plant], $areas),
        ]);
    }

    /** Catálogo público (modo guest): solo lectura, sin precios ni proveedores. */
    #[Route('/public/parts', methods: ['GET'])]
    public function publicParts(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(Part::class)->createQueryBuilder('p')
            ->where('p.deletedAt IS NULL')->orderBy('p.description');
        if ($q = trim((string) $request->query->get('q', ''))) {
            $qb->andWhere('p.description LIKE :q OR p.materialNumber LIKE :q OR p.manufacturer LIKE :q OR p.manufacturerPartNumber LIKE :q OR p.replacedPartsText LIKE :q')
                ->setParameter('q', '%'.$q.'%');
        }
        $page = max(1, (int) $request->query->get('page', 1));
        $size = 25;
        $total = (int) (clone $qb)->select('COUNT(p.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $rows = $qb->setFirstResult(($page - 1) * $size)->setMaxResults($size)->getQuery()->getResult();

        return $this->json([
            'total' => $total, 'page' => $page, 'pageSize' => $size,
            'items' => array_map(fn (Part $p) => [
                'id' => $p->id, 'materialNumber' => $p->materialNumber, 'description' => $p->description, 'manufacturer' => $p->manufacturer,
                'manufacturerPartNumber' => $p->manufacturerPartNumber, 'class' => $p->partClass?->name ?? $p->classLabel,
                'subClass' => $p->subClass?->name ?? $p->subClassLabel, 'plant' => $p->plant, 'binLocation' => $p->binLocation,
                'stockItem' => $p->stockItem, 'image' => $p->images[0] ?? null,
            ], $rows),
        ]);
    }
}
