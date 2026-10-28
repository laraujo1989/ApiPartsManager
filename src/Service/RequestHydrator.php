<?php

namespace App\Service;

use App\Entity\Part;
use App\Entity\PartClass;
use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\PartsRequisitionItem;
use App\Entity\ServiceRequest;
use App\Entity\SubClass;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\PartRequestType;
use App\Enum\RequestKind;
use Doctrine\ORM\EntityManagerInterface;

/** Valida el payload JSON de cada tipo de solicitud y lo vuelca en la entidad. Lanza \DomainException con mensajes legibles. */
class RequestHydrator
{
    public function __construct(
        private EntityManagerInterface $em,
        private PartRegistrar $registrar,
        private SettingsService $settings,
    ) {
    }

    private static function str(array $d, string $k, ?string $default = null): ?string
    {
        if (!\array_key_exists($k, $d)) {
            return $default;
        }
        $v = trim((string) $d[$k]);

        return $v === '' ? null : $v;
    }

    private static function money(mixed $v): string
    {
        if (!is_numeric($v) || (float) $v < 0) {
            throw new \DomainException('Price must be a positive number.');
        }

        return (string) round((float) $v, 2);
    }

    /** @param int[] $ids */
    private function approvers(array $ids, RequestKind $kind, bool $required, array $mandatory = []): array
    {
        $ids = array_values(array_unique(array_map('intval', [...$ids, ...$mandatory])));
        if ($required && !$ids) {
            throw new \DomainException('Select at least one approver.');
        }
        foreach ($this->em->getRepository(User::class)->findBy(['id' => $ids]) as $u) {
            if (!$u->isActive || !$u->hasPermission($kind->approvePermission())) {
                throw new \DomainException($u->fullName.' cannot approve this kind of request.');
            }
        }

        return $ids;
    }

    public function hydratePart(PartRequest $r, array $d, User $actor, bool $creating): void
    {
        if ($creating) {
            $r->type = PartRequestType::tryFrom((string) ($d['type'] ?? 'normal')) ?? PartRequestType::Normal;
            $r->requestedBy = $actor;
            $targetId = $d['targetPartId'] ?? null;
            if ($r->type !== PartRequestType::Normal) {
                $target = $targetId ? $this->em->find(Part::class, (int) $targetId) : null;
                if (!$target || $target->deletedAt) {
                    throw new \DomainException('Select the part to '.($r->type === PartRequestType::Replacement ? 'replace' : 'modify').'.');
                }
                if ($r->type === PartRequestType::Modification && $target->source !== Part::SOURCE_REGISTERED) {
                    throw new \DomainException('Imported materials cannot be modified, only replaced.');
                }
                $this->assertReplaceablePlant($target, $r->type === PartRequestType::Replacement);
                $r->targetPart = $target;
            }
        }

        $r->shortDescription = self::str($d, 'shortDescription') ?? throw new \DomainException('Short description is required.');
        $r->manufacturer = self::str($d, 'manufacturer');
        $r->manufacturerPartNumber = self::str($d, 'manufacturerPartNumber') ?? throw new \DomainException('Manufacturer part number is required.');
        $r->manufacturerInternalPartNumber = self::str($d, 'manufacturerInternalPartNumber');
        $r->additionalText = self::str($d, 'additionalText');
        $r->partClass = !empty($d['classId']) ? $this->em->find(PartClass::class, (int) $d['classId']) : null;
        $r->subClass = !empty($d['subClassId']) ? $this->em->find(SubClass::class, (int) $d['subClassId']) : null;
        $r->characteristics = array_filter((array) ($d['characteristics'] ?? []), fn ($v) => $v !== null && $v !== '');
        $r->vendor = !empty($d['vendorId']) ? $this->em->find(Vendor::class, (int) $d['vendorId']) : null;
        $r->price = self::money($d['price'] ?? 0);
        $r->quoteNumber = self::str($d, 'quoteNumber');
        $r->quotes = array_values((array) ($d['quotes'] ?? []));
        $r->stockItem = (bool) ($d['stockItem'] ?? false);
        $r->minimumQuantity = $r->stockItem ? max(0, (int) ($d['minimumQuantity'] ?? 0)) : 0;
        $r->desiredQuantity = $r->stockItem ? max(0, (int) ($d['desiredQuantity'] ?? 0)) : 0;
        $r->leadTimeDays = self::str($d, 'leadTimeDays');
        $r->images = array_values((array) ($d['images'] ?? []));
        $r->manuals = array_values((array) ($d['manuals'] ?? []));
        $r->notes = self::str($d, 'notes');
        $r->isPriority = (bool) ($d['isPriority'] ?? false);
        $r->alsoCreateRequisition = (bool) ($d['alsoCreateRequisition'] ?? false);

        $r->equipments->clear();
        foreach ($this->em->getRepository(TreeNode::class)->findBy(['id' => array_map('intval', (array) ($d['equipmentIds'] ?? []))]) as $n) {
            $r->equipments->add($n);
        }
        $inherits = $r->type === PartRequestType::Replacement && $r->targetPart?->source === Part::SOURCE_REGISTERED;
        if ($r->type === PartRequestType::Normal || ($r->type === PartRequestType::Replacement && !$inherits)) {
            if ($r->equipments->isEmpty()) {
                throw new \DomainException('Select at least one equipment.');
            }
        }

        if ($r->type === PartRequestType::Modification && $creating && !empty($d['alsoReplacesPartId'])) {
            $other = $this->em->find(Part::class, (int) $d['alsoReplacesPartId']);
            if (!$other || $other->deletedAt || $other === $r->targetPart) {
                throw new \DomainException('The additional part to replace is not valid.');
            }
            $this->assertReplaceablePlant($other, true);
            $r->alsoReplacesPart = $other;
        }

        if ($creating || array_key_exists('approvers', $d)) {
            $r->approvers = $this->approvers((array) ($d['approvers'] ?? []), RequestKind::Part, true);
        }
        $r->changeNote = $this->registrar->describeChange($r);
    }

