# Worktime Plugin — Plan 4: Urlaub (Antrag, Genehmigung, Saldo, Halbtage)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Mitarbeiter beantragen Urlaub (Zeitraum oder halber Tag), sehen ihren Urlaubsrest und ihre Anträge; Admins genehmigen/lehnen ab — alles protokolliert.

**Architecture:** Eigene `Absence`-Entity (Typ vorerst nur „vacation", Spalte für spätere Typen vorbereitet) mit Status offen/genehmigt/abgelehnt. Ein framework-freier `VacationCalculator` (TDD) zählt Urlaubstage (Arbeitstage laut Vertrag, halber Tag = 0,5) und rechnet den Rest. MA-Seite `/worktime/vacation` (Rest + eigene Anträge + Antrag-Modal), Admin-Seite `/admin/worktime/absences` (offene Anträge genehmigen/ablehnen). Genehmigungen laufen über den bestehenden `AuditLogger`.

**Tech Stack:** PHP 8.2+, Symfony 6.4, Doctrine ORM (Attribute), PHPUnit, Twig, Bootstrap 5 (Tabler) Modal.

## Global Constraints

- Bundle-Namespace `KimaiPlugin\WorktimeBundle`; Tabellen-Präfix `kimai2_worktime_`.
- Datums-Spalten `Types::DATE_IMMUTABLE`; Zeitstempel `Types::DATETIME_IMMUTABLE` (UTC).
- Migration extends `App\Doctrine\AbstractMigration`; `down()` nutzt `$schema->dropTable(...)`.
- Plugin-Views extenden `'base.html.twig'`; jede PHP-Datei mit Lizenz-Header; Code besteht `./phpstan.sh Worktime` (Level 9) + `./php-cs-fixer.sh Worktime`.
- **Nur Urlaub** in diesem Plan (Krankheit/Überstundenabbau später) — `Absence.type` als String-Spalte mit Konstante `TYPE_VACATION` für spätere Erweiterung.
- **Halbtage** werden unterstützt: `halfDay`-Flag, nur für eintägige Anträge (start == end), zählt 0,5.
- Workflow: **MA beantragt → Admin genehmigt/lehnt ab.** MA sieht nur eigene Anträge + eigenen Rest; Admin sieht alle offenen Anträge. Anträge stellen: `worktime_edit_own`; Genehmigen: `worktime_manage`. Alle POST mit CSRF.
- Urlaubstage zählen = Arbeitstage laut Vertrag (Wochentag mit Stunden > 0); Wochenenden/0-Stunden-Tage zählen nicht. Anspruch = `Contract.holidaysPerYear`; Übertrag = `Contract.vacationCarryover` (beide aus Plan 1). Saldo-Bezug: aktuelles **Kalenderjahr**.
- Jede Antrags-/Genehmigungs-Aktion wird auditiert (`AuditLogger`).
- Prod: Plugin gehört `www-data`; nach Änderungen `sudo -u www-data php bin/console cache:clear`.

## Scope / Abgrenzung
Drin: Urlaub beantragen (Bereich/Halbtag), Rest-Anzeige, Admin-Genehmigung, Audit. **Nicht hier:** Krankheit/Überstundenabbau; Verrechnung des genehmigten Urlaubs ins Soll/Ist-Konto (kommt mit dem Konto-Plan — genehmigter Urlaub liegt als `Absence` vor und wird dort konsumiert); Feiertags-Ausschluss (yasumi, eigener Plan); Abwesenheitskalender aller MA (Admin) als Monatsraster (späterer Plan — hier nur die Antragsliste).

---

## File Structure
```
var/plugins/WorktimeBundle/
├── Entity/AuditLog.php                         # ERWEITERT (Task 1: ABSENCE_* Konstanten)
├── Entity/Absence.php                          # NEU (Task 1)
├── Repository/AbsenceRepository.php            # NEU (Task 1)
├── Migrations/Version20260623120000.php        # NEU (Task 1)
├── Calculator/VacationCalculator.php           # NEU (Task 2) framework-frei
├── Controller/VacationController.php           # NEU (Task 3) MA
├── Controller/AbsenceAdminController.php       # NEU (Task 4) Admin
├── EventSubscriber/MenuSubscriber.php          # ERWEITERT (Task 3 + 4: Menüeinträge)
├── Resources/views/vacation/index.html.twig    # NEU (Task 3)
├── Resources/views/vacation/admin.html.twig    # NEU (Task 4)
└── Tests/Calculator/VacationCalculatorTest.php  # NEU (Task 2)
```

---

### Task 1: Absence-Entity, Repository, Migration, Audit-Konstanten

**Files:**
- Modify: `var/plugins/WorktimeBundle/Entity/AuditLog.php` (add ABSENCE_* constants)
- Create: `var/plugins/WorktimeBundle/Entity/Absence.php`
- Create: `var/plugins/WorktimeBundle/Repository/AbsenceRepository.php`
- Create: `var/plugins/WorktimeBundle/Migrations/Version20260623120000.php`

**Interfaces:**
- Consumes: `App\Entity\User`, `App\Doctrine\AbstractMigration`.
- Produces:
  - `AuditLog` constants `ACTION_ABSENCE_REQUEST='absence_request'`, `ACTION_ABSENCE_APPROVE='absence_approve'`, `ACTION_ABSENCE_REJECT='absence_reject'`.
  - `Absence` with constants `TYPE_VACATION='vacation'`, `STATUS_OPEN='open'`, `STATUS_APPROVED='approved'`, `STATUS_REJECTED='rejected'`; constructor `__construct(\DateTimeImmutable $createdAt)`; getters/setters: `getId(): ?int`, `getUser(): User` / `setUser(User)`, `getType(): string` / `setType(string)`, `getStartDate(): \DateTimeImmutable` / `setStartDate(\DateTimeImmutable)`, `getEndDate(): \DateTimeImmutable` / `setEndDate(\DateTimeImmutable)`, `isHalfDay(): bool` / `setHalfDay(bool)`, `getStatus(): string` / `setStatus(string)`, `getNote(): ?string` / `setNote(?string)`, `getDecidedBy(): ?User` / `setDecidedBy(?User)`, `getDecidedAt(): ?\DateTimeImmutable` / `setDecidedAt(?\DateTimeImmutable)`, `getCreatedAt(): \DateTimeImmutable`.
  - `AbsenceRepository`: `findForUser(User): array`, `findPending(): array`, `findApprovedForUserInYear(User, int $year): array`, `save(Absence): void`, `remove(Absence): void`.
  - Table `kimai2_worktime_absence`.

- [ ] **Step 1: AuditLog-Konstanten ergänzen**

In `var/plugins/WorktimeBundle/Entity/AuditLog.php`, after the existing `ACTION_BLOCK_DELETE` constant line, add:
```php
    public const ACTION_ABSENCE_REQUEST = 'absence_request';
    public const ACTION_ABSENCE_APPROVE = 'absence_approve';
    public const ACTION_ABSENCE_REJECT = 'absence_reject';
```

- [ ] **Step 2: Absence-Entity anlegen**

`var/plugins/WorktimeBundle/Entity/Absence.php`:
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
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;

#[ORM\Entity(repositoryClass: AbsenceRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_absence')]
#[ORM\Index(columns: ['user_id', 'start_date'], name: 'IDX_worktime_absence_user_start')]
#[ORM\Index(columns: ['status'], name: 'IDX_worktime_absence_status')]
class Absence
{
    public const TYPE_VACATION = 'vacation';

    public const STATUS_OPEN = 'open';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'type', type: Types::STRING, length: 30)]
    private string $type = self::TYPE_VACATION;

    #[ORM\Column(name: 'start_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $startDate;

    #[ORM\Column(name: 'end_date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $endDate;

    #[ORM\Column(name: 'half_day', type: Types::BOOLEAN)]
    private bool $halfDay = false;

    #[ORM\Column(name: 'status', type: Types::STRING, length: 20)]
    private string $status = self::STATUS_OPEN;

    #[ORM\Column(name: 'note', type: Types::TEXT, nullable: true)]
    private ?string $note = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'decided_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $decidedBy = null;

    #[ORM\Column(name: 'decided_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(\DateTimeImmutable $createdAt)
    {
        $this->createdAt = $createdAt;
    }

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

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getStartDate(): \DateTimeImmutable
    {
        return $this->startDate;
    }

    public function setStartDate(\DateTimeImmutable $startDate): void
    {
        $this->startDate = $startDate;
    }

    public function getEndDate(): \DateTimeImmutable
    {
        return $this->endDate;
    }

    public function setEndDate(\DateTimeImmutable $endDate): void
    {
        $this->endDate = $endDate;
    }

    public function isHalfDay(): bool
    {
        return $this->halfDay;
    }

    public function setHalfDay(bool $halfDay): void
    {
        $this->halfDay = $halfDay;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getNote(): ?string
    {
        return $this->note;
    }

    public function setNote(?string $note): void
    {
        $this->note = $note;
    }

    public function getDecidedBy(): ?User
    {
        return $this->decidedBy;
    }

    public function setDecidedBy(?User $decidedBy): void
    {
        $this->decidedBy = $decidedBy;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): void
    {
        $this->decidedAt = $decidedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 3: AbsenceRepository anlegen**

`var/plugins/WorktimeBundle/Repository/AbsenceRepository.php`:
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
use KimaiPlugin\WorktimeBundle\Entity\Absence;

/**
 * @extends ServiceEntityRepository<Absence>
 */
class AbsenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Absence::class);
    }

    /**
     * @return Absence[]
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['startDate' => 'DESC']);
    }

    /**
     * @return Absence[]
     */
    public function findPending(): array
    {
        return $this->findBy(['status' => Absence::STATUS_OPEN], ['startDate' => 'ASC']);
    }

    /**
     * Approved absences for the user that start within the given calendar year.
     *
     * @return Absence[]
     */
    public function findApprovedForUserInYear(User $user, int $year): array
    {
        $from = new \DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = new \DateTimeImmutable(sprintf('%04d-12-31', $year));

        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate >= :from')
            ->andWhere('a.startDate <= :to')
            ->setParameter('user', $user)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function save(Absence $absence): void
    {
        $em = $this->getEntityManager();
        $em->persist($absence);
        $em->flush();
    }

    public function remove(Absence $absence): void
    {
        $em = $this->getEntityManager();
        $em->remove($absence);
        $em->flush();
    }
}
```

- [ ] **Step 4: Migration anlegen**

`var/plugins/WorktimeBundle/Migrations/Version20260623120000.php`:
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

final class Version20260623120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create absence table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_absence (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            decided_by INT DEFAULT NULL,
            type VARCHAR(30) NOT NULL,
            start_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            end_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            half_day TINYINT(1) NOT NULL,
            status VARCHAR(20) NOT NULL,
            note LONGTEXT DEFAULT NULL,
            decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_worktime_absence_user_start (user_id, start_date),
            INDEX IDX_worktime_absence_status (status),
            INDEX IDX_worktime_absence_decided_by (decided_by),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_absence
            ADD CONSTRAINT FK_worktime_absence_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_worktime_absence
            ADD CONSTRAINT FK_worktime_absence_decided_by FOREIGN KEY (decided_by)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_absence');
    }
}
```

