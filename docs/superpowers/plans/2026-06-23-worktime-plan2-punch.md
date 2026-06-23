# Worktime Plugin — Plan 2: Punch In/Out auf der Arbeitszeit-Seite

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitarbeiter können auf der `/worktime`-Seite per Button ein-/ausstempeln; die Seite zeigt den aktuellen Status und die heutigen Stempelzeiten.

**Architecture:** Neue `WorkBlock`-Entity (offener Block = eingestempelt). Ein Toggle-Controller (Punch In öffnet Block, Punch Out schließt ihn) mit CSRF. Die `/worktime`-Seite zeigt Status + Punch-Button + heutige Stempel. Netto-Dauer-Berechnung in einer framework-freien Klasse (unit-getestet). **Kein** Kopfzeilen-Button (bewusst später), **keine** Kopplung an Projektzeit.

**Tech Stack:** PHP 8.2+, Symfony 6.4, Doctrine ORM (Attribute), PHPUnit, Twig (Kimai-Theme + Filter `|time`, `|date_short`, `|duration`).

## Global Constraints

- Bundle-Namespace `KimaiPlugin\WorktimeBundle`; autoload via Core-PSR-4 `KimaiPlugin\` → `var/plugins/`.
- Tabellen-Präfix `kimai2_worktime_`.
- Zeit-Spalten: `Types::DATETIME_IMMUTABLE` mit `\DateTimeImmutable` (Kimai überschreibt `datetime_immutable` global mit einem UTC-Typ → Speicherung in UTC, Anzeige via `|time`/`|date_short` in Nutzer-Zeitzone).
- Plugin-Views extenden **`'base.html.twig'`** (NICHT `'@theme/base.html.twig'` → LoaderError/500). Blöcke: `page_title`, `main`.
- Jede PHP-Datei trägt den Lizenz-/Copyright-Header (siehe bestehende Plugin-Dateien).
- Permissions (bereits registriert in Plan 1): `worktime_view_own`, `worktime_edit_own`, `worktime_manage`, `worktime_view_other`.
- Erfassung ist **ausschließlich Punch In/Out**, vollständig getrennt von Kimais Projektzeit. Kein Projekt/Tätigkeit, keine manuelle Mehrblock-Eingabe in diesem Plan.
- Prod-Env: Plugin-Dateien gehören `www-data`; nach Änderungen Cache als `www-data` neu bauen: `sudo -u www-data php bin/console cache:clear`.
- CSRF: Twig `csrf_token('worktime.punch')`, Controller `$this->isCsrfTokenValid('worktime.punch', $token)`.

## Scope / Abgrenzung

In **diesem** Plan: WorkBlock-Modell, Punch-Toggle, Status + heutige Stempel auf `/worktime`. **Nicht** hier (Folgeplan 3): nachträgliche Korrektur durch den MA (Bearbeiten/Löschen/Anlegen von Stempeln), AuditLog, Nacht-Cron für vergessenes Ausstempeln. **Nicht** hier (später): Kopfzeilen-Button (Risiko #1), Soll/Ist-Konto-Anzeige.

---

## File Structure

```
var/plugins/WorktimeBundle/
├── phpstan.neon                                  # NEU (Task 1)
├── .php-cs-fixer.dist.php                         # NEU (Task 1)
├── Entity/
│   └── WorkBlock.php                              # NEU (Task 2)
├── Repository/
│   └── WorkBlockRepository.php                    # NEU (Task 2)
├── Migrations/
│   └── Version20260623100000.php                  # NEU (Task 2)
├── Calculator/
│   └── WorkBlockMath.php                           # NEU (Task 3, framework-frei)
├── Controller/
│   └── WorktimeController.php                      # ERWEITERT (Task 4: index + punch action)
├── Resources/views/
│   └── index.html.twig                            # ERSETZT (Task 4)
└── Tests/Calculator/
    └── WorkBlockMathTest.php                       # NEU (Task 3)
