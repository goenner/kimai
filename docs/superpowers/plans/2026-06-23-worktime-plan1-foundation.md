# Worktime Plugin — Plan 1: Fundament, Vertrag & Konto-Rechenkern

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein installierbares Kimai-Plugin-Skelett mit Vertrags-Entity (eine pro Mitarbeiter) und einem framework-freien, voll unit-getesteten Arbeitszeitkonto-Rechenkern (Soll/Ist/Saldo).

**Architecture:** Eigenständiges `kimai-plugin`-Bundle unter `var/plugins/WorktimeBundle`. Eigene Doctrine-Entity + Migration (via `prepend()` registriert). Die gesamte Konto-Mathematik liegt in reinen PHP-Klassen ohne Kernel/DB-Abhängigkeit, damit sie ohne gebooteten Kernel testbar ist (Plugins werden in der `test`-Env nicht geladen).

**Tech Stack:** PHP 8.2+, Symfony 6.4, Doctrine ORM (Attribute-Mapping), PHPUnit. Basis: Kimai 2.56.0 (`App\Plugin\PluginInterface`, `App\Plugin\AbstractPluginExtension`, `App\Doctrine\AbstractMigration`).

## Global Constraints

- Bundle-Namespace: `KimaiPlugin\WorktimeBundle` (autoloadet via Core-`composer.json` PSR-4 `KimaiPlugin\` → `var/plugins/`).
- Verzeichnisname **ohne** „Bundle"-Suffix muss zur Klasse passen: Ordner `WorktimeBundle`, Klasse `WorktimeBundle`.
- Tabellen-Präfix: `kimai2_worktime_` (Konvention des Cores ist `kimai2_*`).
- Datei-Header: Jede PHP-Datei trägt einen Lizenz-/Copyright-Header (siehe Tasks; PHP-CS-Fixer des Plugins erzwingt ihn).
- Permissions: `worktime_view_own`, `worktime_edit_own`, `worktime_manage`, `worktime_view_other`.
- Datumsfelder: `Types::DATE_IMMUTABLE` mit `\DateTimeImmutable` (Core-Muster, siehe `src/Entity/WorkingTime.php`).
- Dieses Plugin nutzt **nicht** `WorkingTimeService`, native Contract-Felder am `User` oder Projekt-Timesheets.
- Prod-Env: nach Template-/Config-Änderungen Cache leeren (`bin/console cache:clear` oder `./kimai.sh cache`).

---

## File Structure

```
var/plugins/WorktimeBundle/
├── composer.json
├── phpunit.xml.dist
├── WorktimeBundle.php                         # Bundle-Klasse
├── DependencyInjection/
│   ├── WorktimeExtension.php                  # load() + prepend() (permissions, doctrine mapping, migrations)
│   └── Configuration.php
├── Resources/
│   ├── config/
│   │   ├── services.yaml
│   │   └── routes.yaml
│   └── views/
│       ├── index.html.twig
│       └── contract/
│           ├── index.html.twig
│           └── edit.html.twig
├── EventSubscriber/
│   └── MenuSubscriber.php
├── Controller/
│   ├── WorktimeController.php                  # Landing (Task 1)
│   └── ContractAdminController.php             # Vertrags-CRUD (Task 4)
├── Form/
│   └── ContractType.php                        # (Task 4)
├── Entity/
│   └── Contract.php                            # (Task 2)
├── Repository/
│   └── ContractRepository.php                  # (Task 2)
├── Model/
│   └── DayFacts.php                            # (Task 3) framework-frei
├── Calculator/
│   └── WorktimeCalculator.php                  # (Task 3) framework-frei
├── Migrations/
│   └── Version20260623090000.php               # (Task 2)
└── Tests/
    └── Calculator/
        └── WorktimeCalculatorTest.php          # (Task 3)
```

---

### Task 1: Plugin-Skelett & Registrierung

Installierbares Bundle, das in Kimai geladen wird, einen Menüeintrag und eine Landing-Route bereitstellt. `prepend()` registriert Permissions, das (noch leere) Doctrine-Mapping und den Migrations-Pfad — damit Task 2 ohne weitere Verdrahtung greift.

**Files:**
- Create: `var/plugins/WorktimeBundle/composer.json`
- Create: `var/plugins/WorktimeBundle/WorktimeBundle.php`
- Create: `var/plugins/WorktimeBundle/DependencyInjection/WorktimeExtension.php`
- Create: `var/plugins/WorktimeBundle/DependencyInjection/Configuration.php`
- Create: `var/plugins/WorktimeBundle/Resources/config/services.yaml`
- Create: `var/plugins/WorktimeBundle/Resources/config/routes.yaml`
- Create: `var/plugins/WorktimeBundle/Controller/WorktimeController.php`
- Create: `var/plugins/WorktimeBundle/Resources/views/index.html.twig`
- Create: `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php`

**Interfaces:**
- Consumes: Core `App\Plugin\PluginInterface`, `App\Plugin\AbstractPluginExtension`, `App\Event\ConfigureMainMenuEvent`, `App\Utils\MenuItemModel`.
- Produces: Route-Name `worktime_index` (Pfad `/worktime`); Permissions `worktime_view_own|edit_own|manage|view_other`; Doctrine-Mapping-Alias `WorktimeBundle` für `KimaiPlugin\WorktimeBundle\Entity`; Migrations-Pfad-Namespace `KimaiPlugin\WorktimeBundle\Migrations`.

- [ ] **Step 1: composer.json anlegen**

```json
{
    "name": "sicoda/worktime-bundle",
    "description": "Arbeitszeit- und Abwesenheitserfassung (Punch In/Out, Zeitkonto, Monatsabschluss).",
    "type": "kimai-plugin",
    "version": "0.1.0",
    "keywords": ["kimai", "kimai-plugin", "Worktime"],
    "license": "proprietary",
    "authors": [{ "name": "SICODA" }],
    "extra": {
        "kimai": {
            "require": 20000,
            "name": "Worktime"
        }
    },
    "autoload": {
        "psr-4": { "KimaiPlugin\\WorktimeBundle\\": "" }
    }
}
```

- [ ] **Step 2: Bundle-Klasse anlegen**

`var/plugins/WorktimeBundle/WorktimeBundle.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle;

use App\Plugin\PluginInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

class WorktimeBundle extends Bundle implements PluginInterface
{
}
```

- [ ] **Step 3: Configuration anlegen**

`var/plugins/WorktimeBundle/DependencyInjection/Configuration.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('worktime');
        $treeBuilder->getRootNode()->children()->end();

        return $treeBuilder;
    }
}
```

- [ ] **Step 4: Extension mit load() + prepend() anlegen**

`var/plugins/WorktimeBundle/DependencyInjection/WorktimeExtension.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\DependencyInjection;

