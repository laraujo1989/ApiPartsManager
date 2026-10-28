<?php

namespace App\Service;

use App\Entity\ApprovalLock;
use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\RequestChange;
use App\Entity\ServiceRequest;
use App\Entity\User;
use App\Enum\Permission;
use App\Enum\RequestKind;
use App\Enum\RequestStatus;
use App\Enum\UserRole;
use Doctrine\ORM\EntityManagerInterface;

/** Transiciones del flujo Pending → (ChangesRequested) → Approved/Rejected → Registered, común a los tres tipos. */
class WorkflowService
{
    private const CLASSES = [
        'part' => PartRequest::class,
        'requisition' => PartsRequisition::class,
        'service' => ServiceRequest::class,
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private Notifier $notifier,
        private ActivityLogger $logger,
        private PartRegistrar $registrar,
    ) {
    }

    public function find(RequestKind $kind, int $id): ?object
    {
        return $this->em->find(self::CLASSES[$kind->value], $id);
    }

    public function kindOf(object $e): RequestKind
    {
        return match (true) {
            $e instanceof PartRequest => RequestKind::Part,
            $e instanceof PartsRequisition => RequestKind::Requisition,
            $e instanceof ServiceRequest => RequestKind::Service,
        };
    }

    public function label(object $e): string
    {
        return match ($this->kindOf($e)) {
            RequestKind::Part => 'Part request #'.$e->id,
            RequestKind::Requisition => 'Parts requisition #'.$e->id,
            RequestKind::Service => 'Service request #'.$e->id,
        };
    }

    public function isSuperAdmin(User $u): bool
    {
        return $u->role === UserRole::SuperAdmin;
    }

    public function canDecide(User $u, object $e): bool
    {
        $kind = $this->kindOf($e);

        return $u->hasPermission($kind->approvePermission())
            && ($this->isSuperAdmin($u) || \in_array($u->id, $e->approvers, true));
    }

    public function canRegister(User $u, object $e): bool
    {
        return $u->hasPermission($this->kindOf($e)->registerPermission());
    }

    public function canView(User $u, object $e): bool
    {
        $kind = $this->kindOf($e);

        return $e->requestedBy === $u
            || \in_array($u->id, $e->approvers, true)
            || $u->hasPermission($kind->approvePermission())
            || $u->hasPermission($kind->registerPermission())
            || $u->hasPermission(Permission::ManageUsers)
            || $u->hasPermission(Permission::ViewReports);
    }

    // ------------------------------------------------------------------ locks

    /** Devuelve el bloqueo ajeno vigente, o null si el usuario pudo (re)tomar el bloqueo. */
    public function acquireLock(RequestKind $kind, int $id, User $user): ?ApprovalLock
    {
        $repo = $this->em->getRepository(ApprovalLock::class);
        $lock = $repo->findOneBy(['kind' => $kind, 'entityId' => $id]);
        $now = new \DateTimeImmutable();
        if ($lock && $lock->lockedBy !== $user && $lock->expiresAt > $now) {
            return $lock;
        }
        $lock ??= new ApprovalLock();
        $lock->kind = $kind;
        $lock->entityId = $id;
        $lock->lockedBy = $user;
        $lock->lockedAt = $now;
        $lock->expiresAt = $now->modify('+10 minutes');
        $this->em->persist($lock);

        return null;
    }

    public function releaseLock(RequestKind $kind, int $id, User $user): void
    {
        $lock = $this->em->getRepository(ApprovalLock::class)->findOneBy(['kind' => $kind, 'entityId' => $id]);
        if ($lock && $lock->lockedBy === $user) {
            $this->em->remove($lock);
        }
    }

    private function guardLock(object $e, User $actor): void
    {
        $lock = $this->em->getRepository(ApprovalLock::class)->findOneBy(['kind' => $this->kindOf($e), 'entityId' => $e->id]);
        if ($lock && $lock->lockedBy !== $actor && $lock->expiresAt > new \DateTimeImmutable()) {
            throw new \DomainException('This request is being reviewed by '.$lock->lockedBy->fullName.'.');
        }
    }

