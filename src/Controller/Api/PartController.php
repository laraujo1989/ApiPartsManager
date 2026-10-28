<?php

namespace App\Controller\Api;

use App\Entity\ActivityLog;
use App\Entity\Part;
use App\Entity\PartClass;
use App\Entity\SubClass;
use App\Entity\TreeNode;
use App\Entity\Vendor;
use App\Enum\Permission;
use App\Service\ActivityLogger;
use App\Service\PartRegistrar;
use App\Service\Presenter;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

#[Route('/api/parts')]
class PartController extends ApiController
{
    public function __construct(
        private EntityManagerInterface $em,
        private Presenter $presenter,
        private ActivityLogger $logger,
        private PartRegistrar $registrar,
        private SettingsService $settings,
        private CacheInterface $cache,
    ) {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(Part::class)->createQueryBuilder('p')
            ->where('p.deletedAt IS NULL');
        $q = $request->query;

        // "q" admite varios términos separados por coma (los "chips" del filtro): todos deben coincidir.
        $terms = array_values(array_filter(array_map('trim', explode(',', (string) $q->get('q', ''))), fn ($t) => $t !== ''));
        if ($terms) {
            $ids = $this->searchIds($terms);
            if ($ids === []) {
                $qb->andWhere('1 = 0');
            } elseif ($ids !== null) {
                $qb->andWhere('p.id IN (:found)')->setParameter('found', $ids);
            } else {
                // Términos muy cortos: el índice FULLTEXT no los cubre, se busca por subcadena.
                foreach ($terms as $i => $term) {
                    $qb->andWhere("p.description LIKE :t$i OR p.materialNumber LIKE :t$i OR p.sapPartNumber LIKE :t$i OR p.manufacturer LIKE :t$i OR p.manufacturerPartNumber LIKE :t$i OR p.additionalText LIKE :t$i OR p.replacedPartsText LIKE :t$i")
                        ->setParameter("t$i", '%'.$term.'%');
                }
            }
        }
        if ($v = $q->get('classId')) {
            $qb->andWhere('p.partClass = :cl')->setParameter('cl', (int) $v);
        }
        if ($v = $q->get('subClassId')) {
            $qb->andWhere('p.subClass = :sc')->setParameter('sc', (int) $v);
        }
        if ($v = $q->get('manufacturer')) {
            $qb->andWhere('p.manufacturer = :mf')->setParameter('mf', $v);
        }
        if ($v = $q->get('plant')) {
            $qb->andWhere('p.plant = :pl')->setParameter('pl', $v);
        }
        if ($v = $q->get('source')) {
            $qb->andWhere('p.source = :src')->setParameter('src', $v);
        }
        if (\in_array($q->get('stock'), ['yes', 'no'], true)) {
            $qb->andWhere('p.stockItem = :st')->setParameter('st', $q->get('stock') === 'yes');
        }
        if ($q->get('lowStock')) {
            $qb->andWhere('p.stockItem = 1 AND p.currentQuantity < p.minimumQuantity');
        }
        // Flujo "This part replaces…": solo partes de la planta por defecto si así está configurado.
        if ($q->get('forReplacement') && ($dp = $this->settings->get('default_plant')) && $this->settings->get('replacement_search_only_default_plant')) {
            $qb->andWhere('p.plant = :dp')->setParameter('dp', $dp);
        }
        $qb->orderBy('p.description', 'ASC');

        return $this->paginate($qb, $request, fn (Part $p) => $this->presenter->part($p));
    }

