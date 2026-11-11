<?php

namespace App\Filament\Resources\Reports;

use App\Exceptions\DomainException;
use App\Filament\Resources\Reports\Pages\ManageReports;
use App\Models\ChatMessage;
use App\Models\Plaza;
use App\Services\Plaza\PlazaService;
use App\Models\Report;
use App\Services\Moderation\ModerationService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
 * 신고 처리 화면.
 * 목록 → 행의 [승인]/[반려] 버튼 → 모달에서 조치 선택 → ModerationService가 실제 처리.
 * 화면은 입력만 받고, 규칙(감점·정지·토큰 폐기·이력)은 전부 서비스에 있다 (테스트된 경로 재사용).
 */
class ReportResource extends Resource
{
    protected static ?string $model = Report::class;

    public static function getNavigationLabel(): string
    {
        return '신고';
    }

    public static function getModelLabel(): string
    {
        return '신고';
    }

    public static function getPluralModelLabel(): string
    {
        return '신고';
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::Flag;
    }

    public static function getNavigationSort(): ?int
    {
        return 1;
    }

    /** 메뉴 옆 숫자: 대기 중인 신고 수 */
    public static function getNavigationBadge(): ?string
    {
        $n = Report::where('status', Report::STATUS_PENDING)->count();

        return $n ? (string) $n : null;
    }

