<?php

namespace App\Service;

use App\Entity\ImportJob;
use App\Entity\Nomenclator;
use App\Entity\Part;
use App\Entity\PartClass;
use App\Entity\SubClass;
use App\Entity\SubClassCharacteristic;
use App\Entity\User;
use App\Entity\Vendor;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Cargas masivas de datos que en el sistema original se hacían desde Settings y Vendors:
 * proveedores desde el archivo de texto de SAP, materiales (layout PARTSEXPORTS) y el Excel de nomenclatura.
 * Cada carga es idempotente: volver a subir el mismo archivo no duplica nada (sirve para sincronizaciones automáticas).
 */
class ImportService
{
    public function __construct(private EntityManagerInterface $em, private SettingsService $settings, private ActivityLogger $logger)
    {
    }

    public function run(string $type, string $path, string $filename, ?User $user, string $source = 'manual'): ImportJob
    {
        $job = new ImportJob();
        $job->type = $type;
        $job->filename = $filename;
        $job->user = $user;
        $job->source = $source;
        @set_time_limit(0);
        try {
            match ($type) {
                ImportJob::VENDORS_PARTS => $this->vendors($job, $path, Vendor::TYPE_PARTS),
                ImportJob::VENDORS_SERVICE => $this->vendors($job, $path, Vendor::TYPE_SERVICE),
                ImportJob::MATERIALS => $this->materials($job, $path),
                ImportJob::NOMENCLATURE => $this->nomenclature($job, $path),
                default => throw new \DomainException('Unknown import type.'),
            };
        } catch (\Throwable $e) {
            $job->success = false;
            $job->errors[] = $e->getMessage();
        }
        $job->errors = \array_slice($job->errors, 0, 50);
        // Las cargas grandes limpian el EntityManager: se vuelve a referenciar al usuario.
        $user = $user ? $this->em->getReference(User::class, $user->id) : null;
        $job->user = $user;
        $this->em->persist($job);
        $this->logger->log($user, 'import.'.$type, sprintf('Import %s "%s": %d created, %d updated, %d skipped', $type, $filename, $job->created, $job->updated, $job->skipped), 'import');
        $this->em->flush();

        return $job;
    }

    // ------------------------------------------------------------------ vendors (SAP text)

    /**
     * Formato SAP separado por "|": líneas 1-3 y 5 son cabeceras/separadores, la 4 trae el "Number" global
     * en la columna 24, y desde la 6 cada línea es un proveedor (col 2 = número SAP, col 3 = nombre).
     */
    private function vendors(ImportJob $job, string $path, int $type): void
    {
        $text = (string) file_get_contents($path);
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $global = '';
        $existing = [];
        foreach ($this->em->getRepository(Vendor::class)->findBy(['type' => $type]) as $v) {
            if ($v->vendorNumber !== null) {
                $existing[$v->vendorNumber] = $v;
            }
        }

        foreach ($lines as $i => $line) {
            $n = $i + 1;
            if ($n === 4) {
                $p = explode('|', $line);
                $global = trim($p[23] ?? '');
            }
            if ($n < 6 || trim($line) === '') {
                continue;
            }
            $p = array_map('trim', explode('|', $line));
            if (\count($p) < 25) {
                continue;
            }
            [, $number, $name] = $p;
            if ($number === '' || $name === '') {
                ++$job->skipped;
                continue;
            }
            $v = $existing[$number] ?? null;
            $isNew = $v === null;
            $v ??= new Vendor();
            $v->vendorNumber = $number;
            $v->name = $name;
            $v->type = $type;
            $v->city = $p[4] ?: null;
            $v->country = $p[6] ?: null;
            $v->address = $p[9] ?: null;
            $v->phone = $p[12] ?: null;
            $v->incoterms = $p[17] ?: null;
            $v->currency = $p[22] ?: null;
            $v->minValue = (string) (is_numeric(str_replace(',', '', $p[21])) ? (float) str_replace(',', '', $p[21]) : 0);
            $v->purchasingGroup = $p[25] ?? null;
            $v->extra = [
                'globalNumber' => $global, 'searchTerm' => $p[3], 'postCode' => $p[5], 'porg' => $p[7], 'payT' => $p[8], 'isB' => strcasecmp($p[10], 'X') === 0,
                'salespers' => $p[11], 'bf' => (int) $p[13], 'delF' => $p[14] !== '', 'oneTime' => $p[15], 'descr' => $p[16], 'inco2' => $p[18],
                'isB2' => $p[19] !== '', 'isD' => $p[20] !== '', 'porg2' => $p[23],
            ];
            if ($isNew) {
                $this->em->persist($v);
                $existing[$number] = $v;
                ++$job->created;
            } else {
                ++$job->updated;
            }
        }
        $this->em->flush();
    }