    /**
     * Ids que coinciden con todos los términos usando el índice FULLTEXT (incluye replaced_parts_text:
     * buscar el número de una parte reemplazada devuelve la parte nueva). null = usar LIKE.
     *
     * @param string[] $terms
     * @return int[]|null
     */
    private function searchIds(array $terms): ?array
    {
        $boolean = [];
        foreach ($terms as $t) {
            foreach (preg_split('/[^\p{L}\p{N}]+/u', $t, -1, \PREG_SPLIT_NO_EMPTY) as $word) {
                if (mb_strlen($word) < 3) {
                    return null;
                }
                $boolean[] = '+'.$word.'*';
            }
        }
        if (!$boolean) {
            return null;
        }
        $ids = $this->em->getConnection()->fetchFirstColumn(
            'SELECT id FROM part WHERE deleted_at IS NULL AND MATCH(material_number, sap_part_number, description, manufacturer, manufacturer_part_number, additional_text, replaced_parts_text) AGAINST (? IN BOOLEAN MODE) LIMIT 5000',
            [implode(' ', $boolean)],
        );

        return array_map('intval', $ids);
    }

    #[Route('/facets', methods: ['GET'])]
    public function facets(): JsonResponse
    {
        // DISTINCT sobre cientos de miles de filas: se cachea unos minutos.
        return $this->json($this->cache->get('part_facets', function (ItemInterface $item) {
            $item->expiresAfter(600);
            $plants = $this->em->createQuery('SELECT DISTINCT p.plant FROM App\Entity\Part p WHERE p.deletedAt IS NULL AND p.plant IS NOT NULL ORDER BY p.plant')->getSingleColumnResult();

            return ['plants' => $plants];
        }));
    }

    #[Route('/{id<\d+>}', methods: ['GET'])]
    public function show(int $id): JsonResponse
    {
        $p = $this->found($this->em->find(Part::class, $id), 'Part');

        return $this->json($this->presenter->part($p));
    }

    #[Route('/{id<\d+>}/history', methods: ['GET'])]
    public function history(int $id): JsonResponse
    {
        $rows = $this->em->getRepository(ActivityLog::class)->findBy(['entityType' => 'part', 'entityId' => $id], ['id' => 'DESC'], 50);

        return $this->json(array_map(fn (ActivityLog $l) => [
            'id' => $l->id, 'action' => $l->action, 'description' => $l->description, 'changes' => $l->changes,
            'user' => $this->presenter->userBrief($l->user), 'createdAt' => $l->createdAt->format(\DATE_ATOM),
        ], $rows));
    }

    #[Route('', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $this->need(Permission::EditInventory);
        $p = new Part();
        $p->source = Part::SOURCE_REGISTERED;
        try {
            $this->apply($p, $this->body($request), true);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        $p->lastEditedBy = $this->me();
        $this->em->persist($p);
        $this->em->flush();
        $this->logger->log($this->me(), 'part.created', 'Part "'.$p->description.'" added', 'part', $p->id);
        $this->em->flush();

        return $this->json($this->presenter->part($p), 201);
    }

    #[Route('/{id<\d+>}', methods: ['PUT'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $this->need(Permission::EditInventory);
        $p = $this->found($this->em->find(Part::class, $id), 'Part');
        $before = $this->presenter->part($p);
        try {
            $this->apply($p, $this->body($request), $this->me()->role->canEditSensitiveInventory());
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
        $p->lastEditedBy = $this->me();
        $p->updatedAt = new \DateTimeImmutable();
        $this->em->flush();
        $after = $this->presenter->part($p);
        $changes = [];
        foreach ($after as $k => $v) {
            if (!\in_array($k, ['updatedAt', 'lowStock'], true) && json_encode($v) !== json_encode($before[$k] ?? null)) {
                $changes[$k] = ['from' => $before[$k] ?? null, 'to' => $v];
            }
        }
        $this->logger->log($this->me(), 'part.updated', 'Part "'.$p->description.'" edited', 'part', $p->id, $changes ?: null);
        $this->em->flush();

        return $this->json($after);
    }

