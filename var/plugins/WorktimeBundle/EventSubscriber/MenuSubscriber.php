<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\EventSubscriber;

use App\Event\ConfigureMainMenuEvent;
use App\Utils\MenuItemModel;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

final class MenuSubscriber implements EventSubscriberInterface
{
    public function __construct(private readonly AuthorizationCheckerInterface $security)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Run after the core menu subscriber (priority 100) so the core
        // "Employment contract" (id "contract") parent already exists.
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', -100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if ($this->security->isGranted('worktime_view_own')) {
            // Nest the worktime entries under the core "Employment contract" menu.
            // If the current user lacks the core "hours" permission, that parent was
            // not created, so we add it ourselves to keep the entries reachable.
            $contract = $event->findById('contract');
            if (null === $contract) {
                $contract = new MenuItemModel('contract', 'work_contract', null, [], 'contract');
                $event->getMenu()->addChild($contract);
            }

            $contract->addChild(
                new MenuItemModel('worktime', 'Arbeitszeit', 'worktime_index', [], 'fas fa-business-time')
            );
            $contract->addChild(
                new MenuItemModel('worktime_vacation', 'Urlaub', 'worktime_vacation', [], 'fas fa-umbrella-beach')
            );
            $contract->addChild(
                new MenuItemModel('worktime_account', 'Zeitkonto', 'worktime_account', [], 'fas fa-scale-balanced')
            );

            if ($this->security->isGranted('worktime_manage')) {
                $contract->addChild(
                    new MenuItemModel('worktime_overview', 'Team-Übersicht', 'worktime_account_overview', [], 'fas fa-users')
                );
            }
        }

        if ($this->security->isGranted('worktime_manage')) {
            $event->getSystemMenu()->addChild(
                new MenuItemModel('worktime_absences_admin', 'Urlaubsanträge', 'worktime_absences_admin', [], 'fas fa-clipboard-check')
            );
        }
    }
}