    // ------------------------------------------------------------------ local materials (PARTSEXPORTS)

    private const MATERIAL_HEADERS = [
        'materialNumber' => ['material'],
        'description' => ['material description'],
        'binLocation' => ['bin'],
        'manufacturerPartNumber' => ['manufacturer part number'],
        'manufacturer' => ['manufacturer name'],
        'class' => ['class'],
        'subClass' => ['subclass'],
        'plant' => ['plnt', 'plant'],
        'storageLocation' => ['sloc'],
        'minimum' => ['reorder pt'],
        'desired' => ['max. level'],
        'price' => ['price'],
        'vendor' => ['supplier'],
        'onHand' => ['unrestricted', 'unrestr.', 'unrestricted-use', 'stock'],
    ];

    private function materials(ImportJob $job, string $path): void
    {
        $rows = $this->rows($path);
        // La cabecera es la primera fila (de las 5 primeras) que contiene la columna "Material".
        $headerAt = null;
        foreach (\array_slice($rows, 0, 5, true) as $i => $r) {
            if (\in_array('material', array_map(fn ($c) => strtolower(trim($c)), $r), true)) {
                $headerAt = $i;
                break;
            }
        }
        if ($headerAt === null) {
            throw new \DomainException('The "Material" column was not found. Check that the file uses the PARTSEXPORTS layout.');
        }
        $col = [];
        foreach ($rows[$headerAt] as $i => $h) {
            $h = strtolower(trim((string) $h));
            foreach (self::MATERIAL_HEADERS as $field => $names) {
                if (!isset($col[$field]) && (\in_array($h, $names, true) || ($field === 'description' && str_contains($h, 'material description')))) {
                    $col[$field] = $i;
                }
            }
            if (str_contains($h, 'po text')) {
                $col['poText'] = $i;
            }
        }

        $existing = [];
        foreach ($this->em->createQuery('SELECT p.id, p.materialNumber, p.plant, p.source FROM '.Part::class.' p WHERE p.materialNumber IS NOT NULL AND p.deletedAt IS NULL')->toIterable() as $r) {
            $existing[$r['materialNumber'].'|'.$r['plant']] = $r;
            $existing[$r['materialNumber']] ??= $r;
        }
        $get = fn (array $r, string $f): string => isset($col[$f]) ? trim((string) ($r[$col[$f]] ?? '')) : '';
        $num = fn (string $v): float => is_numeric($clean = str_replace(',', '', $v)) ? (float) $clean : 0.0;

        $count = \count($rows);
        $i = $headerAt + 1;
        $pending = 0;
        while ($i < $count) {
            $r = $rows[$i];
            $material = $get($r, 'materialNumber');
            ++$i;
            // Las filas de texto PO empiezan con "/" (o con la etiqueta "PO Text") y no son materiales.
            if ($material === '' || str_contains($material, '/') || stripos($material, 'PO Text') === 0) {
                continue;
            }
            // Las filas siguientes (columna B = texto, C = valor) son el "PO Text" de este material.
            $po = [];
            while ($i < $count && !$this->blank($rows[$i])) {
                $first = trim((string) ($rows[$i][1] ?? ''));
                if ($first === '') {
                    break;
                }
                $second = trim((string) ($rows[$i][2] ?? ''));
                $po[] = trim(str_replace('/', '', $first)).($second !== '' ? ': '.$second : '');
                ++$i;
            }

            $plant = $get($r, 'plant');
            $key = $material.'|'.$plant;
            $hit = $existing[$key] ?? null;
            if ($hit && ($hit['source'] === Part::SOURCE_REGISTERED || !$hit['id'])) {
                // Registrada aquí (el sistema manda) o repetida dentro del mismo archivo.
                ++$job->skipped;
                continue;
            }
            $p = $hit ? $this->em->find(Part::class, $hit['id']) : new Part();
            $before = $hit ? md5(serialize([$p->description, $p->binLocation, $p->manufacturer, $p->manufacturerPartNumber, $p->price, $p->minimumQuantity, $p->desiredQuantity, $p->currentQuantity, $p->additionalText, $p->vendorName])) : null;

            $p->source = Part::SOURCE_IMPORTED;
            $p->materialNumber = $material;
            $p->description = mb_substr($get($r, 'description') ?: $material, 0, 255);
            $p->binLocation = $get($r, 'binLocation') ?: null;
            $p->manufacturer = $get($r, 'manufacturer') ?: null;
            $p->manufacturerPartNumber = $get($r, 'manufacturerPartNumber') ?: null;
            $p->classLabel = $get($r, 'class') ?: null;
            $p->subClassLabel = $get($r, 'subClass') ?: null;
            $p->plant = $plant ?: null;
            $p->storageLocation = $get($r, 'storageLocation') ?: null;
            $p->minimumQuantity = (int) $num($get($r, 'minimum'));
            $p->desiredQuantity = (int) $num($get($r, 'desired'));
            $p->currentQuantity = isset($col['onHand']) ? (int) $num($get($r, 'onHand')) : $p->currentQuantity;
            $p->stockItem = $p->minimumQuantity > 0 || $p->desiredQuantity > 0;
            $p->price = (string) $num($get($r, 'price'));
            $p->vendorName = $get($r, 'vendor') ?: null;
            $p->additionalText = $po ? implode(' | ', $po) : null;

            if (!$hit) {
                $this->em->persist($p);
                $existing[$key] = ['id' => 0, 'source' => Part::SOURCE_IMPORTED, 'materialNumber' => $material, 'plant' => $plant];
                ++$job->created;
            } elseif ($before !== md5(serialize([$p->description, $p->binLocation, $p->manufacturer, $p->manufacturerPartNumber, $p->price, $p->minimumQuantity, $p->desiredQuantity, $p->currentQuantity, $p->additionalText, $p->vendorName]))) {
                $p->updatedAt = new \DateTimeImmutable();
                ++$job->updated;
            } else {
                ++$job->skipped;
            }
            if (++$pending % 500 === 0) {
                $this->em->flush();
                $this->em->clear();
            }
        }
        $this->em->flush();
    }