```

---

### Task 1: Plugin-lokale Analyse-Configs (phpstan + cs-fixer)

Damit `./phpstan.sh Worktime` und `./php-cs-fixer.sh Worktime` (siehe `/opt/kimai/CLAUDE.md`) das Plugin auf Core-Niveau prüfen. Empfehlung aus dem Plan-1-Schluss-Review: vor weiterem Code einziehen.

**Files:**
- Create: `var/plugins/WorktimeBundle/phpstan.neon`
- Create: `var/plugins/WorktimeBundle/.php-cs-fixer.dist.php`

**Interfaces:**
- Consumes: nichts (Tooling). Spiegelt das Muster von `var/plugins/EasyBackupBundle/phpstan.neon` / `.php-cs-fixer.dist.php`.
- Produces: lauffähige `./phpstan.sh Worktime` und `./php-cs-fixer.sh Worktime`.

- [ ] **Step 1: phpstan.neon anlegen**

`var/plugins/WorktimeBundle/phpstan.neon`:
```neon
parameters:
    level: 9
    paths:
        - .
    excludePaths:
        - vendor/
        - Tests/
    bootstrapFiles:
        - ../../../vendor/autoload.php
```

- [ ] **Step 2: .php-cs-fixer.dist.php anlegen**

`var/plugins/WorktimeBundle/.php-cs-fixer.dist.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['vendor'])
;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        'declare_strict_types' => false,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'header_comment' => [
            'header' => "This file is part of the WorktimeBundle for Kimai.\nFor the full copyright and license information, please view the LICENSE\nfile that was distributed with this source code.",
            'location' => 'after_open',
            'separate' => 'both',
        ],
    ])
    ->setFinder($finder)
;
```

- [ ] **Step 3: Beide Tools gegen den bestehenden Plugin-Code laufen lassen**

Run:
```bash
cd /opt/kimai
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: php-cs-fixer meldet keine zu ändernden Dateien (oder fixt nur Header-Formatierung — dann erneut laufen bis clean); phpstan meldet `[OK] No errors` für das Plugin. Falls phpstan echte Fehler im Plan-1-Code findet, hier beheben.

- [ ] **Step 4: Commit**

```bash
git add var/plugins/WorktimeBundle/phpstan.neon var/plugins/WorktimeBundle/.php-cs-fixer.dist.php
git commit -m "chore(worktime): add plugin-local phpstan + php-cs-fixer config"
```

---

### Task 2: WorkBlock-Entity, Repository & Migration

**Files:**
- Create: `var/plugins/WorktimeBundle/Entity/WorkBlock.php`
- Create: `var/plugins/WorktimeBundle/Repository/WorkBlockRepository.php`
- Create: `var/plugins/WorktimeBundle/Migrations/Version20260623100000.php`

**Interfaces:**
- Consumes: `App\Entity\User`, `App\Doctrine\AbstractMigration`, Doctrine mapping/migrations-path (registriert in Plan 1 `prepend()`).
- Produces:
  - `WorkBlock`: `getId(): ?int`, `getUser(): User`, `setUser(User): void`, `getStart(): \DateTimeImmutable`, `setStart(\DateTimeImmutable): void`, `getEnd(): ?\DateTimeImmutable`, `setEnd(?\DateTimeImmutable): void`, `isOpen(): bool`, `getSource(): string`, `setSource(string): void`, `isNeedsReview(): bool`, `setNeedsReview(bool): void`, `getDurationSeconds(): ?int` (null wenn offen).
  - `WorkBlockRepository`: `findOpenBlock(User $user): ?WorkBlock`, `findForUserBetween(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array` (WorkBlock[], nach `start` aufsteigend, `start` in [from, to)), `save(WorkBlock): void`, `remove(WorkBlock): void`.
  - Table `kimai2_worktime_work_block`.

- [ ] **Step 1: WorkBlock-Entity anlegen**

`var/plugins/WorktimeBundle/Entity/WorkBlock.php`:
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
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;

#[ORM\Entity(repositoryClass: WorkBlockRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_work_block')]
#[ORM\Index(columns: ['user_id', 'start_time'], name: 'IDX_worktime_block_user_start')]
class WorkBlock
{
    public const SOURCE_PUNCH = 'punch';
    public const SOURCE_MANUAL = 'manual';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'start_time', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $start;

    #[ORM\Column(name: 'end_time', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $end = null;

