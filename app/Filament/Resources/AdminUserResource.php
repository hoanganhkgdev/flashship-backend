<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AdminUserResource\Pages;
use App\Filament\Traits\RestrictToFullAdmin;
use App\Services\AdminAccountService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

class AdminUserResource extends Resource
{
    // Chỉ admin đầy đủ mới được quản lý tài khoản admin/subadmin — trước
    // đây subadmin lọt qua (chỉ chặn call_center), có thể tự sửa tài khoản
    // admin khác (đổi mật khẩu, chiếm quyền) hoặc tự nâng cấp user_type của
    // chính mình lên 'admin' qua form bên dưới.
    use RestrictToFullAdmin;

    // Quản lý tài khoản admin/city_manager/call_center là dữ liệu quản trị,
    // không phải dữ liệu nghiệp vụ theo khu vực — xem xuyên suốt mọi tenant.
    protected static bool $isScopedToTenant = false;

    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Hệ thống';

    protected static ?string $navigationLabel = 'Quản trị viên';

    protected static ?string $modelLabel = 'Quản trị viên';

    protected static ?string $pluralModelLabel = 'Quản trị viên';

    protected static ?string $slug = 'admins';

    protected static ?int $navigationSort = 1;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereIn('user_type', array_keys(AdminAccountService::ROLES));
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Thông tin cá nhân')
                ->description('Thông tin nhận diện và liên hệ của nhân sự quản trị')
                ->icon('heroicon-o-identification')
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label('Họ tên')
                        ->required(),

