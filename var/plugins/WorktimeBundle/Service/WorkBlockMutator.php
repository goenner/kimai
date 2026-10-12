<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Service;

use App\Entity\User;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
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
    /** Ausstempeln so kurz nach dem Einstempeln gilt als Doppelklick und wird ignoriert. */
    public const PUNCH_MIN_SECONDS = 5;

    public function __construct(
        private readonly WorkBlockRepository $blocks,
        private readonly WorkBlockValidator $validator,
        private readonly AuditLogger $audit,
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Stempeluhr ein/aus. Läuft in einer Transaktion mit Zeilensperre auf den Nutzer, damit
     * Doppelklicks und parallele Requests nicht zwei offene Blöcke erzeugen.
     *
     * @param string|null $expect 'in' oder 'out' = beabsichtigte Aktion; passt der Zustand nicht, passiert nichts.
     *                            null = umschalten (altes Verhalten).
     *
     * @return array{0: string, 1: string} Flash-Typ und Meldung
     */
    public function punch(User $user, ?string $expect, \DateTimeImmutable $now): array
    {
        /** @var array{0: string, 1: string} $result */
        $result = $this->em->wrapInTransaction(function () use ($user, $expect, $now): array {
            $this->em->lock($user, LockMode::PESSIMISTIC_WRITE);
            $open = $this->blocks->findOpenBlock($user);

            if ('in' === $expect && null !== $open) {
                return ['warning', 'Du bist bereits eingestempelt.'];
            }
            if ('out' === $expect && null === $open) {
                return ['warning', 'Du bist bereits ausgestempelt.'];
            }

            if (null !== $open) {
                if ($now->getTimestamp() - $open->getStart()->getTimestamp() < self::PUNCH_MIN_SECONDS) {
                    return ['warning', 'Ausstempeln ignoriert – du hast gerade erst eingestempelt.'];
                }
                $error = $this->validator->validateInterval($open->getStart(), $now, $now);
                if (null === $error && [] !== $this->blocks->findOverlapping($user, $open->getStart(), $now, $open->getId())) {
                    $error = 'Der Zeitraum überschneidet sich mit einer bestehenden Buchung.';
                }
                if (null !== $error) {
                    return ['error', $error.' Bitte die laufende Buchung manuell korrigieren.'];
                }

                $open->setEnd($now);
                $this->blocks->save($open);
                $this->audit->log($user, $user, AuditLog::ACTION_PUNCH_OUT, 'work_block', $open->getId(), ['end' => $now->format('c')]);

                return ['success', 'Ausgestempelt.'];
            }

            if ([] !== $this->blocks->findOverlapping($user, $now, $now->modify('+1 second'), null)) {
                return ['error', 'Zu diesem Zeitpunkt gibt es bereits eine Buchung.'];
            }

            $block = new WorkBlock();
            $block->setUser($user);
            $block->setStart($now);
            $block->setSource(WorkBlock::SOURCE_PUNCH);
            $this->blocks->save($block);
            $this->audit->log($user, $user, AuditLog::ACTION_PUNCH_IN, 'work_block', $block->getId(), ['start' => $now->format('c')]);

            return ['success', 'Eingestempelt.'];
        });

        return $result;
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
        // offenes Ende: läuft unbegrenzt weiter, überschneidet also jeden späteren Block
        $overlapEnd = $end ?? new \DateTimeImmutable('9999-12-31 00:00:00');
        if ([] !== $this->blocks->findOverlapping($target, $start, $overlapEnd, $block->getId())) {
            return 'Der Zeitraum überschneidet sich mit einer bestehenden Buchung.';
        }

        $old = [
            'start' => $block->getStart()->format('c'),
            'end' => $block->getEnd()?->format('c'),
        ];
        $block->setStart($start);
        $block->setEnd($end);
        $block->setNeedsReview(false); // korrigiert = geprüft
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
