<?php

namespace App\Controller\Api;

use App\Entity\ImportJob;
use App\Enum\Permission;
use App\Service\ImportService;
use App\Service\Presenter;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Carga masiva de datos. Dos entradas con la misma lógica:
 *  - /api/admin/imports/{type}        → persona con sesión (Settings / Vendors)
 *  - /api/integrations/imports/{type} → automatización (n8n) con cabecera X-Api-Key
 */
class ImportController extends ApiController
{
    private const TYPES = [
        ImportJob::VENDORS_PARTS => [['EditInventory', 'ManageUsers'], ['txt', 'csv']],
        ImportJob::VENDORS_SERVICE => [['EditInventory', 'ManageUsers'], ['txt', 'csv']],
        ImportJob::MATERIALS => [['EditInventory', 'ManageUsers'], ['xlsx', 'xls', 'csv']],
        ImportJob::NOMENCLATURE => [['ManageUsers'], ['xlsx', 'xls']],
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ImportService $importer,
        private SettingsService $settings,
        private Presenter $presenter,
    ) {
    }

    private static function out(ImportJob $j, Presenter $p): array
    {
        return [
            'id' => $j->id, 'type' => $j->type, 'filename' => $j->filename, 'source' => $j->source, 'created' => $j->created, 'updated' => $j->updated,
            'skipped' => $j->skipped, 'errors' => $j->errors, 'success' => $j->success, 'user' => $p->userBrief($j->user), 'createdAt' => $j->createdAt->format(\DATE_ATOM),
        ];
    }

    #[Route('/api/admin/imports', methods: ['GET'])]
    public function history(): JsonResponse
    {
        $this->needAny([Permission::EditInventory, Permission::ManageUsers]);
        $rows = $this->em->getRepository(ImportJob::class)->findBy([], ['id' => 'DESC'], 30);

        return $this->json(array_map(fn ($j) => self::out($j, $this->presenter), $rows));
    }

    #[Route('/api/admin/imports/{type}', methods: ['POST'])]
    public function upload(string $type, Request $request): JsonResponse
    {
        $spec = self::TYPES[$type] ?? throw $this->createNotFoundException('Unknown import type.');
        $this->needAny($spec[0]);

        return $this->process($type, $request, $this->me(), 'manual');
    }

    #[Route('/api/integrations/imports/{type}', methods: ['POST'])]
    public function fromAutomation(string $type, Request $request): JsonResponse
    {
        if (!isset(self::TYPES[$type])) {
            throw $this->createNotFoundException('Unknown import type.');
        }
        $hash = $this->settings->get('integration_api_key_hash');
        $given = (string) $request->headers->get('X-Api-Key', '');
        if (!$hash || $given === '' || !hash_equals((string) $hash, hash('sha256', $given))) {
            return $this->json(['error' => 'Invalid or missing API key.'], 401);
        }

        return $this->process($type, $request, null, 'automation');
    }

    private function process(string $type, Request $request, ?\App\Entity\User $user, string $source): JsonResponse
    {
        /** @var UploadedFile|null $file */
        $file = $request->files->get('file');
        if (!$file) {
            return $this->json(['error' => 'Attach the file in the "file" field.'], 422);
        }
        $ext = strtolower($file->getClientOriginalExtension());
        if (!\in_array($ext, self::TYPES[$type][1], true)) {
            return $this->json(['error' => 'This import accepts: '.implode(', ', self::TYPES[$type][1]).'.'], 422);
        }
        if ($file->getSize() > 60 * 1024 * 1024) {
            return $this->json(['error' => 'The file exceeds the 60 MB limit.'], 422);
        }
        $name = $file->getClientOriginalName();
        $tmp = $file->move(sys_get_temp_dir(), bin2hex(random_bytes(6)).'.'.$ext);
        try {
            $job = $this->importer->run($type, $tmp->getPathname(), $name, $user, $source);
        } finally {
            @unlink($tmp->getPathname());
        }

        return $this->json(self::out($job, $this->presenter), $job->success ? 200 : 422);
    }

    /** Genera (o rota) la API key que usan las automatizaciones; solo se muestra una vez. */
    #[Route('/api/admin/integrations/api-key', methods: ['POST'])]
    public function rotateKey(): JsonResponse
    {
        $this->need(Permission::ManageUsers);
        $key = 'ph_'.bin2hex(random_bytes(24));
        $this->settings->set('integration_api_key_hash', hash('sha256', $key));
        $this->em->flush();

        return $this->json(['apiKey' => $key]);
    }
}