use App\Plugin\AbstractPluginExtension;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader;

class WorktimeExtension extends AbstractPluginExtension implements PrependExtensionInterface
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);
        $this->registerBundleConfiguration($container, $config);

        $loader = new Loader\YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $loader->load('services.yaml');
    }

    public function prepend(ContainerBuilder $container): void
    {
        $container->prependExtensionConfig('kimai', [
            'permissions' => [
                'roles' => [
                    'ROLE_USER' => [
                        'worktime_view_own',
                        'worktime_edit_own',
                    ],
                    'ROLE_SUPER_ADMIN' => [
                        'worktime_view_own',
                        'worktime_edit_own',
                        'worktime_manage',
                        'worktime_view_other',
                    ],
                ],
            ],
        ]);

        $container->prependExtensionConfig('doctrine', [
            'orm' => [
                'mappings' => [
                    'WorktimeBundle' => [
                        'type' => 'attribute',
                        'dir' => __DIR__ . '/../Entity',
                        'prefix' => 'KimaiPlugin\\WorktimeBundle\\Entity',
                        'alias' => 'WorktimeBundle',
                        'is_bundle' => false,
                    ],
                ],
            ],
        ]);

        $container->prependExtensionConfig('doctrine_migrations', [
            'migrations_paths' => [
                'KimaiPlugin\\WorktimeBundle\\Migrations' => __DIR__ . '/../Migrations',
            ],
        ]);
    }
}
```

- [ ] **Step 5: services.yaml + routes.yaml anlegen**

`var/plugins/WorktimeBundle/Resources/config/services.yaml`:

```yaml
services:
    _defaults:
        autowire: true
        autoconfigure: true
        public: false

    KimaiPlugin\WorktimeBundle\:
        resource: '../../*'
        exclude:
            - '../../Resources/'
            - '../../Entity/'
            - '../../Model/'
            - '../../Migrations/'
            - '../../WorktimeBundle.php'

    KimaiPlugin\WorktimeBundle\Controller\:
        resource: '../../Controller'
        tags: ['controller.service_arguments']
```

`var/plugins/WorktimeBundle/Resources/config/routes.yaml`:

```yaml
worktime:
    resource: '../../Controller'
    type: attribute
```

- [ ] **Step 6: Landing-Controller + View + Menü anlegen**

`var/plugins/WorktimeBundle/Controller/WorktimeController.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime')]
#[IsGranted('worktime_view_own')]
final class WorktimeController extends AbstractController
{
    #[Route(path: '', name: 'worktime_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Worktime/index.html.twig', []);
    }
}
```

`var/plugins/WorktimeBundle/Resources/views/index.html.twig`:

```twig
{% extends '@theme/base.html.twig' %}

{% block page_title %}{{ 'Arbeitszeit'|trans }}{% endblock %}

