<?php

namespace App\Filament\Resources\UserSanctions;

use App\Filament\Resources\UserSanctions\Pages\ManageUserSanctions;
use App\Models\UserSanction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** 제재 이력 (읽기 전용): 누가·언제·왜. 분쟁·문의 대응용 */
class UserSanctionResource extends Resource
{
    protected static ?string $model = UserSanction::class;

    public static function getNavigationLabel(): string
    {
        return '제재 이력';
    }

    public static function getModelLabel(): string
    {
        return '제재 이력';
    }

    public static function getPluralModelLabel(): string
    {
        return '제재 이력';
    }

    public static function getNavigationIcon(): Heroicon
    {
        return Heroicon::ClipboardDocumentList;
    }

    public static function getNavigationSort(): ?int
    {
        return 3;
    }

    // 이력은 고치거나 지울 수 없게
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'admin']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label('일시')->dateTime('Y-m-d H:i')->sortable(),
                TextColumn::make('user.nickname')->label('회원')->searchable(),
                TextColumn::make('type')->label('종류')->badge()
                    ->formatStateUsing(fn (string $state) => ['suspend' => '정지', 'unsuspend' => '해제', 'ban' => '영구정지'][$state] ?? $state)
                    ->color(fn (string $state) => ['suspend' => 'warning', 'unsuspend' => 'success', 'ban' => 'danger'][$state] ?? 'gray'),
                TextColumn::make('until')->label('정지 기한')->dateTime('Y-m-d H:i')
                    ->placeholder(fn (UserSanction $record) => $record->type === 'suspend' ? '무기한' : '—'),
                TextColumn::make('reason')->label('사유')->limit(50)->placeholder('—'),
                TextColumn::make('admin.name')->label('처리')->placeholder('시스템'),
            ])
            ->filters([
                SelectFilter::make('type')->label('종류')->options(['suspend' => '정지', 'unsuspend' => '해제', 'ban' => '영구정지']),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUserSanctions::route('/')];
    }
}