                    Forms\Components\TextInput::make('phone')
                        ->label('Số điện thoại')
                        ->tel()
                        ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, Forms\Get $get) => $rule->where('user_type', $get('user_type')))
                        ->validationMessages(['unique' => 'Số điện thoại này đã có tài khoản cùng vai trò.']),

                    Forms\Components\TextInput::make('email')
                        ->label('Email đăng nhập')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->helperText('Tài khoản quản trị đăng nhập bằng email.')
                        ->validationMessages(['unique' => 'Email này đã được dùng.']),
                ])->columns(3),

            Forms\Components\Section::make('Tài khoản')
                ->description('Vai trò quyết định phạm vi truy cập và khu vực phụ trách')
                ->icon('heroicon-o-shield-check')
                ->schema([
                    Forms\Components\Select::make('user_type')
                        ->label('Vai trò')
                        ->options(AdminAccountService::ROLES)
                        ->live()
                        ->required()
                        ->helperText(fn (Forms\Get $get) => AdminAccountService::ROLE_DESCRIPTIONS[$get('user_type')] ?? null),

                    Forms\Components\Select::make('city_id')
                        ->label('Khu vực phụ trách')
                        ->options(fn () => DB::table('cities')
                            ->where('is_active', 1)
                            ->orderBy('name')
                            ->pluck('name', 'id'))
                        ->searchable()
                        ->visible(fn (Forms\Get $get) => in_array($get('user_type'), ['city_manager', 'call_center', 'viewer'], true))
                        ->required(fn (Forms\Get $get) => in_array($get('user_type'), ['city_manager', 'call_center', 'viewer'], true)),

                    Forms\Components\Select::make('status')
                        ->label('Trạng thái')
                        ->options([1 => 'Hoạt động', 2 => 'Bị khóa'])
                        ->default(1)
                        ->required()
                        ->disabledOn('edit')
                        ->helperText('Để khóa hoặc mở khóa, dùng nút Khóa/Mở khóa (có ghi lý do).'),

                    Forms\Components\TextInput::make('password')
                        ->label('Mật khẩu')
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation) => $operation === 'create')
                        ->minLength(8)
                        ->helperText('Tối thiểu 8 ký tự. Khi sửa tài khoản, để trống nếu không đổi.')
                        ->dehydrateStateUsing(fn ($state) => filled($state) ? bcrypt($state) : null)
                        ->dehydrated(fn ($state) => filled($state)),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Họ tên')
                    ->searchable()
                    ->weight('semibold')
                    ->description(fn (User $u) => collect([$u->email, $u->phone])->filter()->join(' · ')),

                Tables\Columns\TextColumn::make('user_type')
                    ->label('Vai trò')
                    ->badge()
                    ->formatStateUsing(fn ($state) => AdminAccountService::ROLES[$state] ?? $state)
                    ->color(fn ($state) => match ($state) {
                        'admin' => 'danger', 'city_manager' => 'info', 'call_center' => 'success', 'accountant' => 'warning', 'viewer' => 'gray', default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('city.name')->label('Khu vực')->default('Tất cả')->badge()->color('gray'),

                Tables\Columns\TextColumn::make('last_login_at')
                    ->label('Đăng nhập gần nhất')
                    ->since()
                    ->placeholder('Chưa ghi nhận')
                    ->tooltip(fn (User $u) => $u->last_login_at?->format('H:i d/m/Y')),

                Tables\Columns\TextColumn::make('status')
                    ->label('Trạng thái')
                    ->badge()
                    ->formatStateUsing(fn ($state) => match ((int) $state) {
                        1 => 'Hoạt động', 2 => 'Bị khóa', default => 'Không rõ'
                    })
                    ->color(fn ($state) => match ((int) $state) {
                        1 => 'success', 2 => 'danger', default => 'gray'
                    }),

                Tables\Columns\TextColumn::make('created_at')->label('Ngày tạo')->dateTime('d/m/Y')->sortable(),
            ])
            ->filters([
                SelectFilter::make('user_type')->label('Vai trò')->options(AdminAccountService::ROLES),
                SelectFilter::make('city_id')->label('Khu vực')->relationship('city', 'name'),
            ])
            ->actions([
                Tables\Actions\ActionGroup::make([
                    Tables\Actions\EditAction::make()->label('Chỉnh sửa'),
                    self::lockAction(Tables\Actions\Action::make('lock')),
                    self::passwordAction(Tables\Actions\Action::make('password')),
                    self::deleteAction(Tables\Actions\Action::make('remove')),
                ])->icon('heroicon-m-ellipsis-horizontal')->label(''),
            ])
            ->bulkActions([])
            ->recordAction('edit')
            ->defaultSort('created_at', 'desc')
            ->defaultPaginationPageOption(25)
            ->paginationPageOptions([25, 50, 100]);
    }

    private static function notify(array $res): void
    {
        Notification::make()->title($res['message'])->{$res['ok'] ? 'success' : 'danger'}()->send();
    }

    public static function lockAction($action)
    {
        return $action
            ->label(fn (User $u) => (int) $u->status === 1 ? 'Khóa tài khoản' : 'Mở khóa')
            ->icon(fn (User $u) => (int) $u->status === 1 ? 'heroicon-o-lock-closed' : 'heroicon-o-lock-open')
            ->color(fn (User $u) => (int) $u->status === 1 ? 'danger' : 'success')
            ->modalHeading(fn (User $u) => ((int) $u->status === 1 ? 'Khóa ' : 'Mở khóa ').$u->name)
            ->modalDescription(fn (User $u) => (int) $u->status === 1 ? 'Tài khoản bị đăng xuất và không vào được trang quản trị. Lý do được ghi lại.' : 'Tài khoản vào lại được trang quản trị.')
            ->form([Forms\Components\Textarea::make('reason')->label('Lý do')->required()->minLength(3)->maxLength(250)->rows(2)])
            ->action(fn (User $record, array $data) => self::notify(AdminAccountService::setLocked($record, (int) $record->status === 1, $data['reason'], auth()->user())));
    }

    public static function passwordAction($action)
    {
        return $action->label('Đặt lại mật khẩu')->icon('heroicon-o-key')->color('warning')
            ->modalHeading(fn (User $u) => 'Đặt lại mật khẩu của '.$u->name)
            ->modalDescription('Tài khoản bị đăng xuất khỏi mọi phiên hiện có. Hãy gửi mật khẩu mới cho người đó qua kênh an toàn.')
            ->form([Forms\Components\TextInput::make('password')->label('Mật khẩu mới')->password()->revealable()->required()->minLength(8)])
            ->action(fn (User $record, array $data) => self::notify(AdminAccountService::resetPassword($record, $data['password'], auth()->user())));
    }

    public static function deleteAction($action)
    {
        return $action->label('Xóa tài khoản')->icon('heroicon-o-trash')->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (User $u) => 'Xóa '.$u->name.'?')
            ->modalDescription('Chỉ xóa được tài khoản chưa có lịch sử thao tác. Nếu đã từng làm việc trong hệ thống, hãy khóa tài khoản.')
            ->action(fn (User $record) => self::notify(AdminAccountService::delete($record, auth()->user())));
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAdminUsers::route('/'),
            'create' => Pages\CreateAdminUser::route('/create'),
            'edit' => Pages\EditAdminUser::route('/{record}/edit'),
        ];
    }
}
