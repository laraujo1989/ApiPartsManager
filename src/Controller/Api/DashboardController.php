<?php

namespace App\Controller\Api;

use App\Entity\ActivityLog;
use App\Entity\Notification;
use App\Entity\Part;
use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\ServiceRequest;
use App\Enum\RequestKind;
use App\Enum\RequestStatus;
use App\Service\Presenter;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

class DashboardController extends ApiController
{
    private const CLASSES = ['part' => PartRequest::class, 'requisition' => PartsRequisition::class, 'service' => ServiceRequest::class];

    public function __construct(private EntityManagerInterface $em, private Presenter $presenter, private WorkflowService $workflow, private CacheInterface $cache)
    {
    }

    /** Solo contadores y avisos; las tablas se piden a /api/requests con su scope. */
    #[Route('/api/dashboard', methods: ['GET'])]
    public function index(): JsonResponse
    {
        $me = $this->me();
        $views = [];
        $out = [];

        $approvalKinds = array_values(array_filter(RequestKind::cases(), fn ($k) => $me->hasPermission($k->approvePermission())));
        $registerKinds = array_values(array_filter(RequestKind::cases(), fn ($k) => $me->hasPermission($k->registerPermission())));

        if ($approvalKinds) {
            $views[] = 'approval';
            foreach ($approvalKinds as $k) {
                $pending = $this->em->getRepository(self::CLASSES[$k->value])->findBy(['status' => RequestStatus::Pending]);
                $mine = array_filter($pending, fn ($e) => $this->workflow->isSuperAdmin($me) || \in_array($me->id, $e->approvers, true));
                $out['approval'][$k->value] = \count($mine);
            }
            $out['approvalMeta'] = $this->waitingMeta($approvalKinds, $me);
        }
        if ($registerKinds) {
            $views[] = 'registration';
            foreach ($registerKinds as $k) {
                $repo = $this->em->getRepository(self::CLASSES[$k->value]);
                $out['registration'][$k->value] = [
                    'ready' => $repo->count(['status' => RequestStatus::Approved]),
                    'poRequested' => $k === RequestKind::Part ? 0 : $repo->count(['status' => RequestStatus::Registered, 'po' => null, 'closed' => false]),
                    'poIssued' => $k === RequestKind::Part ? 0 : (int) $this->em->createQuery('SELECT COUNT(r.id) FROM '.self::CLASSES[$k->value].' r WHERE r.status = :s AND r.po IS NOT NULL AND r.closed = 0')->setParameter('s', RequestStatus::Registered)->getSingleScalarResult(),
                    'closed' => $k === RequestKind::Part ? 0 : $repo->count(['closed' => true]),
                ];
            }
        }
        if ($me->hasPermission('CreateRequests') || !$views) {
            array_unshift($views, 'my');
            foreach (RequestKind::cases() as $k) {
                $rows = $this->em->createQuery('SELECT r.status AS s, COUNT(r.id) AS n FROM '.self::CLASSES[$k->value].' r WHERE r.requestedBy = :me GROUP BY r.status')->setParameter('me', $me)->getResult();
                $counts = array_fill_keys(array_map(fn ($s) => $s->value, RequestStatus::cases()), 0);
                foreach ($rows as $r) {
                    $counts[$r['s']->value] = (int) $r['n'];
                }
                $out['my'][$k->value] = $counts;
            }
        }
        // Prioridad por defecto: aprobación > registro > mis solicitudes (como en el sistema original).
        $default = \in_array('approval', $views, true) ? 'approval' : (\in_array('registration', $views, true) ? 'registration' : 'my');

        $logs = $this->em->getRepository(ActivityLog::class)->findBy(['user' => $me], ['id' => 'DESC'], 5);

        return $this->json($out + [
            'views' => $views,
            'defaultView' => $default,
            'unreadNotifications' => $this->em->getRepository(Notification::class)->count(['user' => $me, 'isRead' => false]),
            'lowStockParts' => $this->cache->get('low_stock_count', function (ItemInterface $item) {
                $item->expiresAfter(300);

                return (int) $this->em->createQuery('SELECT COUNT(p.id) FROM App\Entity\Part p WHERE p.deletedAt IS NULL AND p.stockItem = 1 AND p.currentQuantity < p.minimumQuantity')->getSingleScalarResult();
            }),
            'needsAttention' => $this->em->getRepository(PartRequest::class)->count(['requestedBy' => $me, 'status' => RequestStatus::ChangesRequested])
                + $this->em->getRepository(PartsRequisition::class)->count(['requestedBy' => $me, 'status' => RequestStatus::ChangesRequested])
                + $this->em->getRepository(ServiceRequest::class)->count(['requestedBy' => $me, 'status' => RequestStatus::ChangesRequested]),
            'recentActivity' => array_map(fn (ActivityLog $l) => ['description' => $l->description, 'action' => $l->action, 'createdAt' => $l->createdAt->format(\DATE_ATOM)], $logs),
        ]);
    }

    /** Antigüedad de la solicitud pendiente más vieja de cada tipo. */
    private function waitingMeta(array $kinds, $me): array
    {
        $oldest = null;
        foreach ($kinds as $k) {
            foreach ($this->em->getRepository(self::CLASSES[$k->value])->findBy(['status' => RequestStatus::Pending], ['requestedAt' => 'ASC'], 20) as $e) {
                if ($this->workflow->isSuperAdmin($me) || \in_array($me->id, $e->approvers, true)) {
                    $oldest = $oldest === null || $e->requestedAt < $oldest ? $e->requestedAt : $oldest;
                    break;
                }
            }
        }

        return ['oldestPendingAt' => $oldest?->format(\DATE_ATOM)];
    }
}
