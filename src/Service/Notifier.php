<?php

namespace App\Service;

use App\Entity\Notification;
use App\Entity\User;
use App\Enum\RequestKind;
use Doctrine\ORM\EntityManagerInterface;

class Notifier
{
    public function __construct(private EntityManagerInterface $em)
    {
    }

    /** @param User|iterable<User> $users */
    public function notify(User|iterable $users, string $title, string $message, string $type = 'system', ?RequestKind $kind = null, ?int $relatedId = null, bool $urgent = false): void
    {
        foreach ($users instanceof User ? [$users] : $users as $user) {
            $n = new Notification();
            $n->user = $user;
            $n->title = $title;
            $n->message = $message;
            $n->type = $type;
            $n->relatedKind = $kind?->value;
            $n->relatedId = $relatedId;
            $n->isUrgent = $urgent;
            $this->em->persist($n);
        }
    }

    /**
     * Usuarios activos con un permiso, priorizando (si hay) a los que comparten área con el solicitante.
     *
     * @return User[]
     */
    public function usersWithPermission(string $permission, ?User $sameAreaAs = null): array
    {
        $all = array_values(array_filter(
            $this->em->getRepository(User::class)->findBy(['isActive' => true]),
            fn (User $u) => $u->hasPermission($permission),
        ));
        if ($sameAreaAs && !$sameAreaAs->areas->isEmpty()) {
            $ids = $sameAreaAs->areas->map(fn ($a) => $a->id)->toArray();
            $shared = array_values(array_filter($all, fn (User $u) => $u->areas->exists(fn ($k, $a) => \in_array($a->id, $ids, true))));
            if ($shared) {
                return $shared;
            }
        }

        return $all;
    }
}
