<?php

namespace App\Controller\Api;

use App\Entity\ActivityLog;
use App\Entity\Area;
use App\Entity\CostCenter;
use App\Entity\Nomenclator;
use App\Entity\PartClass;
use App\Entity\SubClass;
use App\Entity\SubClassCharacteristic;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\Permission;
use App\Enum\UserRole;
use App\Service\ActivityLogger;
use App\Service\Presenter;
use App\Service\SettingsService;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/** Administración: usuarios, áreas, vendors, clasificaciones, nomenclators, cost centers, árbol, settings y auditoría. */
#[Route('/api/admin')]
class AdminController extends ApiController
{
    /** Catálogos simples con CRUD genérico: recurso => [clase, campos (s=texto, s!=obligatorio, b=bool)]. */
    private const SIMPLE = [
        'areas' => [Area::class, ['name' => 's!', 'description' => 's', 'plant' => 's!', 'isActive' => 'b']],
        'cost-centers' => [CostCenter::class, ['name' => 's!', 'description' => 's', 'isActive' => 'b']],
        'nomenclators' => [Nomenclator::class, ['type' => 's!', 'abbreviation' => 's!', 'longName' => 's!', 'isActive' => 'b']],
        'classes' => [PartClass::class, ['name' => 's!', 'description' => 's', 'isActive' => 'b']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private Presenter $presenter,
        private ActivityLogger $logger,
        private SettingsService $settings,
        private UserPasswordHasherInterface $hasher,
    ) {
    }

    // ------------------------------------------------------------------ users

    #[Route('/users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $qb = $this->em->getRepository(User::class)->createQueryBuilder('u')->orderBy('u.fullName');
        $q = $request->query;
        if ($t = trim((string) $q->get('q', ''))) {
            $qb->andWhere('u.fullName LIKE :t OR u.username LIKE :t OR u.email LIKE :t')->setParameter('t', '%'.$t.'%');
        }
        if ($r = UserRole::tryFrom((string) $q->get('role'))) {
            $qb->andWhere('u.role = :r')->setParameter('r', $r);
        }
        if (\in_array($q->get('active'), ['yes', 'no'], true)) {
            $qb->andWhere('u.isActive = :a')->setParameter('a', $q->get('active') === 'yes');
        }

        return $this->paginate($qb, $request, fn (User $u) => $this->presenter->user($u));
    }