> The two FK columns each get a Doctrine-managed index; `start_date`'s composite already exists. To stay drift-free with `doctrine:schema:validate`, run the migrate then validate in Step 5 and, if validate reports an index name diff for this table, align the migration's `INDEX` names to the names Doctrine reports (same approach as the audit_log table in Plan 3).

- [ ] **Step 5: Migrieren, validieren, Rollback, Analyse**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console doctrine:schema:validate
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623120000' --down --no-interaction
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623120000' --up --no-interaction
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: migration runs; `schema:validate` shows no diff for `kimai2_worktime_absence` (verify with `sudo -u www-data php bin/console doctrine:schema:update --dump-sql | grep absence` → empty); down+up succeed; cs-fixer + phpstan clean.

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Entity/AuditLog.php var/plugins/WorktimeBundle/Entity/Absence.php var/plugins/WorktimeBundle/Repository/AbsenceRepository.php var/plugins/WorktimeBundle/Migrations/Version20260623120000.php
git commit -m "feat(worktime): absence entity, repository, migration and audit constants"
```

---

### Task 2: VacationCalculator (framework-frei, TDD)

**Files:**
- Create: `var/plugins/WorktimeBundle/Calculator/VacationCalculator.php`
- Create: `var/plugins/WorktimeBundle/Tests/Calculator/VacationCalculatorTest.php`

**Interfaces:**
- Consumes: `Contract` (`getWorkHoursForWeekday(int): int`).
- Produces:
  - `VacationCalculator::countDays(Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $halfDay): float`
  - `VacationCalculator::remainingDays(float $entitlement, float $carryover, float $usedDays): float`

- [ ] **Step 1: Failing test schreiben**

`var/plugins/WorktimeBundle/Tests/Calculator/VacationCalculatorTest.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use PHPUnit\Framework\TestCase;

