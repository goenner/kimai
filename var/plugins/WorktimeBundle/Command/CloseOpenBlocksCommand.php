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
    description: 'Auto-close work blocks open for more than 16 h (forgotten punch-out) at the daily end time (17:00 default), or start + 1 h if they began after it.',
)]
final class CloseOpenBlocksCommand extends Command
{
    private const MAX_OPEN_HOURS = 16;

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
        // Nur Blöcke, die seit mehr als 16 h laufen: eine Spätschicht über Mitternacht ist legitim
        foreach ($this->blocks->findOpenStartedBefore($now->modify(\sprintf('-%d hours', self::MAX_OPEN_HOURS))) as $block) {
            $user = $block->getUser();
            $tz = new \DateTimeZone($user->getTimezone());

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