{% block main %}
    <div class="card">
        <div class="card-body">
            {{ 'Arbeitszeit-Modul aktiv.'|trans }}
        </div>
    </div>
{% endblock %}
```

`var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', 100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if (!$this->security->isGranted('worktime_view_own')) {
            return;
        }

        $event->getMenu()->addChild(
            new MenuItemModel('worktime', 'Arbeitszeit', 'worktime_index', [], 'fas fa-business-time')
        );
    }
}
```

- [ ] **Step 7: Plugin laden & verifizieren**

Run:
```bash
cd /opt/kimai
bin/console cache:clear
bin/console kimai:bundles 2>/dev/null || bin/console debug:container --parameter=kernel.bundles 2>/dev/null | grep -i worktime
bin/console debug:router | grep worktime_index
```
Expected: `worktime_index` taucht mit Pfad `/worktime` in der Router-Liste auf; keine Fehler beim Cache-Clear (Bundle wird geladen).

- [ ] **Step 8: Commit**

```bash
git add var/plugins/WorktimeBundle
git commit -m "feat(worktime): plugin skeleton, menu, permissions, prepend wiring"
```

---

### Task 2: Contract-Entity, Repository & Migration

Eine Vertrags-Entity pro Mitarbeiter (1:1 zum `User`), zugehöriges Repository und die Tabellen-Migration.

**Files:**
- Create: `var/plugins/WorktimeBundle/Entity/Contract.php`
- Create: `var/plugins/WorktimeBundle/Repository/ContractRepository.php`
- Create: `var/plugins/WorktimeBundle/Migrations/Version20260623090000.php`

**Interfaces:**
- Consumes: Doctrine-Mapping + Migrations-Pfad aus Task 1 `prepend()`; Core `App\Entity\User`, `App\Doctrine\AbstractMigration`.
- Produces:
  - `Contract` mit `getId(): ?int`, `getUser(): User`, `setUser(User): void`, `getWorkHoursForDay(\DateTimeInterface): int`, `setWorkHoursForWeekday(int $isoWeekday, int $seconds): void`, `getWorkHoursForWeekday(int $isoWeekday): int`, `getHolidaysPerYear(): float`, `setHolidaysPerYear(float): void`, `getVacationCarryover(): float`, `setVacationCarryover(float): void`, `getInitialOvertimeSeconds(): int`, `setInitialOvertimeSeconds(int): void`, `getEmploymentStart(): ?\DateTimeImmutable`, `setEmploymentStart(?\DateTimeImmutable): void`, `getEmploymentEnd(): ?\DateTimeImmutable`, `setEmploymentEnd(?\DateTimeImmutable): void`, `getHolidayRegion(): string`, `setHolidayRegion(string): void`, `getDailyEndTime(): ?string`, `setDailyEndTime(?string): void`.
  - `ContractRepository` mit `findForUser(User $user): ?Contract`, `save(Contract $contract): void`.
- Table: `kimai2_worktime_contract`, unique `user_id`.

- [ ] **Step 1: Contract-Entity anlegen**

`var/plugins/WorktimeBundle/Entity/Contract.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Entity;

use App\Entity\User;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;

