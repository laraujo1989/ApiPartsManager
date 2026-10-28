<?php

namespace App\Command;

use App\Entity\Area;
use App\Entity\CostCenter;
use App\Entity\Nomenclator;
use App\Entity\Part;
use App\Entity\PartClass;
use App\Entity\SubClass;
use App\Entity\SubClassCharacteristic;
use App\Entity\TreeNode;
use App\Entity\User;
use App\Entity\Vendor;
use App\Enum\UserRole;
use App\Service\SettingsService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:seed-demo', description: 'Crea un usuario por rol, catálogos mínimos y datos de ejemplo para probar el sistema.')]
class SeedDemoCommand extends Command
{
    public const DEMO_PASSWORD = 'Demo1234';

    public function __construct(
        private EntityManagerInterface $em,
        private UserPasswordHasherInterface $hasher,
        private SettingsService $settings,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('admin-only', null, InputOption::VALUE_NONE, 'Solo crea el superadmin (para producción).');
        $this->addOption('users-only', null, InputOption::VALUE_NONE, 'Crea (o restablece) solo los usuarios demo, aunque la base ya tenga datos.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($input->getOption('admin-only')) {
            $this->user('superadmin', 'Super Administrator', UserRole::SuperAdmin, 'Admin1234', [], true);
            $this->em->flush();
            $io->success('superadmin / Admin1234 (se pedirá cambiar la contraseña al primer ingreso).');

            return Command::SUCCESS;
        }
        if ($input->getOption('users-only')) {
            $users = $this->demoUsers($this->area('Maintenance', 'PLANT-01'), $this->area('Storeroom', 'PLANT-01'));
            $this->em->flush();
            $io->success('Usuarios demo listos.');
            $io->table(['Usuario', 'Contraseña', 'Rol'], array_map(fn (User $u) => [$u->username, self::DEMO_PASSWORD, $u->role->label()], $users));

            return Command::SUCCESS;
        }
        if ($this->em->getRepository(User::class)->count([]) > 0) {
            $io->warning('Ya hay usuarios; no se vuelve a sembrar.');

            return Command::SUCCESS;
        }

        $plant = 'PLANT-01';
        $this->settings->set('default_plant', $plant);
        $this->settings->set('org_name', 'PartsHub Demo');

        $areaMaint = $this->area('Maintenance', $plant);
        $areaStore = $this->area('Storeroom', $plant);
        $this->area('Utilities', 'PLANT-02');

        $users = $this->demoUsers($areaMaint, $areaStore);

        foreach (['Operations' => 'Plant operations', 'Maintenance' => 'Preventive maintenance'] as $n => $d) {
            $c = new CostCenter();
            $c->name = 'CC-'.strtoupper(substr($n, 0, 3)).'-100';
            $c->description = $d;
            $this->em->persist($c);
        }
        foreach ([['WO', 'WO', 'Work Order'], ['Order', 'ORD', 'Order']] as [$t, $a, $l]) {
            $n = new Nomenclator();
            $n->type = $t;
            $n->abbreviation = $a;
            $n->longName = $l;
            $this->em->persist($n);
        }

        $vendorParts = $this->vendor('Industrial Supply Co.', Vendor::TYPE_PARTS, 'V-1001');
        $this->vendor('Motion Industries', Vendor::TYPE_PARTS, 'V-1002');
        $svc = $this->vendor('Apex Contractors', Vendor::TYPE_SERVICE, 'V-2001');
        $this->vendor('Reliable Calibration Services', Vendor::TYPE_SERVICE, 'V-2002');

        $bearing = $this->partClass('BEARING', ['BALL' => ['Bore', 'Outer diameter', 'Seal type'], 'ROLLER' => ['Bore', 'Width']]);
        $seal = $this->partClass('SEAL', ['MECHANICAL' => ['Shaft diameter', 'Face material'], 'ORING' => ['Inner diameter', 'Material']]);
        $this->partClass('ABRASIVE', ['BELT' => ['Grit', 'Length'], 'DISC' => ['Grit', 'Diameter']]);

        $area = $this->node('Production Area A', 'Area', null);
        $loc = $this->node('Pump House', 'Location', $area);
        $grp = $this->node('Crude Charge Pumps', 'EquipmentGroup', $loc);
        $eq1 = $this->node('Crude Charge Pump 102', 'Equipment', $grp, '(P-102)');
        $eq2 = $this->node('Crude Charge Pump 103', 'Equipment', $grp, '(P-103)');
        $this->node('Boiler Feed Aux', 'Equipment', $loc, '(BF-01)');

        $this->em->flush();

        $samples = [
            ['MAT-10001', 'Ball bearing 6205-2RS 25x52x15', 'SKF', '6205-2RS', $bearing, $bearing->subClasses[0], $vendorParts, '12.50', true, 4, 10, 2, 'A-01-03', [$eq1]],
            ['MAT-10002', 'Mechanical seal ISC2PP 2.500in dual cartridge', 'Flowserve', 'ISC2PP-2500', $seal, $seal->subClasses[0], $vendorParts, '2450.00', true, 2, 6, 1, 'B-12-04', [$eq1, $eq2]],
            ['MAT-10003', 'O-ring kit Viton 382 pcs', 'Parker', 'OR-VITON-382', $seal, $seal->subClasses[1], $vendorParts, '64.90', true, 3, 8, 5, 'C-02-01', []],
            ['MAT-10004', 'Roller bearing NU2310 ECP', 'SKF', 'NU2310ECP', $bearing, $bearing->subClasses[1], $vendorParts, '210.00', false, 0, 0, 0, 'Nonstock', [$eq2]],
        ];
        foreach ($samples as [$mat, $desc, $mf, $mpn, $cl, $sc, $v, $price, $stock, $min, $des, $cur, $bin, $eqs]) {
            $p = new Part();
            $p->materialNumber = $mat;
            $p->sapPartNumber = (string) (4000000 + random_int(1000, 9999));
            $p->description = $desc;
            $p->manufacturer = $mf;
            $p->manufacturerPartNumber = $mpn;
            $p->partClass = $cl;
            $p->subClass = $sc;
            $p->vendor = $v;
            $p->price = $price;
            $p->stockItem = $stock;
            $p->minimumQuantity = $min;
            $p->desiredQuantity = $des;
            $p->currentQuantity = $cur;
            $p->binLocation = $bin;
            $p->plant = $plant;
            foreach ($eqs as $e) {
                $p->equipments->add($e);
            }
            $this->em->persist($p);
        }

        // Aprobador obligatorio del vendor de servicio de ejemplo.
        $users[1]->vendors->add($svc);
        $this->em->flush();

        $io->success('Datos demo creados.');
        $io->table(['Usuario', 'Contraseña', 'Rol'], array_map(fn (User $u) => [$u->username, self::DEMO_PASSWORD, $u->role->label()], $users));

        return Command::SUCCESS;
    }