    // ------------------------------------------------------------- transitions

    /** Se llama tras crear la solicitud: avisa a los aprobadores elegidos. */
    public function submitted(object $e, User $actor): void
    {
        $kind = $this->kindOf($e);
        $approvers = $e->approvers ? $this->em->getRepository(User::class)->findBy(['id' => $e->approvers]) : [];
        $this->notifier->notify($approvers, 'New request to approve', $this->label($e).' from '.$actor->fullName.' is waiting for your decision.', 'approval', $kind, $e->id);
        $this->logger->log($actor, 'request.created', $this->label($e).' created', $kind->value, $e->id);
    }

    public function approve(object $e, User $actor): void
    {
        $this->assertStatus($e, RequestStatus::Pending);
        $this->guardLock($e, $actor);
        $kind = $this->kindOf($e);
        $e->status = RequestStatus::Approved;
        $e->approvedBy = $actor;
        $e->approvedAt = new \DateTimeImmutable();
        $e->updatedAt = $e->approvedAt;
        $this->releaseLock($kind, $e->id, $actor);
        $this->notifier->notify($e->requestedBy, 'Request approved', $this->label($e).' was approved by '.$actor->fullName.'.', 'approval', $kind, $e->id);
        $this->notifier->notify($this->notifier->usersWithPermission($kind->registerPermission(), $e->requestedBy), 'Ready to register', $this->label($e).' was approved and is ready to be registered.', 'registration', $kind, $e->id);
        $this->logger->log($actor, 'request.approved', $this->label($e).' approved', $kind->value, $e->id);
    }

    public function reject(object $e, User $actor, string $reason): void
    {
        $this->assertStatus($e, RequestStatus::Pending);
        if (trim($reason) === '') {
            throw new \DomainException('A reason is required to reject a request.');
        }
        $this->guardLock($e, $actor);
        $kind = $this->kindOf($e);
        $e->status = RequestStatus::Rejected;
        $e->rejectedBy = $actor;
        $e->rejectedAt = new \DateTimeImmutable();
        $e->rejectionReason = $reason;
        $e->updatedAt = $e->rejectedAt;
        $this->releaseLock($kind, $e->id, $actor);
        $this->notifier->notify($e->requestedBy, 'Request rejected', $this->label($e).' was rejected: '.$reason, 'approval', $kind, $e->id, true);
        $this->logger->log($actor, 'request.rejected', $this->label($e).' rejected', $kind->value, $e->id, ['reason' => $reason]);
    }

    /** @param string[] $fields */
    public function requestChanges(object $e, User $actor, array $fields, string $reason): void
    {
        $this->assertStatus($e, RequestStatus::Pending);
        if (!$fields || trim($reason) === '') {
            throw new \DomainException('Select at least one field and give a reason.');
        }
        $this->guardLock($e, $actor);
        $kind = $this->kindOf($e);
        $c = new RequestChange();
        $c->kind = $kind;
        $c->entityId = $e->id;
        $c->fields = array_values($fields);
        $c->reason = $reason;
        $c->requestedBy = $actor;
        $this->em->persist($c);
        $e->status = RequestStatus::ChangesRequested;
        $e->updatedAt = new \DateTimeImmutable();
        $this->releaseLock($kind, $e->id, $actor);
        $this->notifier->notify($e->requestedBy, 'Changes requested', $this->label($e).': '.$reason, 'approval', $kind, $e->id, true);
        $this->logger->log($actor, 'request.changes_requested', $this->label($e).' sent back for changes', $kind->value, $e->id, ['fields' => $fields, 'reason' => $reason]);
    }

