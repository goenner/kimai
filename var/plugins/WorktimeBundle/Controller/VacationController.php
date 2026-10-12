<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use KimaiPlugin\WorktimeBundle\Account\VacationAccount;
use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Calculator\VacationCalculator;
use KimaiPlugin\WorktimeBundle\Entity\Absence;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Repository\AbsenceRepository;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use KimaiPlugin\WorktimeBundle\Validator\DateInput;
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
    public function index(Request $request, AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc, VacationAccount $vacation): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        $contract = $contracts->findForUser($user);
        $year = DateInput::year($request->query->get('year'), (int) (new \DateTimeImmutable('now', new \DateTimeZone($user->getTimezone())))->format('Y'));
        $summary = $vacation->summary($user, $year);

        $rows = [];
        foreach ($absences->findForUser($user) as $a) {
            $rows[] = [
                'absence' => $a,
                'days' => null !== $contract ? $calc->countAbsence($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay()) : 0.0,
            ];
        }

        return $this->render('@Worktime/vacation/index.html.twig', [
            'has_contract' => null !== $contract,
            'year' => $year,
            'available_years' => $summary['available_years'],
            'entitlement' => $summary['entitlement'],
            'carryover' => $summary['carryover'],
            'used' => $summary['used'],
            'remaining' => $summary['remaining'],
            'rows' => $rows,
        ]);
    }

    #[Route(path: '/request', name: 'worktime_vacation_request', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function request(Request $request, AbsenceRepository $absences, AuditLogger $audit, ContractRepository $contracts, VacationCalculator $calc, VacationAccount $vacation): Response
    {
        if (!$this->isCsrfTokenValid('worktime.vacation', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $tz = new \DateTimeZone($user->getTimezone());
        $start = DateInput::parse($request->request->getString('start'), $tz);
        $end = DateInput::parse($request->request->getString('end'), $tz);
        $halfDay = $request->request->getBoolean('half_day');
        $note = trim($request->request->getString('note'));

        if (null === $start || null === $end
            || DateInput::year($start->format('Y'), 0) !== (int) $start->format('Y')
            || DateInput::year($end->format('Y'), 0) !== (int) $end->format('Y')) {
            $this->addFlash('error', 'Bitte gültige Daten angeben.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }
        if (mb_strlen($note) > DateInput::NOTE_MAX_LENGTH) {
            $this->addFlash('error', \sprintf('Die Notiz darf höchstens %d Zeichen lang sein.', DateInput::NOTE_MAX_LENGTH));

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

        if ([] !== $absences->findOverlappingForUser($user, $start, $end, null, [Absence::STATUS_OPEN, Absence::STATUS_APPROVED])) {
            $this->addFlash('error', 'Der Zeitraum überschneidet sich mit einem bestehenden Urlaub.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        $contract = $contracts->findForUser($user);
        $days = null !== $contract ? $calc->countAbsence($contract, $start, $end, $halfDay) : null;
        if (0.0 === $days) {
            $this->addFlash('error', 'Der Zeitraum enthält keine Arbeitstage (nur Wochenende oder Feiertage) – kein Urlaub nötig.');

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
        if (null !== $days) {
            $remaining = $vacation->summary($user, (int) $start->format('Y'))['remaining'];
            if ($days > $remaining) {
                $this->addFlash('warning', \sprintf('Hinweis: Der Antrag (%s Tage) übersteigt deinen Resturlaub für %s (%s Tage).', self::num($days), $start->format('Y'), self::num($remaining)));
            }
        }

        return new RedirectResponse($this->generateUrl('worktime_vacation'));
    }

    #[Route(path: '/{id}/cancel', name: 'worktime_vacation_cancel', methods: ['POST'])]
    #[IsGranted('worktime_edit_own')]
    public function cancel(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        if (!$this->isCsrfTokenValid('worktime.vacation', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        /** @var User $user */
        $user = $this->getUser();
        $absence = $absences->find($id);
        if (null === $absence || $absence->getUser()->getId() !== $user->getId()) {
            throw $this->createNotFoundException();
        }

        // Offener (noch nicht entschiedener) Antrag: sofort zurückziehen, keine Freigabe nötig
        if (Absence::STATUS_OPEN === $absence->getStatus()) {
            $absence->setStatus(Absence::STATUS_CANCELLED);
            $absence->setCancelRequested(false);
            $absences->save($absence);

            $audit->log($user, $user, AuditLog::ACTION_ABSENCE_CANCEL, 'absence', $absence->getId(), [
                'start' => $absence->getStartDate()->format('Y-m-d'),
                'end' => $absence->getEndDate()->format('Y-m-d'),
            ]);

            $this->addFlash('success', 'Antrag zurückgezogen.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        $today = (new \DateTimeImmutable('today', new \DateTimeZone($user->getTimezone())))->format('Y-m-d');
        $cancellable = Absence::STATUS_APPROVED === $absence->getStatus()
            && !$absence->isCancelRequested()
            && $absence->getStartDate()->format('Y-m-d') > $today;

        if (!$cancellable) {
            $this->addFlash('error', 'Dieser Urlaub kann nicht (mehr) storniert werden.');

            return new RedirectResponse($this->generateUrl('worktime_vacation'));
        }

        $absence->setCancelRequested(true);
        $absences->save($absence);

        $audit->log($user, $user, AuditLog::ACTION_ABSENCE_CANCEL_REQUEST, 'absence', $absence->getId(), [
            'start' => $absence->getStartDate()->format('Y-m-d'),
            'end' => $absence->getEndDate()->format('Y-m-d'),
        ]);

        $this->addFlash('success', 'Stornierung beantragt.');

        return new RedirectResponse($this->generateUrl('worktime_vacation'));
    }

    private static function num(float $days): string
    {
        return number_format($days, 1, ',', '.');
    }
}