    /** @return User[] */
    private function demoUsers(Area $areaMaint, Area $areaStore): array
    {
        return [
            $this->user('tr', 'Taylor Reed', UserRole::MaintenanceTR, self::DEMO_PASSWORD, [$areaMaint]),
            $this->user('lead', 'Morgan Lead', UserRole::MaintenanceLead, self::DEMO_PASSWORD, [$areaMaint]),
            $this->user('store', 'Sam Storeroom', UserRole::StoreroomAssociate, self::DEMO_PASSWORD, [$areaStore]),
            $this->user('manager', 'Jordan Manager', UserRole::Manager, self::DEMO_PASSWORD, [$areaMaint]),
            $this->user('superadmin', 'Super Administrator', UserRole::SuperAdmin, self::DEMO_PASSWORD, [$areaMaint]),
        ];
    }

    private function user(string $username, string $name, UserRole $role, string $password, array $areas, bool $mustChange = false): User
    {
        $u = $this->em->getRepository(User::class)->findOneBy(['username' => $username]) ?? new User();
        $u->username = $username;
        $u->fullName = $name;
        $u->email = $username.'@example.com';
        $u->role = $role;
        $u->permissions = $role->defaultPermissions();
        $u->mustChangePassword = $mustChange;
        $u->password = $this->hasher->hashPassword($u, $password);
        foreach ($areas as $a) {
            if (!$u->areas->contains($a)) {
                $u->areas->add($a);
            }
        }
        $this->em->persist($u);

        return $u;
    }

    private function area(string $name, string $plant): Area
    {
        $a = $this->em->getRepository(Area::class)->findOneBy(['name' => $name, 'plant' => $plant]) ?? new Area();
        $a->name = $name;
        $a->plant = $plant;
        $this->em->persist($a);

        return $a;
    }

    private function vendor(string $name, int $type, string $number): Vendor
    {
        $v = new Vendor();
        $v->name = $name;
        $v->type = $type;
        $v->vendorNumber = $number;
        $v->email = strtolower(preg_replace('/\W+/', '', $name)).'@example.com';
        $this->em->persist($v);

        return $v;
    }

    /** @param array<string, string[]> $subs */
    private function partClass(string $name, array $subs): PartClass
    {
        $c = new PartClass();
        $c->name = $name;
        $this->em->persist($c);
        foreach ($subs as $sn => $chars) {
            $s = new SubClass();
            $s->partClass = $c;
            $s->name = $sn;
            $s->abbreviation = substr($sn, 0, 4);
            $s->code = $name.':'.$sn;
            $this->em->persist($s);
            $c->subClasses->add($s);
            foreach ($chars as $i => $cn) {
                $ch = new SubClassCharacteristic();
                $ch->subClass = $s;
                $ch->name = $cn;
                $ch->abbreviation = strtoupper(substr($cn, 0, 4));
                $ch->charCode = strtoupper(str_replace(' ', '_', $cn));
                $ch->sequence = $i + 1;
                $this->em->persist($ch);
            }
        }

        return $c;
    }

    private function node(string $name, string $type, ?TreeNode $parent, ?string $tag = null): TreeNode
    {
        $n = new TreeNode();
        $n->name = $name;
        $n->nodeType = $type;
        $n->parent = $parent;
        $n->searchTag = $tag;
        $this->em->persist($n);

        return $n;
    }
}
