# Worktime Plugin — Plan 5: Soll/Ist-Zeitkonto (Saldo, Korrekturen, Überlappungsschutz)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ein Soll/Ist-Zeitkonto, das Vertrag (Soll), Stempelzeiten (Ist) und genehmigten Urlaub verrechnet, Über-/Minusstunden als laufenden Saldo zeigt (Monats- und Jahresansicht), Admin-Korrekturbuchungen erlaubt und überlappende Stempelzeiten verhindert.

**Architecture:** Ein framework-freier `AccountBuilder` baut pro Tag aus Vertrag + gearbeiteten Sekunden + genehmigten Abwesenheiten ein `DayAccount` (Soll/Ist/Gutschrift/Saldo) — über den bestehenden `WorktimeCalculator`. Ein `AccountService` verdrahtet Repositories (gruppiert Blöcke je lokalem Tag, lädt Abwesenheiten + Korrekturen) und liefert Monats-/Jahresdaten inkl. kumuliertem Saldo. Ein `AccountController` zeigt das Konto (MA eigenes, Admin beliebiges via `?user=`). `BalanceCorrection` (Admin, ±Sekunden) fließt in den Saldo. `EntryController` verhindert ab jetzt überlappende Blöcke.

**Tech Stack:** PHP 8.2+, Symfony 6.4, Doctrine ORM (Attribute), PHPUnit, Twig.

## Global Constraints

- Bundle-Namespace `KimaiPlugin\WorktimeBundle`; Tabellen-Präfix `kimai2_worktime_`.
- Datums-Spalten `Types::DATE_IMMUTABLE`; Zeitstempel `Types::DATETIME_IMMUTABLE` (UTC). Tagesgruppierung von Blöcken in der **Nutzer-Zeitzone** (`User::getTimezone()`).
- Migration extends `App\Doctrine\AbstractMigration`; `down()` via `$schema->dropTable(...)`. FK-Index-Namen nötigenfalls an Doctrine-Hashes angleichen (drift-free; wie audit_log/absence in Plan 3/4).
- Plugin-Views extenden `'base.html.twig'`; jede PHP-Datei mit Lizenz-Header; Code besteht `./phpstan.sh Worktime` (Level 9) + `./php-cs-fixer.sh Worktime`.
- Soll/Ist-Logik baut auf bestehenden, getesteten Bausteinen auf: `WorktimeCalculator` (`targetSeconds`, `dailyBalanceSeconds`, `accumulatedBalanceSeconds`), `DayFacts`, `WorkBlockMath::netSeconds`. **Neue Fachlogik framework-frei + unit-getestet.**
- **Feiertage werden noch NICHT berücksichtigt** (kein yasumi): `DayFacts.publicHoliday=false`; an Feiertagen ist das Soll vorerst die Vertragsstunden — in der Ansicht als bekannte Einschränkung kennzeichnen. (Folgeplan korrigiert das.)
- Abwesenheits-Gutschrift: genehmigter Urlaub an einem Arbeitstag schreibt das Tagessoll gut (halber Tag = halbes Soll), macht den Tag also soll-neutral.
- Korrekturen + Genehmigungen + Überlappungs-Ablehnungen: keine stillen Datenfehler; Korrekturen werden auditiert. Admin-Konto-Einsicht/Korrektur erfordert `worktime_manage`; eigenes Konto `worktime_view_own`.
- Prod: Plugin gehört `www-data`; nach Änderungen `sudo -u www-data php bin/console cache:clear`.

## Scope / Abgrenzung
Drin: Überlappungsschutz für Stempelblöcke; `BalanceCorrection` (Überstunden-Konto, ±) + Admin-Formular; `AccountBuilder`/`AccountService`; Konto-Ansicht Monat (Tagesliste) + Jahr + laufender Saldo (MA eigenes / Admin beliebiges). **Nicht hier (getrackt):** Feiertage (yasumi); Krankheit/Überstundenabbau als Abwesenheitstypen (Überstundenabbau würde später `overtimeReductionSeconds` füllen); Monatsabschluss/Sperre + Snapshot + PDF (eigener Plan); Urlaubs-Korrekturen (nur Überstunden-Korrekturen hier).

---

## File Structure
```
var/plugins/WorktimeBundle/
├── Entity/AuditLog.php                            # ERWEITERT (Task 2: ACTION_BALANCE_CORRECTION)
├── Entity/BalanceCorrection.php                   # NEU (Task 2)
├── Repository/BalanceCorrectionRepository.php     # NEU (Task 2)
├── Repository/WorkBlockRepository.php             # ERWEITERT (Task 1: findOverlapping)
├── Repository/AbsenceRepository.php               # ERWEITERT (Task 3: findApprovedForUserInRange)
├── Migrations/Version20260623130000.php           # NEU (Task 2)
├── Calculator/IntervalMath.php                    # NEU (Task 1) framework-frei
├── Calculator/AccountBuilder.php                  # NEU (Task 3) framework-frei
├── Model/DayAccount.php                           # NEU (Task 3) value object
├── Account/AccountService.php                     # NEU (Task 4) wires repos
├── Controller/AccountController.php               # NEU (Task 4: month) + (Task 5: year + correction)
├── Controller/EntryController.php                 # ERWEITERT (Task 1: overlap reject)
├── EventSubscriber/MenuSubscriber.php             # ERWEITERT (Task 4: "Zeitkonto" menu)
├── Resources/views/account/month.html.twig        # NEU (Task 4)
├── Resources/views/account/year.html.twig         # NEU (Task 5)
└── Tests/Calculator/
    ├── IntervalMathTest.php                        # NEU (Task 1)
    └── AccountBuilderTest.php                      # NEU (Task 3)
```

---

### Task 1: Überlappungsschutz für Stempelblöcke

**Files:**
- Create: `var/plugins/WorktimeBundle/Calculator/IntervalMath.php`
- Create: `var/plugins/WorktimeBundle/Tests/Calculator/IntervalMathTest.php`
- Modify: `var/plugins/WorktimeBundle/Repository/WorkBlockRepository.php` (add `findOverlapping`)
- Modify: `var/plugins/WorktimeBundle/Controller/EntryController.php` (reject overlaps in create + edit)

**Interfaces:**
- Consumes: `WorkBlock`, `App\Entity\User`.
- Produces:
  - `IntervalMath::overlaps(\DateTimeInterface $aStart, ?\DateTimeInterface $aEnd, \DateTimeInterface $bStart, ?\DateTimeInterface $bEnd): bool` (null end = open-ended/far future).
  - `WorkBlockRepository::findOverlapping(User $user, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId): array` (WorkBlock[]).

