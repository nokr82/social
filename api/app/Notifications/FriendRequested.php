<?php

namespace App\Notifications;

/** 친구 요청 받음. 문구는 Notify에서 만든다 */
class FriendRequested extends AppNotification
{
    public function group(): string
    {
        return 'friend';
    }
}