class VacationCalculatorTest extends TestCase
{
    private const H8 = 28800;

    private function fullTimeContract(): Contract
    {
        $c = new Contract();
        $c->setUser(new User());
        foreach ([1, 2, 3, 4, 5] as $wd) {
            $c->setWorkHoursForWeekday($wd, self::H8);
        }
        $c->setWorkHoursForWeekday(6, 0);
        $c->setWorkHoursForWeekday(7, 0);

        return $c;
    }

    public function testCountsWorkingDaysMonToFri(): void
    {
        $calc = new VacationCalculator();
        $from = new \DateTimeImmutable('2026-06-01'); // Monday
        $to = new \DateTimeImmutable('2026-06-05');   // Friday
        self::assertSame(5.0, $calc->countDays($this->fullTimeContract(), $from, $to, false));
    }

    public function testExcludesWeekend(): void
    {
        $calc = new VacationCalculator();
        $from = new \DateTimeImmutable('2026-06-05'); // Friday
        $to = new \DateTimeImmutable('2026-06-08');   // Monday (Sat+Sun excluded)
        self::assertSame(2.0, $calc->countDays($this->fullTimeContract(), $from, $to, false));
    }

    public function testSingleWorkingDayFull(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-01'); // Monday
        self::assertSame(1.0, $calc->countDays($this->fullTimeContract(), $d, $d, false));
    }