#[ORM\Entity(repositoryClass: ContractRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_contract')]
#[ORM\UniqueConstraint(columns: ['user_id'])]
class Contract
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'work_hours_mon', type: Types::INTEGER)]
    private int $workHoursMonday = 0;

    #[ORM\Column(name: 'work_hours_tue', type: Types::INTEGER)]
    private int $workHoursTuesday = 0;

    #[ORM\Column(name: 'work_hours_wed', type: Types::INTEGER)]
    private int $workHoursWednesday = 0;

    #[ORM\Column(name: 'work_hours_thu', type: Types::INTEGER)]
    private int $workHoursThursday = 0;

    #[ORM\Column(name: 'work_hours_fri', type: Types::INTEGER)]
    private int $workHoursFriday = 0;

    #[ORM\Column(name: 'work_hours_sat', type: Types::INTEGER)]
    private int $workHoursSaturday = 0;

    #[ORM\Column(name: 'work_hours_sun', type: Types::INTEGER)]
    private int $workHoursSunday = 0;

    #[ORM\Column(name: 'holidays_per_year', type: Types::FLOAT)]
    private float $holidaysPerYear = 0.0;

    #[ORM\Column(name: 'vacation_carryover', type: Types::FLOAT)]
    private float $vacationCarryover = 0.0;

    #[ORM\Column(name: 'initial_overtime', type: Types::INTEGER)]
    private int $initialOvertimeSeconds = 0;

    #[ORM\Column(name: 'employment_start', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $employmentStart = null;

    #[ORM\Column(name: 'employment_end', type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $employmentEnd = null;

    #[ORM\Column(name: 'holiday_region', type: Types::STRING, length: 50)]
    private string $holidayRegion = 'NRW';

    #[ORM\Column(name: 'daily_end_time', type: Types::STRING, length: 5, nullable: true)]
    private ?string $dailyEndTime = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function setUser(User $user): void
    {
        $this->user = $user;
    }

    /**
     * @param int $isoWeekday 1 (Mon) .. 7 (Sun)
     */
    public function getWorkHoursForWeekday(int $isoWeekday): int
    {
        return match ($isoWeekday) {
            1 => $this->workHoursMonday,
            2 => $this->workHoursTuesday,
            3 => $this->workHoursWednesday,
            4 => $this->workHoursThursday,
            5 => $this->workHoursFriday,
            6 => $this->workHoursSaturday,
            7 => $this->workHoursSunday,
            default => throw new \InvalidArgumentException('ISO weekday must be 1..7, got ' . $isoWeekday),
        };
    }

    public function setWorkHoursForWeekday(int $isoWeekday, int $seconds): void
    {
        match ($isoWeekday) {
            1 => $this->workHoursMonday = $seconds,
            2 => $this->workHoursTuesday = $seconds,
            3 => $this->workHoursWednesday = $seconds,
            4 => $this->workHoursThursday = $seconds,
            5 => $this->workHoursFriday = $seconds,
            6 => $this->workHoursSaturday = $seconds,
            7 => $this->workHoursSunday = $seconds,
            default => throw new \InvalidArgumentException('ISO weekday must be 1..7, got ' . $isoWeekday),
        };
    }

    public function getWorkHoursForDay(\DateTimeInterface $date): int
    {
        return $this->getWorkHoursForWeekday((int) $date->format('N'));
    }

    public function getHolidaysPerYear(): float
    {
        return $this->holidaysPerYear;
    }

    public function setHolidaysPerYear(float $holidaysPerYear): void
    {
        $this->holidaysPerYear = $holidaysPerYear;
    }

    public function getVacationCarryover(): float
    {
        return $this->vacationCarryover;
    }

    public function setVacationCarryover(float $vacationCarryover): void
    {
        $this->vacationCarryover = $vacationCarryover;
    }

    public function getInitialOvertimeSeconds(): int
    {
        return $this->initialOvertimeSeconds;
    }

    public function setInitialOvertimeSeconds(int $initialOvertimeSeconds): void
    {
        $this->initialOvertimeSeconds = $initialOvertimeSeconds;
    }

    public function getEmploymentStart(): ?\DateTimeImmutable
    {
        return $this->employmentStart;
    }

    public function setEmploymentStart(?\DateTimeImmutable $employmentStart): void
    {
        $this->employmentStart = $employmentStart;
    }

    public function getEmploymentEnd(): ?\DateTimeImmutable
    {
        return $this->employmentEnd;
    }

    public function setEmploymentEnd(?\DateTimeImmutable $employmentEnd): void
    {
        $this->employmentEnd = $employmentEnd;
    }

    public function getHolidayRegion(): string
    {
        return $this->holidayRegion;
    }

    public function setHolidayRegion(string $holidayRegion): void
    {
        $this->holidayRegion = $holidayRegion;
    }

    public function getDailyEndTime(): ?string
    {
        return $this->dailyEndTime;
    }

    public function setDailyEndTime(?string $dailyEndTime): void
    {
        $this->dailyEndTime = $dailyEndTime;
    }
}
```

- [ ] **Step 2: ContractRepository anlegen**

`var/plugins/WorktimeBundle/Repository/ContractRepository.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\WorktimeBundle\Entity\Contract;

/**
 * @extends ServiceEntityRepository<Contract>
 */
class ContractRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contract::class);
    }

    public function findForUser(User $user): ?Contract
    {
        return $this->findOneBy(['user' => $user]);
    }

    public function save(Contract $contract): void
    {
        $em = $this->getEntityManager();
        $em->persist($contract);
        $em->flush();
    }
}
```

- [ ] **Step 3: Migration anlegen**

`var/plugins/WorktimeBundle/Migrations/Version20260623090000.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Migrations;

use App\Doctrine\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

