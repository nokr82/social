<?php

namespace App\Notifications;

/** 번개 1시간 전 (참여자 전원). 문구는 Notify에서 만든다 */
class MeetupReminder extends AppNotification
{
    public function group(): string
    {
        return 'meetup';
    }
}
