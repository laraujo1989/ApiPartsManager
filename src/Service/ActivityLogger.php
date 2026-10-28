<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

class ActivityLogger
{
    public function __construct(private EntityManagerInterface $em, private RequestStack $requests)
    {
    }

    public function log(?User $user, string $action, string $description, ?string $entityType = null, ?int $entityId = null, ?array $changes = null): void
    {
        $log = new ActivityLog();
        $log->user = $user;
        $log->action = $action;
        $log->description = $description;
        $log->entityType = $entityType;
        $log->entityId = $entityId;
        $log->changes = $changes;
        $log->ip = $this->requests->getCurrentRequest()?->getClientIp();
        $this->em->persist($log);
    }
}