    #[ORM\Column(name: 'source', type: Types::STRING, length: 10)]
    private string $source = self::SOURCE_PUNCH;

    #[ORM\Column(name: 'needs_review', type: Types::BOOLEAN)]
    private bool $needsReview = false;

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

    public function getStart(): \DateTimeImmutable
    {
        return $this->start;
    }

    public function setStart(\DateTimeImmutable $start): void
    {
        $this->start = $start;
    }

    public function getEnd(): ?\DateTimeImmutable
    {
        return $this->end;
    }

    public function setEnd(?\DateTimeImmutable $end): void
    {
        $this->end = $end;
    }

    public function isOpen(): bool
    {
        return $this->end === null;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): void
    {
        $this->source = $source;
    }

    public function isNeedsReview(): bool
    {
        return $this->needsReview;
    }

    public function setNeedsReview(bool $needsReview): void
    {
        $this->needsReview = $needsReview;
    }

    public function getDurationSeconds(): ?int
    {
        if ($this->end === null) {
            return null;
        }

        return $this->end->getTimestamp() - $this->start->getTimestamp();
    }
}
```

- [ ] **Step 2: WorkBlockRepository anlegen**

`var/plugins/WorktimeBundle/Repository/WorkBlockRepository.php`:
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
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;

/**
 * @extends ServiceEntityRepository<WorkBlock>
 */
class WorkBlockRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkBlock::class);
    }

    public function findOpenBlock(User $user): ?WorkBlock
    {
        return $this->findOneBy(['user' => $user, 'end' => null], ['start' => 'DESC']);
    }

    /**
     * @return WorkBlock[]
     */
    public function findForUserBetween(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.start >= :from')
            ->andWhere('b.start < :to')
            ->setParameter('user', $user)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('b.start', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(WorkBlock $block): void
    {
        $em = $this->getEntityManager();
        $em->persist($block);
        $em->flush();
    }

    public function remove(WorkBlock $block): void
    {
        $em = $this->getEntityManager();
        $em->remove($block);
        $em->flush();
    }
}
```

- [ ] **Step 3: Migration anlegen**

`var/plugins/WorktimeBundle/Migrations/Version20260623100000.php`:
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

final class Version20260623100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create work_block table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_work_block (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            start_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            end_time DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            source VARCHAR(10) NOT NULL,
            needs_review TINYINT(1) NOT NULL,
            INDEX IDX_worktime_block_user_start (user_id, start_time),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_work_block
            ADD CONSTRAINT FK_worktime_block_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_work_block');
    }
}
```

> `down()` nutzt die Schema-API (`$schema->dropTable`), NICHT `addSql('DROP TABLE …')` — `AbstractMigration::addSql()` blockt DROP TABLE (Lehre aus Plan 1).

- [ ] **Step 4: Migration ausführen, Schema validieren, Rollback testen**

Run:
```bash
cd /opt/kimai
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console doctrine:schema:validate
# Rollback + Re-Apply (Tabelle leer → gefahrlos):
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623100000' --down --no-interaction
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623100000' --up --no-interaction
```
Expected: Migration läuft; `schema:validate` zeigt für `kimai2_worktime_work_block` keinen Diff (Core-Tabellen-Warnungen ignorieren); down+up laufen ohne Exception.

- [ ] **Step 5: Commit**

```bash
git add var/plugins/WorktimeBundle/Entity/WorkBlock.php var/plugins/WorktimeBundle/Repository/WorkBlockRepository.php var/plugins/WorktimeBundle/Migrations/Version20260623100000.php
git commit -m "feat(worktime): WorkBlock entity, repository and migration"
```

---

### Task 3: Netto-Dauer-Berechnung (framework-frei, TDD)

`WorkBlockMath::netSeconds()` summiert die Netto-Arbeitszeit über eine Menge WorkBlocks (offener Block optional bis „jetzt").

**Files:**
- Create: `var/plugins/WorktimeBundle/Calculator/WorkBlockMath.php`
- Create: `var/plugins/WorktimeBundle/Tests/Calculator/WorkBlockMathTest.php`

**Interfaces:**
- Consumes: `WorkBlock` (Task 2: `getStart`, `getEnd`, `isOpen`).
- Produces: `WorkBlockMath::netSeconds(iterable $blocks, ?\DateTimeImmutable $now = null): int`.

- [ ] **Step 1: Failing test schreiben**

`var/plugins/WorktimeBundle/Tests/Calculator/WorkBlockMathTest.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;
use PHPUnit\Framework\TestCase;