- [ ] **Step 1: Failing test für IntervalMath**

`var/plugins/WorktimeBundle/Tests/Calculator/IntervalMathTest.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use KimaiPlugin\WorktimeBundle\Calculator\IntervalMath;
use PHPUnit\Framework\TestCase;

class IntervalMathTest extends TestCase
{
    private function d(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testDisjointDoNotOverlap(): void
    {
        $m = new IntervalMath();
        self::assertFalse($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 13:00')));
    }

    public function testOverlapping(): void
    {
        $m = new IntervalMath();
        self::assertTrue($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 11:00'), $this->d('2026-06-01 13:00')));
    }

    public function testTouchingEdgesDoNotOverlap(): void
    {
        // [08-12) and [12-13): half-open, touching is allowed
        $m = new IntervalMath();
        self::assertFalse($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 12:00'), $this->d('2026-06-01 13:00')));
    }

    public function testContained(): void
    {
        $m = new IntervalMath();
        self::assertTrue($m->overlaps($this->d('2026-06-01 08:00'), $this->d('2026-06-01 16:00'), $this->d('2026-06-01 10:00'), $this->d('2026-06-01 11:00')));
    }

    public function testOpenEndedOverlapsLater(): void
    {
        // open block from 08:00 overlaps a block starting 10:00
        $m = new IntervalMath();
        self::assertTrue($m->overlaps($this->d('2026-06-01 08:00'), null, $this->d('2026-06-01 10:00'), $this->d('2026-06-01 11:00')));
    }
}
```

- [ ] **Step 2: Test ausführen, Fehlschlag bestätigen**

```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter IntervalMathTest
```
Expected: FAIL — class `IntervalMath` not found.

- [ ] **Step 3: IntervalMath implementieren**

`var/plugins/WorktimeBundle/Calculator/IntervalMath.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

final class IntervalMath
{
    /**
     * Half-open interval overlap: [aStart, aEnd) vs [bStart, bEnd).
     * A null end means open-ended (treated as +infinity), so touching edges
     * do NOT count as overlap.
     */
    public function overlaps(\DateTimeInterface $aStart, ?\DateTimeInterface $aEnd, \DateTimeInterface $bStart, ?\DateTimeInterface $bEnd): bool
    {
        $aStartTs = $aStart->getTimestamp();
        $bStartTs = $bStart->getTimestamp();
        $aEndTs = $aEnd?->getTimestamp() ?? PHP_INT_MAX;
        $bEndTs = $bEnd?->getTimestamp() ?? PHP_INT_MAX;

        return $aStartTs < $bEndTs && $bStartTs < $aEndTs;
    }
}
```

- [ ] **Step 4: Test ausführen, Erfolg bestätigen**

```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter IntervalMathTest
```
Expected: PASS — 5 Tests grün.

- [ ] **Step 5: Repository-Methode `findOverlapping` ergänzen**

In `var/plugins/WorktimeBundle/Repository/WorkBlockRepository.php`, add this method (before `save`):
```php
    /**
     * Blocks of the user whose [start, end) intersects [start, end), optionally
     * excluding one block id. Open blocks (end IS NULL) are treated as open-ended.
     *
     * @return WorkBlock[]
     */
    public function findOverlapping(User $user, \DateTimeInterface $start, \DateTimeInterface $end, ?int $excludeId): array
    {
        $qb = $this->createQueryBuilder('b')
            ->andWhere('b.user = :user')
            ->andWhere('b.start < :end')
            ->andWhere('b.end IS NULL OR b.end > :start')
            ->setParameter('user', $user)
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($excludeId !== null) {
            $qb->andWhere('b.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return $qb->getQuery()->getResult();
    }
```

- [ ] **Step 6: Überlappung in EntryController ablehnen**

In `var/plugins/WorktimeBundle/Controller/EntryController.php`:

(a) In `create()`, AFTER the `validateInterval` check and BEFORE `new WorkBlock()`:
```php
        if ($blocks->findOverlapping($user, $start, $end, null) !== []) {
            $this->addFlash('error', 'Der Zeitraum überschneidet sich mit einer bestehenden Buchung.');

            return $this->redirectIndex();
        }
```

(b) In `edit()`, AFTER the `validateInterval` check and the open-block guard, and BEFORE capturing `$old` / `setStart`, add (only when an end is set — an open block is checked by the existing single-open guard):
```php
        if ($end !== null && $blocks->findOverlapping($user, $start, $end, $block->getId()) !== []) {
            $this->addFlash('error', 'Der Zeitraum überschneidet sich mit einer bestehenden Buchung.');

            return $this->redirectIndex();
        }
```

> Note: `IntervalMath` is the unit-tested specification of the rule; the SQL in `findOverlapping` mirrors it (`b.start < :end AND (b.end IS NULL OR b.end > :start)`). The controller uses the repository (DB-level) for the actual check.

- [ ] **Step 7: Tests, Routen, Analyse**

```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
( cd var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist 2>&1 | tail -3 )
sudo -u www-data php bin/console cache:clear
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: full suite green (incl. 5 new IntervalMath tests); cs-fixer + phpstan clean.

- [ ] **Step 8: Commit**

```bash
git add var/plugins/WorktimeBundle/Calculator/IntervalMath.php var/plugins/WorktimeBundle/Tests/Calculator/IntervalMathTest.php var/plugins/WorktimeBundle/Repository/WorkBlockRepository.php var/plugins/WorktimeBundle/Controller/EntryController.php
git commit -m "feat(worktime): reject overlapping work blocks (interval math + repo + controller)"
```

---

### Task 2: BalanceCorrection (Entity, Repository, Migration, Audit-Konstante)

**Files:**
- Modify: `var/plugins/WorktimeBundle/Entity/AuditLog.php` (add `ACTION_BALANCE_CORRECTION`)
- Create: `var/plugins/WorktimeBundle/Entity/BalanceCorrection.php`
- Create: `var/plugins/WorktimeBundle/Repository/BalanceCorrectionRepository.php`
- Create: `var/plugins/WorktimeBundle/Migrations/Version20260623130000.php`

**Interfaces:**
- Produces:
  - `AuditLog::ACTION_BALANCE_CORRECTION = 'balance_correction'`.
  - `BalanceCorrection` constants `ACCOUNT_OVERTIME='overtime'`; ctor `__construct(\DateTimeImmutable $createdAt)`; getters/setters: `getId(): ?int`, `getUser/setUser(User)`, `getDate/setDate(\DateTimeImmutable)`, `getAccount/setAccount(string)`, `getSeconds/setSeconds(int)`, `getReason/setReason(?string)`, `getCreatedBy/setCreatedBy(?User)`, `getCreatedAt(): \DateTimeImmutable`.
  - `BalanceCorrectionRepository`: `findOvertimeForUserUpTo(User $user, \DateTimeInterface $date): array`, `findForUser(User $user): array`, `save(BalanceCorrection): void`.
  - Table `kimai2_worktime_balance_correction`.

- [ ] **Step 1: AuditLog-Konstante ergänzen**

In `var/plugins/WorktimeBundle/Entity/AuditLog.php`, after `ACTION_ABSENCE_REJECT`, add:
```php
    public const ACTION_BALANCE_CORRECTION = 'balance_correction';
