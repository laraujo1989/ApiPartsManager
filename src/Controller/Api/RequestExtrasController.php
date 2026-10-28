<?php

namespace App\Controller\Api;

use App\Entity\ActivityLog;
use App\Enum\RequestKind;
use App\Service\ConsolidationExporter;
use App\Service\Presenter;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/requests/{kind}/{id<\d+>}', requirements: ['kind' => 'part|requisition|service'])]
class RequestExtrasController extends ApiController
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowService $workflow,
        private Presenter $presenter,
        private ConsolidationExporter $consolidation,
    ) {
    }

    /** Excel "Consolidation Form" para cargar en SAP; lo genera quien registra ese tipo de solicitud. */
    #[Route('/consolidation', methods: ['GET'])]
    public function consolidationForm(string $kind, int $id): BinaryFileResponse
    {
        $e = $this->found($this->workflow->find(RequestKind::from($kind), $id), 'Request');
        if (!$this->workflow->canRegister($this->me(), $e)) {
            throw new AccessDeniedHttpException('You cannot generate this document.');
        }
        try {
            $file = $this->consolidation->export($e, $this->me());
        } catch (\DomainException $ex) {
            throw new UnprocessableEntityHttpException($ex->getMessage());
        }
        $response = new BinaryFileResponse($file['path']);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file['filename']);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/history', methods: ['GET'])]
    public function history(string $kind, int $id): JsonResponse
    {
        $e = $this->found($this->workflow->find(RequestKind::from($kind), $id), 'Request');
        if (!$this->workflow->canView($this->me(), $e)) {
            throw new AccessDeniedHttpException();
        }
        $rows = $this->em->getRepository(ActivityLog::class)->findBy(['entityType' => $kind, 'entityId' => $id], ['id' => 'ASC']);

        return $this->json(array_map(fn (ActivityLog $l) => [
            'id' => $l->id, 'action' => $l->action, 'description' => $l->description, 'changes' => $l->changes,
            'user' => $this->presenter->userBrief($l->user), 'createdAt' => $l->createdAt->format(\DATE_ATOM),
        ], $rows));
    }
}
