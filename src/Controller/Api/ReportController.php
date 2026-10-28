<?php

namespace App\Controller\Api;

use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\PartsRequisitionItem;
use App\Entity\ServiceRequest;
use App\Enum\Permission;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

class ReportController extends ApiController
{
    private const CLASSES = ['part' => PartRequest::class, 'requisition' => PartsRequisition::class, 'service' => ServiceRequest::class];

    public function __construct(private EntityManagerInterface $em)
    {
    }

    #[Route('/api/reports/summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $this->need(Permission::ViewReports);
        $days = min(365, max(7, (int) $request->query->get('days', 180)));
        $since = new \DateTimeImmutable('-'.$days.' days');
        $plant = $request->query->get('plant') ?: ($this->me()->plants()[0] ?? null);

        $byStatus = array_fill_keys(array_map(fn ($s) => $s->value, RequestStatus::cases()), 0);
        $byKind = [];
        $monthly = [];
        $approvalHours = [];
        $registrationHours = [];
        $perUser = [];

        foreach (self::CLASSES as $kind => $class) {
            $rows = $this->em->createQuery('SELECT r FROM '.$class.' r WHERE r.requestedAt >= :s')->setParameter('s', $since)->getResult();
            $byKind[$kind] = 0;
            foreach ($rows as $r) {
                if ($plant && $this->plantOf($r) !== null && $this->plantOf($r) !== $plant) {
                    continue;
                }
                ++$byKind[$kind];
                ++$byStatus[$r->status->value];
                $m = $r->requestedAt->format('Y-m');
                $monthly[$m][$kind] = ($monthly[$m][$kind] ?? 0) + 1;
                if ($r->approvedAt) {
                    $approvalHours[] = ($r->approvedAt->getTimestamp() - $r->requestedAt->getTimestamp()) / 3600;
                }
                if ($r->registeredAt && $r->approvedAt) {
                    $registrationHours[] = ($r->registeredAt->getTimestamp() - $r->approvedAt->getTimestamp()) / 3600;
                }
                $name = $r->requestedBy->fullName;
                $perUser[$name] ??= ['user' => $name, 'total' => 0, 'approved' => 0, 'rejected' => 0, 'pending' => 0];
                ++$perUser[$name]['total'];
                match ($r->status) {
                    RequestStatus::Approved, RequestStatus::Registered => ++$perUser[$name]['approved'],
                    RequestStatus::Rejected => ++$perUser[$name]['rejected'],
                    default => ++$perUser[$name]['pending'],
                };
            }
        }
        ksort($monthly);

        $top = $this->em->createQuery(
            'SELECT i.description AS name, SUM(i.quantity) AS qty FROM '.PartsRequisitionItem::class.' i JOIN i.requisition r WHERE r.requestedAt >= :s GROUP BY i.description ORDER BY qty DESC'
        )->setParameter('s', new \DateTimeImmutable('-30 days'))->setMaxResults(8)->getArrayResult();

        usort($perUser, fn ($a, $b) => $b['total'] <=> $a['total']);

        return $this->json([
            'days' => $days,
            'plant' => $plant,
            'byStatus' => $byStatus,
            'byKind' => $byKind,
            'monthly' => array_map(fn ($m, $v) => ['month' => $m] + $v, array_keys($monthly), $monthly),
            'avgApprovalHours' => $approvalHours ? round(array_sum($approvalHours) / \count($approvalHours), 1) : null,
            'avgRegistrationHours' => $registrationHours ? round(array_sum($registrationHours) / \count($registrationHours), 1) : null,
            'topParts' => array_map(fn ($r) => ['name' => $r['name'], 'quantity' => (int) $r['qty']], $top),
            'perUser' => array_slice($perUser, 0, 10),
        ]);
    }

    private function plantOf(object $r): ?string
    {
        return $r->requestedBy->plants()[0] ?? null;
    }
}
