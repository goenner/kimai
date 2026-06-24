<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Command;

use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Calculator\AutoCloseTime;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'worktime:close-open-blocks',
    description: 'Auto-close work blocks left open from previous days (forgotten punch-out) at the daily end time (17:00 default).',
)]
final class CloseOpenBlocksCommand extends Command
{
    public function __construct(
        private readonly WorkBlockRepository $blocks,
        private readonly ContractRepository $contracts,
        private readonly AutoCloseTime $autoClose,
        private readonly AuditLogger $audit,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $now = new \DateTimeImmutable('now');

        $closed = 0;
        foreach ($this->blocks->findOpenStartedBefore($now) as $block) {
            $user = $block->getUser();
            $tz = new \DateTimeZone($user->getTimezone());

            // Only previous days: a block started on the user's local "today" is
            // a legitimately running session and must not be auto-closed.
            $startLocalDay = $block->getStart()->setTimezone($tz)->format('Y-m-d');
            $todayLocalDay = $now->setTimezone($tz)->format('Y-m-d');
            if ($startLocalDay >= $todayLocalDay) {
                continue;
            }

            $contract = $this->contracts->findForUser($user);
            $end = $this->autoClose->endFor($block->getStart(), $tz, $contract?->getDailyEndTime());

            $block->setEnd($end);
            $block->setNeedsReview(true);
            $this->blocks->save($block);

            $this->audit->log(null, $user, AuditLog::ACTION_BLOCK_AUTO_CLOSE, 'work_block', $block->getId(), [
                'start' => $block->getStart()->format('c'),
                'end' => $end->format('c'),
                'auto' => true,
            ]);

            ++$closed;
        }

        $io->success(\sprintf('Auto-closed %d open block(s).', $closed));

        return Command::SUCCESS;
    }
}
