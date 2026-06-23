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
        return [ConfigureMainMenuEvent::class => ['onMenuConfigure', 100]];
    }

    public function onMenuConfigure(ConfigureMainMenuEvent $event): void
    {
        if ($this->security->isGranted('worktime_view_own')) {
            $event->getMenu()->addChild(
                new MenuItemModel('worktime', 'Arbeitszeit', 'worktime_index', [], 'fas fa-business-time')
            );
            $event->getMenu()->addChild(
                new MenuItemModel('worktime_vacation', 'Urlaub', 'worktime_vacation', [], 'fas fa-umbrella-beach')
            );
        }

        if ($this->security->isGranted('worktime_manage')) {
            $event->getSystemMenu()->addChild(
                new MenuItemModel('worktime_absences_admin', 'Urlaubsanträge', 'worktime_absences_admin', [], 'fas fa-clipboard-check')
            );
        }
    }
}
