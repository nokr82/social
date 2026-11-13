<?php

namespace App\Notifications;

/**
 * 친구가 내 위치를 확인함 (위치정보법: 제3자 제공 시 정보주체에게 통보).
 * 알림센터에는 항상 남기고, 폰 푸시는 친구 알림 설정을 따른다. 같은 친구는 tag로 1개만 보이게.
 */
class LocationViewed extends AppNotification
{
    public function group(): string
    {
        return 'friend';
    }
}
