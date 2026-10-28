<?php

namespace App\Controller\Api;

use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\PartsRequisitionItem;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\PartRequestType;
use App\Enum\RequestKind;
use App\Enum\RequestStatus;
use App\Service\PartRegistrar;
use App\Service\Presenter;
use App\Service\RequestHydrator;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Los tres tipos de solicitud comparten rutas: /api/requests/{kind}/… con kind = part | requisition | service.
 * scope=mine → mis solicitudes · approval → cola de aprobación · registration → cola del almacén.
 */
#[Route('/api/requests/{kind}', requirements: ['kind' => 'part|requisition|service'])]
class RequestController extends ApiController
{
    private const CLASSES = ['part' => PartRequest::class, 'requisition' => PartsRequisition::class, 'service' => ServiceRequest::class];

    public function __construct(
        private EntityManagerInterface $em,
        private Presenter $presenter,
        private WorkflowService $workflow,
        private RequestHydrator $hydrator,
        private PartRegistrar $registrar,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(string $kind, Request $request): JsonResponse
    {
        $k = RequestKind::from($kind);
        $me = $this->me();
        $scope = $request->query->get('scope', 'mine');
        $tab = $request->query->get('tab');

        $qb = $this->em->getRepository(self::CLASSES[$kind])->createQueryBuilder('r')->join('r.requestedBy', 'u');

        switch ($scope) {
            case 'mine':
                $qb->where('r.requestedBy = :me')->setParameter('me', $me);
                break;
            case 'approval':
                $this->need($k->approvePermission());
                $this->approvalScope($qb, $tab, $me);
                break;
            case 'registration':
                $this->need($k->registerPermission());
                $this->registrationScope($qb, $kind, $tab);
                break;
            case 'all':
                $this->needAny(['ManageUsers', 'ViewReports']);
                $qb->where('1 = 1');
                break;
            default:
                throw new AccessDeniedHttpException();
        }

        $q = $request->query;
        if ($status = RequestStatus::tryFrom((string) $q->get('status'))) {
            $qb->andWhere('r.status = :st')->setParameter('st', $status);
        }
        // Varios términos separados por coma (chips del filtro): todos deben coincidir.
        $field = match ($kind) { 'part' => 'r.shortDescription', default => 'r.description' };
        foreach (array_values(array_filter(array_map('trim', explode(',', (string) $q->get('q', ''))))) as $i => $term) {
            $extra = $kind === 'part' ? " OR r.manufacturerPartNumber LIKE :t$i" : '';
            $qb->andWhere("$field LIKE :t$i OR u.fullName LIKE :t$i OR r.id = :id$i$extra")->setParameter("t$i", '%'.$term.'%')->setParameter("id$i", (int) ltrim($term, '#'));
        }
        if ($kind === 'part' && $type = PartRequestType::tryFrom((string) $q->get('type'))) {
            $qb->andWhere('r.type = :ty')->setParameter('ty', $type);
        }
        if ($q->get('priority')) {
            $qb->andWhere('r.isPriority = 1');
        }
        if ($from = $q->get('from')) {
            $qb->andWhere('r.requestedAt >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }
        if ($to = $q->get('to')) {
            $qb->andWhere('r.requestedAt <= :to')->setParameter('to', new \DateTimeImmutable($to.' 23:59:59'));
        }
        $qb->orderBy('r.isPriority', 'DESC')->addOrderBy('r.requestedAt', 'DESC');

        // La cola de aprobación filtra por la lista JSON de aprobadores; se resuelve en PHP (colas pequeñas).
        if ($scope === 'approval' && $tab !== 'decided') {
            $rows = array_values(array_filter($qb->getQuery()->getResult(), fn ($e) => $this->workflow->isSuperAdmin($me) || \in_array($me->id, $e->approvers, true)));
            $page = max(1, (int) $q->get('page', 1));
            $size = min(100, max(1, (int) $q->get('pageSize', $me->pageSize)));

            return $this->json([
                'items' => array_map(fn ($e) => $this->presenter->request($e), \array_slice($rows, ($page - 1) * $size, $size)),
                'total' => \count($rows), 'page' => $page, 'pageSize' => $size,
            ]);
        }

        return $this->paginate($qb, $request, fn ($e) => $this->presenter->request($e));
    }

    private function approvalScope(QueryBuilder $qb, ?string $tab, User $me): void
    {
        match ($tab) {
            'changes' => $qb->where('r.status = :s')->setParameter('s', RequestStatus::ChangesRequested),
            'decided' => $qb->where('r.approvedBy = :me OR r.rejectedBy = :me')->setParameter('me', $me),
            default => $qb->where('r.status = :s')->setParameter('s', RequestStatus::Pending),
        };
    }

    private function registrationScope(QueryBuilder $qb, string $kind, ?string $tab): void
    {
        $reg = RequestStatus::Registered;
        match (true) {
            $tab === 'registered' && $kind === 'part' => $qb->where('r.status = :s')->setParameter('s', $reg),
            $tab === 'po_requested' => $qb->where('r.status = :s AND r.po IS NULL AND r.closed = 0')->setParameter('s', $reg),
            $tab === 'po_issued' => $qb->where('r.status = :s AND r.po IS NOT NULL AND r.closed = 0')->setParameter('s', $reg),
            $tab === 'closed' => $qb->where('r.status = :s AND r.closed = 1')->setParameter('s', $reg),
            default => $qb->where('r.status = :s')->setParameter('s', RequestStatus::Approved),
        };
    }

    #[Route('/counts', methods: ['GET'])]
    public function counts(string $kind): JsonResponse
    {
        $rows = $this->em->createQuery('SELECT r.status AS s, COUNT(r.id) AS n FROM '.self::CLASSES[$kind].' r WHERE r.requestedBy = :me GROUP BY r.status')
            ->setParameter('me', $this->me())->getResult();
        $out = [];
        foreach ($rows as $r) {
            $out[$r['s']->value] = (int) $r['n'];
        }

        return $this->json($out);
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(string $kind, int $id): JsonResponse
    {
        $e = $this->load($kind, $id);
        $me = $this->me();
        if (!$this->workflow->canView($me, $e)) {
            throw new AccessDeniedHttpException('You cannot see this request.');
        }
        $data = $this->presenter->request($e);
        $data['permissions'] = [
            'canEdit' => $e->requestedBy === $me && $e->isEditableByRequester(),
            'canDecide' => $e->status === RequestStatus::Pending && $this->workflow->canDecide($me, $e),
            'canRegister' => $e->status === RequestStatus::Approved && $this->workflow->canRegister($me, $e),
            'canTrack' => $e->status === RequestStatus::Registered && $this->workflow->canRegister($me, $e),
            'canDelete' => $e->requestedBy === $me && $e->isEditableByRequester(),
        ];
        if ($e instanceof PartRequest && $e->type === PartRequestType::Modification) {
            $data['diff'] = $this->partDiff($e);
        }
        $this->markViewed($e, $me);
        $this->em->flush();

        return $this->json($data);
    }

    private function partDiff(PartRequest $e): array
    {
        $out = [];
        foreach ($this->registrar->diff($e) as $label => $v) {
            $out[] = ['field' => $label] + $v;
        }

        return $out;
    }

    private function markViewed(object $e, User $me): void
    {
        $stage = match ($e->status) { RequestStatus::Pending => 'approval', RequestStatus::Approved => 'registration', default => null };
        if ($stage && !$e->viewedBy && $e->requestedBy !== $me) {
            $e->viewedBy = $me;
            $e->viewedAt = new \DateTimeImmutable();
            $e->viewedAtStage = $stage;
        }
    }

    #[Route('', methods: ['POST'])]
    public function create(string $kind, Request $request): JsonResponse
    {
        $me = $this->me();
        if (!$me->hasPermission('CreateRequests')) {
            throw new AccessDeniedHttpException('You do not have permission to create requests.');
        }
        $data = $this->body($request);
        $e = new (self::CLASSES[$kind])();
        try {
            match ($kind) {
                'part' => $this->hydrator->hydratePart($e, $data, $me, true),
                'requisition' => $this->hydrator->hydrateRequisition($e, $data, $me, true),
                'service' => $this->hydrator->hydrateService($e, $data, $me, true),
            };
        } catch (\DomainException $ex) {
            return $this->domainError($ex);
        }
        $this->em->persist($e);
        $this->em->flush();
        $this->workflow->submitted($e, $me);
        if ($e instanceof PartRequest && $e->alsoCreateRequisition) {
            $this->spawnRequisition($e, $me);
        }
        $this->em->flush();

        return $this->json($this->presenter->request($e), 201);
    }

    /** "Also create a Parts Requisition with this request": una línea manual con los datos de la parte. */
    private function spawnRequisition(PartRequest $p, User $me): void
    {
        $r = new PartsRequisition();
        $r->requestedBy = $me;
        $r->description = 'For part request #'.$p->id.' — '.$p->shortDescription;
        $r->approvers = $p->approvers;
        $r->globalCostCenter = 'TBD';
        $i = new PartsRequisitionItem();
        $i->requisition = $r;
        $i->sourceType = PartsRequisitionItem::SOURCE_PART_REQUEST;
        $i->sourceId = $p->id;
        $i->description = $p->shortDescription;
        $i->manufacturerPartNumber = $p->manufacturerPartNumber;
        $i->unitPrice = $p->price;
        $i->quantity = max(1, $p->desiredQuantity ?: $p->minimumQuantity ?: 1);
        $i->quoteNumber = $p->quoteNumber;
        $i->vendor = $p->vendor;
        $i->costCenter = 'TBD';
        $r->items->add($i);
        $this->em->persist($r);
        $this->em->flush();
        $this->workflow->submitted($r, $me);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    public function update(string $kind, int $id, Request $request): JsonResponse
    {
        $e = $this->load($kind, $id);
        $me = $this->me();
        if ($e->requestedBy !== $me || !$e->isEditableByRequester()) {
            throw new AccessDeniedHttpException('This request can no longer be edited.');
        }
        try {
            $data = $this->body($request);
            match ($kind) {
                'part' => $this->hydrator->hydratePart($e, $data, $me, false),
                'requisition' => $this->hydrator->hydrateRequisition($e, $data, $me, false),
                'service' => $this->hydrator->hydrateService($e, $data, $me, false),
            };
        } catch (\DomainException $ex) {
            return $this->domainError($ex);
        }
        $this->workflow->resubmit($e, $me);
        $this->em->flush();

        return $this->json($this->presenter->request($e));
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(string $kind, int $id): JsonResponse
    {
        $e = $this->load($kind, $id);
        if ($e->requestedBy !== $this->me() || !$e->isEditableByRequester()) {
            throw new AccessDeniedHttpException('This request can no longer be deleted.');
        }
        $this->em->remove($e);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    // ----------------------------------------------------------------- acciones

    #[Route('/{id<\d+>}/lock', methods: ['POST'])]
    public function lock(string $kind, int $id): JsonResponse
    {
        $e = $this->load($kind, $id);
        if (!$this->workflow->canDecide($this->me(), $e)) {
            throw new AccessDeniedHttpException();
        }
        $other = $this->workflow->acquireLock(RequestKind::from($kind), $id, $this->me());
        $this->em->flush();

        return $this->json(['locked' => $other === null, 'lockedBy' => $other ? $this->presenter->userBrief($other->lockedBy) : null]);
    }

    #[Route('/{id<\d+>}/unlock', methods: ['POST'])]
    public function unlock(string $kind, int $id): JsonResponse
    {
        $this->workflow->releaseLock(RequestKind::from($kind), $id, $this->me());
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id<\d+>}/approve', methods: ['POST'])]
    public function approve(string $kind, int $id): JsonResponse
    {
        return $this->act($kind, $id, fn ($e, $me) => $this->workflow->approve($e, $me), decide: true);
    }

    #[Route('/{id<\d+>}/reject', methods: ['POST'])]
    public function reject(string $kind, int $id, Request $request): JsonResponse
    {
        $reason = (string) ($this->body($request)['reason'] ?? '');

        return $this->act($kind, $id, fn ($e, $me) => $this->workflow->reject($e, $me, $reason), decide: true);
    }

    #[Route('/{id<\d+>}/changes', methods: ['POST'])]
    public function changes(string $kind, int $id, Request $request): JsonResponse
    {
        $d = $this->body($request);

        return $this->act($kind, $id, fn ($e, $me) => $this->workflow->requestChanges($e, $me, (array) ($d['fields'] ?? []), (string) ($d['reason'] ?? '')), decide: true);
    }

    #[Route('/{id<\d+>}/register', methods: ['POST'])]
    public function register(string $kind, int $id, Request $request): JsonResponse
    {
        $d = $this->body($request);

        return $this->act($kind, $id, function ($e, $me) use ($d) {
            $this->workflow->register($e, $me, $d);
            if (!$e instanceof PartRequest) {
                $this->workflow->updateTracking($e, $me, $d);
            }
        }, register: true);
    }

    #[Route('/{id<\d+>}/tracking', methods: ['PATCH'])]
    public function tracking(string $kind, int $id, Request $request): JsonResponse
    {
        $d = $this->body($request);

        return $this->act($kind, $id, fn ($e, $me) => $this->workflow->updateTracking($e, $me, $d), register: true);
    }

    private function act(string $kind, int $id, callable $fn, bool $decide = false, bool $register = false): JsonResponse
    {
        $e = $this->load($kind, $id);
        $me = $this->me();
        if ($decide && !$this->workflow->canDecide($me, $e)) {
            throw new AccessDeniedHttpException('You are not an approver of this request.');
        }
        if ($register && !$this->workflow->canRegister($me, $e)) {
            throw new AccessDeniedHttpException('You cannot register this kind of request.');
        }
        try {
            $fn($e, $me);
            $this->em->flush();
        } catch (\DomainException $ex) {
            return $this->domainError($ex);
        }

        return $this->json($this->presenter->request($e));
    }

    private function load(string $kind, int $id): object
    {
        return $this->found($this->workflow->find(RequestKind::from($kind), $id), 'Request');
    }
}