```

- [ ] **Step 2: BalanceCorrection-Entity anlegen**

`var/plugins/WorktimeBundle/Entity/BalanceCorrection.php`:
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
use KimaiPlugin\WorktimeBundle\Repository\BalanceCorrectionRepository;

#[ORM\Entity(repositoryClass: BalanceCorrectionRepository::class)]
#[ORM\Table(name: 'kimai2_worktime_balance_correction')]
#[ORM\Index(columns: ['user_id', 'date'], name: 'IDX_worktime_correction_user_date')]
class BalanceCorrection
{
    public const ACCOUNT_OVERTIME = 'overtime';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(name: 'id', type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'user_id', nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(name: 'date', type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $date;

    #[ORM\Column(name: 'account', type: Types::STRING, length: 20)]
    private string $account = self::ACCOUNT_OVERTIME;

    #[ORM\Column(name: 'seconds', type: Types::INTEGER)]
    private int $seconds = 0;

    #[ORM\Column(name: 'reason', type: Types::TEXT, nullable: true)]
    private ?string $reason = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'created_by', nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

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

    public function getDate(): \DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(\DateTimeImmutable $date): void
    {
        $this->date = $date;
    }

    public function getAccount(): string
    {
        return $this->account;
    }

    public function setAccount(string $account): void
    {
        $this->account = $account;
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }

    public function setSeconds(int $seconds): void
    {
        $this->seconds = $seconds;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function setReason(?string $reason): void
    {
        $this->reason = $reason;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): void
    {
        $this->createdBy = $createdBy;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
```

- [ ] **Step 3: Repository anlegen**

`var/plugins/WorktimeBundle/Repository/BalanceCorrectionRepository.php`:
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
use KimaiPlugin\WorktimeBundle\Entity\BalanceCorrection;

/**
 * @extends ServiceEntityRepository<BalanceCorrection>
 */
class BalanceCorrectionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BalanceCorrection::class);
    }

    /**
     * @return BalanceCorrection[]
     */
    public function findOvertimeForUserUpTo(User $user, \DateTimeInterface $date): array
    {
        return $this->createQueryBuilder('c')
            ->andWhere('c.user = :user')
            ->andWhere('c.account = :account')
            ->andWhere('c.date <= :date')
            ->setParameter('user', $user)
            ->setParameter('account', BalanceCorrection::ACCOUNT_OVERTIME)
            ->setParameter('date', $date)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return BalanceCorrection[]
     */
    public function findForUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['date' => 'DESC']);
    }

    public function save(BalanceCorrection $correction): void
    {
        $em = $this->getEntityManager();
        $em->persist($correction);
        $em->flush();
    }
}
```

- [ ] **Step 4: Migration anlegen**

`var/plugins/WorktimeBundle/Migrations/Version20260623130000.php`:
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

final class Version20260623130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Worktime: create balance_correction table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE kimai2_worktime_balance_correction (
            id INT AUTO_INCREMENT NOT NULL,
            user_id INT NOT NULL,
            created_by INT DEFAULT NULL,
            date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\',
            account VARCHAR(20) NOT NULL,
            seconds INT NOT NULL,
            reason LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            INDEX IDX_worktime_correction_user_date (user_id, date),
            INDEX IDX_worktime_correction_created_by (created_by),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE kimai2_worktime_balance_correction
            ADD CONSTRAINT FK_worktime_correction_user FOREIGN KEY (user_id)
            REFERENCES kimai2_users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE kimai2_worktime_balance_correction
            ADD CONSTRAINT FK_worktime_correction_created_by FOREIGN KEY (created_by)
            REFERENCES kimai2_users (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('kimai2_worktime_balance_correction');
    }
}
```

- [ ] **Step 5: Migrieren, validieren, Rollback, Analyse**

```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console doctrine:migrations:migrate --no-interaction
sudo -u www-data php bin/console doctrine:schema:validate
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623130000' --down --no-interaction
sudo -u www-data php bin/console doctrine:migrations:execute 'KimaiPlugin\WorktimeBundle\Migrations\Version20260623130000' --up --no-interaction
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: migrate OK; no drift for `kimai2_worktime_balance_correction` (`doctrine:schema:update --dump-sql | grep balance_correction` empty — align FK index name to Doctrine hash if needed, as in Plan 4 Task 1); down+up OK; cs-fixer + phpstan clean.

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Entity/AuditLog.php var/plugins/WorktimeBundle/Entity/BalanceCorrection.php var/plugins/WorktimeBundle/Repository/BalanceCorrectionRepository.php var/plugins/WorktimeBundle/Migrations/Version20260623130000.php
git commit -m "feat(worktime): balance correction entity, repository, migration"
```

---

### Task 3: AccountBuilder + DayAccount (framework-frei, TDD) + AbsenceRepository-Range

**Files:**
- Create: `var/plugins/WorktimeBundle/Model/DayAccount.php`
- Create: `var/plugins/WorktimeBundle/Calculator/AccountBuilder.php`
- Create: `var/plugins/WorktimeBundle/Tests/Calculator/AccountBuilderTest.php`
- Modify: `var/plugins/WorktimeBundle/Repository/AbsenceRepository.php` (add `findApprovedForUserInRange`)

**Interfaces:**
- Consumes: `Contract`, `WorktimeCalculator`, `DayFacts`, `Absence` (`getStartDate`, `getEndDate`, `isHalfDay`).
- Produces:
  - `DayAccount` (readonly): `__construct(\DateTimeImmutable $date, int $targetSeconds, int $workedSeconds, int $creditSeconds, int $balanceSeconds, ?string $note)` with public readonly props of the same names.
  - `AccountBuilder::__construct(WorktimeCalculator $calc)`; `dayAccount(Contract $contract, \DateTimeImmutable $date, int $workedSeconds, array $approvedAbsences): DayAccount` — builds one day's account; `$approvedAbsences` is a list of `Absence` (only those overlapping the date matter; the method checks coverage itself).
  - `AbsenceRepository::findApprovedForUserInRange(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array`.

