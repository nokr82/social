<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ManageUsers;
use App\Models\User;
use App\Services\Moderation\ModerationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 회원 관리: 정지·해제·영구정지.
 * 개인정보 최소화 — 본인인증 정보(CI·생년월일)·위치는 보여주지 않는다.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    public static function getNavigationLabel(): string
    {
        return '회원';
    }

    public static function getModelLabel(): string
    {
        return '회원';
    }

    public static function getPluralModelLabel(): string
    {
        return '회원';
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::Users;
    }

    public static function getNavigationSort(): ?int
    {
        return 2;
    }

    public static function canCreate(): bool
    {
        return false; // 가입은 앱(본인인증)으로만
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('trustScore')
                ->withCount(['reportsReceived as pending_reports' => fn ($r) => $r->where('status', 'pending')]))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#')->sortable(),
                TextColumn::make('nickname')->label('닉네임')->searchable(),
                TextColumn::make('status')->label('상태')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => ['active' => 'success', 'suspended' => 'warning', 'banned' => 'danger'][$state] ?? 'gray')
                    ->description(fn (User $record) => $record->suspended_until ? $record->suspended_until->format('n/j H:i').'까지' : null),
                TextColumn::make('trustScore.score')->label('매너점수')->sortable(),
                TextColumn::make('pending_reports')->label('대기 신고')->badge()
                    ->color(fn (int $state) => $state ? 'warning' : 'gray'),
                TextColumn::make('trustScore.report_count')->label('승인된 신고'),
                TextColumn::make('trustScore.noshow_count')->label('노쇼'),
                TextColumn::make('last_seen_at')->label('최근 접속')->since()->sortable()->placeholder('—'),
                TextColumn::make('created_at')->label('가입')->date('Y-m-d')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('상태')->options(self::STATUS_LABELS),
            ])
            ->recordActions([
                Action::make('suspend')
                    ->label('정지')
                    ->icon(Heroicon::LockClosed)
                    ->color('warning')
                    ->visible(fn (User $record) => $record->status === User::STATUS_ACTIVE)
                    ->modalHeading(fn (User $record) => "{$record->nickname}님 이용 정지")
                    ->modalDescription('앱 로그인이 즉시 끊기고, 기간이 끝나면 자동으로 풀려요.')
                    ->schema([
                        TextInput::make('days')->label('정지 일수 (비우면 무기한)')->numeric()->minValue(1)->maxValue(365)->default(7),
                        Textarea::make('reason')->label('사유 (내부용)')->required()->maxLength(200),
                    ])
                    ->action(function (array $data, User $record) {
                        app(ModerationService::class)->suspend($record, auth('admin')->user(),
                            filled($data['days'] ?? null) ? (int) $data['days'] : null, $data['reason']);
                        Notification::make()->title('정지했어요')->success()->send();
                    }),

                Action::make('unsuspend')
                    ->label('정지 해제')
                    ->icon(Heroicon::LockOpen)
                    ->color('success')
                    ->visible(fn (User $record) => $record->status === User::STATUS_SUSPENDED)
                    ->schema([
                        Textarea::make('reason')->label('사유 (내부용)')->required()->maxLength(200),
                    ])
                    ->action(function (array $data, User $record) {
                        app(ModerationService::class)->unsuspend($record, auth('admin')->user(), $data['reason']);
                        Notification::make()->title('정지를 풀었어요')->success()->send();
                    }),

                Action::make('ban')
                    ->label('영구정지')
                    ->icon(Heroicon::NoSymbol)
                    ->color('danger')
                    ->visible(fn (User $record) => in_array($record->status, [User::STATUS_ACTIVE, User::STATUS_SUSPENDED], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $record) => "{$record->nickname}님 영구정지")
                    ->modalDescription('되돌릴 수 없어요. 본인인증 정보가 차단 명단에 올라 탈퇴 후 재가입도 막혀요.')
                    ->schema([
                        Textarea::make('reason')->label('사유 (내부용)')->required()->maxLength(200),
                    ])
                    ->action(function (array $data, User $record) {
                        app(ModerationService::class)->ban($record, auth('admin')->user(), $data['reason']);
                        Notification::make()->title('영구정지했어요')->success()->send();
                    }),
            ]);
    }

    public const STATUS_LABELS = ['active' => '정상', 'suspended' => '정지', 'banned' => '영구정지', 'withdrawn' => '탈퇴'];

    public static function getPages(): array
    {
        return ['index' => ManageUsers::route('/')];
    }
}
