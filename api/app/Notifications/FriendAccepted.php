<?php

namespace App\Notifications;

/** 친구 요청 수락됨. 문구는 Notify에서 만든다 */
class FriendAccepted extends AppNotification
{
    public function group(): string
    {
        return 'friend';
    }
}
