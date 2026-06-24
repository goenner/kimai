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
use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Entity\BalanceCorrection;
use KimaiPlugin\WorktimeBundle\Repository\BalanceCorrectionRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
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

    #[Route(path: '/year', name: 'worktime_account_year', methods: ['GET'])]
    public function year(Request $request, AccountService $accounts, UserRepository $users): Response
    {
        $user = $this->resolveUser($request, $users);
        $now = new \DateTimeImmutable('now');
        $year = (int) $request->query->get('year', $now->format('Y'));
        $data = $accounts->yearAccount($user, $year);

        return $this->render('@Worktime/account/year.html.twig', [
            'viewed_user' => $user,
            'year' => $year,
            'data' => $data,
            'warn_overtime' => $data['cumulativeSeconds'] >= self::OVERTIME_WARN_SECONDS,
        ]);
    }

    #[Route(path: '/overview', name: 'worktime_account_overview', methods: ['GET'])]
    #[IsGranted('worktime_manage')]
    public function overview(Request $request, AccountService $accounts, UserRepository $users): Response
    {
        $now = new \DateTimeImmutable('now');

        // Month selection
        $year = (int) $request->query->get('year', $now->format('Y'));
        $month = (int) $request->query->get('month', $now->format('n'));
        if ($month < 1 || $month > 12) {
            $month = (int) $now->format('n');
        }
        $monthLabel = new \DateTimeImmutable(\sprintf('%04d-%02d-01', $year, $month));

        // Week selection (reference date -> Monday..Sunday of that week)
        $ref = $request->query->get('week');
        $refDate = \is_string($ref) ? (\DateTimeImmutable::createFromFormat('Y-m-d', $ref) ?: $now) : $now;
        $weekStart = $refDate->modify('monday this week')->setTime(0, 0);
        $weekEnd = $weekStart->modify('+6 days');

        $rows = [];
        foreach ($users->findBy(['enabled' => true], ['username' => 'ASC']) as $user) {
            $rows[] = [
                'user' => $user,
                'week' => $accounts->rangeSummary($user, $weekStart, $weekEnd),
                'month' => $accounts->monthAccount($user, $year, $month),
            ];
        }

        return $this->render('@Worktime/account/overview.html.twig', [
            'rows' => $rows,
            'week_start' => $weekStart,
            'week_end' => $weekEnd,
            'prev_week' => $weekStart->modify('-7 days'),
            'next_week' => $weekStart->modify('+7 days'),
            'year' => $year,
            'month' => $month,
            'month_label' => $monthLabel,
            'prev' => $monthLabel->modify('-1 month'),
            'next' => $monthLabel->modify('+1 month'),
            'warn_threshold_seconds' => self::OVERTIME_WARN_SECONDS,
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
        if (null === $targetUser) {
            throw $this->createNotFoundException();
        }

        $tz = new \DateTimeZone($admin->getTimezone());
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $request->request->getString('date'), $tz);
        $hours = (float) str_replace(',', '.', $request->request->getString('hours'));
        $reason = trim($request->request->getString('reason'));

        if (!$date instanceof \DateTimeImmutable || 0.0 === $hours) {
            $this->addFlash('error', 'Bitte Datum und eine Stundenzahl ungleich 0 angeben.');

            return new RedirectResponse($this->generateUrl('worktime_account', ['user' => $targetUser->getId()]));
        }

        $seconds = (int) round($hours * 3600);
        $correction = new BalanceCorrection(new \DateTimeImmutable('now'));
        $correction->setUser($targetUser);
        $correction->setDate($date->setTime(0, 0));
        $correction->setAccount(BalanceCorrection::ACCOUNT_OVERTIME);
        $correction->setSeconds($seconds);
        $correction->setReason('' === $reason ? null : $reason);
        $correction->setCreatedBy($admin);
        $corrections->save($correction);

        $audit->log($admin, $targetUser, AuditLog::ACTION_BALANCE_CORRECTION, 'balance_correction', $correction->getId(), [
            'date' => $date->format('Y-m-d'),
            'seconds' => $seconds,
        ]);

        $this->addFlash('success', 'Korrektur gebucht.');

        return new RedirectResponse($this->generateUrl('worktime_account', ['user' => $targetUser->getId()]));
    }
}
