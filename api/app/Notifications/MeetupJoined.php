<?php

namespace App\Notifications;

/** 내 번개에 누가 참여함 (호스트에게). 문구는 Notify에서 만든다 */
class MeetupJoined extends AppNotification
{
    public function group(): string
    {
        return 'meetup';
    }
}