    // ------------------------------------------------------------------ nomenclatura

    private function nomenclature(ImportJob $job, string $path): void
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $cell = fn (array $r, int $i): string => trim((string) ($r[$i] ?? ''));

        // Clases y subclases: hoja "NM - Class SubClass" (A = Mount "CLASE:SUB", C = Abv, D = Sub_Class_Code, F/G = guías)
        if ($sheet = $book->getSheetByName('NM - Class SubClass')) {
            $classes = [];
            foreach ($this->em->getRepository(PartClass::class)->findAll() as $c) {
                $classes[$c->name] = $c;
            }
            $subs = [];
            foreach ($this->em->getRepository(SubClass::class)->findAll() as $s) {
                $subs[$s->code] = $s;
            }
            foreach (\array_slice($this->sheetRows($sheet), 1) as $r) {
                $mount = $cell($r, 0);
                if ($mount === '' || str_starts_with($mount, '#')) {
                    continue;
                }
                $className = str_contains($mount, ':') ? explode(':', $mount)[0] : $mount;
                if (!isset($classes[$className])) {
                    $c = new PartClass();
                    $c->name = $className;
                    $c->description = $cell($r, 5) ?: null;
                    $this->em->persist($c);
                    $classes[$className] = $c;
                    ++$job->created;
                }
                $code = $cell($r, 3);
                if ($code === '' || isset($subs[$code])) {
                    continue;
                }
                $s = new SubClass();
                $s->partClass = $classes[$className];
                $s->name = str_contains($code, ':') ? explode(':', $code, 2)[1] : $code;
                $s->abbreviation = $cell($r, 2);
                $s->code = $code;
                $s->description = $cell($r, 6) ?: null;
                $this->em->persist($s);
                $subs[$code] = $s;
                ++$job->created;
            }
            $this->em->flush();
        }