- [ ] **Step 1: DayAccount-Wertobjekt anlegen**

`var/plugins/WorktimeBundle/Model/DayAccount.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Model;

final class DayAccount
{
    public function __construct(
        public readonly \DateTimeImmutable $date,
        public readonly int $targetSeconds,
        public readonly int $workedSeconds,
        public readonly int $creditSeconds,
        public readonly int $balanceSeconds,
        public readonly ?string $note,
    ) {
    }
}
```

- [ ] **Step 2: Failing test für AccountBuilder**

`var/plugins/WorktimeBundle/Tests/Calculator/AccountBuilderTest.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Calculator;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use PHPUnit\Framework\TestCase;

class AccountBuilderTest extends TestCase
{
    private const H8 = 28800;

    private function contract(): Contract
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

    private function builder(): AccountBuilder
    {
        return new AccountBuilder(new WorktimeCalculator());
    }

    private function vacation(string $from, string $to, bool $half): Absence
    {
        $a = new Absence(new \DateTimeImmutable('2026-01-01 00:00:00'));
        $a->setUser(new User());
        $a->setStartDate(new \DateTimeImmutable($from));
        $a->setEndDate(new \DateTimeImmutable($to));
        $a->setHalfDay($half);

        return $a;
    }

    public function testWorkdayExactlyMet(): void
    {
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), self::H8, []);
        self::assertSame(self::H8, $d->targetSeconds);
        self::assertSame(self::H8, $d->workedSeconds);
        self::assertSame(0, $d->creditSeconds);
        self::assertSame(0, $d->balanceSeconds);
        self::assertNull($d->note);
    }

    public function testOvertime(): void
    {
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), 32400, []);
        self::assertSame(3600, $d->balanceSeconds);
    }

    public function testWeekendNoTargetNoWork(): void
    {
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-06'), 0, []);
        self::assertSame(0, $d->targetSeconds);
        self::assertSame(0, $d->balanceSeconds);
    }

    public function testFullVacationIsNeutral(): void
    {
        $abs = [$this->vacation('2026-06-01', '2026-06-05', false)];
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), 0, $abs);
        self::assertSame(self::H8, $d->creditSeconds);
        self::assertSame(0, $d->balanceSeconds);
        self::assertSame('Urlaub', $d->note);
    }

    public function testHalfVacationCreditsHalfTarget(): void
    {
        $abs = [$this->vacation('2026-06-01', '2026-06-01', true)];
        // worked the other half (4h) -> balance 0
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), 14400, $abs);
        self::assertSame(14400, $d->creditSeconds);
        self::assertSame(0, $d->balanceSeconds);
        self::assertSame('½ Urlaub', $d->note);
    }

    public function testAbsenceOutsideRangeIgnored(): void
    {
        $abs = [$this->vacation('2026-07-01', '2026-07-05', false)];
        $d = $this->builder()->dayAccount($this->contract(), new \DateTimeImmutable('2026-06-01'), self::H8, $abs);
        self::assertSame(0, $d->creditSeconds);
        self::assertNull($d->note);
    }
}
```

- [ ] **Step 3: Test ausführen, Fehlschlag bestätigen**

```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter AccountBuilderTest
```
Expected: FAIL — class `AccountBuilder` not found.

- [ ] **Step 4: AccountBuilder implementieren**

`var/plugins/WorktimeBundle/Calculator/AccountBuilder.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Calculator;

use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Model\DayAccount;
use KimaiPlugin\WorktimeBundle\Model\DayFacts;

final class AccountBuilder
{
    public function __construct(private readonly WorktimeCalculator $calculator)
    {
    }

    /**
     * @param Absence[] $approvedAbsences
     */
    public function dayAccount(Contract $contract, \DateTimeImmutable $date, int $workedSeconds, array $approvedAbsences): DayAccount
    {
        $day = $date->setTime(0, 0);
        $target = $this->calculator->targetSeconds($contract, new DayFacts($day));

        $credit = 0;
        $note = null;
        foreach ($approvedAbsences as $absence) {
            if (!$this->covers($absence, $day)) {
                continue;
            }
            if ($absence->isHalfDay()) {
                $credit = (int) round($target / 2);
                $note = '½ Urlaub';
            } else {
                $credit = $target;
                $note = 'Urlaub';
            }
            break;
        }

        $facts = new DayFacts($day, $workedSeconds, false, $credit, 0);
        $balance = $this->calculator->dailyBalanceSeconds($contract, $facts);

        return new DayAccount($day, $target, $workedSeconds, $credit, $balance, $note);
    }

    private function covers(Absence $absence, \DateTimeImmutable $day): bool
    {
        $d = $day->format('Y-m-d');

        return $d >= $absence->getStartDate()->format('Y-m-d')
            && $d <= $absence->getEndDate()->format('Y-m-d');
    }
}
```

- [ ] **Step 5: Test ausführen, Erfolg bestätigen**

```bash
cd /opt/kimai/var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist --filter AccountBuilderTest
```
Expected: PASS — 6 Tests grün.

- [ ] **Step 6: AbsenceRepository um Range-Abfrage ergänzen**

In `var/plugins/WorktimeBundle/Repository/AbsenceRepository.php`, add (after `findApprovedForUserInYear`):
```php
    /**
     * Approved absences for the user that intersect [from, to] (by date).
     *
     * @return Absence[]
     */
    public function findApprovedForUserInRange(User $user, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        return $this->createQueryBuilder('a')
            ->andWhere('a.user = :user')
            ->andWhere('a.status = :status')
            ->andWhere('a.startDate <= :to')
            ->andWhere('a.endDate >= :from')
            ->setParameter('user', $user)
            ->setParameter('status', Absence::STATUS_APPROVED)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('a.startDate', 'ASC')
            ->getQuery()
            ->getResult();
    }
```

- [ ] **Step 7: Analyse & Commit**

```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
( cd var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist 2>&1 | tail -3 )
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
git add var/plugins/WorktimeBundle/Model/DayAccount.php var/plugins/WorktimeBundle/Calculator/AccountBuilder.php var/plugins/WorktimeBundle/Tests/Calculator/AccountBuilderTest.php var/plugins/WorktimeBundle/Repository/AbsenceRepository.php
git commit -m "feat(worktime): account builder (day soll/ist/credit/balance) with unit tests"
```
Expected: full suite green; cs-fixer + phpstan clean.

