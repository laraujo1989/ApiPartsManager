<?php

namespace App\Service;

use App\Entity\Area;
use App\Entity\ApprovalLock;
use App\Entity\CostCenter;
use App\Entity\Notification;
use App\Entity\Part;
use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\PartsRequisitionItem;
use App\Entity\RequestChange;
use App\Entity\ServiceRequest;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\RequestKind;
use App\Enum\RequestStatus;
use Doctrine\ORM\EntityManagerInterface;

/** Convierte entidades a arrays JSON para la API (formato estable que consume el frontend). */
class Presenter
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    private static function date(?\DateTimeInterface $d): ?string
    {
        return $d?->format(\DATE_ATOM);
    }

    public function userBrief(?User $u): ?array
    {
        return $u ? ['id' => $u->id, 'fullName' => $u->fullName, 'username' => $u->username, 'picture' => $u->picture, 'role' => $u->role->value] : null;
    }

    public function user(User $u): array
    {
        return $this->userBrief($u) + [
            'email' => $u->email,
            'phone' => $u->phone,
            'roleLabel' => $u->role->label(),
            'permissions' => $u->permissions,
            'isActive' => $u->isActive,
            'mustChangePassword' => $u->mustChangePassword,
            'pageSize' => $u->pageSize,
            'disableAutoLogout' => $u->disableAutoLogout,
            'enableUrgentAlerts' => $u->enableUrgentAlerts,
            'appearance' => (object) $u->appearance,
            'areas' => array_map(fn (Area $a) => ['id' => $a->id, 'name' => $a->name, 'plant' => $a->plant], $u->areas->toArray()),
            'vendors' => array_map(fn (Vendor $v) => ['id' => $v->id, 'name' => $v->name], $u->vendors->toArray()),
            'canRequest' => $u->hasPermission(\App\Enum\Permission::CreateRequests),
            'canEditSensitiveInventory' => $u->role->canEditSensitiveInventory(),
            'createdAt' => self::date($u->createdAt),
            'lastLoginAt' => self::date($u->lastLoginAt),
        ];
    }

    public function vendor(?Vendor $v, bool $full = false): ?array
    {
        if (!$v) {
            return null;
        }
        $brief = ['id' => $v->id, 'name' => $v->name, 'vendorNumber' => $v->vendorNumber, 'type' => $v->type, 'isActive' => $v->isActive];
        if (!$full) {
            return $brief;
        }

        return $brief + [
            'email' => $v->email, 'phone' => $v->phone, 'address' => $v->address, 'city' => $v->city, 'country' => $v->country,
            'contractNumber' => $v->contractNumber, 'incoterms' => $v->incoterms, 'currency' => $v->currency,
            'purchasingGroup' => $v->purchasingGroup, 'minValue' => (float) $v->minValue, 'createdFromRequest' => $v->createdFromRequest,
            'handler' => $v->handler ? ['id' => $v->handler->id, 'name' => $v->handler->name] : null,
            'approvers' => array_map(fn (User $u) => $this->userBrief($u), $v->assignedUsers->toArray()),
        ];
    }

    public function area(Area $a): array
    {
        return ['id' => $a->id, 'name' => $a->name, 'description' => $a->description, 'plant' => $a->plant, 'isActive' => $a->isActive, 'userCount' => $a->users->count()];
    }

    public function costCenter(CostCenter $c): array
    {
        return ['id' => $c->id, 'name' => $c->name, 'description' => $c->description, 'isActive' => $c->isActive];
    }

    public function node(TreeNode $n): array
    {
        return ['id' => $n->id, 'name' => $n->name, 'description' => $n->description, 'nodeType' => $n->nodeType, 'searchTag' => $n->searchTag, 'isActive' => $n->isActive, 'parentId' => $n->parent?->id];
    }

    public function partBrief(?Part $p): ?array
    {
        return $p ? ['id' => $p->id, 'materialNumber' => $p->materialNumber, 'description' => $p->description, 'manufacturerPartNumber' => $p->manufacturerPartNumber, 'source' => $p->source, 'plant' => $p->plant, 'deleted' => (bool) $p->deletedAt] : null;
    }

    public function part(Part $p): array
    {
        return [
            'id' => $p->id,
            'materialNumber' => $p->materialNumber,
            'sapPartNumber' => $p->sapPartNumber,
            'description' => $p->description,
            'additionalText' => $p->additionalText,
            'manufacturer' => $p->manufacturer,
            'manufacturerPartNumber' => $p->manufacturerPartNumber,
            'manufacturerInternalPartNumber' => $p->manufacturerInternalPartNumber,
            'binLocation' => $p->binLocation,
            'class' => $p->partClass ? ['id' => $p->partClass->id, 'name' => $p->partClass->name] : ($p->classLabel ? ['id' => null, 'name' => $p->classLabel] : null),
            'subClass' => $p->subClass ? ['id' => $p->subClass->id, 'name' => $p->subClass->name, 'code' => $p->subClass->code] : ($p->subClassLabel ? ['id' => null, 'name' => $p->subClassLabel, 'code' => null] : null),
            'characteristics' => (object) $p->characteristics,
            'vendor' => $p->vendor ? ['id' => $p->vendor->id, 'name' => $p->vendor->name] : ($p->vendorName ? ['id' => null, 'name' => $p->vendorName] : null),
            'price' => (float) $p->price,
            'leadTimeDays' => $p->leadTimeDays,
            'stockItem' => $p->stockItem,
            'minimumQuantity' => $p->minimumQuantity,
            'desiredQuantity' => $p->desiredQuantity,
            'currentQuantity' => $p->currentQuantity,
            'lowStock' => $p->stockItem && $p->currentQuantity < $p->minimumQuantity,
            'plant' => $p->plant,
            'storageLocation' => $p->storageLocation,
            'notes' => $p->notes,
            'replacedPartsText' => $p->replacedPartsText,
            'images' => $p->images,
            'manuals' => $p->manuals,
            'equipments' => array_map(fn (TreeNode $n) => ['id' => $n->id, 'name' => $n->name, 'searchTag' => $n->searchTag], $p->equipments->toArray()),
            'source' => $p->source,
            'canModify' => $p->source === Part::SOURCE_REGISTERED,
            'updatedAt' => self::date($p->updatedAt),
            'createdAt' => self::date($p->createdAt),
        ];
    }

    /** Campos comunes del flujo de aprobación. */
    private function workflow(object $e, RequestKind $kind): array
    {
        $approverIds = $e->approvers;
        $approvers = [];
        if ($approverIds) {
            foreach ($this->em->getRepository(User::class)->findBy(['id' => $approverIds]) as $u) {
                $approvers[] = $this->userBrief($u);
            }
        }
        $change = null;
        if ($e->status === RequestStatus::ChangesRequested) {
            $c = $this->em->getRepository(RequestChange::class)->findOneBy(['kind' => $kind, 'entityId' => $e->id, 'isResolved' => false], ['id' => 'DESC']);
            if ($c) {
                $change = ['fields' => $c->fields, 'reason' => $c->reason, 'by' => $this->userBrief($c->requestedBy), 'at' => self::date($c->createdAt)];
            }
        }
        $lock = $this->em->getRepository(ApprovalLock::class)->findOneBy(['kind' => $kind, 'entityId' => $e->id]);
        if ($lock && $lock->expiresAt < new \DateTimeImmutable()) {
            $lock = null;
        }

        return [
            'kind' => $kind->value,
            'id' => $e->id,
            'status' => $e->status->value,
            'isPriority' => $e->isPriority,
            'requestedBy' => $this->userBrief($e->requestedBy),
            'requestedAt' => self::date($e->requestedAt),
            'approvers' => $approvers,
            'approvedBy' => $this->userBrief($e->approvedBy),
            'approvedAt' => self::date($e->approvedAt),
            'rejectedBy' => $this->userBrief($e->rejectedBy),
            'rejectedAt' => self::date($e->rejectedAt),
            'rejectionReason' => $e->rejectionReason,
            'registeredBy' => $this->userBrief($e->registeredBy),
            'registeredAt' => self::date($e->registeredAt),
            'registrationNotes' => $e->registrationNotes,
            'updatedAt' => self::date($e->updatedAt),
            'pendingChange' => $change,
            'lockedBy' => $lock ? $this->userBrief($lock->lockedBy) : null,
        ];
    }

    public function partRequest(PartRequest $r): array
    {
        return $this->workflow($r, RequestKind::Part) + [
            'type' => $r->type->value,
            'title' => $r->shortDescription,
            'shortDescription' => $r->shortDescription,
            'manufacturer' => $r->manufacturer,
            'manufacturerPartNumber' => $r->manufacturerPartNumber,
            'manufacturerInternalPartNumber' => $r->manufacturerInternalPartNumber,
            'additionalText' => $r->additionalText,
            'class' => $r->partClass ? ['id' => $r->partClass->id, 'name' => $r->partClass->name] : null,
            'subClass' => $r->subClass ? ['id' => $r->subClass->id, 'name' => $r->subClass->name, 'code' => $r->subClass->code] : null,
            'characteristics' => (object) $r->characteristics,
            'vendor' => $this->vendor($r->vendor),
            'price' => (float) $r->price,
            'amount' => (float) $r->price,
            'quoteNumber' => $r->quoteNumber,
            'quotes' => $r->quotes,
            'stockItem' => $r->stockItem,
            'minimumQuantity' => $r->minimumQuantity,
            'desiredQuantity' => $r->desiredQuantity,
            'leadTimeDays' => $r->leadTimeDays,
            'equipments' => array_map(fn (TreeNode $n) => ['id' => $n->id, 'name' => $n->name, 'searchTag' => $n->searchTag], $r->equipments->toArray()),
            'images' => $r->images,
            'manuals' => $r->manuals,
            'notes' => $r->notes,
            'targetPart' => $this->partBrief($r->targetPart),
            'targetPartFull' => $r->targetPart ? $this->part($r->targetPart) : null,
            'alsoReplacesPart' => $this->partBrief($r->alsoReplacesPart),
            'changeNote' => $r->changeNote,
            'resultingPart' => $this->partBrief($r->resultingPart),
            'binLocation' => $r->binLocation,
            'alsoCreateRequisition' => $r->alsoCreateRequisition,
        ];
    }

    public function requisitionItem(PartsRequisitionItem $i): array
    {
        return [
            'id' => $i->id, 'sourceType' => $i->sourceType, 'sourceId' => $i->sourceId, 'materialNumber' => $i->materialNumber,
            'description' => $i->description, 'manufacturerPartNumber' => $i->manufacturerPartNumber,
            'unitPrice' => (float) $i->unitPrice, 'quantity' => $i->quantity, 'total' => (float) $i->unitPrice * $i->quantity,
            'quoteNumber' => $i->quoteNumber, 'quoteDocument' => $i->quoteDocument,
            'costCenterType' => $i->costCenterType, 'costCenter' => $i->costCenter, 'vendor' => $this->vendor($i->vendor),
        ];
    }

    public function requisition(PartsRequisition $r): array
    {
        return $this->workflow($r, RequestKind::Requisition) + [
            'title' => $r->description,
            'description' => $r->description,
            'notes' => $r->notes,
            'useSameCostCenter' => $r->useSameCostCenter,
            'costCenterType' => $r->costCenterType,
            'globalCostCenter' => $r->globalCostCenter,
            'items' => array_map(fn ($i) => $this->requisitionItem($i), $r->items->toArray()),
            'itemCount' => $r->items->count(),
            'amount' => $r->total(),
            'po' => $r->po,
            'requisitionNumber' => $r->requisitionNumber,
            'workOrder' => $r->workOrder,
            'closed' => $r->closed,
            'poAssignedAt' => self::date($r->poAssignedAt),
            'finalCost' => $r->finalCost !== null ? (float) $r->finalCost : null,
            'poState' => $r->poState(),
        ];
    }

    public function service(ServiceRequest $r): array
    {
        return $this->workflow($r, RequestKind::Service) + [
            'title' => $r->description,
            'description' => $r->description,
            'vendor' => $this->vendor($r->vendor),
            'costCenterType' => $r->costCenterType,
            'costCenter' => $r->costCenter,
            'quotedPrice' => $r->quotedPrice !== null ? (float) $r->quotedPrice : null,
            'amount' => $r->quotedPrice !== null ? (float) $r->quotedPrice : 0,
            'notes' => $r->notes,
            'quoteDocuments' => $r->quoteDocuments,
            'po' => $r->po,
            'requisitionNumber' => $r->requisitionNumber,
            'closed' => $r->closed,
            'completed' => $r->completed,
            'poAssignedAt' => self::date($r->poAssignedAt),
            'finalCost' => $r->finalCost !== null ? (float) $r->finalCost : null,
            'poState' => $r->poState(),
        ];
    }

    public function request(object $e): array
    {
        return match (true) {
            $e instanceof PartRequest => $this->partRequest($e),
            $e instanceof PartsRequisition => $this->requisition($e),
            $e instanceof ServiceRequest => $this->service($e),
        };
    }

    public function notification(Notification $n): array
    {
        return [
            'id' => $n->id, 'title' => $n->title, 'message' => $n->message, 'type' => $n->type, 'isRead' => $n->isRead,
            'isUrgent' => $n->isUrgent, 'relatedKind' => $n->relatedKind, 'relatedId' => $n->relatedId, 'createdAt' => self::date($n->createdAt),
        ];
    }
}
