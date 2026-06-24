<?php

/*
 * This file is part of the WorktimeBundle for Kimai.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\WorktimeBundle\EventSubscriber\Actions;

use App\Entity\User;
use App\Event\PageActionsEvent;
use App\EventSubscriber\Actions\AbstractActionsSubscriber;

final class ContractTabSubscriber extends AbstractActionsSubscriber
{
    public static function getActionName(): string
    {
        return 'user_forms';
    }

    public function onActions(PageActionsEvent $event): void
    {
        $payload = $event->getPayload();

        /** @var User $user */
        $user = $payload['user'];

        if (null === $user->getId()) {
            return;
        }

        if (!$this->isGranted('worktime_manage')) {
            return;
        }

        $event->addAction('worktime_contract', [
            'url' => $this->path('worktime_user_contract', ['username' => $user->getUserIdentifier()]),
            'title' => 'Arbeitszeit-Vertrag',
        ]);
    }
}