    public function testSingleWorkingDayHalf(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-01'); // Monday
        self::assertSame(0.5, $calc->countDays($this->fullTimeContract(), $d, $d, true));
    }

    public function testWeekendDayIsZero(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-06'); // Saturday
        self::assertSame(0.0, $calc->countDays($this->fullTimeContract(), $d, $d, false));
    }

    public function testHalfDayOnWeekendIsZero(): void
    {
        $calc = new VacationCalculator();
        $d = new \DateTimeImmutable('2026-06-06'); // Saturday
        self::assertSame(0.0, $calc->countDays($this->fullTimeContract(), $d, $d, true));
    }

    public function testRemainingDays(): void
    {
        $calc = new VacationCalculator();
        self::assertSame(18.5, $calc->remainingDays(30.0, 0.0, 11.5));
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter VacationCalculatorTest
```
Expected: FAIL — `Class "KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator" not found`.

- [ ] **Step 3: VacationCalculator implementieren**

`var/plugins/WorktimeBundle/Calculator/VacationCalculator.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\Contract;

final class VacationCalculator
{
    /**
     * Number of vacation days in [from, to] that are working days per the
     * contract (weekday work hours > 0). A half day (only meaningful for a
     * single day) counts 0.5 on a working day, 0 otherwise.
     */
    public function countDays(Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, bool $halfDay): float
    {
        $start = $from->setTime(0, 0);
        $end = $to->setTime(0, 0);

        if ($halfDay) {
            $weekday = (int) $start->format('N');

            return $contract->getWorkHoursForWeekday($weekday) > 0 ? 0.5 : 0.0;
        }

        $days = 0.0;
        $cursor = $start;
        while ($cursor <= $end) {
            $weekday = (int) $cursor->format('N');
            if ($contract->getWorkHoursForWeekday($weekday) > 0) {
                ++$days;
            }
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    public function remainingDays(float $entitlement, float $carryover, float $usedDays): float
    {
        return $entitlement + $carryover - $usedDays;
    }
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

Run:
```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter VacationCalculatorTest
```
Expected: PASS — 7 Tests grün.

- [ ] **Step 5: Commit**

```bash
git add var/plugins/WorktimeBundle/Calculator/VacationCalculator.php var/plugins/WorktimeBundle/Tests/Calculator/VacationCalculatorTest.php
git commit -m "feat(worktime): framework-free vacation day calculator with unit tests"
```

---

### Task 3: Mitarbeiter-Urlaubsseite (Rest, Anträge, Antrag stellen) + Menü

**Files:**
- Create: `var/plugins/WorktimeBundle/Controller/VacationController.php`
- Create: `var/plugins/WorktimeBundle/Resources/views/vacation/index.html.twig`
- Modify: `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php` (add "Urlaub" entry)

**Interfaces:**
- Consumes: `AbsenceRepository` (`findForUser`, `findApprovedForUserInYear`, `save`), `ContractRepository` (`findForUser`), `VacationCalculator` (`countDays`, `remainingDays`), `Absence` (constants), `AuditLogger`, `AuditLog::ACTION_ABSENCE_REQUEST`, `App\Entity\User` (`getTimezone`), `App\Repository\... ` none. CSRF token `worktime.vacation`.
- Produces: routes `worktime_vacation` (GET `/worktime/vacation`), `worktime_vacation_request` (POST `/worktime/vacation/request`); menu item `worktime_vacation`.

- [ ] **Step 1: VacationController anlegen**

`var/plugins/WorktimeBundle/Controller/VacationController.php`:
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
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime/vacation')]
#[IsGranted('worktime_view_own')]
final class VacationController extends AbstractController
{
    #[Route(path: '', name: 'worktime_vacation', methods: ['GET'])]
    public function index(AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $contract = $contracts->findForUser($user);
        $year = (int) (new \DateTimeImmutable('now'))->format('Y');

        $entitlement = $contract !== null ? $contract->getHolidaysPerYear() : 0.0;
        $carryover = $contract !== null ? $contract->getVacationCarryover() : 0.0;

        $used = 0.0;
        if ($contract !== null) {
            foreach ($absences->findApprovedForUserInYear($user, $year) as $a) {
                $used += $calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay());
            }
        }
        $remaining = $calc->remainingDays($entitlement, $carryover, $used);

        $rows = [];
        foreach ($absences->findForUser($user) as $a) {
            $rows[] = [
                'absence' => $a,
                'days' => $contract !== null ? $calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay()) : 0.0,
            ];
        }

        return $this->render('@Worktime/vacation/index.html.twig', [
            'has_contract' => $contract !== null,
            'year' => $year,
            'entitlement' => $entitlement,
            'carryover' => $carryover,
            'used' => $used,
            'remaining' => $remaining,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/request', name: 'worktime_vacation_request', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function request(Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.vacation', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $request->request->getString('start'), $tz);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $request->request->getString('end'), $tz);
        $halfDay = $request->request->getBoolean('half_day');
        $note = trim($request->request->getString('note'));

        if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'Bitte gültige Daten angeben.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);

        if ($end < $start) {
            $this->addFlash('error', 'Das Enddatum darf nicht vor dem Startdatum liegen.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }
        if ($halfDay && $start->format('Y-m-d') !== $end->format('Y-m-d')) {
            $this->addFlash('error', 'Ein halber Tag ist nur für einen einzelnen Tag möglich.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        $absence = new Absence(new \DateTimeImmutable('now'));
        $absence->setUser($user);
        $absence->setType(Absence::TYPE_VACATION);
        $absence->setStartDate($start);
        $absence->setEndDate($end);
        $absence->setHalfDay($halfDay);
        $absence->setStatus(Absence::STATUS_OPEN);
        $absence->setNote($note === '' ? null : $note);
        $absences->save($absence);

        $audit->log($user, $user, AuditLog::ACTION_ABSENCE_REQUEST, 'absence', $absence->getId(), [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'half_day' => $halfDay,
        ]);

        $this->addFlash('success', 'Urlaubsantrag eingereicht.');

        return new RedirectResponse($this->generateUrl('worktime_vacation'));
    }
}
```

- [ ] **Step 2: MA-View anlegen**

`var/plugins/WorktimeBundle/Resources/views/vacation/index.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block page_title %}{{ 'Urlaub'|trans }}{% endblock %}

{% block main %}
    {% if not has_contract %}
        <div class="alert alert-warning">{{ 'Für dich ist noch kein Vertrag hinterlegt. Bitte wende dich an die Verwaltung.'|trans }}</div>
    {% endif %}

    <div class="row row-cards mb-3">
        <div class="col-sm-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small text-uppercase">{{ 'Urlaubsrest'|trans }} {{ year }}</div>
                <div class="h1 mb-0">{{ remaining|number_format(1, ',', '.') }} {{ 'Tage'|trans }}</div>
            </div></div>
        </div>
        <div class="col-sm-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small text-uppercase">{{ 'Anspruch + Übertrag'|trans }}</div>
                <div class="h2 mb-0">{{ (entitlement + carryover)|number_format(1, ',', '.') }} {{ 'Tage'|trans }}</div>
            </div></div>
        </div>
        <div class="col-sm-4">
            <div class="card"><div class="card-body">
                <div class="text-muted small text-uppercase">{{ 'Genommen'|trans }} {{ year }}</div>
                <div class="h2 mb-0">{{ used|number_format(1, ',', '.') }} {{ 'Tage'|trans }}</div>
            </div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">{{ 'Meine Urlaubsanträge'|trans }}</h3>
            <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#wtVacationModal">
                <i class="{{ 'create'|icon }}"></i> {{ 'Urlaub beantragen'|trans }}
            </button>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr>
                    <th>{{ 'Von'|trans }}</th><th>{{ 'Bis'|trans }}</th><th>{{ 'Tage'|trans }}</th><th>{{ 'Status'|trans }}</th><th>{{ 'Notiz'|trans }}</th>
                </tr></thead>
                <tbody>
                {% for row in rows %}
                    {% set a = row.absence %}
                    <tr>
                        <td>{{ a.startDate|date_short }}</td>
                        <td>{{ a.endDate|date_short }}{% if a.halfDay %} <span class="badge bg-azure-lt">{{ 'halber Tag'|trans }}</span>{% endif %}</td>
                        <td>{{ row.days|number_format(1, ',', '.') }}</td>
                        <td>
                            {% if a.status == 'approved' %}<span class="badge bg-green text-green-fg">{{ 'genehmigt'|trans }}</span>
                            {% elseif a.status == 'rejected' %}<span class="badge bg-red text-red-fg">{{ 'abgelehnt'|trans }}</span>
                            {% else %}<span class="badge bg-yellow text-yellow-fg">{{ 'offen'|trans }}</span>{% endif %}
                        </td>
                        <td class="text-muted">{{ a.note }}</td>
                    </tr>
                {% else %}
                    <tr><td colspan="5" class="text-muted">{{ 'Noch keine Anträge.'|trans }}</td></tr>
                {% endfor %}
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="wtVacationModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <form method="post" action="{{ path('worktime_vacation_request') }}">
                    <div class="modal-header">
                        <h5 class="modal-title">{{ 'Urlaub beantragen'|trans }}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="{{ 'Schließen'|trans }}"></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" name="_token" value="{{ csrf_token('worktime.vacation') }}">
                        <div class="row">
                            <div class="col mb-3">
                                <label class="form-label" for="wtVacStart">{{ 'Von'|trans }}</label>
                                <input type="date" class="form-control" id="wtVacStart" name="start" required data-wt-vac-start>
                            </div>
                            <div class="col mb-3">
                                <label class="form-label" for="wtVacEnd">{{ 'Bis'|trans }}</label>
                                <input type="date" class="form-control" id="wtVacEnd" name="end" required data-wt-vac-end>
                            </div>
                        </div>
                        <label class="form-check">
                            <input type="checkbox" class="form-check-input" name="half_day" value="1" data-wt-vac-half>
                            <span class="form-check-label">{{ 'Halber Tag (nur bei einem einzelnen Tag)'|trans }}</span>
                        </label>
                        <div class="mt-3">
                            <label class="form-label" for="wtVacNote">{{ 'Notiz (optional)'|trans }}</label>
                            <input type="text" class="form-control" id="wtVacNote" name="note" maxlength="500">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-link" data-bs-dismiss="modal">{{ 'Abbrechen'|trans }}</button>
                        <button type="submit" class="btn btn-primary">{{ 'Antrag senden'|trans }}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
{% endblock %}

{% block javascripts %}
    {{ parent() }}
    <script>
        (function () {
            var modal = document.getElementById('wtVacationModal');
            if (!modal) { return; }
            var startEl = modal.querySelector('[data-wt-vac-start]');
            var endEl = modal.querySelector('[data-wt-vac-end]');
            var halfEl = modal.querySelector('[data-wt-vac-half]');
            // keep end >= start, and disable half-day unless it is a single day
            function sync() {
                if (startEl.value && (!endEl.value || endEl.value < startEl.value)) {
                    endEl.value = startEl.value;
                }
                var single = startEl.value && endEl.value && startEl.value === endEl.value;
                halfEl.disabled = !single;
                if (!single) { halfEl.checked = false; }
            }
            startEl.addEventListener('input', sync);
            endEl.addEventListener('input', sync);
            modal.addEventListener('show.bs.modal', sync);
        })();
    </script>
{% endblock %}
```

- [ ] **Step 3: Menüeintrag „Urlaub" ergänzen**

In `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php`, inside `onMenuConfigure`, AFTER the existing `$event->getMenu()->addChild(... 'worktime' ...)` line, add (keep the existing `worktime_view_own` guard wrapping these):
```php
        $event->getMenu()->addChild(
            new MenuItemModel('worktime_vacation', 'Urlaub', 'worktime_vacation', [], 'fas fa-umbrella-beach')
        );
```

- [ ] **Step 4: Routen, Cache, Lint, Analyse**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console debug:router | grep -E "worktime_vacation"
sudo -u www-data php bin/console lint:twig var/plugins/WorktimeBundle/Resources/views
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: routes `worktime_vacation` (GET) and `worktime_vacation_request` (POST) present; lint valid; cs-fixer + phpstan clean.

- [ ] **Step 5: Manueller Smoke-Test (Mensch)**

Als Mitarbeiter `/worktime/vacation`: Urlaubsrest-Karten sichtbar; „Urlaub beantragen" öffnet Modal; Zeitraum + optional halber Tag (nur bei gleichem Von/Bis aktivierbar) + Notiz → „Antrag senden" → Antrag erscheint als „offen".

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Controller/VacationController.php var/plugins/WorktimeBundle/Resources/views/vacation/index.html.twig var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php
git commit -m "feat(worktime): employee vacation page — balance, requests, request modal"
```

---

### Task 4: Admin-Genehmigung + Menü

**Files:**
- Create: `var/plugins/WorktimeBundle/Controller/AbsenceAdminController.php`
- Create: `var/plugins/WorktimeBundle/Resources/views/vacation/admin.html.twig`
- Modify: `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php` (add admin entry)

**Interfaces:**
- Consumes: `AbsenceRepository` (`findPending`, `find`, `save`), `ContractRepository` (`findForUser`), `VacationCalculator` (`countDays`), `Absence` (constants), `AuditLogger`, `AuditLog::ACTION_ABSENCE_APPROVE/REJECT`. CSRF token `worktime.absence`.
- Produces: routes `worktime_absences_admin` (GET `/admin/worktime/absences`), `worktime_absences_approve` (POST `/admin/worktime/absences/{id}/approve`), `worktime_absences_reject` (POST `/admin/worktime/absences/{id}/reject`); menu item `worktime_absences_admin`.

- [ ] **Step 1: AbsenceAdminController anlegen**

`var/plugins/WorktimeBundle/Controller/AbsenceAdminController.php`:
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
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/worktime/absences')]
#[IsGranted('worktime_manage')]
final class AbsenceAdminController extends AbstractController
{
    private function redirectIndex(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('worktime_absences_admin'));
    }

    #[Route(path: '', name: 'worktime_absences_admin', methods: ['GET'])]
    public function index(AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc): Response
    {
        $rows = [];
        foreach ($absences->findPending() as $a) {
            $contract = $contracts->findForUser($a->getUser());
            $rows[] = [
                'absence' => $a,
                'days' => $contract !== null ? $calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay()) : 0.0,
            ];
        }

        return $this->render('@Worktime/vacation/admin.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/{id}/approve', name: 'worktime_absences_approve', methods: ['POST'])]
    public function approve(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        return $this->decide($id, $request, $absences, $audit, Absence::STATUS_APPROVED);
    }

    #[Route(path: '/{id}/reject', name: 'worktime_absences_reject', methods: ['POST'])]
    public function reject(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        return $this->decide($id, $request, $absences, $audit, Absence::STATUS_REJECTED);
    }

    private function decide(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit, string $status): Response
    {
        if (!$this->isCsrfTokenValid('worktime.absence', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        $absence = $absences->find($id);
        if ($absence === null) {
            throw $this->createNotFoundException();
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $absence->setStatus($status);
        $absence->setDecidedBy($admin);
        $absence->setDecidedAt(new \DateTimeImmutable('now'));
        $absences->save($absence);

        $action = $status === Absence::STATUS_APPROVED ? AuditLog::ACTION_ABSENCE_APPROVE : AuditLog::ACTION_ABSENCE_REJECT;
        $audit->log($admin, $absence->getUser(), $action, 'absence', $absence->getId(), [
            'start' => $absence->getStartDate()->format('Y-m-d'),
            'end' => $absence->getEndDate()->format('Y-m-d'),
        ]);

        $this->addFlash('success', $status === Absence::STATUS_APPROVED ? 'Antrag genehmigt.' : 'Antrag abgelehnt.');

        return $this->redirectIndex();
    }
}
```

- [ ] **Step 2: Admin-View anlegen**

`var/plugins/WorktimeBundle/Resources/views/vacation/admin.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block page_title %}{{ 'Urlaubsanträge'|trans }}{% endblock %}

{% block main %}
    <div class="card">
        <div class="card-header"><h3 class="card-title">{{ 'Offene Urlaubsanträge'|trans }}</h3></div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr>
                    <th>{{ 'Mitarbeiter'|trans }}</th><th>{{ 'Von'|trans }}</th><th>{{ 'Bis'|trans }}</th>
                    <th>{{ 'Tage'|trans }}</th><th>{{ 'Notiz'|trans }}</th><th></th>
                </tr></thead>
                <tbody>
                {% for row in rows %}
                    {% set a = row.absence %}
                    <tr>
                        <td>{{ a.user.displayName }}</td>
                        <td>{{ a.startDate|date_short }}</td>
                        <td>{{ a.endDate|date_short }}{% if a.halfDay %} <span class="badge bg-azure-lt">{{ 'halber Tag'|trans }}</span>{% endif %}</td>
                        <td>{{ row.days|number_format(1, ',', '.') }}</td>
                        <td class="text-muted">{{ a.note }}</td>
                        <td class="text-end">
                            <form method="post" action="{{ path('worktime_absences_approve', {id: a.id}) }}" class="d-inline">
                                <input type="hidden" name="_token" value="{{ csrf_token('worktime.absence') }}">
                                <button type="submit" class="btn btn-sm btn-success">{{ 'Genehmigen'|trans }}</button>
                            </form>
                            <form method="post" action="{{ path('worktime_absences_reject', {id: a.id}) }}" class="d-inline">
                                <input type="hidden" name="_token" value="{{ csrf_token('worktime.absence') }}">
                                <button type="submit" class="btn btn-sm btn-outline-danger">{{ 'Ablehnen'|trans }}</button>
                            </form>
                        </td>
                    </tr>
                {% else %}
                    <tr><td colspan="6" class="text-muted">{{ 'Keine offenen Anträge.'|trans }}</td></tr>
                {% endfor %}
                </tbody>
            </table>
        </div>
    </div>
{% endblock %}
```

- [ ] **Step 3: Admin-Menüeintrag ergänzen**

In `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php`, inside `onMenuConfigure`, add a guarded admin entry (use the system menu, gated by `worktime_manage`):
```php
        if ($this->security->isGranted('worktime_manage')) {
            $event->getSystemMenu()->addChild(
                new MenuItemModel('worktime_absences_admin', 'Urlaubsanträge', 'worktime_absences_admin', [], 'fas fa-umbrella-beach')
            );
        }
```
(Place this after the `worktime_view_own` block. The existing constructor already injects `AuthorizationCheckerInterface $security`.)

- [ ] **Step 4: Routen, Cache, Lint, Analyse**

Run:
```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console debug:router | grep -E "worktime_absences"
sudo -u www-data php bin/console lint:twig var/plugins/WorktimeBundle/Resources/views
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: routes `worktime_absences_admin` (GET), `worktime_absences_approve` (POST), `worktime_absences_reject` (POST) present; lint valid; cs-fixer + phpstan clean.

- [ ] **Step 5: Manueller Smoke-Test (Mensch)**

Als Super-Admin `/admin/worktime/absences`: offene Anträge sichtbar mit berechneten Tagen; „Genehmigen" → Antrag verschwindet aus der Liste, beim MA steht er auf „genehmigt", Urlaubsrest sinkt; „Ablehnen" → Status „abgelehnt", Rest unverändert. Prod-Log ohne neue Fehler.

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Controller/AbsenceAdminController.php var/plugins/WorktimeBundle/Resources/views/vacation/admin.html.twig var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php
git commit -m "feat(worktime): admin vacation approval (approve/reject) with audit"
```

---

## Self-Review

**Spec coverage (Spec §2.4 Urlaub / §3 Kontostand / §5 Absence):**
- Urlaub beantragen (Zeitraum + halber Tag) → Task 3 (`request` + Modal). ✓
- Antrags-Workflow MA → Admin genehmigt/lehnt ab → Task 3 (MA) + Task 4 (Admin). ✓
- MA sieht eigenen Urlaubsrest + Anträge read-only → Task 3. ✓
- Admin sieht alle offenen Anträge → Task 4. ✓
- Halbtage → `Absence.halfDay` + `VacationCalculator` (Task 1/2), nur Einzeltag (validiert Task 3). ✓
- Urlaubstage = Arbeitstage laut Vertrag; Anspruch+Übertrag aus Contract → Task 2/3. ✓
- Audit aller Aktionen → Task 1 (Konstanten) + Task 3/4 (Logging). ✓
- framework-freie, getestete Logik (§11) → Task 2 (`VacationCalculator`, 7 Tests). ✓
- `down()` Schema-API; Views base.html.twig; phpstan L9 + cs-fixer → alle Tasks. ✓

**Bewusst nicht hier (getrackt):** Verrechnung genehmigten Urlaubs ins Soll/Ist-Konto (Konto-Plan: genehmigte `Absence` als Gutschrift); Feiertags-Ausschluss bei der Tageszählung (yasumi-Plan); Krankheit/Überstundenabbau (Typ-Spalte vorbereitet); Monats-Abwesenheitskalender; Stornieren/Zurückziehen eigener offener Anträge (späteres Refinement).

**Placeholder scan:** keine TBD/TODO; jeder Code-Step vollständig. ✓

**Type consistency:** `Absence`-Getter/Setter + Konstanten, `AbsenceRepository`-Methoden (`findForUser`, `findPending`, `findApprovedForUserInYear`, `save`, `find`), `VacationCalculator::countDays/remainingDays`, `AuditLog::ACTION_ABSENCE_*` über Tasks 1–4 konsistent. ✓
