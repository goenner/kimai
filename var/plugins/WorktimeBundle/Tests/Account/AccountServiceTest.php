<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Tests\Account;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Account\AccountService;
use KimaiPlugin\WorktimeBundle\Calculator\AccountBuilder;
use KimaiPlugin\WorktimeBundle\Calculator\DayOffProvider;
use KimaiPlugin\WorktimeBundle\Calculator\HolidayCalendar;
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Calculator\WorktimeCalculator;
use KimaiPlugin\WorktimeBundle\Entity\BalanceCorrection;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\BalanceCorrectionRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use KimaiPlugin\WorktimeBundle\Repository\FreeDayRepository;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use PHPUnit\Framework\TestCase;

/**
 * Rechenlogik des AccountService ohne DB (Repositories gemockt). „Heute" ist Mi, 10.06.2026 12:00 Berlin.
 */
class AccountServiceTest extends TestCase
{
    private const H = 3600;

    private User $user;
    private Contract $contract;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->user->setTimezone('Europe/Berlin');
        $this->contract = new Contract();
        $this->contract->setUser($this->user);
        foreach ([1, 2, 3, 4, 5] as $wd) {
            $this->contract->setWorkHoursForWeekday($wd, 8 * self::H);
        }
        $this->contract->setWorkHoursForWeekday(6, 0);
        $this->contract->setWorkHoursForWeekday(7, 0);
        $this->contract->setHolidayRegion('NW');
    }

    private function block(string $start, string $end): WorkBlock
    {
        $b = new WorkBlock();
        $b->setUser($this->user);
        $b->setStart(new \DateTimeImmutable($start.' Europe/Berlin'));
        $b->setEnd(new \DateTimeImmutable($end.' Europe/Berlin'));

        return $b;
    }

    /**
     * @param WorkBlock[] $blocks
     */
    private function service(array $blocks, array $correctionList = []): AccountService
    {
        $contracts = $this->createMock(ContractRepository::class);
        $contracts->method('findForUser')->willReturn($this->contract);

        $blockRepo = $this->createMock(WorkBlockRepository::class);
        $blockRepo->method('findOverlapping')->willReturn($blocks);
        $blockRepo->method('findFirstStart')->willReturn([] === $blocks ? null : $blocks[0]->getStart());

        $absences = $this->createMock(AbsenceRepository::class);
        $absences->method('findApprovedForUserInRange')->willReturn([]);

        $corrections = $this->createMock(BalanceCorrectionRepository::class);
        $corrections->method('findFirstDate')->willReturn(null);
        $corrections->method('findOvertimeForUserUpTo')->willReturn($correctionList);

        $freeDays = $this->createMock(FreeDayRepository::class);
        $freeDays->method('findBetween')->willReturn([]);

        return new AccountService(
            new AccountBuilder(new WorktimeCalculator()),
            $contracts,
            $blockRepo,
            $absences,
            $corrections,
            new DayOffProvider(new HolidayCalendar(), $freeDays),
            new WorkBlockMath(),
            new \DateTimeImmutable('2026-06-10 12:00:00 Europe/Berlin'),
        );
    }

    /**
     * @return WorkBlock[]
     */
    private function blocks(): array
    {
        return [
            $this->block('2026-06-08 08:00', '2026-06-08 16:00'), // Mo 8 h
            $this->block('2026-06-09 22:00', '2026-06-10 02:00'), // Di/Mi über Mitternacht, je 2 h
        ];
    }

    public function testMonthShowsFutureTargetButBalancesOnlyUpToToday(): void
    {
        $data = $this->service($this->blocks())->monthAccount($this->user, 2026, 6);

        // Juni 2026 NRW: 22 Werktage, Fronleichnam (04.06.) frei -> 21 × 8 h Soll
        self::assertSame(21 * 8 * self::H, $data['targetSeconds']);
        self::assertSame(12 * self::H, $data['workedSeconds']);
        // Saldo nur 01.–09.06. (heute läuft noch): 6 Soll-Tage (48 h) gegen 10 h
        self::assertSame((10 - 48) * self::H, $data['balanceSeconds']);
        // kumuliert ab dem ersten Block (08.06.) bis gestern: 16 h Soll gegen 10 h
        self::assertSame(-6 * self::H, $data['cumulativeSeconds']);

        $byDate = [];
        foreach ($data['days'] as $d) {
            $byDate[$d->date->format('Y-m-d')] = $d;
        }
        self::assertSame(2 * self::H, $byDate['2026-06-09']->workedSeconds);
        self::assertSame(2 * self::H, $byDate['2026-06-10']->workedSeconds);
        self::assertSame(0, $byDate['2026-06-10']->balanceSeconds, 'heute zählt erst nach Tagesende');
        self::assertSame(8 * self::H, $byDate['2026-06-15']->targetSeconds);
        self::assertSame(0, $byDate['2026-06-15']->balanceSeconds);
    }

    public function testYearAndWeekUseSameRules(): void
    {
        $service = $this->service($this->blocks());

        $year = $service->yearAccount($this->user, 2026);
        self::assertSame(-6 * self::H, $year['cumulativeSeconds']);
        self::assertSame((10 - 48) * self::H, $year['months'][6]['balanceSeconds']);
        self::assertSame(0, $year['months'][7]['balanceSeconds']);
        self::assertGreaterThan(0, $year['months'][7]['targetSeconds']);
        // Mai liegt vor dem ersten Block, zählt aber im Monatssaldo voll: 21 Werktage − 3 Feiertage
        self::assertSame(-18 * 8 * self::H, $year['months'][5]['balanceSeconds']);

        $week = $service->rangeSummary($this->user, new \DateTimeImmutable('2026-06-08'), new \DateTimeImmutable('2026-06-14'));
        self::assertSame(40 * self::H, $week['targetSeconds']);
        self::assertSame(-6 * self::H, $week['balanceSeconds']);

        $row = $service->overviewRow($this->user, new \DateTimeImmutable('2026-06-08'), new \DateTimeImmutable('2026-06-14'), 2026, 6);
        self::assertEquals(['week' => $week, 'month' => $service->monthAccount($this->user, 2026, 6)], $row);
    }

    public function testWithoutBlocksOrCorrectionsCumulativeIsInitialOnly(): void
    {
        $this->contract->setInitialOvertimeSeconds(5 * self::H);
        $data = $this->service([])->monthAccount($this->user, 2026, 6);
        self::assertSame(5 * self::H, $data['cumulativeSeconds']);
    }

    public function testEmploymentStartWinsOverFirstBlock(): void
    {
        $this->contract->setEmploymentStart(new \DateTimeImmutable('2026-06-09'));
        $data = $this->service($this->blocks())->monthAccount($this->user, 2026, 6);
        // nur 09.06. (heute läuft noch): 8 h Soll gegen 2 h; der Block vom 08.06. liegt vor Beschäftigungsbeginn
        self::assertSame(-6 * self::H, $data['cumulativeSeconds']);
        self::assertSame(0, $data['days'][7]->targetSeconds); // 08.06. vor Beschäftigungsbeginn
    }

    public function testAccountStartIgnoresDaysBeforeRollout(): void
    {
        $this->contract->setEmploymentStart(new \DateTimeImmutable('2020-01-01'));
        $this->contract->setAccountStart(new \DateTimeImmutable('2026-06-09'));
        $data = $this->service($this->blocks())->monthAccount($this->user, 2026, 6);
        // wie oben nur 09.06.: 8 h Soll gegen 2 h – trotz Beschäftigung seit 2020 keine Altlast
        self::assertSame(-6 * self::H, $data['cumulativeSeconds']);
        // Monatssaldo: nur der 09.06. zählt, das Soll der Tage davor wird nur ausgewiesen
        self::assertSame((2 - 8) * self::H, $data['balanceSeconds']);
        self::assertSame(8 * self::H, $data['days'][7]->targetSeconds);
        self::assertSame(0, $data['days'][7]->balanceSeconds);
    }

    public function testCorrectionsBeforeAccountStartAreIgnored(): void
    {
        $this->contract->setAccountStart(new \DateTimeImmutable('2026-06-09'));
        $old = new BalanceCorrection(new \DateTimeImmutable());
        $old->setUser($this->user);
        $old->setDate(new \DateTimeImmutable('2026-06-01'));
        $old->setSeconds(-3 * self::H);
        $today = new BalanceCorrection(new \DateTimeImmutable());
        $today->setUser($this->user);
        $today->setDate(new \DateTimeImmutable('2026-06-10'));
        $today->setSeconds(5 * self::H);

        $data = $this->service($this->blocks(), [$old, $today])->monthAccount($this->user, 2026, 6);
        // 09.06.: −6 h; Korrektur vom 01.06. liegt vor dem Stichtag, die von heute zählt sofort
        self::assertSame(-1 * self::H, $data['cumulativeSeconds']);
    }
}
