<?php

namespace App\Controller\Api;

use App\Entity\Area;
use App\Entity\CostCenter;
use App\Entity\Nomenclator;
use App\Entity\PartClass;
use App\Entity\SubClass;
use App\Entity\SubClassCharacteristic;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\RequestKind;
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Catálogos de solo lectura que alimentan selectores de los formularios. */
#[Route('/api/lookups')]
class LookupController extends ApiController
{
    public function __construct(private EntityManagerInterface $em, private Presenter $presenter)
    {
    }

    #[Route('/classes', methods: ['GET'])]
    public function classes(): JsonResponse
    {
        // Con el catálogo real son más de mil clases: la lista es liviana y las subclases se piden al elegir una clase.
        return $this->json($this->em->createQuery('SELECT c.id, c.name FROM '.PartClass::class.' c WHERE c.isActive = 1 ORDER BY c.name')->getArrayResult());
    }

    #[Route('/classes/{id<\d+>}/subclasses', methods: ['GET'])]
    public function subClasses(int $id): JsonResponse
    {
        $c = $this->found($this->em->find(PartClass::class, $id), 'Class');

        return $this->json(array_values(array_map(fn (SubClass $s) => [
            'id' => $s->id, 'name' => $s->name, 'abbreviation' => $s->abbreviation, 'code' => $s->code, 'description' => $s->description,
            'characteristics' => array_values(array_map(fn (SubClassCharacteristic $ch) => ['id' => $ch->id, 'name' => $ch->name, 'abbreviation' => $ch->abbreviation, 'charCode' => $ch->charCode], $s->characteristics->filter(fn ($x) => $x->isActive)->toArray())),
        ], $c->subClasses->filter(fn ($s) => $s->isActive)->toArray())));
    }

    #[Route('/vendors', methods: ['GET'])]
    public function vendors(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(Vendor::class)->createQueryBuilder('v')->where('v.isActive = 1')->orderBy('v.name')->setMaxResults(100);
        if ($t = (int) $request->query->get('type')) {
            $qb->andWhere('v.type = :t')->setParameter('t', $t);
        }
        if ($q = trim((string) $request->query->get('q', ''))) {
            $qb->andWhere('v.name LIKE :q OR v.vendorNumber LIKE :q')->setParameter('q', '%'.$q.'%');
        }

        return $this->json(array_map(function (Vendor $v) {
            return $this->presenter->vendor($v) + ['contractNumber' => $v->contractNumber, 'approvers' => array_map(fn (User $u) => $this->presenter->userBrief($u), $v->assignedUsers->toArray())];
        }, $qb->getQuery()->getResult()));
    }

    #[Route('/cost-centers', methods: ['GET'])]
    public function costCenters(): JsonResponse
    {
        return $this->json(array_map(fn (CostCenter $c) => $this->presenter->costCenter($c), $this->em->getRepository(CostCenter::class)->findBy(['isActive' => true], ['name' => 'ASC'])));
    }

    #[Route('/areas', methods: ['GET'])]
    public function areas(): JsonResponse
    {
        return $this->json(array_map(fn (Area $a) => $this->presenter->area($a), $this->em->getRepository(Area::class)->findBy(['isActive' => true], ['name' => 'ASC'])));
    }

    #[Route('/nomenclators', methods: ['GET'])]
    public function nomenclators(Request $request): JsonResponse
    {
        $criteria = ['isActive' => true];
        if ($t = $request->query->get('type')) {
            $criteria['type'] = $t;
        }

        return $this->json(array_map(fn (Nomenclator $n) => ['id' => $n->id, 'type' => $n->type, 'abbreviation' => $n->abbreviation, 'longName' => $n->longName], $this->em->getRepository(Nomenclator::class)->findBy($criteria, ['abbreviation' => 'ASC'])));
    }

    /** Árbol completo en lista plana; el cliente arma la jerarquía. */
    #[Route('/tree', methods: ['GET'])]
    public function tree(): JsonResponse
    {
        // Los nodos SapPart (decenas de miles) son partes de SAP colgadas de un equipo; no son ubicaciones y se omiten.
        $rows = $this->em->createQuery("SELECT n.id, IDENTITY(n.parent) AS parentId, n.name, n.nodeType, n.searchTag, n.description, n.isActive FROM App\Entity\TreeNode n WHERE n.nodeType != 'SapPart' ORDER BY n.name")->getArrayResult();

        return $this->json($rows);
    }

    /** Aprobadores o registradores sugeridos, primero los que comparten área con quien pide. */
    #[Route('/approvers', methods: ['GET'])]
    public function approvers(Request $request): JsonResponse
    {
        $kind = RequestKind::tryFrom((string) $request->query->get('kind')) ?? RequestKind::Part;
        $perm = $request->query->get('for') === 'register' ? $kind->registerPermission() : $kind->approvePermission();
        $me = $this->me();
        $myAreas = $me->areas->map(fn ($a) => $a->id)->toArray();

        $mandatory = [];
        if ($kind === RequestKind::Service && ($vid = (int) $request->query->get('vendorId')) && ($v = $this->em->find(Vendor::class, $vid))) {
            $mandatory = $v->assignedUsers->map(fn (User $u) => $u->id)->toArray();
        }

        $out = [];
        foreach ($this->em->getRepository(User::class)->findBy(['isActive' => true], ['fullName' => 'ASC']) as $u) {
            if (!$u->hasPermission($perm)) {
                continue;
            }
            $out[] = $this->presenter->userBrief($u) + [
                'sameArea' => (bool) array_intersect($myAreas, $u->areas->map(fn ($a) => $a->id)->toArray()),
                'areas' => $u->areas->map(fn ($a) => $a->name)->toArray(),
                'mandatory' => \in_array($u->id, $mandatory, true),
            ];
        }
        usort($out, fn ($a, $b) => [$b['mandatory'], $b['sameArea']] <=> [$a['mandatory'], $a['sameArea']]);

        return $this->json($out);
    }
}
