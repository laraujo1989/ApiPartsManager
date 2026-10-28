<?php

namespace App\Controller\Api;

use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

abstract class ApiController extends AbstractController
{
    protected function me(): User
    {
        $u = $this->getUser();
        if (!$u instanceof User) {
            throw new AccessDeniedHttpException();
        }

        return $u;
    }

    protected function body(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);
        if (!\is_array($data)) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        return $data;
    }

    protected function need(string $permission): void
    {
        if (!$this->isGranted('PERM_'.$permission)) {
            throw new AccessDeniedHttpException('You do not have the "'.$permission.'" permission.');
        }
    }

    /** @param string[] $permissions al menos una */
    protected function needAny(array $permissions): void
    {
        foreach ($permissions as $p) {
            if ($this->isGranted('PERM_'.$p)) {
                return;
            }
        }
        throw new AccessDeniedHttpException('You do not have permission to do this.');
    }

    protected function found(?object $entity, string $what = 'Item'): object
    {
        return $entity ?? throw new NotFoundHttpException($what.' not found.');
    }

    /** Pagina un QueryBuilder (alias raíz obligatorio) y mapea cada fila. */
    protected function paginate(QueryBuilder $qb, Request $request, callable $map, string $countField = 'id'): JsonResponse
    {
        $page = max(1, (int) $request->query->get('page', 1));
        $size = min(100, max(1, (int) $request->query->get('pageSize', $this->me()->pageSize)));
        $alias = $qb->getRootAliases()[0];
        $total = (int) (clone $qb)->select('COUNT(DISTINCT '.$alias.'.'.$countField.')')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $rows = $qb->setFirstResult(($page - 1) * $size)->setMaxResults($size)->getQuery()->getResult();

        return $this->json(['items' => array_map($map, $rows), 'total' => $total, 'page' => $page, 'pageSize' => $size]);
    }

    protected function domainError(\DomainException $e): JsonResponse
    {
        return $this->json(['error' => $e->getMessage()], 422);
    }
}
