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

#[Route(path: '/admin/worktime/absences')]
#[IsGranted('worktime_manage')]
final class AbsenceAdminController extends AbstractController
{
    private function redirectIndex(): RedirectResponse
    {
        return new RedirectResponse($this->generateUrl('worktime_absences_admin'));
    }

    #[Route(path: '', name: 'worktime_absences_admin', methods: ['GET'])]
    public function index(AbsenceRepository $absences, ContractRepository $contracts, VacationCalculator $calc): Response
    {
        $rows = [];
        foreach ($absences->findPending() as $a) {
            $contract = $contracts->findForUser($a->getUser());
            $rows[] = [
                'absence' => $a,
                'days' => null !== $contract ? $calc->countDays($contract, $a->getStartDate(), $a->getEndDate(), $a->isHalfDay()) : 0.0,
            ];
        }

        return $this->render('@Worktime/vacation/admin.html.twig', ['rows' => $rows]);
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
}
