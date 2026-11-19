<?php

namespace App\Notifications;

/**
 * 번개 참여자가 내 위치를 확인함 (위치정보법: 제3자 제공 시 정보주체에게 통보).
 * 알림센터에는 사람마다 남기고, 폰 푸시는 번개 알림 설정을 따르며 번개마다 tag로 1개만 보이게(참여자가 여럿이라 쌓이지 않게).
 */
class MeetupLocationViewed extends AppNotification
{
    public function group(): string
    {
        return 'meetup';
    }
}
