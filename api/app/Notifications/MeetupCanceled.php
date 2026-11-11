<?php

namespace App\Notifications;

/** 호스트가 번개를 취소함 (참여자에게). 문구는 Notify에서 만든다 */
class MeetupCanceled extends AppNotification
{
    public function group(): string
    {
        return 'meetup';
    }
}
