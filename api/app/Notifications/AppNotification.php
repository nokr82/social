<?php

namespace App\Notifications;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * 모든 앱 알림의 공통 틀.
 * - 내용(payload)은 만들 때 한 번 계산해 배열로 들고 다닌다 → 큐 직렬화가 단순하고, 보낸 시점의 닉네임이 남음
 * - 채널: 알림센터(database) + 실시간 배지(broadcast) + 폰 푸시(webpush)
 * - 푸시는 사용자 설정(종류별 켬/끔, 야간 무음)과 구독 여부에 따라서만 붙는다
 * - 큐(queue 컨테이너)에서 보내고, DB 트랜잭션이 확정된 뒤에만 보낸다 (롤백된 일로 알림이 가지 않게)
 */
abstract class AppNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** 알림센터에 남길지 (채팅 메시지는 채팅 탭에 안 읽음 표시가 있어 푸시만) */
    protected bool $inCenter = true;

    /**
     * @param  array{kind:string, title:string, body:string, url:string, actor?:?array, tag?:string}  $payload
     */
    public function __construct(protected array $payload)
    {
        $this->afterCommit();
    }

    /** 설정 그룹: friend | chat | meetup */
    abstract public function group(): string;

    public function via(User $notifiable): array
    {
        $channels = $this->inCenter ? ['database', 'broadcast'] : [];

        // 구독이 없으면 푸시 채널 자체를 건너뜀 (불필요한 큐 작업·외부 요청 방지)
        if ($notifiable->pushSubscriptions()->exists()
            && $notifiable->notificationSettingOrDefault()->allowsPush($this->group())) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    /** 알림센터에 저장되는 내용 */
    public function toArray(User $notifiable): array
    {
        return array_diff_key($this->payload, ['tag' => true]);
    }

    /** 실시간: 화면의 종 아이콘 숫자 +1, 토스트 */
    public function toBroadcast(User $notifiable): BroadcastMessage
    {
        return new BroadcastMessage($this->toArray($notifiable));
    }

    public function toWebPush(User $notifiable, $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->payload['title'])
            ->body($this->payload['body'])
            ->icon('/icons/app-icon-192.png')
            ->badge('/icons/badge-72.png')
            // 같은 tag면 폰 알림이 쌓이지 않고 최신 것으로 바뀜 (한 대화방 = 알림 1개)
            ->tag($this->payload['tag'] ?? $this->payload['kind'].'-'.$notification->id)
            ->data(['url' => $this->payload['url']])
            ->options(['TTL' => 60 * 60 * 6, 'urgency' => 'high']); // 6시간 안에 못 받으면 버림
    }
}
