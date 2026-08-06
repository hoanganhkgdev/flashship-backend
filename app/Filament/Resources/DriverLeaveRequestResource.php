<?php

namespace App\Filament\Resources;

use App\Filament\Resources\DriverLeaveRequestResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Core\Models\User;
use Modules\Driver\Models\DriverLeaveRequest;

class DriverLeaveRequestResource extends Resource
{
    public static function canAccess(): bool
    {
        return in_array(auth()->user()?->user_type, ['admin', 'subadmin', 'city_manager']) && static::canViewAny();
    }

    // DriverLeaveRequest không có city_id trực tiếp — khu vực xác định qua driver_id -> users.city_id.
    public static function scopeEloquentQueryToTenant(\Illuminate\Database\Eloquent\Builder $query, ?\Illuminate\Database\Eloquent\Model $tenant): \Illuminate\Database\Eloquent\Builder
    {
        return $query->whereHas('driver', fn ($q) => $q->where('city_id', $tenant?->id));
    }

    protected static ?string $model            = DriverLeaveRequest::class;
    protected static ?string $navigationIcon   = 'heroicon-o-calendar-days';
    protected static ?string $navigationGroup  = 'Cấu hình';
    protected static ?string $modelLabel       = 'Xin nghỉ phép';
    protected static ?string $pluralModelLabel = 'Xin nghỉ phép';
    protected static ?int    $navigationSort   = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Ghi nhận nghỉ phép')
                ->description('Tài xế báo nghỉ trước qua điện thoại/Zalo — admin tạo bản ghi này để miễn chấm điểm "Có mặt" cho ngày nghỉ, không bị tính -15 điểm vì không online.')
                ->columns(2)
                ->schema([
                    Forms\Components\Select::make('driver_id')
                        ->label('Tài xế')
                        ->options(fn () => User::where('user_type', 'driver')
                            ->where('status', 1)
                            ->orderBy('name')
                            ->get()
                            ->mapWithKeys(fn ($u) => [$u->id => $u->name . ' — ' . $u->phone]))
                        ->searchable()
                        ->required(),

                    Forms\Components\DatePicker::make('leave_date')
                        ->label('Ngày nghỉ')
                        ->native(false)
                        ->displayFormat('d/m/Y')
                        ->required(),

                    Forms\Components\Textarea::make('note')
                        ->label('Ghi chú')
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('driver.name')
                    ->label('Tài xế')
                    ->searchable()
                    ->weight('bold')
                    ->description(fn (DriverLeaveRequest $r) => $r->driver?->phone ?? ''),

                Tables\Columns\TextColumn::make('driver.city.name')
                    ->label('Khu vực')
                    ->alignCenter()
                    ->default('—'),

                Tables\Columns\TextColumn::make('leave_date')
                    ->label('Ngày nghỉ')
                    ->alignCenter()
                    ->date('d/m/Y')
                    ->weight('bold'),

                Tables\Columns\TextColumn::make('note')
                    ->label('Ghi chú')
                    ->limit(40)
                    ->default('—')
                    ->tooltip(fn ($state) => $state),

                Tables\Columns\TextColumn::make('creator.name')
                    ->label('Người tạo')
                    ->default('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Ngày tạo')
                    ->alignCenter()
                    ->dateTime('d/m/Y H:i'),
            ])
            ->defaultSort('leave_date', 'desc')
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getRelations(): array { return []; }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListDriverLeaveRequests::route('/'),
            'create' => Pages\CreateDriverLeaveRequest::route('/create'),
            'edit'   => Pages\EditDriverLeaveRequest::route('/{record}/edit'),
        ];
    }
}
