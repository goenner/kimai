<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use KimaiPlugin\WorktimeBundle\Service\WorkBlockMutator;
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

    #[Route(path: '', name: 'worktime_entry_create', methods: ['POST'])]
    public function create(Request $request, WorkBlockMutator $mutator): Response
    {
        if (!$this->isCsrfTokenValid('worktime.entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        /** @var User $user */
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $start = $this->parse($request->request->getString('start'), $tz);
        $end = $this->parse($request->request->getString('end'), $tz);

        if (null === $start) {
            $this->addFlash('error', 'Bitte Beginn und Ende angeben.');

            return $this->redirectIndex();
        }

        $error = $mutator->create($user, $user, $start, $end);
        $this->addFlash($error ?? 'success', $error ?? 'Eintrag gespeichert.');

        return $this->redirectIndex();
    }

    #[Route(path: '/{id}/edit', name: 'worktime_entry_edit', methods: ['POST'])]
    public function edit(int $id, Request $request, WorkBlockRepository $blocks, WorkBlockMutator $mutator): Response
    {
        if (!$this->isCsrfTokenValid('worktime.entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        /** @var User $user */
        $user = $this->getUser();
        $block = $blocks->find($id);
        if (null === $block || $block->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $tz = new \DateTimeZone($user->getTimezone());
        $start = $this->parse($request->request->getString('start'), $tz);
        $end = $this->parse($request->request->getString('end'), $tz);
        if (null === $start) {
            $this->addFlash('error', 'Bitte einen Beginn angeben.');

            return $this->redirectIndex();
        }

        $error = $mutator->update($user, $block, $start, $end);
        $this->addFlash($error ?? 'success', $error ?? 'Eintrag aktualisiert.');

        return $this->redirectIndex();
    }

    #[Route(path: '/{id}/delete', name: 'worktime_entry_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, WorkBlockRepository $blocks, WorkBlockMutator $mutator): Response
    {
        if (!$this->isCsrfTokenValid('worktime.entry', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        /** @var User $user */
        $user = $this->getUser();
        $block = $blocks->find($id);
        if (null === $block || $block->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        $mutator->delete($user, $block);
        $this->addFlash('success', 'Eintrag gelöscht.');

        return $this->redirectIndex();
    }
}
