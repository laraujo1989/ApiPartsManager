<?php

namespace App\Controller\Api;

use App\Service\FileStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/api/files')]
class FileController extends ApiController
{
    public function __construct(private FileStorage $storage)
    {
    }

    /** multipart: file, scope (part-request | requisition | service | part | user), kind (image | document). */
    #[Route('', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'No file was uploaded.'], 422);
        }
        try {
            return $this->json($this->storage->save($file, (string) $request->request->get('scope', 'part-request'), (string) $request->request->get('kind', 'document')), 201);
        } catch (\DomainException $e) {
            return $this->domainError($e);
        }
    }

    #[Route('/{scope}/{name}', methods: ['GET'], requirements: ['name' => '[^/]+'])]
    public function download(string $scope, string $name, Request $request): BinaryFileResponse
    {
        $path = $this->storage->resolve($scope, $name);
        if (!$path) {
            throw $this->createNotFoundException('File not found.');
        }
        $response = new BinaryFileResponse($path);
        $inline = \in_array(strtolower(pathinfo($name, \PATHINFO_EXTENSION)), [...FileStorage::IMAGE_EXT, 'pdf'], true);
        $response->setContentDisposition($inline && !$request->query->get('download') ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, preg_replace('/^[0-9a-f]{12}-/', '', $name));
        $response->headers->set('Cache-Control', 'private, max-age=3600');

        return $response;
    }
}