        // Características: "NMC - Class SubClass Char" (C = nombre, D = Abv, E = Seq, F = Sub_Class_Code, G = Char_Code)
        if ($sheet = $book->getSheetByName('NMC - Class SubClass Char')) {
            $subs = [];
            foreach ($this->em->getRepository(SubClass::class)->findAll() as $s) {
                $subs[$s->code] = $s;
            }
            $have = [];
            foreach ($this->em->getRepository(SubClassCharacteristic::class)->findAll() as $c) {
                $have[$c->subClass->id.'|'.$c->name.'|'.$c->charCode] = true;
            }
            foreach (\array_slice($this->sheetRows($sheet), 1) as $r) {
                $s = $subs[$cell($r, 5)] ?? null;
                $name = $cell($r, 2);
                if (!$s || $name === '') {
                    continue;
                }
                $key = $s->id.'|'.$name.'|'.$cell($r, 6);
                if (isset($have[$key])) {
                    continue;
                }
                $c = new SubClassCharacteristic();
                $c->subClass = $s;
                $c->name = $name;
                $c->abbreviation = $cell($r, 3);
                $c->charCode = $cell($r, 6);
                $c->sequence = (int) $cell($r, 4);
                $c->description = $cell($r, 7) ?: null;
                $this->em->persist($c);
                $have[$key] = true;
                ++$job->created;
            }
            $this->em->flush();
        }

        // Abreviaturas: "Abbreviations in ABC Order" → Order, "Abbrv in Word Order" → WO (desde la fila 3)
        foreach (['Abbreviations in ABC Order' => 'Order', 'Abbrv in Word Order' => 'WO'] as $name => $type) {
            if (!($sheet = $book->getSheetByName($name))) {
                continue;
            }
            $have = [];
            foreach ($this->em->getRepository(Nomenclator::class)->findBy(['type' => $type]) as $n) {
                $have[$n->abbreviation] = true;
            }
            foreach (\array_slice($this->sheetRows($sheet), 2) as $r) {
                $abbr = $cell($r, 0);
                $long = $cell($r, 1);
                if ($abbr === '' || $long === '' || isset($have[$abbr])) {
                    continue;
                }
                $n = new Nomenclator();
                $n->type = $type;
                $n->abbreviation = $abbr;
                $n->longName = $long;
                $this->em->persist($n);
                $have[$abbr] = true;
                ++$job->created;
            }
            $this->em->flush();
        }

        // Fabricantes (OEM List) y reglas/instrucciones se guardan como JSON en Settings (el original los dejaba en wwwroot/resources).
        if ($sheet = $book->getSheetByName('OEM List')) {
            $oems = [];
            foreach (\array_slice($this->sheetRows($sheet), 1) as $r) {
                if ($cell($r, 0) !== '') {
                    $oems[] = ['code' => $cell($r, 0), 'name' => $cell($r, 1), 'type' => $cell($r, 2)];
                }
            }
            $this->settings->set('manufacturers', $oems);
            $job->updated += \count($oems);
        }
        $standards = ['updatedAt' => date(\DATE_ATOM), 'rules' => [], 'instructions' => []];
        if ($sheet = $book->getSheetByName('Standard Rules')) {
            foreach (\array_slice($this->sheetRows($sheet), 2) as $r) {
                if (array_filter($r, fn ($c) => trim((string) $c) !== '')) {
                    $standards['rules'][] = array_map(fn ($c) => trim((string) $c), $r);
                }
            }
        }
        if ($sheet = $book->getSheetByName('Instructions')) {
            foreach ($this->sheetRows($sheet) as $r) {
                $line = trim(implode(' ', array_filter(array_map(fn ($c) => trim((string) $c), $r))));
                if ($line !== '') {
                    $standards['instructions'][] = $line;
                }
            }
        }
        $this->settings->set('nomenclature_standards', $standards);
        $this->em->flush();
    }

    // ------------------------------------------------------------------ utilidades

    /** @return array<int, array<int, string>> */
    private function rows(string $path): array
    {
        $ext = strtolower(pathinfo($path, \PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            $reader = new CsvReader();
            $book = $reader->load($path);
        } else {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(true);
            $book = $reader->load($path);
        }

        return $this->sheetRows($book->getActiveSheet());
    }

    /** @return array<int, array<int, string>> */
    private function sheetRows(Worksheet $sheet): array
    {
        return array_map(fn ($r) => array_map(fn ($c) => $c === null ? '' : (string) $c, $r), $sheet->toArray(null, true, false, false));
    }

    /** @param array<int, string> $r */
    private function blank(array $r): bool
    {
        foreach ($r as $c) {
            if (trim((string) $c) !== '' && trim((string) $c) !== ',') {
                return false;
            }
        }

        return true;
    }
}
