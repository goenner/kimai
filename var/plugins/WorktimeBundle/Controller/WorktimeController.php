<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\Controller;

use App\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(path: '/worktime')]
#[IsGranted('worktime_view_own')]
final class WorktimeController extends AbstractController
{
    #[Route(path: '', name: 'worktime_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('@Worktime/index.html.twig', []);
    }
}
