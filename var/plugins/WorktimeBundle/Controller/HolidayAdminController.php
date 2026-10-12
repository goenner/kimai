<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use KimaiPlugin\WorktimeBundle\Audit\AuditLogger;
use KimaiPlugin\WorktimeBundle\Calculator\HolidayCalendar;
use KimaiPlugin\WorktimeBundle\Entity\AuditLog;
use KimaiPlugin\WorktimeBundle\Entity\FreeDay;
use KimaiPlugin\WorktimeBundle\Repository\FreeDayRepository;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Gesetzliche Feiertage je Bundesland ansehen, freie ganze/halbe Tage für alle oder ein Bundesland pflegen.
 */
#[Route(path: '/admin/worktime/holidays')]
#[IsGranted('worktime_manage')]
final class HolidayAdminController extends AbstractController
{
    #[Route(path: '', name: 'worktime_holidays_admin', methods: ['GET'])]
    public function index(Request $request, HolidayCalendar $calendar, FreeDayRepository $freeDays): Response
    {
        $year = $request->query->getInt('year', (int) date('Y'));
        if ($year < 2000 || $year > 2100) {
            $year = (int) date('Y');
        }
        $region = HolidayCalendar::normalizeRegion($request->query->getString('region', HolidayCalendar::DEFAULT_REGION));

        return $this->render('@Worktime/holiday/admin.html.twig', [
            'year' => $year,
            'region' => $region,
            'regions' => HolidayCalendar::REGIONS,
            'holidays' => $calendar->holidays($year, $region),
            'free_days' => $freeDays->findBetween(new \DateTimeImmutable($year.'-01-01'), new \DateTimeImmutable($year.'-12-31')),
        ]);
    }

    #[Route(path: '', name: 'worktime_holidays_add', methods: ['POST'])]
    public function add(Request $request, FreeDayRepository $freeDays, AuditLogger $audit): Response
    {
        $back = $this->back($request);
        if (!$this->isCsrfTokenValid('worktime.holiday', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $back;
        }

        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $request->request->getString('day'));
        $name = mb_substr(trim($request->request->getString('name')), 0, 100);
        $region = $request->request->getString('region');
        if (false === $day || $day->format('Y-m-d') !== $request->request->getString('day') || '' === $name) {
            $this->addFlash('error', 'Bitte Datum und Bezeichnung angeben.');

            return $back;
        }
        if ('' !== $region && !isset(HolidayCalendar::REGIONS[$region])) {
            $this->addFlash('error', 'Unbekanntes Bundesland.');

            return $back;
        }

        $free = new FreeDay($day, $name, $request->request->getBoolean('half_day'), '' === $region ? null : $region);
        $freeDays->save($free);
        $this->logChange($audit, AuditLog::ACTION_FREE_DAY_ADD, $free);
        $this->addFlash('success', \sprintf('Freier Tag „%s" am %s eingetragen.', $name, $day->format('d.m.Y')));
        $this->warnIfPast($day);

        return $back;
    }

    #[Route(path: '/{id}/delete', name: 'worktime_holidays_delete', methods: ['POST'])]
    public function delete(int $id, Request $request, FreeDayRepository $freeDays, AuditLogger $audit): Response
    {
        $back = $this->back($request);
        if (!$this->isCsrfTokenValid('worktime.holiday', $request->request->getString('_token'))) {
            $this->addFlash('error', 'Ungültiges Token.');

            return $back;
        }
        $day = $freeDays->find($id);
        if (null === $day) {
            throw $this->createNotFoundException();
        }
        $this->logChange($audit, AuditLog::ACTION_FREE_DAY_DELETE, $day);
        $freeDays->delete($day);
        $this->addFlash('success', 'Freier Tag gelöscht.');
        $this->warnIfPast($day->getDay());

        return $back;
    }

    /** Freie Tage ändern Soll, Saldo und Urlaubsverbrauch aller Betroffenen — auch rückwirkend. */
    private function logChange(AuditLogger $audit, string $action, FreeDay $day): void
    {
        $audit->log($this->getUser(), null, $action, 'free_day', $day->getId(), [
            'day' => $day->getDay()->format('Y-m-d'),
            'name' => $day->getName(),
            'half_day' => $day->isHalfDay(),
            'region' => $day->getRegion(),
        ]);
    }

    private function warnIfPast(\DateTimeImmutable $day): void
    {
        if ($day->format('Y-m-d') < date('Y-m-d')) {
            $this->addFlash('warning', 'Das Datum liegt in der Vergangenheit: Zeitkonten und bereits genommener Urlaub werden rückwirkend neu berechnet.');
        }
    }

    private function back(Request $request): RedirectResponse
    {
        $year = $request->request->getInt('year', (int) date('Y'));

        return new RedirectResponse($this->generateUrl('worktime_holidays_admin', [
            'year' => $year >= 2000 && $year <= 2100 ? $year : (int) date('Y'),
            'region' => HolidayCalendar::normalizeRegion($request->request->getString('view_region')),
        ]));
    }
}
