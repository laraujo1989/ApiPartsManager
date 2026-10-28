<?php

namespace App\Controller\Api;

use App\Entity\Notification;
use App\Service\Presenter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/notifications')]
class NotificationController extends ApiController
{
    public function __construct(private EntityManagerInterface $em, private Presenter $presenter)
    {
    }

    #[Route('', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $qb = $this->em->getRepository(Notification::class)->createQueryBuilder('n')
            ->where('n.user = :me')->setParameter('me', $this->me())->orderBy('n.createdAt', 'DESC');
        if ($request->query->get('unread')) {
            $qb->andWhere('n.isRead = 0');
        }
        if ($t = $request->query->get('type')) {
            $qb->andWhere('n.type = :t')->setParameter('t', $t);
        }

        return $this->paginate($qb, $request, fn (Notification $n) => $this->presenter->notification($n));
    }

    /** Lo consulta el frontend cada minuto: contador y si hay alguna urgente sin leer (para la campana). */
    #[Route('/summary', methods: ['GET'])]
    public function summary(): JsonResponse
    {
        $repo = $this->em->getRepository(Notification::class);
        $me = $this->me();

        return $this->json([
            'unread' => $repo->count(['user' => $me, 'isRead' => false]),
            'urgent' => $repo->count(['user' => $me, 'isRead' => false, 'isUrgent' => true]),
        ]);
    }

    #[Route('/{id<\d+>}/read', methods: ['POST'])]
    public function read(int $id): JsonResponse
    {
        $n = $this->own($id);
        $n->isRead = true;
        $n->readAt = new \DateTimeImmutable();
        $this->em->flush();

        return $this->json($this->presenter->notification($n));
    }

    #[Route('/read-all', methods: ['POST'])]
    public function readAll(): JsonResponse
    {
        $this->em->createQuery('UPDATE App\Entity\Notification n SET n.isRead = 1, n.readAt = :now WHERE n.user = :me AND n.isRead = 0')
            ->setParameter('now', new \DateTimeImmutable())->setParameter('me', $this->me())->execute();

        return $this->json(['ok' => true]);
    }

    #[Route('/{id<\d+>}', methods: ['DELETE'])]
    public function delete(int $id): JsonResponse
    {
        $this->em->remove($this->own($id));
        $this->em->flush();

        return $this->json(['ok' => true]);
    }

    private function own(int $id): Notification
    {
        $n = $this->found($this->em->find(Notification::class, $id), 'Notification');
        if ($n->user !== $this->me()) {
            throw $this->createAccessDeniedException();
        }

        return $n;
    }
}
