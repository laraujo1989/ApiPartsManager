<?php

namespace App\Message;

use App\Entity\PartsRequisition;
use App\Entity\ServiceRequest;
use App\Enum\RequestKind;
use App\Enum\RequestStatus;
use App\Service\Notifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class FollowUpReminderHandler
{
    public function __construct(private EntityManagerInterface $em, private Notifier $notifier)
    {
    }

    /** @return int cantidad de recordatorios enviados */
    public function __invoke(FollowUpReminderMessage $message): int
    {
        $now = new \DateTimeImmutable();
        $sent = 0;
        foreach ([[PartsRequisition::class, RequestKind::Requisition, 'requisition'], [ServiceRequest::class, RequestKind::Service, 'service request']] as [$class, $kind, $label]) {
            $due = $this->em->createQuery("SELECT r FROM $class r WHERE r.status = :s AND r.po IS NOT NULL AND r.closed = 0 AND r.nextNotificationAt IS NOT NULL AND r.nextNotificationAt <= :now")
                ->setParameter('s', RequestStatus::Registered)->setParameter('now', $now)->getResult();
            foreach ($due as $r) {
                // Se avisa a quien registró; si ya no está activo, a todos los que pueden registrar este tipo.
                $targets = $r->registeredBy?->isActive ? [$r->registeredBy] : $this->notifier->usersWithPermission($kind->registerPermission());
                $this->notifier->notify($targets, 'PO follow-up', sprintf('The %s #%d has PO %s open. Close it once the vendor is paid and the work or material is verified.', $label, $r->id, $r->po), 'followup', $kind, $r->id);
                $r->nextNotificationAt = $now->modify('+7 days');
                ++$sent;
            }
        }
        $this->em->flush();

        return $sent;
    }
}
