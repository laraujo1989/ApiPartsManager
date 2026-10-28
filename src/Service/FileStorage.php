<?php

namespace App\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Guarda los archivos subidos fuera de /public (se sirven por /api/files, con sesión).
 * Mismas reglas que el sistema original: imágenes ≤5 MB y documentos ≤10 MB.
 */
class FileStorage
{
    public const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'jfif'];
    public const DOC_EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx'];
    public const SCOPES = ['part-request', 'requisition', 'service', 'part', 'user', 'consolidation'];

    public function __construct(#[Autowire('%kernel.project_dir%/var/uploads')] private string $root)
    {
    }

    /** @return array{path: string, name: string, size: int, kind: string} */
    public function save(UploadedFile $file, string $scope, string $kind): array
    {
        if (!\in_array($scope, self::SCOPES, true)) {
            throw new \DomainException('Unknown upload scope.');
        }
        $ext = strtolower($file->getClientOriginalExtension() ?: pathinfo($file->getClientOriginalName(), \PATHINFO_EXTENSION));
        $isImage = $kind === 'image';
        if (!\in_array($ext, $isImage ? self::IMAGE_EXT : self::DOC_EXT, true)) {
            throw new \DomainException('Allowed types: '.implode(', ', $isImage ? self::IMAGE_EXT : self::DOC_EXT).'.');
        }
        $limit = $isImage ? 5 : 10;
        if ($file->getSize() > $limit * 1024 * 1024) {
            throw new \DomainException("The file exceeds the {$limit} MB limit.");
        }
        if ($isImage && @getimagesize($file->getPathname()) === false) {
            throw new \DomainException('The file is not a valid image.');
        }

        $dir = $this->root.'/'.$scope;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create the upload directory.');
        }
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '_', pathinfo($file->getClientOriginalName(), \PATHINFO_FILENAME)) ?: 'file';
        $stored = bin2hex(random_bytes(6)).'-'.substr($base, 0, 60).'.'.$ext;
        $file->move($dir, $stored);

        return ['path' => '/api/files/'.$scope.'/'.$stored, 'name' => $file->getClientOriginalName(), 'size' => (int) filesize($dir.'/'.$stored), 'kind' => $kind];
    }

    public function resolve(string $scope, string $name): ?string
    {
        if (!\in_array($scope, self::SCOPES, true) || $name !== basename($name) || str_contains($name, '..')) {
            return null;
        }
        $path = $this->root.'/'.$scope.'/'.$name;

        return is_file($path) ? $path : null;
    }

    public function dir(string $scope): string
    {
        $dir = $this->root.'/'.$scope;
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }
}