class WorkBlockMathTest extends TestCase
{
    private function block(string $start, ?string $end): WorkBlock
    {
        $b = new WorkBlock();
        $b->setUser(new User());
        $b->setStart(new \DateTimeImmutable($start));
        $b->setEnd($end === null ? null : new \DateTimeImmutable($end));

        return $b;
    }

    public function testEmptyIsZero(): void
    {
        self::assertSame(0, (new WorkBlockMath())->netSeconds([]));
    }

    public function testSumsClosedBlocks(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'), // 4h
            $this->block('2026-06-01 12:30:00', '2026-06-01 16:30:00'), // 4h
        ];
        self::assertSame(28800, (new WorkBlockMath())->netSeconds($blocks)); // 8h
    }

    public function testOpenBlockIgnoredWithoutNow(): void
    {
        $blocks = [$this->block('2026-06-01 08:00:00', null)];
        self::assertSame(0, (new WorkBlockMath())->netSeconds($blocks));
    }

    public function testOpenBlockCountedUpToNow(): void
    {
        $blocks = [$this->block('2026-06-01 08:00:00', null)];
        $now = new \DateTimeImmutable('2026-06-01 09:30:00');
        self::assertSame(5400, (new WorkBlockMath())->netSeconds($blocks, $now)); // 1.5h
    }

    public function testMixedClosedAndOpen(): void
    {
        $blocks = [
            $this->block('2026-06-01 08:00:00', '2026-06-01 12:00:00'), // 4h
            $this->block('2026-06-01 13:00:00', null),                   // open
        ];
        $now = new \DateTimeImmutable('2026-06-01 14:00:00');            // +1h
        self::assertSame(18000, (new WorkBlockMath())->netSeconds($blocks, $now)); // 5h
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter WorkBlockMathTest
```
Expected: FAIL — `Class "KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath" not found`.

- [ ] **Step 3: WorkBlockMath implementieren**

`var/plugins/WorktimeBundle/Calculator/WorkBlockMath.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;

final class WorkBlockMath
{
    /**
     * @param iterable<WorkBlock> $blocks
     */
    public function netSeconds(iterable $blocks, ?\DateTimeImmutable $now = null): int
    {
        $total = 0;
        foreach ($blocks as $block) {
            if (!$block->isOpen()) {
                $total += $block->getDurationSeconds() ?? 0;
            } elseif ($now !== null) {
                $delta = $now->getTimestamp() - $block->getStart()->getTimestamp();
                if ($delta > 0) {
                    $total += $delta;
                }
            }
        }

        return $total;
    }
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter WorkBlockMathTest
```
Expected: PASS — 5 Tests grün, Output pristine.

- [ ] **Step 5: Commit**

```bash
git add var/plugins/WorktimeBundle/Calculator/WorkBlockMath.php var/plugins/WorktimeBundle/Tests/Calculator/WorkBlockMathTest.php
git commit -m "feat(worktime): framework-free net working-time math with unit tests"
```

---

### Task 4: Punch-Toggle-Action & Arbeitszeit-Seite

`/worktime` zeigt Status (eingestempelt seit … / ausgestempelt), einen Punch-Button (Toggle) und die heutigen Stempelzeiten.

**Files:**
- Modify: `var/plugins/WorktimeBundle/Controller/WorktimeController.php` (index erweitern + punch-Action)
- Modify: `var/plugins/WorktimeBundle/Resources/views/index.html.twig` (Status + Button + Tagesliste)

**Interfaces:**
- Consumes: `WorkBlockRepository` (`findOpenBlock`, `findForUserBetween`, `save`), `WorkBlockMath::netSeconds`, `WorkBlock` (`SOURCE_PUNCH`), `App\Entity\User` (`getTimezone(): string`), permission `worktime_view_own` (Seite) / `worktime_edit_own` (Punch).
- Produces: Routen `worktime_index` (GET `/worktime`, bereits vorhanden) und `worktime_punch` (POST `/worktime/punch`).

- [ ] **Step 1: Controller erweitern**

Ersetze den gesamten Inhalt von `var/plugins/WorktimeBundle/Controller/WorktimeController.php` durch:
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
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime')]
#[IsGranted('worktime_view_own')]
final class WorktimeController extends AbstractController
{
    #[Route(path: '', name: 'worktime_index', methods: ['GET'])]
    public function index(WorkBlockRepository $blocks, WorkBlockMath $math): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tz = new \DateTimeZone($user->getTimezone());
        $todayStart = new \DateTimeImmutable('today', $tz);
        $todayEnd = $todayStart->modify('+1 day');

        $todayBlocks = $blocks->findForUserBetween($user, $todayStart, $todayEnd);
        $openBlock = $blocks->findOpenBlock($user);
        $now = new \DateTimeImmutable('now');

        return $this->render('@Worktime/index.html.twig', [
            'open_block' => $openBlock,
            'today_blocks' => $todayBlocks,
            'today_seconds' => $math->netSeconds($todayBlocks, $now),
        ]);
    }

    #[Route(path: '/punch', name: 'worktime_punch', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function punch(Request $request, WorkBlockRepository $blocks): Response
    {
        if (!$this->isCsrfTokenValid('worktime.punch', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_index'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $now = new \DateTimeImmutable('now');
        $open = $blocks->findOpenBlock($user);

        if ($open !== null) {
            $open->setEnd($now);
            $blocks->save($open);
            $this->addFlash('success', 'Ausgestempelt.');
        } else {
            $block = new WorkBlock();
            $block->setUser($user);
            $block->setStart($now);
            $block->setSource(WorkBlock::SOURCE_PUNCH);
            $blocks->save($block);
            $this->addFlash('success', 'Eingestempelt.');
        }

        return new RedirectResponse($this->generateUrl('worktime_index'));
    }
}
```

- [ ] **Step 2: View ersetzen**

Ersetze den gesamten Inhalt von `var/plugins/WorktimeBundle/Resources/views/index.html.twig` durch:
```twig
{% extends 'base.html.twig' %}

{% block page_title %}{{ 'Arbeitszeit'|trans }}{% endblock %}

{% block main %}
    <div class="card mb-3">
        <div class="card-body">
            {% if open_block %}
                <p class="mb-2">
                    <span class="badge bg-green text-green-fg">{{ 'eingestempelt'|trans }}</span>
                    {{ 'seit'|trans }} <strong>{{ open_block.start|time }}</strong>
                </p>
            {% else %}
                <p class="mb-2"><span class="badge bg-secondary">{{ 'ausgestempelt'|trans }}</span></p>
            {% endif %}

            <form method="post" action="{{ path('worktime_punch') }}">
                <input type="hidden" name="_token" value="{{ csrf_token('worktime.punch') }}">
                {% if open_block %}
                    <button type="submit" class="btn btn-lg btn-danger">
                        <i class="{{ 'stop'|icon }}"></i> {{ 'Ausstempeln'|trans }}
                    </button>
                {% else %}
                    <button type="submit" class="btn btn-lg btn-success">
                        <i class="{{ 'start'|icon }}"></i> {{ 'Einstempeln'|trans }}
                    </button>
                {% endif %}
            </form>

            <p class="mt-3 mb-0 text-muted">
                {{ 'Heute gebucht'|trans }}: <strong>{{ today_seconds|duration }}</strong>
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ 'Heutige Stempelzeiten'|trans }}</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead>
                    <tr>
                        <th>{{ 'Beginn'|trans }}</th>
                        <th>{{ 'Ende'|trans }}</th>
                        <th>{{ 'Dauer'|trans }}</th>
                    </tr>
                </thead>
                <tbody>
                    {% for block in today_blocks %}
                        <tr>
                            <td>{{ block.start|time }}</td>
                            <td>{{ block.end is null ? '–' : block.end|time }}</td>
                            <td>{{ block.durationSeconds is null ? ('läuft'|trans) : (block.durationSeconds|duration) }}</td>
                        </tr>
                    {% else %}
                        <tr><td colspan="3" class="text-muted">{{ 'Noch keine Buchung heute.'|trans }}</td></tr>
                    {% endfor %}
                </tbody>
            </table>
        </div>
    </div>
{% endblock %}
```

> Hinweis: `'start'|icon` / `'stop'|icon` nutzen Kimais Icon-Filter (gleiche Icons wie der Projekt-Timer). Falls ein Iconname nicht existiert, ersetze durch ein vorhandenes (z. B. `'play'|icon` / `'stop'|icon`) — der Filter-Aufruf selbst ist das stabile Muster.

- [ ] **Step 3: Ownership + Cache + Routen prüfen**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console debug:router | grep -E "worktime_index|worktime_punch"
sudo -u www-data php bin/console lint:twig var/plugins/WorktimeBundle/Resources/views
```
Expected: beide Routen vorhanden (`worktime_index` GET, `worktime_punch` POST); `lint:twig` meldet gültige Syntax.

- [ ] **Step 4: Manueller Smoke-Test (live)**

Als eingeloggter Nutzer auf `https://kimai.sicoda.work/worktime`:
- Status zeigt „ausgestempelt", Button „Einstempeln". Klick → Flash „Eingestempelt.", Status wechselt auf „eingestempelt seit HH:MM", Button wird „Ausstempeln", in der Tagesliste erscheint eine Zeile mit Beginn + „läuft".
- Erneuter Klick „Ausstempeln" → Zeile bekommt Ende + Dauer; „Heute gebucht" steigt.
- Prüfen, dass das Prod-Log (`var/log/prod.log`) keinen neuen Fehler zeigt.

- [ ] **Step 5: Codestyle + PHPStan + Commit**

Run:
```bash
cd /opt/kimai
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: keine Fehler. Dann:
```bash
git add var/plugins/WorktimeBundle/Controller/WorktimeController.php var/plugins/WorktimeBundle/Resources/views/index.html.twig
git commit -m "feat(worktime): punch in/out toggle and daily stamp view on /worktime"
```

---

## Self-Review

**Spec coverage (gegen Spec §6 „Erfassung — ausschließlich Punch In/Out"):**
- Punch In öffnet Block, Punch Out schließt → Task 4 (`punch`-Action) + Task 2 (WorkBlock). ✓
- Nur Stempeln, kein Projekt/Tätigkeit/Mehrblock-Formular → Task 4 (Button-only UI). ✓
- Mehrere Blöcke/Tag, Lücken = Pause → WorkBlock-Modell erlaubt beliebig viele Blöcke; Netto = Σ Blöcke (Task 3). ✓
- Eigene Tagesansicht der Stempel (read) → Task 4 (Tagesliste). ✓
- Getrennt von Projektzeit → eigenes Modell/Route/Seite, keine Timesheet-Kopplung. ✓
- Vollständig getestete framework-freie Logik (§11) → Task 3 (`WorkBlockMath`, 5 Tests). ✓
- `down()` per Schema-API (Lehre Plan 1) → Task 2 Step 3. ✓
- Plugin-Views extenden `base.html.twig` (Lehre Plan 1) → Task 4. ✓
- phpstan/cs-fixer-Gate (Plan-1-Schluss-Review) → Task 1. ✓

**Bewusst NICHT in Plan 2 (Folgepläne):** MA-Korrektur (Bearbeiten/Anlegen/Löschen eigener Stempel) + AuditLog + Nacht-Cron (Plan 3); Kopfzeilen-Button (später, Risiko #1); Soll/Ist-Konto-Anzeige (späterer Plan). Diese Auslassungen sind beabsichtigt und mit dem Nutzer abgestimmt („erst einmal das Punch In/Out").

**Placeholder scan:** keine TBD/TODO; jeder Code-Step vollständig. ✓

**Type consistency:** `WorkBlock`-Getter/Setter, `WorkBlockRepository`-Methoden, `WorkBlockMath::netSeconds` über Tasks 2–4 konsistent verwendet (`findOpenBlock`, `findForUserBetween`, `getStart`, `getEnd`, `isOpen`, `getDurationSeconds`, `SOURCE_PUNCH`). ✓