    /** El solicitante corrigió: vuelve a la cola de aprobación. */
    public function resubmit(object $e, User $actor): void
    {
        $kind = $this->kindOf($e);
        $wasChanges = $e->status === RequestStatus::ChangesRequested;
        $e->status = RequestStatus::Pending;
        $e->updatedAt = new \DateTimeImmutable();
        foreach ($this->em->getRepository(RequestChange::class)->findBy(['kind' => $kind, 'entityId' => $e->id, 'isResolved' => false]) as $c) {
            $c->isResolved = true;
            $c->resolvedAt = $e->updatedAt;
        }
        if ($wasChanges && $e->approvers) {
            $this->notifier->notify($this->em->getRepository(User::class)->findBy(['id' => $e->approvers]), 'Request updated', $this->label($e).' was corrected and is waiting for your decision again.', 'approval', $kind, $e->id);
        }
        $this->logger->log($actor, 'request.updated', $this->label($e).' updated', $kind->value, $e->id);
    }

    /**
     * Registra una solicitud aprobada. Para Part Requests crea/actualiza la parte del catálogo.
     *
     * @param array{binLocation?: ?string, nonstock?: bool, notes?: ?string} $data
     */
    public function register(object $e, User $actor, array $data): void
    {
        $this->assertStatus($e, RequestStatus::Approved);
        $kind = $this->kindOf($e);
        if ($e instanceof PartRequest) {
            $part = $this->registrar->register($e, $actor, $data['binLocation'] ?? null, (bool) ($data['nonstock'] ?? false));
            $this->em->persist($part);
            $e->resultingPart = $part;
            $e->binLocation = $part->binLocation;
        }
        $e->status = RequestStatus::Registered;
        $e->registeredBy = $actor;
        $e->registeredAt = new \DateTimeImmutable();
        $e->registrationNotes = $data['notes'] ?? null;
        $e->updatedAt = $e->registeredAt;
        $this->notifier->notify($e->requestedBy, 'Request registered', $this->label($e).' was registered.', 'registration', $kind, $e->id);
        $this->logger->log($actor, 'request.registered', $this->label($e).' registered', $kind->value, $e->id);
    }

    /** Seguimiento de PO una vez registrada (requisiciones y servicios). */
    public function updateTracking(object $e, User $actor, array $data): void
    {
        if (!$e instanceof PartsRequisition && !$e instanceof ServiceRequest) {
            throw new \DomainException('Only requisitions and service requests have PO tracking.');
        }
        $this->assertStatus($e, RequestStatus::Registered);
        if (\array_key_exists('po', $data)) {
            $po = trim((string) $data['po']) ?: null;
            if ($po && !$e->po) {
                $e->poAssignedAt = new \DateTimeImmutable();
                $e->nextNotificationAt = $e->poAssignedAt->modify('+7 days');
            }
            $e->po = $po;
        }
        foreach (['requisitionNumber'] as $f) {
            if (\array_key_exists($f, $data)) {
                $e->$f = trim((string) $data[$f]) ?: null;
            }
        }
        if ($e instanceof PartsRequisition && \array_key_exists('workOrder', $data)) {
            $e->workOrder = trim((string) $data['workOrder']) ?: null;
        }
        if (\array_key_exists('finalCost', $data)) {
            $e->finalCost = $data['finalCost'] === null || $data['finalCost'] === '' ? null : (string) $data['finalCost'];
        }
        if ($e instanceof ServiceRequest && \array_key_exists('completed', $data)) {
            $e->completed = (bool) $data['completed'];
        }
        if (\array_key_exists('closed', $data)) {
            $e->closed = (bool) $data['closed'];
            if ($e->closed) {
                $e->nextNotificationAt = null;
            }
        }
        $e->updatedAt = new \DateTimeImmutable();
        $this->logger->log($actor, 'request.tracking', $this->label($e).' PO tracking updated', $this->kindOf($e)->value, $e->id);
    }

    private function assertStatus(object $e, RequestStatus $expected): void
    {
        if ($e->status !== $expected) {
            throw new \DomainException('The request is "'.$e->status->value.'"; expected "'.$expected->value.'".');
        }
    }
}
