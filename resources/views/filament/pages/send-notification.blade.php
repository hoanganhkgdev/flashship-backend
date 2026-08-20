<x-filament-panels::page>
    {{-- wire:confirm: gửi thông báo đẩy tới toàn bộ người dùng khớp đối
    tượng chọn, không giới hạn tốc độ/số lượng — bấm nhầm hoặc bấm 2 lần đều
    gửi lại toàn bộ. Xác nhận 1 lần trước khi thật sự gửi. --}}
    <form wire:submit="send" wire:confirm="Gửi thông báo này tới TẤT CẢ người dùng khớp đối tượng đã chọn? Không thể thu hồi sau khi gửi.">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-4">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" size="lg">
                Gửi thông báo
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
