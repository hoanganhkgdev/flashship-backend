<x-filament-panels::page>
    <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
        Cấu hình mới áp dụng cho nghiệp vụ phát sinh sau khi lưu. Mốc chốt tuần không sửa các khoản đã chốt; mức thưởng mưa được khóa theo từng đơn ngay khi tài xế nhận.
    </div>

    <x-filament-panels::form wire:submit="save" wire:confirm="Lưu cấu hình vận hành mới? Các lượt xử lý tiếp theo sẽ dùng giá trị này.">
        {{ $this->form }}

        <x-filament-panels::form.actions
            :actions="[
                \Filament\Actions\Action::make('save')
                    ->label('Lưu cấu hình')
                    ->icon('heroicon-o-check')
                    ->submit('save'),
            ]"
        />
    </x-filament-panels::form>
</x-filament-panels::page>
