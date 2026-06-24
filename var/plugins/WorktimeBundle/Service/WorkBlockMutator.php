<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Service;

use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Entity\WorkBlock;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use KimaiPlugin\WorktimeBundle\Validator\WorkBlockValidator;

/**
 * Single source of truth for creating, editing and deleting work blocks
 * (validation + overlap guard + persistence + audit). Used by the Stempeluhr
 * EntryController (self) and the Zeitkonto DayController (self or admin).
 *
 * `actor` is who performs the change; `target` is whose block it is.
 */
final class WorkBlockMutator
{
    public function __construct(
        private readonly WorkBlockRepository $blocks,
        private readonly WorkBlockValidator $validator,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @return string|null error message, or null on success
     */
    public function create(User $actor, User $target, \DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string
    {
        if (null === $end) {
            return 'Bitte Beginn und Ende angeben.';
        }
        $error = $this->validator->validateInterval($start, $end);
        if (null !== $error) {
            return $error;
        }
        if ([] !== $this->blocks->findOverlapping($target, $start, $end, null)) {
            return 'Der Zeitraum überschneidet sich mit einer bestehenden Buchung.';
        }

        $block = new WorkBlock();
        $block->setUser($target);
        $block->setStart($start);
        $block->setEnd($end);
        $block->setSource(WorkBlock::SOURCE_MANUAL);
        $this->blocks->save($block);

        $this->audit->log($actor, $target, AuditLog::ACTION_BLOCK_CREATE, 'work_block', $block->getId(), [
            'start' => $start->format('c'),
            'end' => $end->format('c'),
            'source' => WorkBlock::SOURCE_MANUAL,
        ]);

        return null;
    }

    /**
     * @return string|null error message, or null on success
     */
    public function update(User $actor, WorkBlock $block, \DateTimeImmutable $start, ?\DateTimeImmutable $end): ?string
    {
        $error = $this->validator->validateInterval($start, $end);
        if (null !== $error) {
            return $error;
        }

        $target = $block->getUser();
        if (null === $end) {
            $existingOpen = $this->blocks->findOpenBlock($target);
            if (null !== $existingOpen && $existingOpen->getId() !== $block->getId()) {
                return 'Es gibt bereits eine laufende Buchung. Bitte zuerst beenden.';
            }
        }
        if (null !== $end && [] !== $this->blocks->findOverlapping($target, $start, $end, $block->getId())) {
            return 'Der Zeitraum überschneidet sich mit einer bestehenden Buchung.';
        }

        $old = [
            'start' => $block->getStart()->format('c'),
            'end' => $block->getEnd()?->format('c'),
        ];
        $block->setStart($start);
        $block->setEnd($end);
        $this->blocks->save($block);

        $this->audit->log($actor, $target, AuditLog::ACTION_BLOCK_EDIT, 'work_block', $block->getId(), [
            'old' => $old,
            'new' => ['start' => $start->format('c'), 'end' => $end?->format('c')],
        ]);

        return null;
    }

    public function delete(User $actor, WorkBlock $block): void
    {
        $target = $block->getUser();
        $details = [
            'start' => $block->getStart()->format('c'),
            'end' => $block->getEnd()?->format('c'),
        ];
        $blockId = $block->getId();
        $this->blocks->remove($block);

        $this->audit->log($actor, $target, AuditLog::ACTION_BLOCK_DELETE, 'work_block', $blockId, $details);
    }
}
