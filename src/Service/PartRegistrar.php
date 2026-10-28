<?php

namespace App\Service;

use App\Entity\Part;
use App\Entity\PartRequest;
use App\Entity\User;
use App\Enum\PartRequestType;

/**
 * Lo que ocurre en el inventario cuando el almacén registra una Part Request aprobada:
 * Normal → crea la parte; Replacement → crea la parte heredando datos y borra la vieja;
 * Modification → aplica los cambios sobre la parte existente.
 */
class PartRegistrar
{
    public const REPLACE_PREFIX = 'This part replaces ';

    public function __construct(private SettingsService $settings)
    {
    }

    public function register(PartRequest $r, User $actor, ?string $binLocation, bool $nonstock): Part
    {
        $bin = $nonstock ? 'Nonstock' : ($binLocation ?: 'Pending');
        $now = new \DateTimeImmutable();

        if ($r->type === PartRequestType::Modification) {
            $part = $r->targetPart ?? throw new \DomainException('The part to modify no longer exists.');
            $this->applyFields($part, $r);
            $part->binLocation = $part->binLocation && $part->binLocation !== 'Pending' && !$binLocation ? $part->binLocation : $bin;
            if ($r->alsoReplacesPart) {
                $this->absorb($part, $r->alsoReplacesPart, $now);
            }
        } else {
            $part = new Part();
            $part->source = Part::SOURCE_REGISTERED;
            $part->plant = $r->requestedBy?->plants()[0] ?? $this->settings->get('default_plant', null);
            $this->applyFields($part, $r);
            $part->binLocation = $bin;
            if ($r->type === PartRequestType::Replacement) {
                $old = $r->targetPart ?? throw new \DomainException('The part to replace no longer exists.');
                $part->materialNumber = $old->materialNumber;
                $part->sapPartNumber = $old->sapPartNumber;
                $part->plant = $old->plant ?? $part->plant;
                if ($old->source === Part::SOURCE_REGISTERED) {
                    foreach ($old->equipments as $e) {
                        $part->equipments->add($e);
                    }
                }
                $this->absorb($part, $old, $now);
            }
        }
        $part->lastEditedBy = $actor;
        $part->updatedAt = $now;

        return $part;
    }

    /** Copia los campos de la solicitud a la parte. */
    private function applyFields(Part $part, PartRequest $r): void
    {
        $part->description = $r->shortDescription ?: $part->description;
        $part->additionalText = $r->additionalText ?? $part->additionalText;
        $part->manufacturer = $r->manufacturer ?? $part->manufacturer;
        $part->manufacturerPartNumber = $r->manufacturerPartNumber ?: $part->manufacturerPartNumber;
        $part->manufacturerInternalPartNumber = $r->manufacturerInternalPartNumber ?? $part->manufacturerInternalPartNumber;
        $part->partClass = $r->partClass ?? $part->partClass;
        $part->subClass = $r->subClass ?? $part->subClass;
        $part->characteristics = $r->characteristics ?: $part->characteristics;
        $part->vendor = $r->vendor ?? $part->vendor;
        $part->price = $r->price;
        $part->leadTimeDays = $r->leadTimeDays ?? $part->leadTimeDays;
        $part->stockItem = $r->stockItem;
        $part->minimumQuantity = $r->stockItem ? $r->minimumQuantity : 0;
        $part->desiredQuantity = $r->stockItem ? $r->desiredQuantity : 0;
        $part->images = $r->images ?: $part->images;
        $part->manuals = $r->manuals ?: $part->manuals;
        if ($r->notes) {
            $part->notes = $this->mergeNotes($part->notes, $r->notes);
        }
        if ($r->equipments->count()) {
            // En una modificación la solicitud trae la lista final de equipos.
            $part->equipments->clear();
            foreach ($r->equipments as $e) {
                $part->equipments->add($e);
            }
        }
    }

    /** Mantiene las líneas "This part replaces …" (protegidas) y reemplaza el resto de las notas. */
    private function mergeNotes(?string $current, string $incoming): string
    {
        $protected = $this->protectedLines($current);

        return trim(implode("\n", [...$protected, ...array_filter(explode("\n", $incoming), fn ($l) => !str_starts_with($l, self::REPLACE_PREFIX))]));
    }

    /** @return string[] */
    public function protectedLines(?string $notes): array
    {
        return array_values(array_filter(explode("\n", (string) $notes), fn ($l) => str_starts_with($l, self::REPLACE_PREFIX)));
    }

    /** La parte `$old` deja de existir; `$part` conserva sus identificadores para que la búsqueda por el número viejo la encuentre. */
    private function absorb(Part $part, Part $old, \DateTimeImmutable $now): void
    {
        $label = trim(($old->materialNumber ?: '—').' - '.$old->description);
        $ids = array_filter([
            $old->materialNumber, $old->sapPartNumber, $old->manufacturerPartNumber, $old->description, $old->replacedPartsText,
        ]);
        $part->replacedPartsText = trim(implode("\n", array_filter([$part->replacedPartsText, implode(' | ', $ids)])));
        $rest = array_filter(explode("\n", (string) $part->notes), fn ($l) => !str_starts_with($l, self::REPLACE_PREFIX));
        $part->notes = trim(implode("\n", [self::REPLACE_PREFIX.$label, ...$this->protectedLines($part->notes), ...$rest]));
        $old->deletedAt = $now;
    }

    /** Aviso legible que se guarda en la solicitud al crearla. */
    public function describeChange(PartRequest $r): ?string
    {
        if ($r->type === PartRequestType::Replacement && $r->targetPart) {
            return 'Replacement — Replaces '.trim(($r->targetPart->materialNumber ?: '—').' - '.$r->targetPart->description);
        }
        if ($r->type === PartRequestType::Modification && $r->targetPart) {
            $diff = $this->diff($r);
            $text = 'Modification request — Modified: '.($diff ? implode(', ', array_keys($diff)) : 'no field changes');
            if ($r->alsoReplacesPart) {
                $text .= ' — Also replaces '.trim(($r->alsoReplacesPart->materialNumber ?: '—').' - '.$r->alsoReplacesPart->description);
            }

            return $text;
        }

        return null;
    }

    /** @return array<string, array{from: mixed, to: mixed}> */
    public function diff(PartRequest $r): array
    {
        $p = $r->targetPart;
        if (!$p) {
            return [];
        }
        $pairs = [
            'Description' => [$p->description, $r->shortDescription],
            'Manufacturer' => [$p->manufacturer, $r->manufacturer],
            'Manufacturer part #' => [$p->manufacturerPartNumber, $r->manufacturerPartNumber],
            'Vendor' => [$p->vendor?->id, $r->vendor?->id],
            'Price' => [(float) $p->price, (float) $r->price],
            'Stock item' => [$p->stockItem, $r->stockItem],
            'Minimum quantity' => [$p->minimumQuantity, $r->minimumQuantity],
            'Desired quantity' => [$p->desiredQuantity, $r->desiredQuantity],
            'Class' => [$p->partClass?->id, $r->partClass?->id],
            'Sub class' => [$p->subClass?->id, $r->subClass?->id],
            'Equipment' => [
                $this->ids($p->equipments->toArray()),
                $this->ids($r->equipments->toArray()),
            ],
        ];
        $out = [];
        foreach ($pairs as $label => [$from, $to]) {
            if ($from != $to) {
                $out[$label] = ['from' => $from, 'to' => $to];
            }
        }

        return $out;
    }

    private function ids(array $items): array
    {
        $ids = array_map(fn ($i) => $i->id, $items);
        sort($ids);

        return $ids;
    }
}
