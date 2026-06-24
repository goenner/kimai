<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use App\Entity\User;
use App\Utils\PageSetup;
use KimaiPlugin\WorktimeBundle\Entity\Contract;
use KimaiPlugin\WorktimeBundle\Form\ContractType;
use KimaiPlugin\WorktimeBundle\Repository\ContractRepository;
use Symfony\Bridge\Doctrine\Attribute\MapEntity;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/profile/{username}/work-contract')]
#[IsGranted('worktime_manage')]
final class UserContractController extends AbstractController
{
    private const WEEKDAYS = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

    #[Route(path: '', name: 'worktime_user_contract', methods: ['GET', 'POST'])]
    public function edit(
        #[MapEntity(mapping: ['username' => 'username'])]
        User $profile,
        Request $request,
        ContractRepository $contracts,
    ): Response {
        $contract = $contracts->findForUser($profile);
        if (null === $contract) {
            $contract = new Contract();
            $contract->setUser($profile);
        }

        $form = $this->createForm(ContractType::class, $contract, [
            'action' => $this->generateUrl('worktime_user_contract', ['username' => $profile->getUserIdentifier()]),
            'method' => 'POST',
        ]);

        // Prefill hour fields (seconds -> hours) on GET
        if (!$request->isMethod('POST')) {
            foreach (self::WEEKDAYS as $iso => $name) {
                $form->get('workHours'.$name)->setData((int) round($contract->getWorkHoursForWeekday($iso) / 3600));
            }
        }

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            foreach (self::WEEKDAYS as $iso => $name) {
                $raw = $form->get('workHours'.$name)->getData();
                $hours = \is_int($raw) ? $raw : 0;
                $contract->setWorkHoursForWeekday($iso, $hours * 3600);
            }
            $contracts->save($contract);
            $this->addFlash('success', 'Vertrag gespeichert.');

            return $this->redirectToRoute('worktime_user_contract', ['username' => $profile->getUserIdentifier()]);
        }

        return $this->render('@Worktime/contract/profile.html.twig', [
            'tab' => 'worktime_contract',
            'page_setup' => $this->createPageSetup($profile),
            'user' => $profile,
            'form' => $form->createView(),
        ]);
    }

    private function createPageSetup(User $profile): PageSetup
    {
        $page = new PageSetup('users');
        $page->setActionName('user');
        $page->setActionView('worktime_contract');
        $page->setActionPayload(['user' => $profile]);

        return $page;
    }
}
