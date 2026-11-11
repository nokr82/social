<?php

namespace App\Notifications;

/** DM·번개방 새 메시지 (푸시만, 알림센터 X — 채팅 탭에 안 읽음 표시가 있음). 문구는 Notify에서 만든다 */
class ChatMessagePushed extends AppNotification
{
    protected bool $inCenter = false;

    public function group(): string
    {
        return 'chat';
    }
}
