<?php

namespace App\Service;

use App\Entity\PartRequest;
use App\Entity\PartsRequisition;
use App\Entity\ServiceRequest;
use App\Entity\User;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * "Consolidation Form": Excel que el almacén carga en SAP para agregar líneas a un contrato.
 * Parte de la plantilla "Template for Contract Lines" y llena una fila por ítem desde la fila 2.
 */
class ConsolidationExporter
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/resources/templates/consolidation-template.xlsx')] private string $template,
        private FileStorage $storage,
        private SettingsService $settings,
    ) {
    }

    /** @return array{path: string, filename: string} */
    public function export(object $request, User $actor): array
    {
        $book = IOFactory::load($this->template);
        $sheet = $book->getActiveSheet();
        $plant = $actor->plants()[0] ?? $this->settings->get('default_plant') ?? '';
        $today = new \DateTimeImmutable();

        // Columnas (A=1): B contrato, D vendor, E parte local, H precio, K planta, L almacén, U/V vigencia, X moneda, Z material del vendor.
        $rows = $this->lines($request);
        if (!$rows) {
            throw new \DomainException('This request has nothing to export.');
        }
        $styles = [];
        foreach (range(1, 26) as $c) {
            $styles[$c] = $sheet->getCell([$c, 2])->getStyle()->exportArray();
        }
        $label = (string) $request->id;
        foreach ($rows as $i => $line) {
            $r = 2 + $i;
            $values = [2 => $line['contract'], 4 => $line['vendor'], 5 => $line['part'], 8 => (float) $line['price'], 11 => $plant, 12 => '2001',
                21 => $today->format('m/d/Y'), 22 => $today->modify('+10 days')->format('m/d/Y'), 24 => 'USD', 26 => $line['material']];
            foreach ($values as $c => $v) {
                $sheet->setCellValue([$c, $r], $v);
                if ($i > 0) {
                    $sheet->getCell([$c, $r])->getStyle()->applyFromArray($styles[$c]);
                }
            }
            $label = $line['part'] ?: $label;
        }

        $filename = 'Consolidation Form_'.preg_replace('/[^A-Za-z0-9._-]+/', '_', $label).'.xlsx';
        $path = $this->storage->dir('consolidation').'/'.bin2hex(random_bytes(4)).'-'.$filename;
        $this->save($book, $path);

        return ['path' => $path, 'filename' => $filename];
    }

    private function save(Spreadsheet $book, string $path): void
    {
        IOFactory::createWriter($book, 'Xlsx')->save($path);
    }

    /** @return array<int, array{contract: string, vendor: string, part: string, price: string, material: string}> */
    private function lines(object $e): array
    {
        if ($e instanceof PartRequest) {
            return [['contract' => (string) $e->vendor?->contractNumber, 'vendor' => (string) $e->vendor?->vendorNumber, 'part' => $e->manufacturerPartNumber, 'price' => $e->price, 'material' => $e->manufacturerPartNumber]];
        }
        if ($e instanceof ServiceRequest) {
            return [['contract' => 'SR-'.$e->id, 'vendor' => (string) $e->vendor?->vendorNumber, 'part' => 'SR-'.$e->id, 'price' => $e->quotedPrice ?? '0', 'material' => 'SR-'.$e->id]];
        }
        if ($e instanceof PartsRequisition) {
            return array_map(fn ($i) => ['contract' => (string) $i->quoteNumber, 'vendor' => (string) $i->vendor?->vendorNumber, 'part' => (string) $i->manufacturerPartNumber, 'price' => $i->unitPrice, 'material' => (string) $i->materialNumber], $e->items->toArray());
        }

        return [];
    }
}
