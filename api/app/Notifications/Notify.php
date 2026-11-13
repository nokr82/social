<?php

namespace App\Notifications;

use App\Http\Resources\AvatarSummary;
use App\Models\Meetup;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/**
 * 알림 보내기 창구. 서비스 코드는 "무슨 일이 있었는지"만 알리고,
 * 문구·이동 경로·묶음(tag)은 전부 여기서 정한다 (문구를 고칠 때 이 파일만 보면 됨).
 */
class Notify
{
    public static function friendRequested(User $from, User $to, ?string $message): void
    {
        $to->notify(new FriendRequested([
            'kind' => 'friend_request',
            'title' => '새 친구 요청',
            'body' => $message ? "{$from->nickname}: ".Str::limit($message, 40) : "{$from->nickname}님이 친구 요청을 보냈어요",
            'url' => '/friends/requests',
            'actor' => AvatarSummary::of($from),
        ]));
    }

    public static function friendAccepted(User $by, User $to): void
    {
        $to->notify(new FriendAccepted([
            'kind' => 'friend_accepted',
            'title' => '친구가 됐어요',
            'body' => "{$by->nickname}님이 친구 요청을 수락했어요. 이제 비공개 사진도 볼 수 있어요",
            'url' => "/users/{$by->id}",
            'actor' => AvatarSummary::of($by),
        ]));
    }

    public static function locationShareStarted(User $sharer, User $viewer, ?\DateTimeInterface $until): void
    {
        $span = $until ? '' : ' (직접 끌 때까지)';
        $viewer->notify(new LocationShareStarted([
            'kind' => 'location_share_started',
            'title' => '위치 공유',
            'body' => "{$sharer->nickname}님이 나에게 위치를 공유하기 시작했어요{$span}",
            'url' => '/map',
            'actor' => AvatarSummary::of($sharer),
            'tag' => "loc-share-{$sharer->id}",
        ]));
    }

    /** 위치정보법상 '제공 사실 통보'. 같은 친구는 일정 시간에 1번만 (LocationShareService가 조절) */
    public static function locationViewed(User $viewer, User $sharer): void
    {
        $sharer->notify(new LocationViewed([
            'kind' => 'location_viewed',
            'title' => '위치 확인',
            'body' => "{$viewer->nickname}님이 내 위치를 확인했어요",
            'url' => '/map',
            'actor' => AvatarSummary::of($viewer),
            'tag' => "loc-viewed-{$viewer->id}",
        ]));
    }

    public static function meetupJoined(Meetup $meetup, User $joiner): void
    {
        if ($joiner->id === $meetup->host_id) {
            return;
        }
        $meetup->host?->notify(new MeetupJoined([
            'kind' => 'meetup_joined',
            'title' => $meetup->title,
            'body' => "{$joiner->nickname}님이 번개에 참여했어요",
            'url' => "/meetups/{$meetup->id}",
            'actor' => AvatarSummary::of($joiner),
            'tag' => "meetup-joined-{$meetup->id}",
        ]));
    }

    /** @param  iterable<User>  $members  호스트 제외 참여자 */
    public static function meetupCanceled(Meetup $meetup, iterable $members): void
    {
        Notification::send($members, new MeetupCanceled([
            'kind' => 'meetup_canceled',
            'title' => $meetup->title,
            'body' => '호스트가 번개를 취소했어요',
            'url' => "/meetups/{$meetup->id}",
            'actor' => AvatarSummary::of($meetup->host),
        ]));
    }

    /** @param  iterable<User>  $members  호스트 포함 참여자 전원 */
    public static function meetupReminder(Meetup $meetup, iterable $members): void
    {
        Notification::send($members, new MeetupReminder([
            'kind' => 'meetup_reminder',
            'title' => $meetup->title,
            'body' => '1시간 뒤에 만나요! 장소와 시간을 한 번 더 확인해 주세요',
            'url' => "/meetups/{$meetup->id}/room",
            'actor' => null,
        ]));
    }

    public static function dm(User $from, User $to, int $roomId, string $body): void
    {
        $to->notify(new ChatMessagePushed([
            'kind' => 'dm',
            'title' => $from->nickname,
            'body' => Str::limit($body, 60),
            'url' => "/chats/{$roomId}",
            'tag' => "dm-{$roomId}",
        ]));
    }

    /** @param  iterable<User>  $members  보낸 사람 제외 참여자 */
    public static function meetupMessage(Meetup $meetup, User $from, iterable $members, string $body): void
    {
        Notification::send($members, new ChatMessagePushed([
            'kind' => 'meetup_message',
            'title' => $meetup->title,
            'body' => "{$from->nickname}: ".Str::limit($body, 50),
            'url' => "/meetups/{$meetup->id}/room",
            'tag' => "meetup-{$meetup->id}",
        ]));
    }
}
