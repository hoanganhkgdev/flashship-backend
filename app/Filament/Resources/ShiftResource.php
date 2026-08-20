<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ShiftResource\Pages;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Core\Models\Shift;

class ShiftResource extends Resource
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin', 'city_manager']);
    }

    protected static ?string $model          = Shift::class;
    protected static ?string $navigationIcon = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Cấu hình';
    protected static ?int    $navigationSort  = 2;
    protected static ?string $label          = 'Ca làm việc';
    protected static ?string $pluralLabel    = 'Ca làm việc';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin ca')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Tên ca')
                        ->required()
                        ->placeholder('VD: Ca sáng'),

                    Forms\Components\TextInput::make('code')
                        ->label('Mã ca')
                        ->required()
                        ->alphaDash()
                        ->helperText('VD: morning — không dấu, không khoảng trắng, duy nhất trong khu vực này')
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where('city_id', Filament::getTenant()?->id),
                        ),

                    Forms\Components\TimePicker::make('start_time')
                        ->label('Bắt đầu')
                        ->seconds(false)
                        ->required()
                        ->helperText('⚠️ Đổi giờ ca sau khi ca hôm nay đã/đang chạy có thể làm sai lệch % online tính điểm cuối ca — hệ thống chấm điểm luôn dùng giờ ca hiện tại, không lưu lại giờ lúc tài xế vào ca.'),

                    Forms\Components\TimePicker::make('end_time')
                        ->label('Kết thúc')
                        ->seconds(false)
                        ->required()
                        ->helperText('Chọn 00:00 nếu ca kết thúc lúc nửa đêm. Cùng lưu ý như giờ bắt đầu — tránh đổi giữa/ngay sau khi ca hôm nay đang chạy.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Kích hoạt')
                        ->default(true)
                        ->inline(false),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Tên ca')
                    ->weight('bold')
                    ->searchable(),

                Tables\Columns\TextColumn::make('code')
                    ->label('Mã ca')
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('start_time')
                    ->label('Bắt đầu')
                    ->time('H:i'),

                Tables\Columns\TextColumn::make('end_time')
                    ->label('Kết thúc')
                    ->time('H:i'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Kích hoạt')
                    ->alignCenter(),
            ])
            ->defaultSort('start_time')
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListShifts::route('/'),
            'create' => Pages\CreateShift::route('/create'),
            'edit'   => Pages\EditShift::route('/{record}/edit'),
        ];
    }
}
