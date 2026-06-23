# Worktime Plugin — Plan 3: Manuelle Zeiteinträge, Korrektur, Audit-Log & grüne Uhr

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitarbeiter können Stempelzeiten manuell anlegen ("Zeit eintragen", beliebiges Datum+Uhrzeit), bestehende Einträge bearbeiten/löschen (Bleistift-Icon), jede Buchung/Korrektur wird protokolliert (Audit-Log), und der laufende Timer hat einen SICODA-grünen Hintergrund.

**Architecture:** Neue `AuditLog`-Entity + `AuditLogger`-Service protokollieren alle WorkBlock-Mutationen. Ein framework-freier `WorkBlockValidator` (TDD) prüft Intervalle. Ein neuer `EntryController` (create/edit/delete) parst `datetime-local`-Eingaben in der Nutzer-Zeitzone, prüft Eigentum, validiert und auditet. Die `/worktime`-Seite bekommt ein wiederverwendbares Bootstrap-5-Modal, ein Bleistift-Icon je Zeile, einen "Zeit eintragen"-Button und die grüne Hero-Optik.

**Tech Stack:** PHP 8.2+, Symfony 6.4, Doctrine ORM (Attribute), PHPUnit, Twig, Bootstrap 5 (Tabler) Modal, `datetime-local` inputs.

## Global Constraints

- Bundle-Namespace `KimaiPlugin\WorktimeBundle`; Tabellen-Präfix `kimai2_worktime_`.
- Zeit-Spalten `Types::DATETIME_IMMUTABLE` (Kimai speichert UTC; Anzeige/Eingabe in Nutzer-Zeitzone via `app.user.timezone`).
- Migration extends `App\Doctrine\AbstractMigration`; `down()` nutzt `$schema->dropTable(...)` (NIE `addSql('DROP TABLE …')`).
- Plugin-Views extenden `'base.html.twig'` (nie `'@theme/base.html.twig'`).
- Jede PHP-Datei trägt den Lizenz-Header; Code besteht `./phpstan.sh Worktime` (Level 9) und `./php-cs-fixer.sh Worktime`.
- Erfassung bleibt getrennt von Projektzeit (keine Timesheet/Project/Activity-Bezüge). Quelle manueller Einträge: `WorkBlock::SOURCE_MANUAL`.
- **Jede** Mutation (punch in/out, create, edit, delete) wird im Audit-Log protokolliert (wer/wann/Aktion/alt→neu).
- Mitarbeiter dürfen nur **eigene** Blöcke bearbeiten/löschen (Ownership-Check → 404 sonst). Aktionen erfordern `worktime_edit_own` + CSRF.
- Prod: Plugin gehört `www-data`; nach Änderungen `sudo -u www-data php bin/console cache:clear`. Console-Kommandos als `www-data` ausführen.
- Icons via `|icon`: `edit` (far fa-edit), `create` (fas fa-plus), `delete` (far fa-trash-alt). Brand: SICODA Marine & Mint (mint `#2EC4A9`).

## Scope / Abgrenzung
In diesem Plan: Audit-Log-Infrastruktur, manuelle Erfassung + Korrektur (create/edit/delete) mit Modal, grüne laufende Uhr. **Nicht hier:** Nacht-Cron für vergessenes Ausstempeln (eigener Folgeplan); Soll/Ist-Konto-Anzeige; Monatssperre (existiert noch nicht → kein Sperr-Guard nötig, kommt später).

---

## File Structure
```
var/plugins/WorktimeBundle/
├── Entity/AuditLog.php                         # NEU (Task 1)
├── Repository/AuditLogRepository.php           # NEU (Task 1)
├── Audit/AuditLogger.php                       # NEU (Task 1) — service
├── Migrations/Version20260623110000.php        # NEU (Task 1)
├── Validator/WorkBlockValidator.php            # NEU (Task 2) framework-frei
├── Controller/EntryController.php              # NEU (Task 2) create/edit/delete
├── Controller/WorktimeController.php           # ERWEITERT (Task 2) punch → audit
├── Resources/views/index.html.twig             # ERWEITERT (Task 3) grün, Bleistift, Button, Modal
└── Tests/Validator/WorkBlockValidatorTest.php   # NEU (Task 2)
```