---

### Task 4: AccountService + Monatsansicht + Menü

**Files:**
- Create: `var/plugins/WorktimeBundle/Account/AccountService.php`
- Create: `var/plugins/WorktimeBundle/Controller/AccountController.php`
- Create: `var/plugins/WorktimeBundle/Resources/views/account/month.html.twig`
- Modify: `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php` (add "Zeitkonto" entry)

**Interfaces:**
- Consumes: `AccountBuilder`, `WorkBlockMath`, `ContractRepository::findForUser`, `WorkBlockRepository::findForUserBetween`, `AbsenceRepository::findApprovedForUserInRange`, `BalanceCorrectionRepository::findOvertimeForUserUpTo`, `App\Repository\UserRepository`, `App\Entity\User` (`getTimezone`), `Contract::getInitialOvertimeSeconds/getEmploymentStart`.
- Produces:
  - `AccountService::monthAccount(User $user, int $year, int $month): array` returning `['days' => DayAccount[], 'targetSeconds' => int, 'workedSeconds' => int, 'balanceSeconds' => int (month delta), 'cumulativeSeconds' => int (overtime balance up to month end)]`.
  - Route `worktime_account` (GET `/worktime/account`), menu item `worktime_account`.

- [ ] **Step 1: AccountService anlegen**

`var/plugins/WorktimeBundle/Account/AccountService.php`:
```php
<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Account;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Model\DayAccount;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\BalanceCorrectionRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;

class AccountService
{
    public function __construct(
        private readonly AccountBuilder $builder,
        private readonly WorkBlockMath $blockMath,
        private readonly ContractRepository $contracts,
        private readonly WorkBlockRepository $blocks,
        private readonly AbsenceRepository $absences,
        private readonly BalanceCorrectionRepository $corrections,
    ) {
    }

    /**
     * @return array{days: DayAccount[], targetSeconds: int, workedSeconds: int, balanceSeconds: int, cumulativeSeconds: int, has_contract: bool}
     */
    public function monthAccount(User $user, int $year, int $month): array
    {
        $contract = $this->contracts->findForUser($user);
        $tz = new \DateTimeZone($user->getTimezone());
        $monthStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $monthEnd = $monthStart->modify('last day of this month')->setTime(23, 59, 59);

        if ($contract === null) {
            return ['days' => [], 'targetSeconds' => 0, 'workedSeconds' => 0, 'balanceSeconds' => 0, 'cumulativeSeconds' => 0, 'has_contract' => false];
        }

        $days = $this->buildDays($user, $contract, $monthStart, $monthStart->modify('last day of this month')->setTime(0, 0), $tz);

        $target = 0;
        $worked = 0;
        $balance = 0;
        foreach ($days as $d) {
            $target += $d->targetSeconds;
            $worked += $d->workedSeconds;
            $balance += $d->balanceSeconds;
        }

        return [
            'days' => $days,
            'targetSeconds' => $target,
            'workedSeconds' => $worked,
            'balanceSeconds' => $balance,
            'cumulativeSeconds' => $this->cumulativeBalance($user, $contract, $monthEnd, $tz),
            'has_contract' => true,
        ];
    }

    /**
     * Build a DayAccount for every calendar day in [from, to] (both at 00:00 local).
     *
     * @return DayAccount[]
     */
    private function buildDays(User $user, \KimaiPlugin\WorktimeBundle\Entity\Contract $contract, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeZone $tz): array
    {
        $rangeStart = $from->setTime(0, 0);
        $rangeEnd = $to->setTime(23, 59, 59);
        $blocks = $this->blocks->findForUserBetween($user, $rangeStart, $rangeEnd->modify('+1 second'));
        $absences = $this->absences->findApprovedForUserInRange($user, $rangeStart, $rangeEnd);

        // group worked seconds per local day
        $workedByDay = [];
        foreach ($blocks as $block) {
            $key = $block->getStart()->setTimezone($tz)->format('Y-m-d');
            $workedByDay[$key] = ($workedByDay[$key] ?? 0) + ($block->getDurationSeconds() ?? 0);
        }

        $days = [];
        $cursor = $rangeStart;
        $last = $to->setTime(0, 0);
        while ($cursor <= $last) {
            $key = $cursor->format('Y-m-d');
            $days[] = $this->builder->dayAccount($contract, $cursor, $workedByDay[$key] ?? 0, $absences);
            $cursor = $cursor->modify('+1 day');
        }

        return $days;
    }

    private function cumulativeBalance(User $user, \KimaiPlugin\WorktimeBundle\Entity\Contract $contract, \DateTimeImmutable $upTo, \DateTimeZone $tz): int
    {
        $start = $contract->getEmploymentStart();
        if ($start === null) {
            // fall back to the first day of the displayed month's year
            $start = $upTo->setDate((int) $upTo->format('Y'), 1, 1);
        }
        $start = $start->setTimezone($tz)->setTime(0, 0);
        $end = $upTo->setTimezone($tz)->setTime(0, 0);
        if ($end < $start) {
            return $contract->getInitialOvertimeSeconds();
        }

        $days = $this->buildDays($user, $contract, $start, $end, $tz);
        $sum = $contract->getInitialOvertimeSeconds();
        foreach ($days as $d) {
            $sum += $d->balanceSeconds;
        }
        foreach ($this->corrections->findOvertimeForUserUpTo($user, $upTo) as $correction) {
            $sum += $correction->getSeconds();
        }

        return $sum;
    }
}
```

- [ ] **Step 2: AccountController (Monat) anlegen**

`var/plugins/WorktimeBundle/Controller/AccountController.php`:
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
use KimaiPlugin\WorktimeBundle\Account\AccountService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime/account')]
#[IsGranted('worktime_view_own')]
final class AccountController extends AbstractController
{
    /**
     * Resolve the user being viewed: a `user` query param requires worktime_manage;
     * otherwise the current user.
     */
    private function resolveUser(Request $request, UserRepository $users): User
    {
        $current = $this->getUser();
        \assert($current instanceof User);

        $requested = $request->query->get('user');
        if ($requested !== null && (int) $requested !== $current->getId()) {
            if (!$this->isGranted('worktime_manage')) {
                throw $this->createAccessDeniedException();
            }
            $user = $users->find((int) $requested);
            if ($user === null) {
                throw $this->createNotFoundException();
            }

            return $user;
        }

        return $current;
    }

