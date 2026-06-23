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
    private const OVERTIME_WARN_SECONDS = 21600; // 6 h — show a prominent warning at/above this balance

    /**
     * Resolve the user being viewed: a `user` query param requires worktime_manage;
     * otherwise the current user.
     */
    private function resolveUser(Request $request, UserRepository $users): User
    {
        $current = $this->getUser();

        $requested = $request->query->get('user');
        if (null !== $requested && (int) $requested !== $current->getId()) {
            if (!$this->isGranted('worktime_manage')) {
                throw $this->createAccessDeniedException();
            }
            $user = $users->find((int) $requested);
            if (null === $user) {
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
        $current = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));

        return $this->render('@Worktime/account/month.html.twig', [
            'viewed_user' => $user,
            'year' => $year,
            'month' => $month,
            'month_label' => $current,
            'prev' => $current->modify('-1 month'),
            'next' => $current->modify('+1 month'),
            'data' => $data,
            'warn_overtime' => $data['cumulativeSeconds'] >= self::OVERTIME_WARN_SECONDS,
            'warn_threshold_seconds' => self::OVERTIME_WARN_SECONDS,
        ]);
    }
}
