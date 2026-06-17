<x-filament-panels::page>

    {{-- Kết quả đặt đơn --}}
    @if ($resultOrderCode)
        <div class="mb-6 rounded-xl border border-success-300 bg-success-50 p-5 dark:border-success-700 dark:bg-success-950">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-success-700 dark:text-success-300">✅ Đặt đơn thành công</p>
                    <p class="mt-1 text-2xl font-bold text-success-800 dark:text-success-200">{{ $resultOrderCode }}</p>
                </div>
                <button wire:click="clearResult" class="text-success-600 hover:text-success-800 dark:text-success-400">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
        </div>
    @endif

    @if ($resultError)
        <div class="mb-6 rounded-xl border border-danger-300 bg-danger-50 p-4 dark:border-danger-700 dark:bg-danger-950">
            <p class="text-sm font-medium text-danger-700 dark:text-danger-300">❌ {{ $resultError }}</p>
        </div>
    @endif

    {{-- AI Parse --}}
    <div class="mb-6 rounded-xl border border-primary-200 bg-primary-50 p-5 dark:border-primary-800 dark:bg-primary-950">
        <h3 class="mb-3 flex items-center gap-2 text-sm font-semibold text-primary-700 dark:text-primary-300">
            <x-heroicon-o-sparkles class="h-5 w-5" />
            Phân tích đơn bằng AI (Gemini)
        </h3>
        <textarea
            wire:model="rawText"
            rows="5"
            placeholder="Dán nội dung tin nhắn / mẫu đơn hàng vào đây, AI sẽ tự điền các trường bên dưới..."
            class="w-full rounded-lg border border-primary-300 bg-white p-3 text-sm text-gray-700 shadow-sm focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-primary-700 dark:bg-gray-900 dark:text-gray-200"
        ></textarea>

        <div class="mt-3 flex items-center gap-4">
            <x-filament::button
                wire:click="parseWithAI"
                wire:loading.attr="disabled"
                icon="heroicon-o-sparkles"
                color="primary"
                size="sm"
            >
                <span wire:loading.remove wire:target="parseWithAI">Phân tích đơn</span>
                <span wire:loading wire:target="parseWithAI">Đang phân tích...</span>
            </x-filament::button>

            @if ($aiStatus)
                <span class="text-sm {{ str_starts_with($aiStatus, '✅') ? 'text-success-600 dark:text-success-400' : (str_starts_with($aiStatus, '❌') ? 'text-danger-600 dark:text-danger-400' : 'text-gray-500') }}">
                    {{ $aiStatus }}
                </span>
            @endif
        </div>
    </div>

    {{-- Form --}}
    <form wire:submit="placeOrder">
        {{ $this->form }}

        <div class="mt-6 flex items-center gap-4">
            <x-filament::button
                type="submit"
                wire:loading.attr="disabled"
                icon="heroicon-o-paper-airplane"
                size="lg"
                color="success"
            >
                <span wire:loading.remove wire:target="placeOrder">Đặt đơn ngay</span>
                <span wire:loading wire:target="placeOrder">Đang đặt đơn...</span>
            </x-filament::button>
        </div>
    </form>

    <x-filament-actions::modals />

</x-filament-panels::page>
