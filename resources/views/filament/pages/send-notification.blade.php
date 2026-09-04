<x-filament-panels::page>
    <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800 dark:border-warning-500/20 dark:bg-warning-500/10 dark:text-warning-300">
        Thông báo sẽ được gửi ngay tới <span class="font-medium">{{ number_format($this->getRecipientCountProperty()) }} thiết bị</span> thuộc nhóm <span class="font-medium">{{ $this->getTargetLabelProperty() }}</span> và không thể thu hồi.
    </div>

    <form wire:submit="send" wire:confirm="Xác nhận gửi thông báo tới nhóm người nhận đã chọn? Thông báo không thể thu hồi.">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-4">
            <x-filament::button type="submit" icon="heroicon-o-paper-airplane" size="lg" wire:loading.attr="disabled" wire:target="send">
                <span wire:loading.remove wire:target="send">Gửi thông báo</span>
                <span wire:loading wire:target="send">Đang gửi...</span>
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />
</x-filament-panels::page>