---

### Task 1: Audit-Log (Entity, Repository, Migration, Service)

**Files:**
- Create: `var/plugins/WorktimeBundle/Entity/AuditLog.php`
- Create: `var/plugins/WorktimeBundle/Repository/AuditLogRepository.php`
- Create: `var/plugins/WorktimeBundle/Audit/AuditLogger.php`
- Create: `var/plugins/WorktimeBundle/Migrations/Version20260623110000.php`

**Interfaces:**
- Consumes: `App\Entity\User`, `App\Doctrine\AbstractMigration`, Doctrine mapping/migrations-path (Plan 1 `prepend()`), `Doctrine\ORM\EntityManagerInterface`.
- Produces:
  - `AuditLog` with constants `ACTION_PUNCH_IN='punch_in'`, `ACTION_PUNCH_OUT='punch_out'`, `ACTION_BLOCK_CREATE='block_create'`, `ACTION_BLOCK_EDIT='block_edit'`, `ACTION_BLOCK_DELETE='block_delete'`; getters `getId`, `getCreatedAt`, `getActor`, `getTargetUser`, `getAction`, `getEntityType`, `getEntityId`, `getDetails(): ?string`.
  - `AuditLogger::log(?User $actor, ?User $target, string $action, string $entityType, ?int $entityId, array $details = []): void`.
  - Table `kimai2_worktime_audit_log`.

- [ ] **Step 1: AuditLog-Entity anlegen**

`var/plugins/WorktimeBundle/Entity/AuditLog.php`:
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
use KimaiPlugin\WorktimeBundle\Repository\AuditLogRepository;

#[ORM\Entity(repositoryClass: AuditLogRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_audit_log')]
#[ORM\Index(columns: ['target_id', 'created_at'], name: 'IDX_worktime_audit_target')]
class AuditLog
{
    public const ACTION_PUNCH_IN = 'punch_in';
    public const ACTION_PUNCH_OUT = 'punch_out';
    public const ACTION_BLOCK_CREATE = 'block_create';
    public const ACTION_BLOCK_EDIT = 'block_edit';
    public const ACTION_BLOCK_DELETE = 'block_delete';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'actor_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $actor = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'target_id', nullable: true, onDelete: 'SET NULL')]
    private ?User $targetUser = null;

    #[ORM\Column(name: 'action', type: Types::STRING, length: 40)]
    private string $action;

    #[ORM\Column(name: 'entity_type', type: Types::STRING, length: 40)]
    private string $entityType;

    #[ORM\Column(name: 'entity_id', type: Types::INTEGER, nullable: true)]
    private ?int $entityId = null;

    #[ORM\Column(name: 'details', type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    public function __construct(\DateTimeImmutable $createdAt, string $action, string $entityType)
    {
        $this->createdAt = $createdAt;
        $this->action = $action;
        $this->entityType = $entityType;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getActor(): ?User
    {
        return $this->actor;
    }

    public function setActor(?User $actor): void
    {
        $this->actor = $actor;
    }

    public function getTargetUser(): ?User
    {
        return $this->targetUser;
    }

    public function setTargetUser(?User $targetUser): void
    {
        $this->targetUser = $targetUser;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getEntityType(): string
    {
        return $this->entityType;
    }

    public function getEntityId(): ?int
    {
        return $this->entityId;
    }

    public function setEntityId(?int $entityId): void
    {
        $this->entityId = $entityId;
    }

    public function getDetails(): ?string
    {
        return $this->details;
    }

    public function setDetails(?string $details): void
    {
        $this->details = $details;
    }
}
```

- [ ] **Step 2: AuditLogRepository anlegen**

`var/plugins/WorktimeBundle/Repository/AuditLogRepository.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;

/**
 * @extends ServiceEntityRepository<AuditLog>
 */
class AuditLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AuditLog::class);
    }
}
```

- [ ] **Step 3: AuditLogger-Service anlegen**

`var/plugins/WorktimeBundle/Audit/AuditLogger.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Audit;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;