    /** Edición rápida de la ubicación física (la puede corregir el almacén sin repetir el registro). */
    #[Route('/{id<\d+>}/bin', methods: ['PATCH'])]
    public function bin(int $id, Request $request): JsonResponse
    {
        $this->needAny([Permission::EditInventory, Permission::RegisterPartRequests]);
        $p = $this->found($this->em->find(Part::class, $id), 'Part');
        $bin = trim((string) ($this->body($request)['binLocation'] ?? ''));
        $p->binLocation = $bin ?: 'Pending';
        $p->updatedAt = new \DateTimeImmutable();
        $this->logger->log($this->me(), 'part.bin', 'Bin location of "'.$p->description.'" set to '.$p->binLocation, 'part', $p->id);
        $this->em->flush();

        return $this->json($this->presenter->part($p));
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->need(Permission::EditInventory);
        $p = $this->found($this->em->find(Part::class, $id), 'Part');
        $p->deletedAt = new \DateTimeImmutable();
        $this->logger->log($this->me(), 'part.deleted', 'Part "'.$p->description.'" deleted', 'part', $p->id);
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private static function s(array $d, string $k): ?string
    {
        $v = trim((string) ($d[$k] ?? ''));

        return $v === '' ? null : $v;
    }

    /** @param bool $sensitive true si el usuario puede tocar los campos sensibles (o si se está creando). */
    private function apply(Part $p, array $d, bool $sensitive): void
    {
        $desc = self::s($d, 'description') ?? throw new \DomainException('Description is required.');
        $p->description = $desc;
        foreach (['additionalText', 'manufacturer', 'manufacturerPartNumber', 'manufacturerInternalPartNumber', 'leadTimeDays', 'storageLocation', 'plant'] as $f) {
            if (\array_key_exists($f, $d)) {
                $p->$f = self::s($d, $f);
            }
        }
        if (\array_key_exists('binLocation', $d)) {
            $p->binLocation = self::s($d, 'binLocation');
        }
        if (isset($d['price'])) {
            $p->price = (string) max(0, (float) $d['price']);
        }
        if (\array_key_exists('vendorId', $d)) {
            $p->vendor = $d['vendorId'] ? $this->em->find(Vendor::class, (int) $d['vendorId']) : null;
        }
        if (isset($d['images'])) {
            $p->images = array_values((array) $d['images']);
        }
        if (isset($d['manuals'])) {
            $p->manuals = array_values((array) $d['manuals']);
        }
        if (\array_key_exists('notes', $d)) {
            // Las líneas "This part replaces …" no se editan ni se borran.
            $incoming = array_filter(explode("\n", (string) $d['notes']), fn ($l) => !str_starts_with($l, PartRegistrar::REPLACE_PREFIX));
            $p->notes = trim(implode("\n", [...$this->registrar->protectedLines($p->notes), ...$incoming])) ?: null;
        }
        if (!$sensitive) {
            return;
        }
        foreach (['materialNumber', 'sapPartNumber'] as $f) {
            if (\array_key_exists($f, $d)) {
                $p->$f = self::s($d, $f);
            }
        }
        if (\array_key_exists('classId', $d)) {
            $p->partClass = $d['classId'] ? $this->em->find(PartClass::class, (int) $d['classId']) : null;
            $p->classLabel = null;
        }
        if (\array_key_exists('subClassId', $d)) {
            $p->subClass = $d['subClassId'] ? $this->em->find(SubClass::class, (int) $d['subClassId']) : null;
            $p->subClassLabel = null;
        }
        if (isset($d['characteristics'])) {
            $p->characteristics = (array) $d['characteristics'];
        }
        if (isset($d['stockItem'])) {
            $p->stockItem = (bool) $d['stockItem'];
        }
        if ($p->stockItem) {
            foreach (['minimumQuantity', 'desiredQuantity', 'currentQuantity'] as $f) {
                if (isset($d[$f])) {
                    $p->$f = max(0, (int) $d[$f]);
                }
            }
        } else {
            $p->minimumQuantity = 0;
            $p->desiredQuantity = 0;
            if (isset($d['currentQuantity'])) {
                $p->currentQuantity = max(0, (int) $d['currentQuantity']);
            }
        }
        if (isset($d['equipmentIds'])) {
            $p->equipments->clear();
            foreach ($this->em->getRepository(TreeNode::class)->findBy(['id' => array_map('intval', (array) $d['equipmentIds'])]) as $n) {
                $p->equipments->add($n);
            }
        }
    }
}