    public static function canCreate(): bool
    {
        return false; // 신고는 앱에서만 생성
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['reporter', 'target', 'handler']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('id')->label('#'),
                TextColumn::make('created_at')->label('접수')->since()->sortable(),
                TextColumn::make('target.nickname')->label('신고 대상')->searchable()
                    ->description(fn (Report $record) => self::statusLabel($record->target?->status)),
                TextColumn::make('reporter.nickname')->label('신고자'),
                TextColumn::make('reason')->label('사유')
                    ->formatStateUsing(fn (string $state) => config("social.report_reasons.{$state}", $state)),
                TextColumn::make('evidence')->label('신고된 내용')
                    ->state(fn (Report $record) => self::evidence($record))
                    ->limit(40)->tooltip(fn (Report $record) => self::evidence($record))->placeholder('—'),
                TextColumn::make('detail')->label('신고자 메모')->limit(30)->placeholder('—'),
                TextColumn::make('status')->label('상태')->badge()
                    ->formatStateUsing(fn (string $state) => ['pending' => '대기', 'accepted' => '승인', 'rejected' => '반려'][$state] ?? $state)
                    ->color(fn (string $state) => ['pending' => 'warning', 'accepted' => 'danger', 'rejected' => 'gray'][$state] ?? 'gray'),
                TextColumn::make('action')->label('조치')
                    ->formatStateUsing(fn (?string $state) => self::ACTION_LABELS[$state] ?? $state)->placeholder('—'),
                TextColumn::make('handler.name')->label('처리자')->placeholder('—'),
                TextColumn::make('admin_note')->label('처리 메모')->limit(30)->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('상태')
                    ->options(['pending' => '대기', 'accepted' => '승인', 'rejected' => '반려'])
                    ->default('pending'), // 들어오면 처리할 것부터
                SelectFilter::make('reason')->label('사유')->options(config('social.report_reasons')),
            ])
            ->recordActions([
                Action::make('accept')
                    ->label('승인')
                    ->icon(Heroicon::Check)
                    ->color('danger')
                    ->visible(fn (Report $record) => $record->status === Report::STATUS_PENDING)
                    ->modalHeading(fn (Report $record) => "{$record->target?->nickname}님 신고 승인")
                    ->modalDescription('승인하면 매너점수가 감점되고, 고른 조치가 바로 적용돼요. 정지·영구정지는 상대 앱 로그인이 즉시 끊겨요.')
                    ->schema([
                        Select::make('action')->label('조치')->required()->default('warn')
                            ->options(self::ACTION_LABELS),
                        TextInput::make('days')->label('정지 일수 (기간 정지일 때만, 비우면 무기한)')
                            ->numeric()->minValue(1)->maxValue(365)->default(7),
                        Textarea::make('note')->label('처리 메모 (내부용)')->maxLength(500),
                    ])
                    ->action(function (array $data, Report $record) {
                        self::run(fn (ModerationService $m) => $m->resolve(
                            auth('admin')->user(), $record, true, $data['action'],
                            filled($data['days'] ?? null) ? (int) $data['days'] : null, $data['note'] ?? null,
                        ), '신고를 승인했어요');
                    }),

                Action::make('reject')
                    ->label('반려')
                    ->icon(Heroicon::XMark)
                    ->color('gray')
                    ->visible(fn (Report $record) => $record->status === Report::STATUS_PENDING)
                    ->modalHeading('신고 반려')
                    ->modalDescription('조치 없이 종료해요. 신고 누적으로 자동 정지된 사람은 기준 아래로 내려가면 자동으로 풀려요.')
                    ->schema([
                        Textarea::make('note')->label('반려 사유 (내부용)')->maxLength(500),
                    ])
                    ->action(function (array $data, Report $record) {
                        self::run(fn (ModerationService $m) => $m->resolve(
                            auth('admin')->user(), $record, false, 'none', null, $data['note'] ?? null,
                        ), '신고를 반려했어요');
                    }),

                // 광장 신고: 승인·반려와 별개로 광장 자체를 바로 닫음 (접속자에게 '운영 정책에 따라 닫힘' 안내)
                Action::make('closePlaza')
                    ->label('광장 닫기')
                    ->icon(Heroicon::XMark)
                    ->color('danger')
                    ->visible(fn (Report $record) => $record->target_type === 'plaza'
                        && Plaza::whereKey($record->target_ref_id)->where('status', Plaza::STATUS_OPEN)->exists())
                    ->requiresConfirmation()
                    ->modalHeading('신고된 광장 닫기')
                    ->modalDescription('광장이 바로 닫히고 안에 있던 사람은 목록으로 나가요. 되돌릴 수 없어요. 신고 처리(승인·반려)는 따로 해 주세요.')
                    ->action(function (Report $record) {
                        $plaza = Plaza::find($record->target_ref_id);
                        $closed = $plaza && app(PlazaService::class)->close($plaza, 'admin');
                        Notification::make()->title($closed ? '광장을 닫았어요' : '이미 닫힌 광장이에요')
                            ->{$closed ? 'success' : 'warning'}()->send();
                    }),
            ]);
    }

    public const ACTION_LABELS = [
        'warn' => '경고 (감점만)',
        'suspend' => '기간 정지',
        'ban' => '영구정지',
        'none' => '기록만 (감점 없음)',
    ];

    public static function getPages(): array
    {
        return ['index' => ManageReports::route('/')];
    }

    /** 신고된 메시지 본문·광장 이름을 보여줘 관리자가 앱을 뒤지지 않아도 판단할 수 있게 */
    private static function evidence(Report $r): ?string
    {
        return match ($r->target_type) {
            'message' => ChatMessage::whereKey($r->target_ref_id)->value('body'),
            'plaza' => ($name = Plaza::whereKey($r->target_ref_id)->value('name')) ? "광장: {$name}" : null,
            default => null,
        };
    }

    private static function statusLabel(?string $status): string
    {
        return '현재: '.(['active' => '정상', 'suspended' => '정지', 'banned' => '영구정지', 'withdrawn' => '탈퇴'][$status] ?? '—');
    }

    /** 서비스 호출 + 결과 알림 (이미 처리된 신고 등 규칙 위반은 빨간 알림으로) */
    private static function run(\Closure $fn, string $ok): void
    {
        try {
            $fn(app(ModerationService::class));
            Notification::make()->title($ok)->success()->send();
        } catch (DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();
        }
    }
}
