<?php

namespace App\Filament\Resources\DriverResource\Pages;

use App\Filament\Resources\DriverResource;
use Filament\Actions;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewDriver extends ViewRecord
{
    protected static string $resource = DriverResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin cá nhân')
                ->schema([
                    Infolists\Components\TextEntry::make('name')
                        ->label('Họ tên'),
                    Infolists\Components\TextEntry::make('phone')
                        ->label('Số điện thoại')
                        ->copyable(),
                    Infolists\Components\TextEntry::make('cccd')
                        ->label('CCCD / CMND')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('city.name')
                        ->label('Thành phố')
                        ->default('—'),
                    Infolists\Components\TextEntry::make('status')
                        ->label('Trạng thái tài khoản')
                        ->badge()
                        ->formatStateUsing(fn ($state) => match ((int) $state) {
                            0       => 'Chờ duyệt',
                            1       => 'Hoạt động',
                            2       => 'Bị khóa',
                            default => 'Không rõ',
                        })
                        ->color(fn ($state) => match ((int) $state) {
                            0       => 'warning',
                            1       => 'success',
                            2       => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Ngày đăng ký')
                        ->dateTime('d/m/Y H:i'),
                ])->columns(2),

        ]);
    }
}
