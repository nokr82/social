<?php

namespace App\Notifications;

/** 친구가 나에게 위치 공유를 시작함. 문구는 Notify에서 만든다 */
class LocationShareStarted extends AppNotification
{
    public function group(): string
    {
        return 'friend';
    }
}
