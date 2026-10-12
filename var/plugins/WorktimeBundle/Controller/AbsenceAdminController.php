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
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/worktime/absences')]
#[IsGranted('worktime_manage')]
final class AbsenceAdminController extends AbstractController
{
    private function redirectIndex(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('worktime_absences_admin'));
    }

    /**
     * Build a display row for an absence: days + all overlapping open/approved
     * absences of any user in the same period (decision aid for the admin),
     * plus the remaining vacation of that year after the decision (remaining_after;
     * null without contract): Antrag genehmigt → Rest − Tage, Storno genehmigt → Rest + Tage.
     *
     * @return array{absence: Absence, days: float, conflicts: Absence[], remaining_after: float|null}
     */
    private function buildRow(Absence $a, AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc, VacationAccount $vacation): array
    {
        $contract = $contracts->findForUser($a->getUser());
        $days = null !== $contract ? $calc->countAbsence($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay()) : 0.0;
        $remainingAfter = null;
        if (null !== $contract) {
            $remaining = $vacation->summary($a->getUser(), (int) $a->getStartDate()->format('Y'))['remaining'];
            $remainingAfter = match ($a->getStatus()) {
                Absence::STATUS_OPEN => $remaining - $days,
                Absence::STATUS_APPROVED => $a->isCancelRequested() ? $remaining + $days : $remaining,
                default => $remaining,
            };
        }

        return [
            'absence' => $a,
            'days' => $days,
            'conflicts' => $absences->findOverlapping($a->getStartDate(), $a->getEndDate(), $a->getId(), [Absence::STATUS_OPEN, Absence::STATUS_APPROVED]),
            'remaining_after' => $remainingAfter,
        ];
    }

    #[Route(path: '', name: 'worktime_absences_admin', methods: ['GET'])]
    public function index(AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc, VacationAccount $vacation): Response
    {
        $pending = [];
        foreach ($absences->findPending() as $a) {
            if ($a->isCancelRequested()) {
                continue; // shown in the cancellation list instead
            }
            $pending[] = $this->buildRow($a, $absences, $contracts, $calc, $vacation);
        }

        $cancellations = [];
        foreach ($absences->findCancellationRequests() as $a) {
            $cancellations[] = $this->buildRow($a, $absences, $contracts, $calc, $vacation);
        }

        return $this->render('@Worktime/vacation/admin.html.twig', [
            'rows' => $pending,
            'cancellations' => $cancellations,
        ]);
    }

    #[Route(path: '/{id}/approve', name: 'worktime_absences_approve', methods: ['POST'])]
    public function approve(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        return $this->decide($id, $request, $absences, $audit, Absence::STATUS_APPROVED);
    }

    #[Route(path: '/{id}/reject', name: 'worktime_absences_reject', methods: ['POST'])]
    public function reject(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        return $this->decide($id, $request, $absences, $audit, Absence::STATUS_REJECTED);
    }

    private function decide(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit, string $status): Response
    {
        if (!$this->isCsrfTokenValid('worktime.absence', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        $absence = $absences->find($id);
        if (null === $absence) {
            throw $this->createNotFoundException();
        }

        if (Absence::STATUS_OPEN !== $absence->getStatus()) {
            $this->addFlash('error', 'Dieser Antrag wurde bereits entschieden.');

            return $this->redirectIndex();
        }
        if ($absence->isCancelRequested()) {
            $this->addFlash('error', 'Für diesen Antrag liegt eine Stornierung vor – bitte über die Stornierungs-Liste entscheiden.');

            return $this->redirectIndex();
        }

        // Robust overlap guard: re-check against already approved absences of the
        // same user (another request may have been approved since this one was filed).
        if (Absence::STATUS_APPROVED === $status
            && [] !== $absences->findOverlappingForUser($absence->getUser(), $absence->getStartDate(), $absence->getEndDate(), $absence->getId(), [Absence::STATUS_APPROVED])) {
            $this->addFlash('error', 'Genehmigung nicht möglich: überschneidet sich mit einem bereits genehmigten Urlaub dieses Mitarbeiters.');

            return $this->redirectIndex();
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $absence->setStatus($status);
        $absence->setDecidedBy($admin);
        $absence->setDecidedAt(new \DateTimeImmutable('now'));
        $absences->save($absence);

        $action = Absence::STATUS_APPROVED === $status ? AuditLog::ACTION_ABSENCE_APPROVE : AuditLog::ACTION_ABSENCE_REJECT;
        $audit->log($admin, $absence->getUser(), $action, 'absence', $absence->getId(), [
            'start' => $absence->getStartDate()->format('Y-m-d'),
            'end' => $absence->getEndDate()->format('Y-m-d'),
        ]);

        $this->addFlash('success', Absence::STATUS_APPROVED === $status ? 'Antrag genehmigt.' : 'Antrag abgelehnt.');

        return $this->redirectIndex();
    }

    #[Route(path: '/{id}/cancel-approve', name: 'worktime_absences_cancel_approve', methods: ['POST'])]
    public function cancelApprove(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        return $this->decideCancellation($id, $request, $absences, $audit, true);
    }

    #[Route(path: '/{id}/cancel-reject', name: 'worktime_absences_cancel_reject', methods: ['POST'])]
    public function cancelReject(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit): Response
    {
        return $this->decideCancellation($id, $request, $absences, $audit, false);
    }

    private function decideCancellation(int $id, Request $request, AbsenceRepository $absences, AuditLogger $audit, bool $approve): Response
    {
        if (!$this->isCsrfTokenValid('worktime.absence', (string) $request->request->get('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $this->redirectIndex();
        }

        $absence = $absences->find($id);
        if (null === $absence) {
            throw $this->createNotFoundException();
        }

        if (!$absence->isCancelRequested()) {
            $this->addFlash('error', 'Für diesen Urlaub liegt keine Stornierungs-Anfrage vor.');

            return $this->redirectIndex();
        }

        /** @var User $admin */
        $admin = $this->getUser();
        $absence->setCancelRequested(false);
        if ($approve) {
            $absence->setStatus(Absence::STATUS_CANCELLED);
            $absence->setDecidedBy($admin);
            $absence->setDecidedAt(new \DateTimeImmutable('now'));
        }
        $absences->save($absence);

        $audit->log($admin, $absence->getUser(), $approve ? AuditLog::ACTION_ABSENCE_CANCEL_APPROVE : AuditLog::ACTION_ABSENCE_CANCEL_REJECT, 'absence', $absence->getId(), [
            'start' => $absence->getStartDate()->format('Y-m-d'),
            'end' => $absence->getEndDate()->format('Y-m-d'),
        ]);

        $this->addFlash('success', $approve ? 'Stornierung genehmigt.' : 'Stornierung abgelehnt.');

        return $this->redirectIndex();
    }
}
