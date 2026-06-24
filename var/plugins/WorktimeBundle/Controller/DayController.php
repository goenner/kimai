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
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use KimaiPlugin\WorktimeBundle\Service\WorkBlockMutator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime/account/day')]
#[IsGranted('worktime_view_own')]
final class DayController extends AbstractController
{
    /**
     * Resolve whose day is viewed/edited: own always allowed; a foreign user
     * requires worktime_manage.
     */
    private function resolveUser(?int $requestedId, UserRepository $users): User
    {
        /** @var User $current */
        $current = $this->getUser();
        if (null === $requestedId || $requestedId === $current->getId()) {
            return $current;
        }
        if (!$this->isGranted('worktime_manage')) {
            throw $this->createAccessDeniedException();
        }
        $user = $users->find($requestedId);
        if (null === $user) {
            throw $this->createNotFoundException();
        }

        return $user;
    }

    private function assertCanEdit(User $target): void
    {
        /** @var User $current */
        $current = $this->getUser();
        if ($target->getId() === $current->getId()) {
            if (!$this->isGranted('worktime_edit_own')) {
                throw $this->createAccessDeniedException();
            }

            return;
        }
        if (!$this->isGranted('worktime_manage')) {
            throw $this->createAccessDeniedException();
        }
    }

    private function parse(string $raw, \DateTimeZone $tz): ?\DateTimeImmutable
    {
        $raw = trim($raw);
        if ('' === $raw) {
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

    private function redirectDay(User $user, \DateTimeImmutable $date): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('worktime_account_day', [
            'date' => $date->format('Y-m-d'),
            'user' => $user->getId(),
        ]));
    }

    #[Route(path: '', name: 'worktime_account_day', methods: ['GET'])]
    public function day(Request $request, AccountService $accounts, WorkBlockRepository $blocks, UserRepository $users): Response
    {
        $requested = $request->query->get('user');
        $user = $this->resolveUser(null !== $requested ? (int) $requested : null, $users);

        $tz = new \DateTimeZone($user->getTimezone());
        $dateRaw = (string) $request->query->get('date', '');
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $dateRaw, $tz) ?: new \DateTimeImmutable('now', $tz);
        $dayStart = $date->setTime(0, 0);
        $dayEnd = $dayStart->modify('+1 day');

        $dayBlocks = $blocks->findForUserBetween($user, $dayStart, $dayEnd);
        $summary = $accounts->rangeSummary($user, $dayStart, $dayStart);

        // Actual worked seconds from the blocks themselves, so "Ist" is correct
        // even when the user has no contract (summary would be zeroed then).
        $dayWorked = 0;
        foreach ($dayBlocks as $b) {
            $dayWorked += $b->getDurationSeconds() ?? 0;
        }

        /** @var User $current */
        $current = $this->getUser();
        $canEdit = $user->getId() === $current->getId()
            ? $this->isGranted('worktime_edit_own')
            : $this->isGranted('worktime_manage');

        return $this->render('@Worktime/account/day.html.twig', [
            'viewed_user' => $user,
            'date' => $dayStart,
            'blocks' => $dayBlocks,
            'summary' => $summary,
            'day_worked' => $dayWorked,
            'can_edit' => $canEdit,
            'back_url' => $this->generateUrl('worktime_account', [
                'user' => $user->getId(),
                'year' => (int) $dayStart->format('Y'),
                'month' => (int) $dayStart->format('n'),
            ]),
        ]);
    }

    #[Route(path: '/entry', name: 'worktime_day_entry_create', methods: ['POST'])]
    public function create(Request $request, WorkBlockMutator $mutator, UserRepository $users): Response
    {
        /** @var User $actor */
        $actor = $this->getUser();
        $requested = $request->request->get('user');
        $target = $this->resolveUser(null !== $requested ? (int) $requested : null, $users);
        $this->assertCanEdit($target);

        if (!$this->isCsrfTokenValid('worktime.day_entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectDay($target, new \DateTimeImmutable('now', new \DateTimeZone($target->getTimezone())));
        }

        $tz = new \DateTimeZone($target->getTimezone());
        $start = $this->parse($request->request->getString('start'), $tz);
        $end = $this->parse($request->request->getString('end'), $tz);

        if (null === $start) {
            $this->addFlash('error', 'Bitte Beginn und Ende angeben.');

            return $this->redirectDay($target, new \DateTimeImmutable('now', $tz));
        }

        $error = $mutator->create($actor, $target, $start, $end);
        $this->addFlash($error ?? 'success', $error ?? 'Eintrag gespeichert.');

        return $this->redirectDay($target, $start);
    }

    #[Route(path: '/entry/{id}/edit', name: 'worktime_day_entry_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, WorkBlockRepository $blocks, WorkBlockMutator $mutator): Response
    {
        $block = $blocks->find($id);
        if (null === $block) {
            throw $this->createNotFoundException();
        }
        $target = $block->getUser();
        $this->assertCanEdit($target);

        if (!$this->isCsrfTokenValid('worktime.day_entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectDay($target, $block->getStart());
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $tz = new \DateTimeZone($target->getTimezone());
        $start = $this->parse($request->request->getString('start'), $tz);
        $end = $this->parse($request->request->getString('end'), $tz);
        if (null === $start) {
            $this->addFlash('error', 'Bitte einen Beginn angeben.');

            return $this->redirectDay($target, $block->getStart());
        }

        $error = $mutator->update($actor, $block, $start, $end);
        $this->addFlash($error ?? 'success', $error ?? 'Eintrag aktualisiert.');

        return $this->redirectDay($target, $start);
    }

    #[Route(path: '/entry/{id}/delete', name: 'worktime_day_entry_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, WorkBlockRepository $blocks, WorkBlockMutator $mutator): Response
    {
        $block = $blocks->find($id);
        if (null === $block) {
            throw $this->createNotFoundException();
        }
        $target = $block->getUser();
        $this->assertCanEdit($target);

        if (!$this->isCsrfTokenValid('worktime.day_entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectDay($target, $block->getStart());
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $date = $block->getStart();
        $mutator->delete($actor, $block);
        $this->addFlash('success', 'Eintrag gelöscht.');

        return $this->redirectDay($target, $date);
    }
}
