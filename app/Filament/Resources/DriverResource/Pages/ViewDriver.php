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

    public function getTitle(): string
    {
        return $this->record->name;
    }

    public function getSubheading(): ?string
    {
        return 'Hồ sơ tài xế · '.$this->record->phone.' · '.($this->record->city?->name ?? 'Chưa có khu vực');
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make()->label('Chỉnh sửa hồ sơ')->icon('heroicon-o-pencil-square'),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Thông tin cá nhân')
                ->icon('heroicon-o-identification')
                ->schema([
                    Infolists\Components\ImageEntry::make('profile_photo_path')
                        ->label('Ảnh đại diện')
                        ->disk('public')
                        ->circular()
                        ->defaultImageUrl('https://ui-avatars.com/api/?name=Driver&background=f97316&color=fff'),
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
                            0 => 'Chờ duyệt',
                            1 => 'Hoạt động',
                            2 => 'Bị khóa',
                            default => 'Không rõ',
                        })
                        ->color(fn ($state) => match ((int) $state) {
                            0 => 'warning',
                            1 => 'success',
                            2 => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('created_at')
                        ->label('Ngày đăng ký')
                        ->dateTime('d/m/Y H:i'),
                ])->columns(3),

            Infolists\Components\Section::make('Trạng thái vận hành')
                ->icon('heroicon-o-signal')
                ->schema([
                    Infolists\Components\TextEntry::make('is_online')
                        ->label('Kết nối')
                        ->badge()
                        ->formatStateUsing(fn ($state) => $state ? 'Online' : 'Offline')
                        ->color(fn ($state) => $state ? 'success' : 'gray'),
                    Infolists\Components\TextEntry::make('online_since')
                        ->label('Online từ')
                        ->dateTime('H:i · d/m/Y')
                        ->placeholder('—'),
                    Infolists\Components\TextEntry::make('driver_score')
                        ->label('Điểm hoạt động')
                        ->badge()
                        ->default(80)
                        ->color(fn ($state) => ($state ?? 80) >= 80 ? 'success' : (($state ?? 80) >= 60 ? 'info' : 'warning')),
                    Infolists\Components\TextEntry::make('registeredShifts.name')
                        ->label('Ca đăng ký')
                        ->badge()
                        ->color('info')
                        ->placeholder('Chưa đăng ký'),
                    Infolists\Components\TextEntry::make('active_orders_count')
                        ->label('Đơn đang chạy')
                        ->default(0),
                    Infolists\Components\TextEntry::make('completed_orders_count')
                        ->label('Đơn hoàn thành')
                        ->default(0),
                ])->columns(3),

            Infolists\Components\Section::make('Phương tiện')
                ->icon('heroicon-o-truck')
                ->schema([
                    Infolists\Components\TextEntry::make('vehicle_type')->label('Loại phương tiện')->placeholder('—'),
                    Infolists\Components\TextEntry::make('license_plate')->label('Biển số xe')->placeholder('—'),
                    Infolists\Components\IconEntry::make('has_car_license')->label('Bằng lái')->boolean(),
                ])->columns(3),

            Infolists\Components\Section::make('Tình trạng hồ sơ')
                ->icon('heroicon-o-document-check')
                ->schema([
                    Infolists\Components\TextEntry::make('latestDriverCccdImage.status')
                        ->label('CCCD / CMND')
                        ->default('none')
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'approved' => 'Đã duyệt',
                            'pending' => 'Chờ duyệt',
                            'rejected' => 'Từ chối',
                            default => 'Chưa tải lên',
                        })
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'approved' => 'success',
                            'pending' => 'warning',
                            'rejected' => 'danger',
                            default => 'gray',
                        }),
                    Infolists\Components\TextEntry::make('latestDriverLicense.status')
                        ->label('Bằng lái')
                        ->default('none')
                        ->formatStateUsing(fn ($state) => match ($state) {
                            'approved' => 'Đã duyệt',
                            'pending' => 'Chờ duyệt',
                            'rejected' => 'Từ chối',
                            default => 'Chưa tải lên',
                        })
                        ->badge()
                        ->color(fn ($state) => match ($state) {
                            'approved' => 'success',
                            'pending' => 'warning',
                            'rejected' => 'danger',
                            default => 'gray',
                        }),
                ])->columns(2),

        ]);
    }
}