    #[Route(path: '', name: 'worktime_account', methods: ['GET'])]
    public function month(Request $request, AccountService $accounts, UserRepository $users): Response
    {
        $user = $this->resolveUser($request, $users);
        $now = new \DateTimeImmutable('now');
        $year = (int) $request->query->get('year', $now->format('Y'));
        $month = (int) $request->query->get('month', $now->format('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) $now->format('n');
        }

        $data = $accounts->monthAccount($user, $year, $month);
        $current = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));

        return $this->render('@Worktime/account/month.html.twig', [
            'viewed_user' => $user,
            'year' => $year,
            'month' => $month,
            'month_label' => $current,
            'prev' => $current->modify('-1 month'),
            'next' => $current->modify('+1 month'),
            'data' => $data,
        ]);
    }
}
```

- [ ] **Step 3: Monats-View anlegen**

`var/plugins/WorktimeBundle/Resources/views/account/month.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block page_title %}{{ 'Zeitkonto'|trans }} — {{ viewed_user.displayName }}{% endblock %}

{% macro hm(seconds) %}{% apply spaceless %}
    {% set s = seconds < 0 ? -seconds : seconds %}
    {{ seconds < 0 ? '−' : '' }}{{ (s // 3600) }}:{{ '%02d'|format((s % 3600) // 60) }}
{% endapply %}{% endmacro %}

{% block main %}
    {% import _self as fmt %}
    {% if not data.has_contract %}
        <div class="alert alert-warning">{{ 'Für diesen Nutzer ist kein Vertrag hinterlegt — kein Soll berechenbar.'|trans }}</div>
    {% endif %}
    <div class="alert alert-info">{{ 'Hinweis: Feiertage werden noch nicht berücksichtigt; an Feiertagen entspricht das Soll den Vertragsstunden.'|trans }}</div>

    <div class="row row-cards mb-3">
        <div class="col-sm-3"><div class="card"><div class="card-body">
            <div class="text-muted small text-uppercase">{{ 'Saldo gesamt'|trans }}</div>
            <div class="h1 mb-0 {{ data.cumulativeSeconds < 0 ? 'text-danger' : 'text-success' }}">{{ fmt.hm(data.cumulativeSeconds) }} h</div>
        </div></div></div>
        <div class="col-sm-3"><div class="card"><div class="card-body">
            <div class="text-muted small text-uppercase">{{ 'Saldo Monat'|trans }}</div>
            <div class="h2 mb-0">{{ fmt.hm(data.balanceSeconds) }} h</div>
        </div></div></div>
        <div class="col-sm-3"><div class="card"><div class="card-body">
            <div class="text-muted small text-uppercase">{{ 'Ist Monat'|trans }}</div>
            <div class="h2 mb-0">{{ fmt.hm(data.workedSeconds) }} h</div>
        </div></div></div>
        <div class="col-sm-3"><div class="card"><div class="card-body">
            <div class="text-muted small text-uppercase">{{ 'Soll Monat'|trans }}</div>
            <div class="h2 mb-0">{{ fmt.hm(data.targetSeconds) }} h</div>
        </div></div></div>
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">{{ month_label|month_name }} {{ year }}</h3>
            <div class="btn-list">
                <a class="btn btn-sm" href="{{ path('worktime_account', {year: prev|date('Y'), month: prev|date('n'), user: viewed_user.id}) }}">&larr; {{ 'Vormonat'|trans }}</a>
                <a class="btn btn-sm" href="{{ path('worktime_account', {year: next|date('Y'), month: next|date('n'), user: viewed_user.id}) }}">{{ 'Folgemonat'|trans }} &rarr;</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr>
                    <th>{{ 'Tag'|trans }}</th><th class="text-end">{{ 'Soll'|trans }}</th><th class="text-end">{{ 'Ist'|trans }}</th>
                    <th class="text-end">{{ 'Saldo'|trans }}</th><th>{{ 'Abwesenheit'|trans }}</th>
                </tr></thead>
                <tbody>
                {% for d in data.days %}
                    {% set weekend = d.targetSeconds == 0 and d.workedSeconds == 0 and d.note is null %}
                    <tr class="{{ weekend ? 'text-muted' : '' }}">
                        <td>{{ d.date|date_weekday }}</td>
                        <td class="text-end">{{ fmt.hm(d.targetSeconds) }}</td>
                        <td class="text-end">{{ fmt.hm(d.workedSeconds) }}</td>
                        <td class="text-end {{ d.balanceSeconds < 0 ? 'text-danger' : (d.balanceSeconds > 0 ? 'text-success' : '') }}">{{ fmt.hm(d.balanceSeconds) }}</td>
                        <td>{% if d.note %}<span class="badge bg-azure-lt">{{ d.note }}</span>{% endif %}</td>
                    </tr>
                {% endfor %}
                </tbody>
            </table>
        </div>
    </div>
{% endblock %}
```

> `date_weekday` is a Kimai Twig filter (formats a date with weekday). If it is not available, fall back to `d.date|date_short` — verify with `grep date_weekday src/Twig/LocaleFormatExtensions.php`.

- [ ] **Step 4: Menüeintrag „Zeitkonto" ergänzen**

In `var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php`, inside the existing `worktime_view_own` block (after the `worktime_vacation` entry), add:
```php
        $event->getMenu()->addChild(
            new MenuItemModel('worktime_account', 'Zeitkonto', 'worktime_account', [], 'fas fa-scale-balanced')
        );
```

- [ ] **Step 5: Routen, Cache, Lint, Analyse**

```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console debug:router | grep -E "worktime_account"
sudo -u www-data php bin/console lint:twig var/plugins/WorktimeBundle/Resources/views
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: route `worktime_account` (GET) present; lint valid; cs-fixer + phpstan clean.

- [ ] **Step 6: Manueller Smoke-Test (Mensch)**

`/worktime/account`: Karten (Saldo gesamt / Monat / Ist / Soll) + Tagesliste des aktuellen Monats; Vor-/Folgemonat-Navigation; genehmigter Urlaub erscheint als „Urlaub"/„½ Urlaub" und macht den Tag saldo-neutral; Überstunden positiv (grün), Minus rot. Als Admin `?user=<id>` zeigt fremdes Konto; als MA für fremde Id → 403.

- [ ] **Step 7: Commit**

```bash
git add var/plugins/WorktimeBundle/Account var/plugins/WorktimeBundle/Controller/AccountController.php var/plugins/WorktimeBundle/Resources/views/account/month.html.twig var/plugins/WorktimeBundle/EventSubscriber/MenuSubscriber.php
git commit -m "feat(worktime): time account month view (soll/ist/saldo) + service + menu"
```

---

### Task 5: Jahresübersicht + Admin-Korrekturbuchung

**Files:**
- Modify: `var/plugins/WorktimeBundle/Account/AccountService.php` (add `yearAccount`)
- Modify: `var/plugins/WorktimeBundle/Controller/AccountController.php` (add `year` + `correct` actions)
- Create: `var/plugins/WorktimeBundle/Resources/views/account/year.html.twig`

**Interfaces:**
- Consumes: everything from Task 4 + `BalanceCorrectionRepository::save`, `AuditLogger`, `AuditLog::ACTION_BALANCE_CORRECTION`.
- Produces: `AccountService::yearAccount(User, int $year): array{months: array<int, array{targetSeconds:int, workedSeconds:int, balanceSeconds:int}>, cumulativeSeconds: int}`; routes `worktime_account_year` (GET `/worktime/account/year`), `worktime_account_correct` (POST `/worktime/account/correct`).

- [ ] **Step 1: `yearAccount` im Service ergänzen**

In `var/plugins/WorktimeBundle/Account/AccountService.php`, add:
```php
    /**
     * @return array{months: array<int, array{targetSeconds: int, workedSeconds: int, balanceSeconds: int}>, cumulativeSeconds: int, has_contract: bool}
     */
    public function yearAccount(User $user, int $year): array
    {
        $months = [];
        $hasContract = $this->contracts->findForUser($user) !== null;
        $cumulative = 0;
        for ($m = 1; $m <= 12; ++$m) {
            $data = $this->monthAccount($user, $year, $m);
            $months[$m] = [
                'targetSeconds' => $data['targetSeconds'],
                'workedSeconds' => $data['workedSeconds'],
                'balanceSeconds' => $data['balanceSeconds'],
            ];
            $cumulative = $data['cumulativeSeconds'];
        }

        return ['months' => $months, 'cumulativeSeconds' => $cumulative, 'has_contract' => $hasContract];
    }
```

> `cumulativeSeconds` after the loop equals the balance up to the end of December (the last month's cumulative), which is the year-end balance.

- [ ] **Step 2: `year` + `correct` Actions ergänzen**

In `var/plugins/WorktimeBundle/Controller/AccountController.php`, add these methods (and the needed `use` imports: `KimaiPlugin\WorktimeBundle\Audit\AuditLogger`, `KimaiPlugin\WorktimeBundle\Entity\AuditLog`, `KimaiPlugin\WorktimeBundle\Entity\BalanceCorrection`, `KimaiPlugin\WorktimeBundle\Repository\BalanceCorrectionRepository`, `Symfony\Component\HttpFoundation\RedirectResponse`):
```php
    #[Route(path: '/year', name: 'worktime_account_year', methods: ['GET'])]
    public function year(Request $request, AccountService $accounts, UserRepository $users): Response
    {
        $user = $this->resolveUser($request, $users);
        $now = new \DateTimeImmutable('now');
        $year = (int) $request->query->get('year', $now->format('Y'));

        return $this->render('@Worktime/account/year.html.twig', [
            'viewed_user' => $user,
            'year' => $year,
            'data' => $accounts->yearAccount($user, $year),
        ]);
    }

    #[Route(path: '/correct', name: 'worktime_account_correct', methods: ['POST'])]
    #[IsGranted('worktime_manage')]
    public function correct(Request $request, UserRepository $users, BalanceCorrectionRepository $corrections, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.correct', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_account'));
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $targetUser = $users->find((int) $request->request->get('user'));
        if ($targetUser === null) {
            throw $this->createNotFoundException();
        }

        $tz = new \DateTimeZone($admin->getTimezone());
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $request->request->getString('date'), $tz);
        $hours = (float) str_replace(',', '.', $request->request->getString('hours'));
        $reason = trim($request->request->getString('reason'));

        if (!$date instanceof \DateTimeImmutable || $hours === 0.0) {
            $this->addFlash('error', 'Bitte Datum und eine Stundenzahl ungleich 0 angeben.');

            return new RedirectResponse($this->generateUrl('worktime_account', ['user' => $targetUser->getId()]));
        }

        $seconds = (int) round($hours * 3600);
        $correction = new BalanceCorrection(new \DateTimeImmutable('now'));
        $correction->setUser($targetUser);
        $correction->setDate($date->setTime(0, 0));
        $correction->setAccount(BalanceCorrection::ACCOUNT_OVERTIME);
        $correction->setSeconds($seconds);
        $correction->setReason($reason === '' ? null : $reason);
        $correction->setCreatedBy($admin);
        $corrections->save($correction);

        $audit->log($admin, $targetUser, AuditLog::ACTION_BALANCE_CORRECTION, 'balance_correction', $correction->getId(), [
            'date' => $date->format('Y-m-d'),
            'seconds' => $seconds,
        ]);

        $this->addFlash('success', 'Korrektur gebucht.');

        return new RedirectResponse($this->generateUrl('worktime_account', ['user' => $targetUser->getId()]));
    }
```

- [ ] **Step 3: Korrektur-Formular in die Monats-View einbauen (nur Admin)**

In `var/plugins/WorktimeBundle/Resources/views/account/month.html.twig`, BEFORE the closing `{% endblock %}`, add an admin-only correction card + a link to the year view:
```twig
    <div class="mt-3">
        <a class="btn" href="{{ path('worktime_account_year', {year: year, user: viewed_user.id}) }}">{{ 'Jahresübersicht'|trans }}</a>
    </div>

    {% if is_granted('worktime_manage') %}
        <div class="card mt-3">
            <div class="card-header"><h3 class="card-title">{{ 'Saldo-Korrektur (Überstunden)'|trans }}</h3></div>
            <div class="card-body">
                <form method="post" action="{{ path('worktime_account_correct') }}" class="row g-2 align-items-end">
                    <input type="hidden" name="_token" value="{{ csrf_token('worktime.correct') }}">
                    <input type="hidden" name="user" value="{{ viewed_user.id }}">
                    <div class="col-auto">
                        <label class="form-label" for="wtCorrDate">{{ 'Datum'|trans }}</label>
                        <input type="date" class="form-control" id="wtCorrDate" name="date" required>
                    </div>
                    <div class="col-auto">
                        <label class="form-label" for="wtCorrHours">{{ 'Stunden (±)'|trans }}</label>
                        <input type="text" inputmode="decimal" class="form-control" id="wtCorrHours" name="hours" placeholder="-1,5" required>
                    </div>
                    <div class="col">
                        <label class="form-label" for="wtCorrReason">{{ 'Grund'|trans }}</label>
                        <input type="text" class="form-control" id="wtCorrReason" name="reason" maxlength="500">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-primary">{{ 'Buchen'|trans }}</button>
                    </div>
                </form>
            </div>
        </div>
    {% endif %}
```

- [ ] **Step 4: Jahres-View anlegen**

`var/plugins/WorktimeBundle/Resources/views/account/year.html.twig`:
```twig
{% extends 'base.html.twig' %}

{% block page_title %}{{ 'Zeitkonto-Jahr'|trans }} — {{ viewed_user.displayName }}{% endblock %}

{% macro hm(seconds) %}{% apply spaceless %}
    {% set s = seconds < 0 ? -seconds : seconds %}
    {{ seconds < 0 ? '−' : '' }}{{ (s // 3600) }}:{{ '%02d'|format((s % 3600) // 60) }}
{% endapply %}{% endmacro %}

{% block main %}
    {% import _self as fmt %}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h3 class="card-title">{{ 'Jahr'|trans }} {{ year }}</h3>
            <div class="btn-list">
                <a class="btn btn-sm" href="{{ path('worktime_account_year', {year: year - 1, user: viewed_user.id}) }}">&larr; {{ year - 1 }}</a>
                <a class="btn btn-sm" href="{{ path('worktime_account_year', {year: year + 1, user: viewed_user.id}) }}">{{ year + 1 }} &rarr;</a>
                <a class="btn btn-sm" href="{{ path('worktime_account', {year: year, user: viewed_user.id}) }}">{{ 'Monatsansicht'|trans }}</a>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-vcenter card-table">
                <thead><tr>
                    <th>{{ 'Monat'|trans }}</th><th class="text-end">{{ 'Soll'|trans }}</th>
                    <th class="text-end">{{ 'Ist'|trans }}</th><th class="text-end">{{ 'Saldo'|trans }}</th>
                </tr></thead>
                <tbody>
                {% for m in 1..12 %}
                    {% set row = data.months[m] %}
                    <tr>
                        <td>{{ (year ~ '-' ~ '%02d'|format(m) ~ '-01')|date('F') }}</td>
                        <td class="text-end">{{ fmt.hm(row.targetSeconds) }}</td>
                        <td class="text-end">{{ fmt.hm(row.workedSeconds) }}</td>
                        <td class="text-end {{ row.balanceSeconds < 0 ? 'text-danger' : (row.balanceSeconds > 0 ? 'text-success' : '') }}">{{ fmt.hm(row.balanceSeconds) }}</td>
                    </tr>
                {% endfor %}
                </tbody>
                <tfoot><tr>
                    <th>{{ 'Saldo gesamt (Jahresende)'|trans }}</th><th></th><th></th>
                    <th class="text-end {{ data.cumulativeSeconds < 0 ? 'text-danger' : 'text-success' }}">{{ fmt.hm(data.cumulativeSeconds) }}</th>
                </tr></tfoot>
            </table>
        </div>
    </div>
{% endblock %}
```

- [ ] **Step 5: Routen, Cache, Lint, Analyse, Smoke**

```bash
cd /opt/kimai
chown -R www-data:www-data var/plugins/WorktimeBundle
sudo -u www-data php bin/console cache:clear
sudo -u www-data php bin/console debug:router | grep -E "worktime_account"
sudo -u www-data php bin/console lint:twig var/plugins/WorktimeBundle/Resources/views
( cd var/plugins/WorktimeBundle && ../../../vendor/bin/phpunit -c phpunit.xml.dist 2>&1 | tail -3 )
./php-cs-fixer.sh Worktime
./phpstan.sh Worktime
```
Expected: routes `worktime_account`, `worktime_account_year`, `worktime_account_correct` present; lint valid; full suite green; cs-fixer + phpstan clean. Manual: as admin, book a +/− correction → `Saldo gesamt` shifts by that amount; year view sums per month.

- [ ] **Step 6: Commit**

```bash
git add var/plugins/WorktimeBundle/Account/AccountService.php var/plugins/WorktimeBundle/Controller/AccountController.php var/plugins/WorktimeBundle/Resources/views/account
git commit -m "feat(worktime): account year overview + admin balance correction"
```

---

## Self-Review

**Spec coverage (Spec §2.2 Arbeitszeitkonto / §7 Rechenlogik):**
- Soll/Ist-Vergleich pro Tag/Monat/Jahr → Task 4 (Monat) + Task 5 (Jahr). ✓
- Überstunden/Minusstunden (laufender Saldo) → `cumulativeSeconds` (Task 4/5), farbig. ✓
- Genehmigter Urlaub schreibt Tagessoll gut (halber Tag = halbes Soll) → `AccountBuilder` (Task 3). ✓
- Manuelle Korrekturbuchungen (Admin) → Task 2 (Entity) + Task 5 (Formular/Action), auditiert. ✓
- MA read-only eigenes Konto; Admin beliebiges → `AccountController::resolveUser` (403 für MA auf fremde Id). ✓
- Überlappende Stempelblöcke verhindert → Task 1. ✓
- framework-freie, getestete Logik (§11) → `IntervalMath` (Task 1), `AccountBuilder` (Task 3). ✓
- `down()` Schema-API; Views base.html.twig; phpstan L9 + cs-fixer → alle Tasks. ✓

**Bewusst nicht hier (getrackt):** Feiertage (yasumi) — Soll an Feiertagen vorerst Vertragsstunden, in der Ansicht als Hinweis; Krankheit/Überstundenabbau-Typen (Abbau würde `overtimeReductionSeconds` füllen); Monatsabschluss/Sperre + Snapshot + PDF; Urlaubs-Korrekturen (nur Überstunden); Performance (kumulativer Saldo iteriert ab Beschäftigungsbeginn — für 1–10 MA unkritisch).

**Placeholder scan:** keine TBD/TODO; jeder Code-Step vollständig. ✓

**Type consistency:** `DayAccount`-Properties, `AccountBuilder::dayAccount`, `AccountService::monthAccount/yearAccount`-Rückgabe-Shapes, Repo-Methoden (`findOverlapping`, `findApprovedForUserInRange`, `findOvertimeForUserUpTo`), `BalanceCorrection`-API, `AuditLog::ACTION_BALANCE_CORRECTION` über Tasks 1–5 konsistent. ✓