final class Version20260623090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create contract table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_contract (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            work_hours_mon INT NOT NULL,
            work_hours_tue INT NOT NULL,
            work_hours_wed INT NOT NULL,
            work_hours_thu INT NOT NULL,
            work_hours_fri INT NOT NULL,
            work_hours_sat INT NOT NULL,
            work_hours_sun INT NOT NULL,
            holidays_per_year DOUBLE PRECISION NOT NULL,
            vacation_carryover DOUBLE PRECISION NOT NULL,
            initial_overtime INT NOT NULL,
            employment_start DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            employment_end DATE DEFAULT NULL COMMENT \'(DC2Type:date_immutable)\',
            holiday_region VARCHAR(50) NOT NULL,
            daily_end_time VARCHAR(5) DEFAULT NULL,
            UNIQUE INDEX UNIQ_worktime_contract_user (user_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_contract
            ADD CONSTRAINT FK_worktime_contract_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE kimai2_worktime_contract');
    }
}
```

- [ ] **Step 4: Migration ausführen & Schema validieren**

Run:
```bash
cd /opt/kimai
bin/console doctrine:migrations:migrate --no-interaction
bin/console doctrine:schema:validate
```
Expected: Migration `Version20260623090000` läuft erfolgreich; `doctrine:schema:validate` meldet das Mapping als gültig und „in sync" für die neue Tabelle (Core-Tabellen-Warnungen, falls vorhanden, ignorieren — die `Contract`-Mapping-Zeile muss fehlerfrei sein).

> Hinweis: Bestätigt zugleich Risiko #2 aus der Spec (Plugin-Migrationsmechanismus). Schlägt der Lauf fehl, weil der Migrations-Pfad nicht gefunden wird, in Task 1 `prepend('doctrine_migrations', …)` prüfen.

- [ ] **Step 5: Commit**

```bash
git add var/plugins/WorktimeBundle/Entity var/plugins/WorktimeBundle/Repository var/plugins/WorktimeBundle/Migrations
git commit -m "feat(worktime): contract entity, repository and migration"
```

---

### Task 3: Konto-Rechenkern (framework-frei, TDD)

`DayFacts` (Tagesfakten-Wertobjekt) und `WorktimeCalculator` (reine Logik). Voll unit-getestet ohne Kernel/DB.

**Files:**
- Create: `var/plugins/WorktimeBundle/Model/DayFacts.php`
- Create: `var/plugins/WorktimeBundle/Calculator/WorktimeCalculator.php`
- Create: `var/plugins/WorktimeBundle/Tests/Calculator/WorktimeCalculatorTest.php`
- Create: `var/plugins/WorktimeBundle/phpunit.xml.dist`

**Interfaces:**
- Consumes: `Contract` aus Task 2 (`getWorkHoursForDay`, `getEmploymentStart`, `getEmploymentEnd`, `getInitialOvertimeSeconds`).
- Produces:
  - `DayFacts` (readonly): Konstruktor `__construct(\DateTimeImmutable $date, int $workedSeconds = 0, bool $publicHoliday = false, int $absenceCreditSeconds = 0, int $overtimeReductionSeconds = 0)`; öffentliche readonly Properties gleichen Namens.
  - `WorktimeCalculator`:
    - `targetSeconds(Contract $contract, DayFacts $day): int`
    - `creditSeconds(DayFacts $day): int`
    - `dailyBalanceSeconds(Contract $contract, DayFacts $day): int`
    - `accumulatedBalanceSeconds(Contract $contract, iterable $days, int $correctionSeconds = 0): int`

- [ ] **Step 1: phpunit.xml.dist für isolierte Plugin-Unit-Tests anlegen**

`var/plugins/WorktimeBundle/phpunit.xml.dist`:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="../../../vendor/phpunit/phpunit/phpunit.xsd"
         bootstrap="../../../vendor/autoload.php"
         colors="true"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="worktime-unit">
            <directory>Tests</directory>
        </testsuite>
    </testsuites>
</phpunit>
```

> Eigener Bootstrap (`vendor/autoload.php`) ⇒ **keine** Test-DB-Zurücksetzung; `KimaiPlugin\WorktimeBundle\` ist über die Core-PSR-4-Map autoloadbar.

- [ ] **Step 2: DayFacts-Wertobjekt anlegen**

`var/plugins/WorktimeBundle/Model/DayFacts.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Model;

final class DayFacts
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly int $workedSeconds = 0,
        public readonly bool $publicHoliday = false,
        public readonly int $absenceCreditSeconds = 0,
        public readonly int $overtimeReductionSeconds = 0,
    ) {
    }
}
```

- [ ] **Step 3: Failing test schreiben**

`var/plugins/WorktimeBundle/Tests/Calculator/WorktimeCalculatorTest.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Model\DayFacts;
use PHPUnit\Framework\TestCase;

class WorktimeCalculatorTest extends TestCase
{
    private const H8 = 28800;  // 8h
    private const H9 = 32400;  // 9h

    private function contract(): Contract
    {
        $c = new Contract();
        $c->setUser(new User());
        // Mon-Fri 8h, weekend 0
        foreach ([1, 2, 3, 4, 5] as $wd) {
            $c->setWorkHoursForWeekday($wd, self::H8);
        }
        $c->setWorkHoursForWeekday(6, 0);
        $c->setWorkHoursForWeekday(7, 0);
        $c->setEmploymentStart(new \DateTimeImmutable('2026-01-01'));

        return $c;
    }

