<?php

namespace App\Filament\Pages;

use Filament\Forms\Components\Section;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Modules\Core\Models\Shift;

class ShiftSettingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon  = 'heroicon-o-clock';
    protected static ?string $navigationGroup = 'Cài đặt';
    protected static ?string $navigationLabel = 'Ca làm việc';
    protected static ?string $title           = 'Cài đặt ca làm việc';
    protected static ?int    $navigationSort  = 91;

    protected static string $view = 'filament.pages.shift-settings';

    public static function canAccess(): bool
    {
        return !in_array(auth()->user()?->user_type, ['city_manager', 'call_center']);
    }

    public array $data = [];

    public function mount(): void
    {
        $morning   = Shift::where('code', 'morning')->first();
        $afternoon = Shift::where('code', 'afternoon')->first();

        $this->form->fill([
            'morning_start'    => $morning?->start_time,
            'morning_end'      => $morning?->end_time,
            'morning_active'   => $morning?->is_active ?? true,
            'afternoon_start'  => $afternoon?->start_time,
            'afternoon_end'    => $afternoon?->end_time,
            'afternoon_active' => $afternoon?->is_active ?? true,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->schema([

            Section::make('Ca sáng')
                ->icon('heroicon-o-sun')
                ->schema([
                    TimePicker::make('morning_start')
                        ->label('Bắt đầu')
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('morning_end')
                        ->label('Kết thúc')
                        ->seconds(false)
                        ->required(),
                    Toggle::make('morning_active')
                        ->label('Kích hoạt ca sáng'),
                ])->columns(3),

            Section::make('Ca chiều')
                ->icon('heroicon-o-moon')
                ->schema([
                    TimePicker::make('afternoon_start')
                        ->label('Bắt đầu')
                        ->seconds(false)
                        ->required(),
                    TimePicker::make('afternoon_end')
                        ->label('Kết thúc')
                        ->seconds(false)
                        ->required(),
                    Toggle::make('afternoon_active')
                        ->label('Kích hoạt ca chiều'),
                ])->columns(3),

        ])->statePath('data');
    }

    public function save(): void
    {
        $values = $this->form->getState();

        Shift::where('code', 'morning')->update([
            'start_time' => $values['morning_start'],
            'end_time'   => $values['morning_end'],
            'is_active'  => $values['morning_active'],
        ]);

        Shift::where('code', 'afternoon')->update([
            'start_time' => $values['afternoon_start'],
            'end_time'   => $values['afternoon_end'],
            'is_active'  => $values['afternoon_active'],
        ]);

        Notification::make()
            ->title('Đã lưu cài đặt ca làm việc')
            ->success()
            ->send();
    }
}
