<?php

namespace App\Filament\Pages;

use App\Services\AdminAccountService;
use App\Services\CatalogService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Hash;

/** Quản trị viên tự đổi tên, email, mật khẩu của chính mình (mọi vai trò quản trị). */
class MyAccountPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'tai-khoan-cua-toi';

    protected static ?string $title = 'Tài khoản của tôi';

    protected static string $view = 'filament.pages.my-account';

    public array $data = [];

    public function getSubheading(): ?string
    {
        return 'Cập nhật thông tin đăng nhập của riêng bạn. Đổi mật khẩu cần nhập mật khẩu hiện tại.';
    }

    public function mount(): void
    {
        $u = auth()->user();
        $this->form->fill(['name' => $u->name, 'email' => $u->email, 'phone' => $u->phone]);
    }

    public function form(Form $form): Form
    {
        $u = auth()->user();

        return $form->schema([
            Section::make('Thông tin')->columns(3)->schema([
                TextInput::make('name')->label('Họ tên')->required()->maxLength(100),
                TextInput::make('email')->label('Email đăng nhập')->email()->required()->unique('users', 'email', ignorable: $u),
                TextInput::make('phone')->label('Số điện thoại')->tel()->unique('users', 'phone', ignorable: $u, modifyRuleUsing: fn ($rule) => $rule->where('user_type', $u->user_type)),
            ]),
            Section::make('Đổi mật khẩu')->description('Để trống nếu không đổi.')->columns(3)->schema([
                TextInput::make('current_password')->label('Mật khẩu hiện tại')->password()->revealable()
                    ->requiredWith('new_password')->currentPassword()->dehydrated(false),
                TextInput::make('new_password')->label('Mật khẩu mới')->password()->revealable()->minLength(8)->same('new_password_confirmation')->dehydrated(false),
                TextInput::make('new_password_confirmation')->label('Nhập lại mật khẩu mới')->password()->revealable()->requiredWith('new_password')->dehydrated(false),
            ]),
        ])->statePath('data');
    }

    public function save(): void
    {
        $this->form->getState();
        $state = $this->data;
        $u = auth()->user();
        $before = $u->only(['name', 'email', 'phone']);

        $update = ['name' => $state['name'], 'email' => $state['email'], 'phone' => $state['phone'] ?? null];
        $changedPassword = filled($state['new_password'] ?? null);
        if ($changedPassword) {
            $update['password'] = Hash::make($state['new_password']);
        }
        $u->update($update);

        CatalogService::logChanges('admin_user', $u->id, $before, $u->only(['name', 'email', 'phone']), ['name' => 'Họ tên', 'email' => 'Email', 'phone' => 'Số điện thoại'], $u->id);
        if ($changedPassword) {
            AdminAccountService::log($u, 'password_reset', $u->id, 'Tự đổi mật khẩu');
        }

        $this->data['current_password'] = $this->data['new_password'] = $this->data['new_password_confirmation'] = null;
        Notification::make()->success()->title($changedPassword ? 'Đã cập nhật thông tin và mật khẩu' : 'Đã cập nhật thông tin')->send();
    }
}