    private function assertReplaceablePlant(Part $p, bool $replacing): void
    {
        $default = $this->settings->get('default_plant');
        if ($replacing && $default && $this->settings->get('replacement_search_only_default_plant') && $p->plant !== $default) {
            throw new \DomainException('Only parts of plant '.$default.' can be replaced.');
        }
    }

    public function hydrateRequisition(PartsRequisition $r, array $d, User $actor, bool $creating): void
    {
        if ($creating) {
            $r->requestedBy = $actor;
        }
        $r->description = self::str($d, 'description') ?? throw new \DomainException('Description is required.');
        $r->notes = self::str($d, 'notes');
        $r->isPriority = (bool) ($d['isPriority'] ?? false);
        $r->useSameCostCenter = (bool) ($d['useSameCostCenter'] ?? true);
        $r->costCenterType = ($d['costCenterType'] ?? '') === PartsRequisition::WORK_ORDER ? PartsRequisition::WORK_ORDER : PartsRequisition::COST_CENTER;
        $r->globalCostCenter = self::str($d, 'globalCostCenter');
        if ($r->useSameCostCenter && !$r->globalCostCenter) {
            throw new \DomainException('Select a cost center or enter a work order.');
        }
        $items = (array) ($d['items'] ?? []);
        if (!$items) {
            throw new \DomainException('Add at least one item.');
        }
        $r->items->clear();
        foreach ($items as $row) {
            $i = new PartsRequisitionItem();
            $i->requisition = $r;
            $i->sourceType = \in_array($row['sourceType'] ?? '', [PartsRequisitionItem::SOURCE_INVENTORY, PartsRequisitionItem::SOURCE_PART_REQUEST], true) ? $row['sourceType'] : PartsRequisitionItem::SOURCE_MANUAL;
            $i->sourceId = isset($row['sourceId']) ? (int) $row['sourceId'] : null;
            $i->materialNumber = self::str($row, 'materialNumber');
            $i->description = self::str($row, 'description') ?? throw new \DomainException('Every item needs a description.');
            $i->manufacturerPartNumber = self::str($row, 'manufacturerPartNumber');
            $i->unitPrice = self::money($row['unitPrice'] ?? 0);
            $i->quantity = max(1, (int) ($row['quantity'] ?? 1));
            $i->quoteNumber = self::str($row, 'quoteNumber');
            $i->quoteDocument = self::str($row, 'quoteDocument');
            $i->vendor = !empty($row['vendorId']) ? $this->em->find(Vendor::class, (int) $row['vendorId']) : null;
            if ($r->useSameCostCenter) {
                $i->costCenterType = $r->costCenterType;
                $i->costCenter = $r->globalCostCenter;
            } else {
                $i->costCenterType = ($row['costCenterType'] ?? '') === PartsRequisition::WORK_ORDER ? PartsRequisition::WORK_ORDER : PartsRequisition::COST_CENTER;
                $i->costCenter = self::str($row, 'costCenter') ?? throw new \DomainException('Every item needs a cost center or work order.');
            }
            $r->items->add($i);
        }
        if ($creating || array_key_exists('approvers', $d)) {
            $r->approvers = $this->approvers((array) ($d['approvers'] ?? []), RequestKind::Requisition, true);
        }
    }

    public function hydrateService(ServiceRequest $r, array $d, User $actor, bool $creating): void
    {
        if ($creating) {
            $r->requestedBy = $actor;
        }
        $vendor = !empty($d['vendorId']) ? $this->em->find(Vendor::class, (int) $d['vendorId']) : null;
        if (!$vendor || $vendor->type !== Vendor::TYPE_SERVICE) {
            throw new \DomainException('Select a service vendor.');
        }
        $r->vendor = $vendor;
        $r->description = self::str($d, 'description') ?? throw new \DomainException('Description is required.');
        $r->costCenterType = ($d['costCenterType'] ?? '') === PartsRequisition::WORK_ORDER ? PartsRequisition::WORK_ORDER : PartsRequisition::COST_CENTER;
        $r->costCenter = self::str($d, 'costCenter') ?? throw new \DomainException('Select a cost center or enter a work order.');
        $r->quotedPrice = isset($d['quotedPrice']) && $d['quotedPrice'] !== '' ? self::money($d['quotedPrice']) : null;
        $r->notes = self::str($d, 'notes');
        $r->quoteDocuments = array_values((array) ($d['quoteDocuments'] ?? []));
        $r->isPriority = (bool) ($d['isPriority'] ?? false);
        $mandatory = $vendor->assignedUsers->map(fn (User $u) => $u->id)->toArray();
        if ($creating || array_key_exists('approvers', $d)) {
            $r->approvers = $this->approvers((array) ($d['approvers'] ?? []), RequestKind::Service, true, $mandatory);
        }
    }
}
