<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankListResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Driver\Models\BankList;
use App\Filament\Traits\HideFromCityManager;

class BankListResource extends Resource
{
    public static function canAccess(): bool
    {
        return !auth()->user()?->isCallCenter();
    }

    use HideFromCityManager;

    protected static ?string $model            = BankList::class;
    protected static ?string $navigationIcon   = 'heroicon-o-building-library';
    protected static ?string $navigationGroup  = 'Cấu hình';
    protected static ?string $modelLabel       = 'Ngân hàng';
    protected static ?string $pluralModelLabel = 'Danh sách ngân hàng';
    protected static ?int    $navigationSort   = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin ngân hàng')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Mã ngân hàng')
                        ->required()
                        ->maxLength(20)
                        ->placeholder('VD: VCB, TCB, MB')
                        ->unique(ignoreRecord: true)
                        ->dehydrateStateUsing(fn ($state) => strtoupper(trim($state))),

                    Forms\Components\TextInput::make('name')
                        ->label('Tên ngân hàng')
                        ->required()
                        ->maxLength(100)
                        ->placeholder('VD: Vietcombank'),

                    Forms\Components\TextInput::make('logo_url')
                        ->label('URL logo')
                        ->url()
                        ->maxLength(255)
                        ->placeholder('https://...')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Hiển thị trong app')
                        ->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('index')
                    ->rowIndex()->label('#')->alignCenter()->width(40),

                Tables\Columns\ImageColumn::make('logo_url')
                    ->label('Logo')
                    ->alignCenter()
                    ->height(32)
                    ->defaultImageUrl('https://placehold.co/64x32?text=Bank'),

                Tables\Columns\TextColumn::make('code')
                    ->label('Mã')
                    ->badge()
                    ->color('primary')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('name')
                    ->label('Tên ngân hàng')
                    ->searchable()
                    ->weight('bold'),

                Tables\Columns\ToggleColumn::make('is_active')
                    ->label('Hiển thị')
                    ->alignCenter(),
            ])
            ->actions([
                Tables\Actions\EditAction::make()->label(''),
                Tables\Actions\DeleteAction::make()->label(''),
            ])
            ->reorderable('id');
    }

    public static function getPages(): array
    {
        return [
            'index'  => Pages\ListBankLists::route('/'),
            'create' => Pages\CreateBankList::route('/create'),
            'edit'   => Pages\EditBankList::route('/{record}/edit'),
        ];
    }
}
