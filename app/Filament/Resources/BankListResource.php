<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BankListResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use App\Services\BankCodeService;
use App\Services\CatalogService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Modules\Driver\Models\BankList;

class BankListResource extends Resource
{
    use RestrictToFullAdmin;

    // Danh mục ngân hàng dùng chung toàn hệ thống, không theo khu vực.
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = BankList::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-library';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Danh sách ngân hàng';

    protected static ?string $modelLabel = 'Ngân hàng';

    protected static ?string $pluralModelLabel = 'Danh sách ngân hàng';

    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin ngân hàng')
                ->description('Danh mục ngân hàng tài xế chọn khi thiết lập tài khoản nhận tiền')
                ->icon('heroicon-o-building-library')
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('code')
                        ->label('Mã BIN ngân hàng')
                        ->required()
                        ->length(6)
                        ->regex('/^\d{6}$/')
                        ->validationMessages(['regex' => 'Mã BIN gồm đúng 6 chữ số (VD: 970436 cho Vietcombank).'])
                        ->placeholder('VD: 970436')
                        ->helperText('Số BIN 6 chữ số của NAPAS, dùng để chuyển khoản qua PayOS. Không dùng mã chữ như VCB hay MB.')
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit'),

                    Forms\Components\TextInput::make('name')
                        ->label('Tên ngân hàng')
                        ->required()
                        ->maxLength(100)
                        ->unique(ignoreRecord: true)
                        ->placeholder('VD: Vietcombank'),

                    Forms\Components\TextInput::make('logo_url')
                        ->label('URL logo')
                        ->url()
                        ->maxLength(255)
                        ->placeholder('https://...')
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_active')->label('Hiển thị trong app')->default(true),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('logo_url')->label('Logo')->alignCenter()->height(32)->defaultImageUrl('https://placehold.co/64x32?text=Bank'),

                Tables\Columns\TextColumn::make('name')->label('Ngân hàng')->searchable()->weight('bold')->description(fn (BankList $b) => 'BIN '.$b->code),

                Tables\Columns\TextColumn::make('usage')
                    ->label('Tài xế đang dùng')
                    ->alignCenter()
                    ->state(fn (BankList $b) => (int) (BankCodeService::usage()[$b->code] ?? 0))
                    ->color(fn ($state) => $state > 0 ? 'info' : 'gray'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->state(fn (BankList $b) => $b->is_active ? 'Đang hiển thị' : 'Đã ẩn')
                    ->color(fn (BankList $b) => $b->is_active ? 'success' : 'gray'),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Trạng thái hiển thị'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa')->after(fn (BankList $b) => CatalogService::log('bank', $b->id, 'updated', auth()->id(), $b->name)),
                    self::toggleAction(Tables\Actions\Action::make('toggle')),
                    Tables\Actions\DeleteAction::make()->label('Xóa ngân hàng')
                        ->before(function (BankList $b, Tables\Actions\DeleteAction $action) {
                            $n = (int) (BankCodeService::usage()[$b->code] ?? 0);
                            if ($n > 0) {
                                Notification::make()->danger()->title('Không thể xóa ngân hàng đang có người dùng')
                                    ->body($n.' tài xế đang lưu tài khoản ngân hàng này. Hãy ẩn ngân hàng thay vì xóa.')->send();
                                $action->halt();
                            }
                        })
                        ->after(fn (BankList $b) => CatalogService::log('bank', $b->id, 'deleted', auth()->id(), $b->name.' ('.$b->code.')')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->recordAction('edit')
            ->defaultSort('name')
            ->paginated(false);
    }

    public static function toggleAction($action)
    {
        return $action
            ->label(fn (BankList $b) => $b->is_active ? 'Ẩn khỏi app' : 'Hiện trong app')
            ->icon(fn (BankList $b) => $b->is_active ? 'heroicon-o-eye-slash' : 'heroicon-o-eye')
            ->color(fn (BankList $b) => $b->is_active ? 'danger' : 'success')
            ->requiresConfirmation()
            ->modalHeading(fn (BankList $b) => ($b->is_active ? 'Ẩn ' : 'Hiện ').$b->name)
            ->modalDescription(function (BankList $b) {
                $n = (int) (BankCodeService::usage()[$b->code] ?? 0);

                return $b->is_active
                    ? 'Tài xế sẽ không chọn được ngân hàng này khi lưu tài khoản mới. '.$n.' tài xế đã lưu tài khoản này vẫn nhận tiền bình thường.'
                    : 'Ngân hàng hiện lại trong danh sách chọn của tài xế.';
            })
            ->action(function (BankList $record): void {
                $record->update(['is_active' => ! $record->is_active]);
                CatalogService::log('bank', $record->id, $record->is_active ? 'activated' : 'deactivated', auth()->id(), $record->name);
                Notification::make()->success()->title($record->is_active ? 'Đã hiện ngân hàng' : 'Đã ẩn ngân hàng')->send();
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBankLists::route('/'),
            'create' => Pages\CreateBankList::route('/create'),
            'edit' => Pages\EditBankList::route('/{record}/edit'),
        ];
    }
}
