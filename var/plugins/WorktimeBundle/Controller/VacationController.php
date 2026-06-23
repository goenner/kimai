<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime/vacation')]
#[IsGranted('worktime_view_own')]
final class VacationController extends AbstractController
{
    #[Route(path: '', name: 'worktime_vacation', methods: ['GET'])]
    public function index(AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $contract = $contracts->findForUser($user);
        $year = (int) (new \DateTimeImmutable('now'))->format('Y');

        $entitlement = null !== $contract ? $contract->getHolidaysPerYear() : 0.0;
        $carryover = null !== $contract ? $contract->getVacationCarryover() : 0.0;

        $used = 0.0;
        if (null !== $contract) {
            foreach ($absences->findApprovedForUserInYear($user, $year) as $a) {
                $used += $calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay());
            }
        }
        $remaining = $calc->remainingDays($entitlement, $carryover, $used);

        $rows = [];
        foreach ($absences->findForUser($user) as $a) {
            $rows[] = [
                'absence' => $a,
                'days' => null !== $contract ? $calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay()) : 0.0,
            ];
        }

        return $this->render('@Worktime/vacation/index.html.twig', [
            'has_contract' => null !== $contract,
            'year' => $year,
            'entitlement' => $entitlement,
            'carryover' => $carryover,
            'used' => $used,
            'remaining' => $remaining,
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/request', name: 'worktime_vacation_request', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function request(Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.vacation', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $start = \DateTimeImmutable::createFromFormat('Y-m-d', $request->request->getString('start'), $tz);
        $end = \DateTimeImmutable::createFromFormat('Y-m-d', $request->request->getString('end'), $tz);
        $halfDay = $request->request->getBoolean('half_day');
        $note = trim($request->request->getString('note'));

        if (!$start instanceof \DateTimeImmutable || !$end instanceof \DateTimeImmutable) {
            $this->addFlash('error', 'Bitte gültige Daten angeben.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }
        $start = $start->setTime(0, 0);
        $end = $end->setTime(0, 0);

        if ($end < $start) {
            $this->addFlash('error', 'Das Enddatum darf nicht vor dem Startdatum liegen.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }
        if ($halfDay && $start->format('Y-m-d') !== $end->format('Y-m-d')) {
            $this->addFlash('error', 'Ein halber Tag ist nur für einen einzelnen Tag möglich.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        if ($start->format('Y') !== $end->format('Y')) {
            $this->addFlash('error', 'Bitte Urlaub über den Jahreswechsel in zwei Anträge aufteilen (einen pro Jahr).');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        $absence = new Absence(new \DateTimeImmutable('now'));
        $absence->setUser($user);
        $absence->setType(Absence::TYPE_VACATION);
        $absence->setStartDate($start);
        $absence->setEndDate($end);
        $absence->setHalfDay($halfDay);
        $absence->setStatus(Absence::STATUS_OPEN);
        $absence->setNote('' === $note ? null : $note);
        $absences->save($absence);

        $audit->log($user, $user, AuditLog::ACTION_ABSENCE_REQUEST, 'absence', $absence->getId(), [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
            'half_day' => $halfDay,
        ]);

        $this->addFlash('success', 'Urlaubsantrag eingereicht.');

        return new RedirectResponse($this->generateUrl('worktime_vacation'));
    }
}