    #[Route('/users', methods: ['POST'])]
    public function createUser(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $d = $this->body($request);
        $u = new User();
        try {
            $this->applyUser($u, $d, true);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        $this->em->persist($u);
        $this->logger->log($this->me(), 'user.created', 'User '.$u->username.' created', 'user');
        $this->em->flush();

        return $this->json($this->presenter->user($u), 201);
    }

    #[Route('/users/{id<\d+>}', methods: ['PUT'])]
    public function updateUser(int $id, Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $u = $this->found($this->em->find(User::class, $id), 'User');
        try {
            $this->applyUser($u, $this->body($request), false);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        $this->logger->log($this->me(), 'user.updated', 'User '.$u->username.' updated', 'user', $u->id);
        $this->em->flush();

        return $this->json($this->presenter->user($u));
    }

    #[Route('/users/{id<\d+>}/reset-password', methods: ['POST'])]
    public function resetPassword(int $id, Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $u = $this->found($this->em->find(User::class, $id), 'User');
        $pwd = (string) ($this->body($request)['password'] ?? '');
        if ($err = AuthController::passwordRuleStatic($pwd)) {
            return $this->json(['error' => $err], 422);
        }
        $u->password = $this->hasher->hashPassword($u, $pwd);
        $u->mustChangePassword = true;
        $this->logger->log($this->me(), 'user.password_reset', 'Password reset for '.$u->username, 'user', $u->id);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private function applyUser(User $u, array $d, bool $creating): void
    {
        if ($creating) {
            $username = trim((string) ($d['username'] ?? ''));
            if ($username === '' || $this->em->getRepository(User::class)->findOneBy(['username' => $username])) {
                throw new \DomainException('A unique username is required.');
            }
            $u->username = $username;
            if ($err = AuthController::passwordRuleStatic((string) ($d['password'] ?? ''))) {
                throw new \DomainException($err);
            }
            $u->password = $this->hasher->hashPassword($u, $d['password']);
            $u->mustChangePassword = true;
        }
        $u->fullName = trim((string) ($d['fullName'] ?? '')) ?: throw new \DomainException('Full name is required.');
        $u->email = trim((string) ($d['email'] ?? '')) ?: null;
        $u->phone = trim((string) ($d['phone'] ?? '')) ?: null;
        $u->role = UserRole::tryFrom((string) ($d['role'] ?? '')) ?? throw new \DomainException('Choose a valid role.');
        if (isset($d['isActive'])) {
            $u->isActive = (bool) $d['isActive'];
        }
        // Los permisos son independientes del rol: el rol solo sugiere un conjunto inicial en la UI.
        $u->permissions = array_values(array_intersect(Permission::all(), (array) ($d['permissions'] ?? [])));
        $u->areas->clear();
        foreach ($this->em->getRepository(Area::class)->findBy(['id' => array_map('intval', (array) ($d['areaIds'] ?? []))]) as $a) {
            $u->areas->add($a);
        }
        $u->vendors->clear();
        if ($u->hasPermission(Permission::ApproveService)) {
            foreach ($this->em->getRepository(Vendor::class)->findBy(['id' => array_map('intval', (array) ($d['vendorIds'] ?? []))]) as $v) {
                $u->vendors->add($v);
            }
        }
    }

    // ----------------------------------------------------------- catálogos simples

    #[Route('/{resource}', requirements: ['resource' => 'areas|cost-centers|nomenclators|classes'], methods: ['GET'])]
    public function simpleList(string $resource): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        [$class] = self::SIMPLE[$resource];
        if ($resource === 'classes') {
            // Más de mil clases: lista liviana con el conteo de subclases; el detalle se pide por id.
            return $this->json(array_map(fn ($r) => ['id' => (int) $r['id'], 'name' => $r['name'], 'description' => $r['description'], 'isActive' => (bool) $r['isActive'], 'subClassCount' => (int) $r['n']],
                $this->em->createQuery('SELECT c.id, c.name, c.description, c.isActive, COUNT(s.id) AS n FROM '.PartClass::class.' c LEFT JOIN c.subClasses s GROUP BY c.id ORDER BY c.name')->getArrayResult()));
        }
        $rows = $this->em->getRepository($class)->findBy([], ['id' => 'ASC']);

        return $this->json(array_map(fn ($e) => $this->simpleOut($resource, $e), $rows));
    }

    #[Route('/classes/{id<\d+>}', methods: ['GET'])]
    public function classDetail(int $id): JsonResponse
    {
        $this->need(Permission::ManageUsers);

        return $this->json($this->simpleOut('classes', $this->found($this->em->find(PartClass::class, $id), 'Class')));
    }

    #[Route('/{resource}', requirements: ['resource' => 'areas|cost-centers|nomenclators|classes'], methods: ['POST'])]
    public function simpleCreate(string $resource, Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        [$class] = self::SIMPLE[$resource];
        $e = new $class();
        try {
            $this->fill($e, self::SIMPLE[$resource][1], $this->body($request));
        } catch (\DomainException $ex) {
            return $this->domainError($ex);
        }
        $this->em->persist($e);
        $this->em->flush();

        return $this->json($this->simpleOut($resource, $e), 201);
    }

    #[Route('/{resource}/{id<\d+>}', requirements: ['resource' => 'areas|cost-centers|nomenclators|classes'], methods: ['PUT'])]
    public function simpleUpdate(string $resource, int $id, Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        [$class, $fields] = self::SIMPLE[$resource];
        $e = $this->found($this->em->find($class, $id));
        try {
            $this->fill($e, $fields, $this->body($request));
        } catch (\DomainException $ex) {
            return $this->domainError($ex);
        }
        $this->em->flush();

        return $this->json($this->simpleOut($resource, $e));
    }

    #[Route('/{resource}/{id<\d+>}', requirements: ['resource' => 'areas|cost-centers|nomenclators|classes|subclasses|characteristics|tree|vendors'], methods: ['DELETE'])]
    public function remove(string $resource, int $id): JsonResponse
    {
        $this->need($resource === 'vendors' ? Permission::EditInventory : Permission::ManageUsers);
        $class = self::SIMPLE[$resource][0] ?? ['subclasses' => SubClass::class, 'characteristics' => SubClassCharacteristic::class, 'tree' => TreeNode::class, 'vendors' => Vendor::class][$resource];
        $e = $this->found($this->em->find($class, $id));
        try {
            $this->em->remove($e);
            $this->em->flush();
        } catch (ForeignKeyConstraintViolationException) {
            return $this->json(['error' => 'It is in use. Deactivate it instead of deleting it.'], 409);
        }

        return $this->json(['ok' => true]);
    }

    private function fill(object $e, array $fields, array $d): void
    {
        foreach ($fields as $f => $t) {
            if (!\array_key_exists($f, $d)) {
                if ($t === 's!' && !$e->$f) {
                    throw new \DomainException(ucfirst($f).' is required.');
                }
                continue;
            }
            if ($t === 'b') {
                $e->$f = (bool) $d[$f];
                continue;
            }
            $v = trim((string) $d[$f]);
            if ($t === 's!' && $v === '') {
                throw new \DomainException(ucfirst($f).' is required.');
            }
            $e->$f = $v === '' ? null : $v;
        }
    }

    private function simpleOut(string $resource, object $e): array
    {
        return match ($resource) {
            'areas' => $this->presenter->area($e),
            'cost-centers' => $this->presenter->costCenter($e),
            'nomenclators' => ['id' => $e->id, 'type' => $e->type, 'abbreviation' => $e->abbreviation, 'longName' => $e->longName, 'isActive' => $e->isActive],
            'classes' => [
                'id' => $e->id, 'name' => $e->name, 'description' => $e->description, 'isActive' => $e->isActive,
                'subClasses' => array_values(array_map(fn (SubClass $s) => $this->subClassOut($s), $e->subClasses->toArray())),
            ],
        };
    }

    // ------------------------------------------------------ subclases / características

    private function subClassOut(SubClass $s): array
    {
        return [
            'id' => $s->id, 'classId' => $s->partClass?->id, 'name' => $s->name, 'abbreviation' => $s->abbreviation, 'code' => $s->code,
            'description' => $s->description, 'isActive' => $s->isActive,
            'characteristics' => array_values(array_map(fn (SubClassCharacteristic $c) => [
                'id' => $c->id, 'name' => $c->name, 'abbreviation' => $c->abbreviation, 'charCode' => $c->charCode, 'sequence' => $c->sequence, 'isActive' => $c->isActive,
            ], $s->characteristics->toArray())),
        ];
    }

    #[Route('/subclasses', methods: ['POST'])]
    public function saveSubClass(Request $request): JsonResponse
    {
        return $this->upsertSubClass(new SubClass(), $request);
    }

    #[Route('/subclasses/{id<\d+>}', methods: ['PUT'])]
    public function updateSubClass(int $id, Request $request): JsonResponse
    {
        return $this->upsertSubClass($this->found($this->em->find(SubClass::class, $id)), $request);
    }

    private function upsertSubClass(SubClass $s, Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $d = $this->body($request);
        try {
            if (!$s->id) {
                $s->partClass = $this->em->find(PartClass::class, (int) ($d['classId'] ?? 0)) ?? throw new \DomainException('Choose a class.');
            }
            $this->fill($s, ['name' => 's!', 'abbreviation' => 's!', 'code' => 's!', 'description' => 's', 'isActive' => 'b'], $d);
            $this->syncCharacteristics($s, $d['characteristics'] ?? null);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        $this->em->persist($s);
        $this->em->flush();

        return $this->json($this->subClassOut($s), $s->id ? 200 : 201);
    }

    private function syncCharacteristics(SubClass $s, ?array $rows): void
    {
        if ($rows === null) {
            return;
        }
        $s->characteristics->clear();
        foreach (array_values($rows) as $i => $r) {
            $c = new SubClassCharacteristic();
            $c->subClass = $s;
            $c->name = trim((string) ($r['name'] ?? '')) ?: throw new \DomainException('Every characteristic needs a name.');
            $c->abbreviation = trim((string) ($r['abbreviation'] ?? ''));
            $c->charCode = trim((string) ($r['charCode'] ?? '')) ?: strtoupper(preg_replace('/\W+/', '_', $c->name));
            $c->sequence = $i + 1;
            $c->isActive = (bool) ($r['isActive'] ?? true);
            $s->characteristics->add($c);
        }
    }

    // ----------------------------------------------------------------- vendors

    #[Route('/vendors', methods: ['GET'])]
    public function vendors(Request $request): JsonResponse
    {
        $this->needAny([Permission::EditInventory, Permission::ManageUsers]);
        $qb = $this->em->getRepository(Vendor::class)->createQueryBuilder('v')->orderBy('v.name');
        if ($t = (int) $request->query->get('type')) {
            $qb->andWhere('v.type = :t')->setParameter('t', $t);
        }
        if ($q = trim((string) $request->query->get('q', ''))) {
            $qb->andWhere('v.name LIKE :q OR v.vendorNumber LIKE :q OR v.contractNumber LIKE :q')->setParameter('q', '%'.$q.'%');
        }

        return $this->paginate($qb, $request, fn (Vendor $v) => $this->presenter->vendor($v, true));
    }

    #[Route('/vendors', methods: ['POST'])]
    public function createVendor(Request $request): JsonResponse
    {
        return $this->saveVendor(new Vendor(), $request);
    }

    #[Route('/vendors/{id<\d+>}', methods: ['PUT'])]
    public function updateVendor(int $id, Request $request): JsonResponse
    {
        return $this->saveVendor($this->found($this->em->find(Vendor::class, $id)), $request);
    }

    private function saveVendor(Vendor $v, Request $request): JsonResponse
    {
        $this->needAny([Permission::EditInventory, Permission::ManageUsers]);
        $d = $this->body($request);
        try {
            $this->fill($v, [
                'name' => 's!', 'vendorNumber' => 's', 'email' => 's', 'phone' => 's', 'address' => 's', 'city' => 's', 'country' => 's',
                'contractNumber' => 's', 'incoterms' => 's', 'currency' => 's', 'purchasingGroup' => 's', 'isActive' => 'b',
            ], $d);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        if (isset($d['type'])) {
            $v->type = (int) $d['type'] === Vendor::TYPE_SERVICE ? Vendor::TYPE_SERVICE : Vendor::TYPE_PARTS;
        }
        if (isset($d['minValue'])) {
            $v->minValue = (string) max(0, (float) $d['minValue']);
        }
        if (\array_key_exists('handlerId', $d)) {
            $h = $d['handlerId'] ? $this->em->find(Vendor::class, (int) $d['handlerId']) : null;
            $v->handler = $h === $v ? null : $h;
        }
        // Aprobadores obligatorios: el lado propietario de la relación es User::$vendors.
        if (isset($d['approverIds']) && $this->isGranted('PERM_'.Permission::ManageUsers)) {
            $ids = array_map('intval', (array) $d['approverIds']);
            foreach ($this->em->getRepository(User::class)->findAll() as $u) {
                $has = $u->vendors->contains($v);
                $want = \in_array($u->id, $ids, true) && $u->hasPermission(Permission::ApproveService);
                if ($want && !$has) {
                    $u->vendors->add($v);
                } elseif (!$want && $has) {
                    $u->vendors->removeElement($v);
                }
            }
        }
        $this->em->persist($v);
        $this->em->flush();

        return $this->json($this->presenter->vendor($v, true), 201);
    }

    // -------------------------------------------------------------------- árbol

    #[Route('/tree', methods: ['POST'])]
    public function createNode(Request $request): JsonResponse
    {
        return $this->saveNode(new TreeNode(), $request);
    }

    #[Route('/tree/{id<\d+>}', methods: ['PUT'])]
    public function updateNode(int $id, Request $request): JsonResponse
    {
        return $this->saveNode($this->found($this->em->find(TreeNode::class, $id)), $request);
    }

    private function saveNode(TreeNode $n, Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $d = $this->body($request);
        try {
            $this->fill($n, ['name' => 's!', 'description' => 's', 'nodeType' => 's', 'searchTag' => 's', 'isActive' => 'b'], $d);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        if (\array_key_exists('parentId', $d)) {
            $p = $d['parentId'] ? $this->em->find(TreeNode::class, (int) $d['parentId']) : null;
            for ($c = $p; $c; $c = $c->parent) {
                if ($c === $n) {
                    return $this->json(['error' => 'A node cannot be moved inside itself.'], 422);
                }
            }
            $n->parent = $p;
        }
        $this->em->persist($n);
        $this->em->flush();

        return $this->json($this->presenter->node($n), 201);
    }

    // ----------------------------------------------------------------- settings

    #[Route('/settings', methods: ['GET'])]
    public function getSettings(): JsonResponse
    {
        $this->need(Permission::ManageUsers);

        return $this->json($this->settings->all());
    }

    #[Route('/settings', methods: ['PUT'])]
    public function putSettings(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $d = $this->body($request);
        foreach (['org_name', 'default_plant', 'replacement_search_only_default_plant', 'session_timeout_minutes', 'default_appearance'] as $k) {
            if (\array_key_exists($k, $d)) {
                $this->settings->set($k, $d[$k]);
            }
        }
        $this->logger->log($this->me(), 'settings.updated', 'Organization settings updated', 'setting');
        $this->em->flush();

        return $this->json($this->settings->all());
    }

    // ---------------------------------------------------------------- auditoría

    #[Route('/activity', methods: ['GET'])]
    public function activity(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $qb = $this->em->getRepository(ActivityLog::class)->createQueryBuilder('l')->orderBy('l.id', 'DESC');
        $q = $request->query;
        if ($v = $q->get('userId')) {
            $qb->andWhere('l.user = :u')->setParameter('u', (int) $v);
        }
        if ($v = $q->get('action')) {
            $qb->andWhere('l.action LIKE :a')->setParameter('a', $v.'%');
        }
        if ($v = trim((string) $q->get('q', ''))) {
            $qb->andWhere('l.description LIKE :t')->setParameter('t', '%'.$v.'%');
        }
        if ($v = $q->get('from')) {
            $qb->andWhere('l.createdAt >= :f')->setParameter('f', new \DateTimeImmutable($v));
        }
        if ($v = $q->get('to')) {
            $qb->andWhere('l.createdAt <= :to')->setParameter('to', new \DateTimeImmutable($v.' 23:59:59'));
        }

        return $this->paginate($qb, $request, fn (ActivityLog $l) => [
            'id' => $l->id, 'action' => $l->action, 'description' => $l->description, 'entityType' => $l->entityType, 'entityId' => $l->entityId,
            'changes' => $l->changes, 'ip' => $l->ip, 'user' => $this->presenter->userBrief($l->user), 'createdAt' => $l->createdAt->format(\DATE_ATOM),
        ]);
    }
}