    private function monday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-01'); // a Monday
    }

    private function saturday(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-06'); // a Saturday
    }

    public function testTargetIsContractHoursOnWorkday(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday());
        self::assertSame(self::H8, $calc->targetSeconds($this->contract(), $facts));
    }

    public function testTargetIsZeroOnWeekend(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->saturday());
        self::assertSame(0, $calc->targetSeconds($this->contract(), $facts));
    }

    public function testTargetIsZeroOnPublicHoliday(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), publicHoliday: true);
        self::assertSame(0, $calc->targetSeconds($this->contract(), $facts));
    }

    public function testTargetIsZeroBeforeEmploymentStart(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setEmploymentStart(new \DateTimeImmutable('2026-07-01'));
        $facts = new DayFacts($this->monday()); // 2026-06-01, before start
        self::assertSame(0, $calc->targetSeconds($c, $facts));
    }

    public function testTargetIsZeroAfterEmploymentEnd(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setEmploymentEnd(new \DateTimeImmutable('2026-05-31'));
        $facts = new DayFacts($this->monday()); // 2026-06-01, after end
        self::assertSame(0, $calc->targetSeconds($c, $facts));
    }

    public function testDailyBalanceNeutralWhenWorkedEqualsTarget(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), workedSeconds: self::H8);
        self::assertSame(0, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testDailyBalancePositiveOnOvertime(): void
    {
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), workedSeconds: self::H9);
        self::assertSame(3600, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testVacationDayIsBalanceNeutral(): void
    {
        // worked 0, but absence credit equals target -> neutral
        $calc = new WorktimeCalculator();
        $facts = new DayFacts($this->monday(), workedSeconds: 0, absenceCreditSeconds: self::H8);
        self::assertSame(0, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testOvertimeReductionDayLowersBalanceByTarget(): void
    {
        // Freizeitausgleich: credit neutralises soll, but overtime balance drops by target
        $calc = new WorktimeCalculator();
        $facts = new DayFacts(
            $this->monday(),
            workedSeconds: 0,
            absenceCreditSeconds: self::H8,
            overtimeReductionSeconds: self::H8
        );
        self::assertSame(-self::H8, $calc->dailyBalanceSeconds($this->contract(), $facts));
    }

    public function testAccumulatedBalanceSumsDaysPlusInitialAndCorrection(): void
    {
        $calc = new WorktimeCalculator();
        $c = $this->contract();
        $c->setInitialOvertimeSeconds(3600); // +1h carry-in
        $days = [
            new DayFacts($this->monday(), workedSeconds: self::H9),                 // +1h
            new DayFacts(new \DateTimeImmutable('2026-06-02'), workedSeconds: self::H8), // 0
            new DayFacts(new \DateTimeImmutable('2026-06-03'), workedSeconds: 0, absenceCreditSeconds: self::H8), // vacation, 0
        ];
        // initial 3600 + 3600 + 0 + 0 + correction 1800 = 9000
        self::assertSame(9000, $calc->accumulatedBalanceSeconds($c, $days, 1800));
    }
}
```

- [ ] **Step 4: Test ausführen, Fehlschlag bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle
../../../vendor/bin/phpunit -c phpunit.xml.dist
```
Expected: FAIL — `Error: Class "KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator" not found`.

- [ ] **Step 5: WorktimeCalculator implementieren**

`var/plugins/WorktimeBundle/Calculator/WorktimeCalculator.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Model\DayFacts;

final class WorktimeCalculator
{
    public function targetSeconds(Contract $contract, DayFacts $day): int
    {
        if ($day->publicHoliday) {
            return 0;
        }

        $start = $contract->getEmploymentStart();
        if ($start !== null && $day->date < $start) {
            return 0;
        }

        $end = $contract->getEmploymentEnd();
        if ($end !== null && $day->date > $end) {
            return 0;
        }

        return $contract->getWorkHoursForDay($day->date);
    }

    public function creditSeconds(DayFacts $day): int
    {
        return $day->workedSeconds + $day->absenceCreditSeconds;
    }

    public function dailyBalanceSeconds(Contract $contract, DayFacts $day): int
    {
        $target = $this->targetSeconds($contract, $day);

        return ($this->creditSeconds($day) - $target) - $day->overtimeReductionSeconds;
    }

    /**
     * @param iterable<DayFacts> $days
     */
    public function accumulatedBalanceSeconds(Contract $contract, iterable $days, int $correctionSeconds = 0): int
    {
        $balance = $contract->getInitialOvertimeSeconds() + $correctionSeconds;
        foreach ($days as $day) {
            $balance += $this->dailyBalanceSeconds($contract, $day);
        }

        return $balance;
    }
}
```

- [ ] **Step 6: Test ausführen, Erfolg bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle
../../../vendor/bin/phpunit -c phpunit.xml.dist
```
Expected: PASS — alle 11 Tests grün.

- [ ] **Step 7: Commit**

```bash
git add var/plugins/WorktimeBundle/Model var/plugins/WorktimeBundle/Calculator var/plugins/WorktimeBundle/Tests var/plugins/WorktimeBundle/phpunit.xml.dist
git commit -m "feat(worktime): framework-free working-time account calculator with unit tests"
```

---

### Task 4: Vertrags-Verwaltung (Admin-CRUD, minimal)

Admin kann pro Mitarbeiter genau einen Vertrag anlegen/bearbeiten. Eine Vertragsliste über alle User, ein Bearbeitungsformular.

**Files:**
- Create: `var/plugins/WorktimeBundle/Form/ContractType.php`
- Create: `var/plugins/WorktimeBundle/Controller/ContractAdminController.php`
- Create: `var/plugins/WorktimeBundle/Resources/views/contract/index.html.twig`
- Create: `var/plugins/WorktimeBundle/Resources/views/contract/edit.html.twig`

**Interfaces:**
- Consumes: `Contract`, `ContractRepository` (Task 2); Core `App\Repository\UserRepository`, `App\Controller\AbstractController`; permission `worktime_manage`.
- Produces: Routen `worktime_admin_contracts` (`/admin/worktime/contracts`, GET), `worktime_admin_contract_edit` (`/admin/worktime/contracts/{id}/edit`, GET|POST mit User-Id).

- [ ] **Step 1: Formular-Type anlegen**

`var/plugins/WorktimeBundle/Form/ContractType.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Form;

use KimaiPlugin\WorktimeBundle\Entity\Contract;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\DateType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\NumberType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Hours are entered/stored as seconds; UI mapping to hours is done in the template/controller for MVP.
 */
class ContractType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $i => $day) {
            $builder->add('workHours' . $day, IntegerType::class, [
                'label' => $day,
                'mapped' => false,
                'required' => false,
            ]);
        }

        $builder
            ->add('holidaysPerYear', NumberType::class, ['label' => 'Urlaubstage/Jahr', 'required' => false])
            ->add('vacationCarryover', NumberType::class, ['label' => 'Urlaubsübertrag', 'required' => false])
            ->add('employmentStart', DateType::class, ['label' => 'Beschäftigungsbeginn', 'widget' => 'single_text', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('employmentEnd', DateType::class, ['label' => 'Beschäftigungsende', 'widget' => 'single_text', 'required' => false, 'input' => 'datetime_immutable'])
            ->add('holidayRegion', TextType::class, ['label' => 'Feiertagsregion', 'required' => false])
            ->add('dailyEndTime', TextType::class, ['label' => 'Tägliche Endzeit (HH:MM)', 'required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => Contract::class]);
    }
}
```

> Wochenstunden sind `mapped => false` und werden im Controller in Sekunden umgerechnet (Eingabe in Stunden). Bewusst minimal für den MVP; eine komfortablere Stunden:Minuten-UI ist späteres Refinement.

- [ ] **Step 2: Admin-Controller anlegen**

`var/plugins/WorktimeBundle/Controller/ContractAdminController.php`:

```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Repository\UserRepository;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Form\ContractType;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/worktime/contracts')]
#[IsGranted('worktime_manage')]
final class ContractAdminController extends AbstractController
{
    #[Route(path: '', name: 'worktime_admin_contracts', methods: ['GET'])]
    public function index(UserRepository $userRepository, ContractRepository $contracts): Response
    {
        $users = $userRepository->findBy([], ['username' => 'ASC']);
        $rows = [];
        foreach ($users as $user) {
            $rows[] = ['user' => $user, 'contract' => $contracts->findForUser($user)];
        }

        return $this->render('@Worktime/contract/index.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/{id}/edit', name: 'worktime_admin_contract_edit', methods: ['GET', 'POST'])]
    public function edit(User $id, Request $request, ContractRepository $contracts): Response
    {
        $user = $id;
        $contract = $contracts->findForUser($user);
        if ($contract === null) {
            $contract = new Contract();
            $contract->setUser($user);
        }

        $form = $this->createForm(ContractType::class, $contract);

        // Prefill hour fields (seconds -> hours) on GET
        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        if (!$request->isMethod('POST')) {
            foreach ($weekdays as $iso => $name) {
                $form->get('workHours' . $name)->setData((int) round($contract->getWorkHoursForWeekday($iso) / 3600));
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($weekdays as $iso => $name) {
                $hours = (int) ($form->get('workHours' . $name)->getData() ?? 0);
                $contract->setWorkHoursForWeekday($iso, $hours * 3600);
            }
            $contracts->save($contract);
            $this->addFlash('success', 'Vertrag gespeichert.');

            return new RedirectResponse($this->generateUrl('worktime_admin_contracts'));
        }

        return $this->render('@Worktime/contract/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }
}
```

- [ ] **Step 3: Views anlegen**

`var/plugins/WorktimeBundle/Resources/views/contract/index.html.twig`:

```twig
{% extends '@theme/base.html.twig' %}

{% block page_title %}{{ 'Verträge'|trans }}{% endblock %}

{% block main %}
    <div class="card">
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ 'Mitarbeiter'|trans }}</th>
                        <th>{{ 'Vertrag'|trans }}</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    {% for row in rows %}
                        <tr>
                            <td>{{ row.user.displayName }}</td>
                            <td>{{ row.contract ? 'vorhanden'|trans : 'fehlt'|trans }}</td>
                            <td class="text-end">
                                <a class="btn btn-sm" href="{{ path('worktime_admin_contract_edit', {id: row.user.id}) }}">{{ 'bearbeiten'|trans }}</a>
                            </td>
                        </tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    </div>
{% endblock %}
```

`var/plugins/WorktimeBundle/Resources/views/contract/edit.html.twig`:

```twig
{% extends '@theme/base.html.twig' %}

{% block page_title %}{{ 'Vertrag'|trans }} — {{ user.displayName }}{% endblock %}

{% block main %}
    <div class="card">
        <div class="card-body">
            {{ form_start(form) }}
            {{ form_widget(form) }}
            <button type="submit" class="btn btn-primary">{{ 'speichern'|trans }}</button>
            {{ form_end(form) }}
        </div>
    </div>
{% endblock %}
```

- [ ] **Step 4: Routen verifizieren & manueller Smoke-Test**

Run:
```bash
cd /opt/kimai
bin/console cache:clear
bin/console debug:router | grep worktime_admin
```
Expected: `worktime_admin_contracts` und `worktime_admin_contract_edit` erscheinen. Manuell als Super-Admin: `/admin/worktime/contracts` öffnen → Userliste; einen User bearbeiten, Stunden/Urlaub speichern → Redirect mit Erfolgsmeldung; erneutes Öffnen zeigt „vorhanden".

- [ ] **Step 5: Codestyle & PHPStan des Plugins**

Run:
```bash
cd /opt/kimai
./php-cs-fixer.sh Worktime 2>/dev/null || true
./phpstan.sh Worktime 2>/dev/null || true
```
Expected: keine Fehler (bzw. nur fehlende lokale Plugin-Tool-Configs → in einem Folge-Schritt eigenes `phpstan.neon`/`.php-cs-fixer.dist.php` ergänzen). Datei-Header müssen vorhanden sein.

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Form var/plugins/WorktimeBundle/Controller var/plugins/WorktimeBundle/Resources/views/contract
git commit -m "feat(worktime): minimal contract admin CRUD"
```

---

## Self-Review

**Spec coverage (gegen Spec §2.1 / §5 / §7, Phase-1-Pkt. 1):**
- §2.1 Vertragskonfiguration (Wochenstunden Mo–So, Beschäftigungszeitraum, Jahresurlaub) → Task 2 (Entity) + Task 4 (CRUD). ✓
- §5 Datenmodell „Contract" → Task 2. ✓ (WorkBlock/Absence/PublicHoliday/Closure/Audit = Folgepläne 2–5.)
- §7 Rechenlogik (Soll=0 an Feiertagen/außerhalb Beschäftigung, Überstundensaldo, Abbau, Korrektur) → Task 3, voll unit-getestet. ✓ (Urlaubssaldo in Tagen → Folgeplan 3, da von genehmigten Abwesenheiten abhängig.)
- §11 Test-Strategie (framework-freie Logik, kernellos testbar) → Task 3 + eigenes `phpunit.xml.dist`. ✓
- §12 Risiko #2 (Plugin-Migrationen) → Task 2 Step 4 verifiziert den Mechanismus. ✓

**Bewusst NICHT in Plan 1 (Folgepläne):** Punch In/Out + WorkBlocks + Nacht-Cron (Plan 2); Abwesenheits-Workflow + Urlaubssaldo (Plan 3); Monatsabschluss + Snapshot + PDF (Plan 4); MA-Read-only-Ansicht + Audit-Log-Oberfläche (Plan 5). AuditLog-Entity wird in Plan 2 eingeführt, sobald die erste Buchung entsteht.

**Placeholder scan:** keine TBD/TODO; jeder Code-Step enthält vollständigen Code. ✓

**Type consistency:** `getWorkHoursForWeekday(int)`/`setWorkHoursForWeekday(int,int)`/`getWorkHoursForDay(\DateTimeInterface)`, `DayFacts`-Properties und `WorktimeCalculator`-Signaturen sind über Tasks 2–4 identisch verwendet. ✓