class AuditLogger
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * @param array<string, mixed> $details
     */
    public function log(?User $actor, ?User $target, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        $log = new AuditLog(new \DateTimeImmutable('now'), $action, $entityType);
        $log->setActor($actor);
        $log->setTargetUser($target);
        $log->setEntityId($entityId);
        $log->setDetails($details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR));

        $this->em->persist($log);
        $this->em->flush();
    }
}
```

- [ ] **Step 4: Migration anlegen**

`var/plugins/WorktimeBundle/Migrations/Version20260623110000.php`:
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

final class Version20260623110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create audit_log table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_audit_log (
            id INT AUTO_INCREMENT NOT NULL,
            actor_id INT DEFAULT NULL,
            target_id INT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            action VARCHAR(40) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id INT DEFAULT NULL,
            details LONGTEXT DEFAULT NULL,
            INDEX IDX_worktime_audit_target (target_id, created_at),
            INDEX IDX_worktime_audit_actor (actor_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_audit_log
            ADD CONSTRAINT FK_worktime_audit_actor FOREIGN KEY (actor_id)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE kimai2_worktime_audit_log
            ADD CONSTRAINT FK_worktime_audit_target FOREIGN KEY (target_id)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_audit_log');
    }
}
```

- [ ] **Step 5: Migrieren, validieren, Rollback testen, Analyse**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console doctrine:schema:validate
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623110000' --down --no-interaction
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623110000' --up --no-interaction
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: migration runs; `schema:validate` shows no diff for `kimai2_worktime_audit_log`; down+up succeed without exception; cs-fixer + phpstan clean.

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Entity/AuditLog.php var/plugins/WorktimeBundle/Repository/AuditLogRepository.php var/plugins/WorktimeBundle/Audit/AuditLogger.php var/plugins/WorktimeBundle/Migrations/Version20260623110000.php
git commit -m "feat(worktime): audit log entity, repository, logger service and migration"
```

---

### Task 2: Validator (TDD) + manuelle Erfassung/Korrektur + Audit-Verdrahtung

**Files:**
- Create: `var/plugins/WorktimeBundle/Validator/WorkBlockValidator.php`
- Create: `var/plugins/WorktimeBundle/Tests/Validator/WorkBlockValidatorTest.php`
- Create: `var/plugins/WorktimeBundle/Controller/EntryController.php`
- Modify: `var/plugins/WorktimeBundle/Controller/WorktimeController.php` (punch → audit)

**Interfaces:**
- Consumes: `WorkBlock` (+ `SOURCE_MANUAL`, `getStart/setStart/getEnd/setEnd/setUser/setSource/getUser/getId`), `WorkBlockRepository` (`save`, `remove`, `findOpenBlock`, `find`), `AuditLogger::log`, `AuditLog` constants, `App\Entity\User` (`getTimezone`, `getId`).
- Produces:
  - `WorkBlockValidator::validateInterval(\DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string` (null = ok, sonst deutsche Fehlermeldung).
  - Routes `worktime_entry_create` (POST `/worktime/entry`), `worktime_entry_edit` (POST `/worktime/entry/{id}/edit`), `worktime_entry_delete` (POST `/worktime/entry/{id}/delete`).

- [ ] **Step 1: Failing test für den Validator**

`var/plugins/WorktimeBundle/Tests/Validator/WorkBlockValidatorTest.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Validator;

use KimaiPlugin\WorktimeBundle\Validator\WorkBlockValidator;
use PHPUnit\Framework\TestCase;

class WorkBlockValidatorTest extends TestCase
{
    private function v(): WorkBlockValidator
    {
        return new WorkBlockValidator();
    }

    public function testEndAfterStartIsValid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 08:00:00');
        $end = new \DateTimeImmutable('2026-06-01 12:00:00');
        self::assertNull($this->v()->validateInterval($start, $end));
    }

    public function testOpenIntervalIsValid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 08:00:00');
        self::assertNull($this->v()->validateInterval($start, null));
    }

    public function testEndBeforeStartIsInvalid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 12:00:00');
        $end = new \DateTimeImmutable('2026-06-01 08:00:00');
        self::assertNotNull($this->v()->validateInterval($start, $end));
    }

    public function testEndEqualStartIsInvalid(): void
    {
        $start = new \DateTimeImmutable('2026-06-01 08:00:00');
        $end = new \DateTimeImmutable('2026-06-01 08:00:00');
        self::assertNotNull($this->v()->validateInterval($start, $end));
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter WorkBlockValidatorTest
```
Expected: FAIL — `Class "KimaiPlugin\WorktimeBundle\Validator\WorkBlockValidator" not found`.

- [ ] **Step 3: Validator implementieren**

`var/plugins/WorktimeBundle/Validator/WorkBlockValidator.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Validator;

class WorkBlockValidator
{
    /**
     * @return string|null null if valid, otherwise a German error message
     */
    public function validateInterval(\DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string
    {
        if ($end !== null && $end <= $start) {
            return 'Das Ende muss nach dem Beginn liegen.';
        }

        return null;
    }
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter WorkBlockValidatorTest
```
Expected: PASS — 4 Tests grün.

- [ ] **Step 5: EntryController anlegen (create/edit/delete)**

`var/plugins/WorktimeBundle/Controller/EntryController.php`:
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
use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use KimaiPlugin\WorktimeBundle\Validator\WorkBlockValidator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime/entry')]
#[IsGranted('worktime_edit_own')]
final class EntryController extends AbstractController
{
    private function redirectIndex(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('worktime_index'));
    }

    private function parse(?string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return null;
        }
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i'] as $fmt) {
            $dt = \DateTimeImmutable::createFromFormat($fmt, $raw, $tz);
            if ($dt instanceof \DateTimeImmutable) {
                return $dt;
            }
        }

        return null;
    }

    #[Route(path: '', name: 'worktime_entry_create', methods: ['POST'])]
    public function create(Request $request, WorkBlockRepository $blocks, WorkBlockValidator $validator, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        /** @var User $user */
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $start = $this->parse($request->request->get('start'), $tz);
        $end = $this->parse($request->request->get('end'), $tz);

        if ($start === null || $end === null) {
            $this->addFlash('error', 'Bitte Beginn und Ende angeben.');

            return $this->redirectIndex();
        }
        $error = $validator->validateInterval($start, $end);
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectIndex();
        }

        $block = new WorkBlock();
        $block->setUser($user);
        $block->setStart($start);
        $block->setEnd($end);
        $block->setSource(WorkBlock::SOURCE_MANUAL);
        $blocks->save($block);

        $audit->log($user, $user, AuditLog::ACTION_BLOCK_CREATE, 'work_block', $block->getId(), [
            'start' => $start->format('c'),
            'end' => $end->format('c'),
            'source' => WorkBlock::SOURCE_MANUAL,
        ]);

        $this->addFlash('success', 'Eintrag gespeichert.');

        return $this->redirectIndex();
    }

    #[Route(path: '/{id}/edit', name: 'worktime_entry_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, WorkBlockRepository $blocks, WorkBlockValidator $validator, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        /** @var User $user */
        $user = $this->getUser();
        $block = $blocks->find($id);
        if ($block === null || $block->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $tz = new \DateTimeZone($user->getTimezone());
        $start = $this->parse($request->request->get('start'), $tz);
        $end = $this->parse($request->request->get('end'), $tz);
        if ($start === null) {
            $this->addFlash('error', 'Bitte einen Beginn angeben.');

            return $this->redirectIndex();
        }
        $error = $validator->validateInterval($start, $end);
        if ($error !== null) {
            $this->addFlash('error', $error);

            return $this->redirectIndex();
        }

        $old = [
            'start' => $block->getStart()->format('c'),
            'end' => $block->getEnd()?->format('c'),
        ];
        $block->setStart($start);
        $block->setEnd($end);
        $blocks->save($block);

        $audit->log($user, $user, AuditLog::ACTION_BLOCK_EDIT, 'work_block', $block->getId(), [
            'old' => $old,
            'new' => ['start' => $start->format('c'), 'end' => $end?->format('c')],
        ]);

        $this->addFlash('success', 'Eintrag aktualisiert.');

        return $this->redirectIndex();
    }

    #[Route(path: '/{id}/delete', name: 'worktime_entry_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, WorkBlockRepository $blocks, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        /** @var User $user */
        $user = $this->getUser();
        $block = $blocks->find($id);
        if ($block === null || $block->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $details = [
            'start' => $block->getStart()->format('c'),
            'end' => $block->getEnd()?->format('c'),
        ];
        $blockId = $block->getId();
        $blocks->remove($block);

        $audit->log($user, $user, AuditLog::ACTION_BLOCK_DELETE, 'work_block', $blockId, $details);

        $this->addFlash('success', 'Eintrag gelöscht.');

        return $this->redirectIndex();
    }
}
```

> `WorkBlockRepository::find($id)` is inherited from `ServiceEntityRepository` (returns `?WorkBlock`). No new repo method needed.

- [ ] **Step 6: Punch-Action um Audit ergänzen**

In `var/plugins/WorktimeBundle/Controller/WorktimeController.php`: add the audit dependency to `punch()` and log each punch. Replace the `punch` method's signature and body so it reads:
```php
    #[Route(path: '/punch', name: 'worktime_punch', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function punch(Request $request, WorkBlockRepository $blocks, \KimaiPlugin\WorktimeBundle\Audit\AuditLogger $audit): Response
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
            $audit->log($user, $user, \KimaiPlugin\WorktimeBundle\Entity\AuditLog::ACTION_PUNCH_OUT, 'work_block', $open->getId(), ['end' => $now->format('c')]);
            $this->addFlash('success', 'Ausgestempelt.');
        } else {
            $block = new WorkBlock();
            $block->setUser($user);
            $block->setStart($now);
            $block->setSource(WorkBlock::SOURCE_PUNCH);
            $blocks->save($block);
            $audit->log($user, $user, \KimaiPlugin\WorktimeBundle\Entity\AuditLog::ACTION_PUNCH_IN, 'work_block', $block->getId(), ['start' => $now->format('c')]);
            $this->addFlash('success', 'Eingestempelt.');
        }

        return new RedirectResponse($this->generateUrl('worktime_index'));
    }
```
(Leave the `index` action and the file's existing `use` imports unchanged; the fully-qualified `\KimaiPlugin\WorktimeBundle\Audit\AuditLogger` / `...\Entity\AuditLog` references avoid touching the import block. If php-cs-fixer prefers `use` imports, let it add them.)

- [ ] **Step 7: Routen, Analyse, Tests**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console debug:router | grep -E "worktime_entry_(create|edit|delete)"
cd var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist && cd /opt/kimai
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: three `worktime_entry_*` routes present (all POST); full plugin test suite green (incl. the 4 new validator tests); cs-fixer + phpstan clean.

- [ ] **Step 8: Commit**

```bash
git add var/plugins/WorktimeBundle/Validator var/plugins/WorktimeBundle/Tests/Validator var/plugins/WorktimeBundle/Controller/EntryController.php var/plugins/WorktimeBundle/Controller/WorktimeController.php
git commit -m "feat(worktime): manual entry, edit/delete with ownership+validation, punch audit"
```

---

### Task 3: UI — grüne Uhr, Bleistift-Bearbeiten, „Zeit eintragen", Modal

**Files:**
- Modify: `var/plugins/WorktimeBundle/Resources/views/index.html.twig`

**Interfaces:**
- Consumes: routes `worktime_entry_create|edit|delete`, CSRF token `worktime.entry`, `app.user.timezone`, `block.id`, `block.start`, `block.end`.
- Produces: UI only.

- [ ] **Step 1: Hero auf SICODA-grün (laufend) umstellen**

In `var/plugins/WorktimeBundle/Resources/views/index.html.twig` im `<style>`-Block den `.wt-hero--in`-Abschnitt ersetzen, sodass der laufende Hero mint-grün statt navy ist (dunkler Text für Kontrast):
```css
        /* IN state — running clock: SICODA mint background (signature) */
        .wt-hero--in {
            background: radial-gradient(120% 140% at 50% -10%, #34d6b8 0%, var(--mint) 55%, #20a48d 100%);
            border-color: transparent; color: #06312b;
        }
        .wt-hero--in .wt-hero__pill { background: rgba(6,49,43,.14); color: #06312b; }
        .wt-hero--in .wt-hero__clock { color: #06312b; font-size: clamp(3.2rem, 12vw, 4.6rem); }
        .wt-hero--in .wt-hero__sub { color: #0a4a40; }
        .wt-punch--out {
            background: #06312b; color: #fff;
            box-shadow: 0 10px 24px -10px rgba(6,49,43,.6);
        }
        .wt-punch--out:hover { background: #0a4a40; }
```
And update the pulsing dot color for the green background — replace the existing `.wt-hero--in .wt-dot` rule and the `@keyframes wt-pulse` block with:
```css
        .wt-hero--in .wt-dot { background: #06312b; }
        @media (prefers-reduced-motion: no-preference) {
            .wt-hero--in .wt-dot { animation: wt-pulse 1.8s ease-out infinite; }
            @keyframes wt-pulse {
                0% { box-shadow: 0 0 0 0 rgba(6,49,43,.45); }
                100% { box-shadow: 0 0 0 .8rem rgba(6,49,43,0); }
            }
        }
```
(Remove the old duplicate `.wt-hero--in .wt-dot` and the old reduced-motion/keyframes block so there is exactly one of each.)

- [ ] **Step 2: CSS für Bleistift-Button, „Zeit eintragen" und Modal-Feinschliff**

Add these rules at the end of the `<style>` block (before `</style>`):
```css
        .wt-sessions__head { display: flex; align-items: center; justify-content: space-between; margin: 0 .25rem .75rem; gap: 1rem; }
        .wt-add {
            -webkit-appearance: none; appearance: none; cursor: pointer;
            border: 1px solid var(--mint); background: var(--white); color: var(--mint-700);
            font-family: var(--body); font-weight: 600; font-size: .85rem;
            border-radius: 999px; padding: .5rem 1rem; display: inline-flex; align-items: center; gap: .45rem;
        }
        .wt-add:hover { background: rgba(46,196,169,.08); }
        .wt-add:focus-visible { outline: 3px solid var(--mint); outline-offset: 2px; }
        .wt-row { grid-template-columns: auto 1fr auto auto; }
        .wt-edit {
            -webkit-appearance: none; appearance: none; cursor: pointer;
            border: 0; background: transparent; color: #9aa7b4; padding: .35rem .5rem; border-radius: 8px;
        }
        .wt-edit:hover { color: var(--navy); background: var(--ice); }
        .wt-edit:focus-visible { outline: 2px solid var(--mint); outline-offset: 1px; }
```

- [ ] **Step 3: Sessions-Kopf mit „Zeit eintragen" + Bleistift je Zeile**

Replace the existing `.wt-sessions` block in `{% block main %}` (the `<div class="wt-sessions">…</div>`) with:
```twig
        <div class="wt-sessions">
            <div class="wt-sessions__head">
                <div class="wt-sessions__title">{{ 'Heutige Stempelzeiten'|trans }}</div>
                <button type="button" class="wt-add"
                        data-bs-toggle="modal" data-bs-target="#wtEntryModal"
                        data-mode="create" data-action="{{ path('worktime_entry_create') }}">
                    <i class="{{ 'create'|icon }}"></i> {{ 'Zeit eintragen'|trans }}
                </button>
            </div>
            {% for block in today_blocks %}
                <div class="wt-row {{ block.end is null ? 'wt-row--live' : '' }}">
                    <span class="wt-row__bullet"></span>
                    <span class="wt-row__time">
                        {{ block.start|time }}
                        <span class="wt-arrow">→</span>
                        {% if block.end is null %}
                            <span class="wt-running">{{ 'läuft'|trans }}</span>
                        {% else %}
                            {{ block.end|time }}
                        {% endif %}
                    </span>
                    <span class="wt-row__dur">{{ block.durationSeconds is null ? '·' : block.durationSeconds|duration }}</span>
                    <button type="button" class="wt-edit" title="{{ 'Bearbeiten'|trans }}"
                            data-bs-toggle="modal" data-bs-target="#wtEntryModal"
                            data-mode="edit"
                            data-action="{{ path('worktime_entry_edit', {id: block.id}) }}"
                            data-delete="{{ path('worktime_entry_delete', {id: block.id}) }}"
                            data-start="{{ block.start|date('Y-m-d\\TH:i', app.user.timezone) }}"
                            data-end="{{ block.end is null ? '' : block.end|date('Y-m-d\\TH:i', app.user.timezone) }}">
                        <i class="{{ 'edit'|icon }}"></i>
                    </button>
                </div>
            {% else %}
                <div class="wt-empty">{{ 'Noch keine Buchung heute.'|trans }}</div>
            {% endfor %}
        </div>

        {# Reusable create/edit modal #}
        <div class="modal fade" id="wtEntryModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" data-wt-title>{{ 'Zeit eintragen'|trans }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'Schließen'|trans }}"></button>
                    </div>
                    <form method="post" data-wt-form>
                        <div class="modal-body">
                            <input type="hidden" name="_token" value="{{ csrf_token('worktime.entry') }}">
                            <div class="mb-3">
                                <label class="form-label" for="wtStart">{{ 'Beginn'|trans }}</label>
                                <input type="datetime-local" class="form-control" id="wtStart" name="start" required>
                            </div>
                            <div class="mb-1">
                                <label class="form-label" for="wtEnd">{{ 'Ende'|trans }}</label>
                                <input type="datetime-local" class="form-control" id="wtEnd" name="end">
                            </div>
                        </div>
                        <div class="modal-footer justify-content-between">
                            <button type="submit" class="btn" data-wt-delete-btn
                                    formmethod="post" formaction="" hidden>
                                <i class="{{ 'delete'|icon }}"></i> {{ 'Löschen'|trans }}
                            </button>
                            <span class="d-flex gap-2 ms-auto">
                                <button type="button" class="btn btn-link" data-bs-dismiss="modal">{{ 'Abbrechen'|trans }}</button>
                                <button type="submit" class="btn btn-primary">{{ 'Speichern'|trans }}</button>
                            </span>
                        </div>
                    </form>
                </div>
            </div>
        </div>
```

> The delete button is a second submit inside the same form, using `formaction` to POST to the delete route (HTML5 lets one submit button override the form's action). JS sets its `formaction` and toggles `hidden` in edit mode. The delete route ignores the `start`/`end` fields, so sharing the form is safe.

- [ ] **Step 4: Modal-JS (Felder füllen, Modus umschalten)**

In `{% block javascripts %}` (after the existing live-timer IIFE, before `</script>` of that block OR as a second `<script>`), add:
```twig
    <script>
        (function () {
            var modal = document.getElementById('wtEntryModal');
            if (!modal) { return; }
            var form = modal.querySelector('[data-wt-form]');
            var title = modal.querySelector('[data-wt-title]');
            var startEl = modal.querySelector('#wtStart');
            var endEl = modal.querySelector('#wtEnd');
            var delBtn = modal.querySelector('[data-wt-delete-btn]');

            function nowLocal() {
                var d = new Date();
                d.setSeconds(0, 0);
                var off = d.getTimezoneOffset();
                var local = new Date(d.getTime() - off * 60000);
                return local.toISOString().slice(0, 16);
            }

            modal.addEventListener('show.bs.modal', function (event) {
                var btn = event.relatedTarget;
                if (!btn) { return; }
                var mode = btn.getAttribute('data-mode');
                form.setAttribute('action', btn.getAttribute('data-action') || '');

                if (mode === 'edit') {
                    title.textContent = 'Eintrag bearbeiten';
                    startEl.value = btn.getAttribute('data-start') || '';
                    endEl.value = btn.getAttribute('data-end') || '';
                    delBtn.setAttribute('formaction', btn.getAttribute('data-delete') || '');
                    delBtn.hidden = false;
                } else {
                    title.textContent = 'Zeit eintragen';
                    startEl.value = nowLocal();
                    endEl.value = '';
                    delBtn.removeAttribute('formaction');
                    delBtn.hidden = true;
                }
            });
        })();
    </script>
```

- [ ] **Step 5: Ownership, Cache, Lint, Analyse, Log**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
LB=$(wc -l < var/log/prod.log)
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console lint:twig var/plugins/WorktimeBundle/Resources/views
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
tail -n +$((LB+1)) var/log/prod.log | grep -iE "CRITICAL|ERROR" || echo "no new log errors"
```
Expected: lint:twig valid; cs-fixer + phpstan clean; no new prod.log errors.

- [ ] **Step 6: Manueller Smoke-Test (live, Mensch)**

Auf `https://kimai.sicoda.work/worktime` (eingeloggt):
- Eingestempelt → Hero ist **mint-grün** mit dunkler Live-Uhr.
- „Zeit eintragen" → Modal öffnet, Beginn vorbefüllt auf jetzt, Ende eingeben, Speichern → neue Zeile erscheint, „Heute gesamt" steigt.
- Bleistift an einer Zeile → Modal öffnet mit den Werten der Zeile; Ändern + Speichern aktualisiert die Zeile; „Löschen" entfernt sie.
- Falsches Intervall (Ende ≤ Beginn) → Fehlermeldung, kein Eintrag.

- [ ] **Step 7: Commit**

```bash
git add var/plugins/WorktimeBundle/Resources/views/index.html.twig
git commit -m "feat(worktime): green running clock, per-row edit pencil, manual time-entry modal"
```

---

## Self-Review

**Spec coverage (gegen Spec §6 + Nutzer-Vorgaben):**
- Manuelle Erfassung „Zeit eintragen" (beliebiges Datum+Uhrzeit) → Task 2 (`create`) + Task 3 (Button+Modal). ✓
- Korrektur per Bleistift (Start/Ende ändern + löschen + Datum frei) → Task 2 (`edit`/`delete`) + Task 3 (Pencil+Modal, datetime-local enthält Datum). ✓
- Audit-Log aller Buchungen+Korrekturen → Task 1 (Infra) + Task 2 (punch/create/edit/delete loggen). ✓
- Eigentumsschutz (nur eigene Blöcke) → Task 2 (Ownership-Check → 404). ✓
- Grüne laufende Uhr → Task 3 Step 1. ✓
- Framework-freie, getestete Logik (§11) → Task 2 (`WorkBlockValidator`, 4 Tests). ✓
- `down()` Schema-API; Views extenden base.html.twig; phpstan L9 + cs-fixer → alle Tasks. ✓

**Bewusst nicht hier:** Nacht-Cron (vergessenes Ausstempeln), Soll/Ist-Konto-Anzeige, Monatssperre-Guard (MonthClosure existiert noch nicht). Audit-Log-Ansicht (Anzeige der Historie) — nur Schreiben in diesem Plan, eine UI dafür ist späterer Plan.

**Placeholder scan:** keine TBD/TODO; jeder Code-Step vollständig. ✓

**Type consistency:** `AuditLogger::log(?User,?User,string,string,?int,array)`, `AuditLog`-Konstanten, `WorkBlockValidator::validateInterval(\DateTimeImmutable, ?\DateTimeImmutable): ?string`, `WorkBlockRepository::find/save/remove/findOpenBlock`, `WorkBlock::SOURCE_MANUAL/SOURCE_PUNCH` über Tasks 1–3 konsistent. ✓
