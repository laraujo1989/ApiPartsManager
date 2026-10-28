<?php

namespace App\Service;

use App\Entity\Setting;
use Doctrine\ORM\EntityManagerInterface;

class SettingsService
{
    /** Valores por defecto de la organización; se sobrescriben desde Settings → Organization. */
    public const DEFAULTS = [
        'org_name' => 'PartsHub',
        'default_plant' => null,
        'replacement_search_only_default_plant' => true,
        'session_timeout_minutes' => 20,
        'default_appearance' => ['mode' => 'light', 'accent' => '#4f46e5', 'radius' => 'md', 'density' => 'standard', 'font' => 'inter'],
    ];

    private ?array $cache = null;

    public function __construct(private EntityManagerInterface $em)
    {
    }

    public function all(): array
    {
        if ($this->cache === null) {
            $this->cache = self::DEFAULTS;
            foreach ($this->em->getRepository(Setting::class)->findAll() as $s) {
                $this->cache[$s->key] = $s->value;
            }
        }

        return $this->cache;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->all()[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $s = $this->em->find(Setting::class, $key) ?? new Setting();
        $s->key = $key;
        $s->value = $value;
        $this->em->persist($s);
        $this->cache = null;
    }
}
