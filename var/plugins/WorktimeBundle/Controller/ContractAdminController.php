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
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Form\ContractType;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/admin/worktime/contracts')]
#[IsGranted('worktime_manage')]
final class ContractAdminController extends AbstractController
{
    #[Route(path: '', name: 'worktime_admin_contracts', methods: ['GET'])]
    public function index(UserRepository $userRepository, ContractRepository $contracts): Response
    {
        $users = $userRepository->findBy([], ['username' => 'ASC']);
        $rows = [];
        foreach ($users as $user) {
            $rows[] = ['user' => $user, 'contract' => $contracts->findForUser($user)];
        }

        return $this->render('@Worktime/contract/index.html.twig', ['rows' => $rows]);
    }

    #[Route(path: '/{id}/edit', name: 'worktime_admin_contract_edit', methods: ['GET', 'POST'])]
    public function edit(#[MapEntity(id: 'id')] User $user, Request $request, ContractRepository $contracts): Response
    {
        $contract = $contracts->findForUser($user);
        if ($contract === null) {
            $contract = new Contract();
            $contract->setUser($user);
        }

        $form = $this->createForm(ContractType::class, $contract);

        // Prefill hour fields (seconds -> hours) on GET
        $weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        if (!$request->isMethod('POST')) {
            foreach ($weekdays as $iso => $name) {
                $form->get('workHours' . $name)->setData((int) round($contract->getWorkHoursForWeekday($iso) / 3600));
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach ($weekdays as $iso => $name) {
                $hours = (int) ($form->get('workHours' . $name)->getData() ?? 0);
                $contract->setWorkHoursForWeekday($iso, $hours * 3600);
            }
            $contracts->save($contract);
            $this->addFlash('success', 'Vertrag gespeichert.');

            return new RedirectResponse($this->generateUrl('worktime_admin_contracts'));
        }

        return $this->render('@Worktime/contract/edit.html.twig', [
            'user' => $user,
            'form' => $form->createView(),
        ]);
    }
}
