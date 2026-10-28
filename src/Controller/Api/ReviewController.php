<?php

namespace App\Controller\Api;

use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\Permission;
use App\Enum\RequestKind;
use App\Enum\RequestStatus;
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "User Activity Review": para una persona (solicitante, aprobador o quien registra) muestra
 * qué solicitudes le tocan o tocó, para dar seguimiento a lo pendiente.
 */
#[Route('/api/admin/user-review')]
class ReviewController extends ApiController
{
    private const CLASSES = ['part' => PartRequest::class, 'requisition' => PartsRequisition::class, 'service' => ServiceRequest::class];

    public function __construct(private EntityManagerInterface $em, private Presenter $presenter)
    {
    }

    /** Usuarios disponibles para cada tipo de perfil. */
    #[Route('/users', methods: ['GET'])]
    public function users(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $type = (string) $request->query->get('userType');
        $out = [];
        foreach ($this->em->getRepository(User::class)->findBy([], ['fullName' => 'ASC']) as $u) {
            $ok = match ($type) {
                'requester' => $u->hasPermission(Permission::CreateRequests),
                'approver' => (bool) array_filter(RequestKind::cases(), fn ($k) => $u->hasPermission($k->approvePermission())),
                'register' => (bool) array_filter(RequestKind::cases(), fn ($k) => $u->hasPermission($k->registerPermission())),
                default => false,
            };
            if ($ok) {
                $out[] = $this->presenter->userBrief($u);
            }
        }

        return $this->json($out);
    }

    #[Route('', methods: ['GET'])]
    public function review(Request $request): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $q = $request->query;
        $type = (string) $q->get('userType');
        $kind = RequestKind::tryFrom((string) $q->get('kind')) ?? throw $this->createNotFoundException('Choose a request type.');
        $userId = (int) $q->get('userId');
        $status = RequestStatus::tryFrom((string) $q->get('status'));

        $qb = $this->em->getRepository(self::CLASSES[$kind->value])->createQueryBuilder('r')->orderBy('r.requestedAt', 'DESC');
        if ($status) {
            $qb->andWhere('r.status = :st')->setParameter('st', $status);
        }
        if (!\in_array($type, ['requester', 'approver', 'register'], true)) {
            throw $this->createNotFoundException('Choose a user type.');
        }
        if ($type === 'requester' && $userId) {
            $qb->andWhere('r.requestedBy = :u')->setParameter('u', $userId);
        } elseif ($type === 'register') {
            // Lo que ya registró esa persona y lo que está aprobado esperando a quien registre.
            if ($userId) {
                $qb->andWhere('r.registeredBy = :u OR r.status = :ap')->setParameter('u', $userId)->setParameter('ap', RequestStatus::Approved);
            } else {
                $qb->andWhere('r.status IN (:sts)')->setParameter('sts', [RequestStatus::Approved, RequestStatus::Registered]);
            }
        }
        $rows = $qb->getQuery()->getResult();
        // La lista de aprobadores es JSON: se filtra en PHP.
        if ($type === 'approver' && $userId) {
            $rows = array_filter($rows, fn ($r) => \in_array($userId, $r->approvers, true));
        }
        $rows = array_values($rows);
        $page = max(1, (int) $q->get('page', 1));
        $size = min(100, max(5, (int) $q->get('pageSize', 15)));

        return $this->json([
            'items' => array_map(fn ($r) => $this->presenter->request($r), \array_slice($rows, ($page - 1) * $size, $size)),
            'total' => \count($rows), 'page' => $page, 'pageSize' => $size,
        ]);
    }
}
