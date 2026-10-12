<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Calculator\WorkBlockMath;
use KimaiPlugin\WorktimeBundle\Repository\WorkBlockRepository;
use KimaiPlugin\WorktimeBundle\Service\WorkBlockMutator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime')]
#[IsGranted('worktime_view_own')]
final class WorktimeController extends AbstractController
{
    #[Route(path: '', name: 'worktime_index', methods: ['GET'])]
    public function index(WorkBlockRepository $blocks, WorkBlockMath $math): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $tz = new \DateTimeZone($user->getTimezone());
        $todayStart = new \DateTimeImmutable('today', $tz);
        $todayEnd = $todayStart->modify('+1 day');

        // The today table/sum filter on block start within today's local window.
        // A block started before midnight and still open (forgotten punch-out) will
        // therefore not appear here until the Plan 3 nightly auto-close splits/closes it.
        // The status badge + button below intentionally derive from $openBlock (not the
        // window) so they stay correct even for such an overnight-open block.
        $todayBlocks = $blocks->findForUserBetween($user, $todayStart, $todayEnd);
        $openBlock = $blocks->findOpenBlock($user);
        $now = new \DateTimeImmutable('now');

        return $this->render('@Worktime/index.html.twig', [
            'today' => $todayStart,
            'open_block' => $openBlock,
            'today_blocks' => $todayBlocks,
            'today_seconds' => $math->netSeconds($todayBlocks, $now),
            'today_break_seconds' => $math->breakSeconds($todayBlocks),
            'server_now' => $now->getTimestamp(),
        ]);
    }

    #[Route(path: '/punch', name: 'worktime_punch', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function punch(Request $request, WorkBlockMutator $mutator): Response
    {
        if (!$this->isCsrfTokenValid('worktime.punch', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_index'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $expect = $request->request->getString('expect');
        [$type, $message] = $mutator->punch($user, \in_array($expect, ['in', 'out'], true) ? $expect : null, new \DateTimeImmutable('now'));
        $this->addFlash($type, $message);

        return new RedirectResponse($this->generateUrl('worktime_index'));
    }
}
