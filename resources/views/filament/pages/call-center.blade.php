<x-filament-panels::page>

    {{-- Kết quả đặt đơn --}}
    @if ($resultOrderCode)
        <div class="mb-6 rounded-xl border border-success-300 bg-success-50 p-5 dark:border-success-700 dark:bg-success-950">
            <div class="flex items-start justify-between gap-4">
                <div class="flex flex-wrap gap-6">
                    <div>
                        <p class="text-xs font-medium uppercase tracking-wide text-success-600 dark:text-success-400">Mã đơn</p>
                        <p class="mt-1 text-2xl font-bold text-success-800 dark:text-success-200">{{ $resultOrderCode }}</p>
                    </div>
                    @if ($resultFee !== null)
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-success-600 dark:text-success-400">Phí ship</p>
                            <p class="mt-1 text-2xl font-bold text-success-800 dark:text-success-200">{{ number_format($resultFee) }}đ</p>
                        </div>
                    @endif
                    @if ($resultDistance)
                        <div>
                            <p class="text-xs font-medium uppercase tracking-wide text-success-600 dark:text-success-400">Khoảng cách</p>
                            <p class="mt-1 text-2xl font-bold text-success-800 dark:text-success-200">{{ $resultDistance }}</p>
                        </div>
                    @endif
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

        {{-- Preview phí --}}
        <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-center gap-4">
                <x-filament::button
                    wire:click.prevent="calculateFee"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-calculator"
                    color="gray"
                    size="sm"
                >
                    <span wire:loading.remove wire:target="calculateFee">Tính km & phí ship</span>
                    <span wire:loading wire:target="calculateFee">Đang tính...</span>
                </x-filament::button>

                @if ($previewFee !== null)
                    <div class="flex items-center gap-6">
                        <div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Khoảng cách</span>
                            <p class="text-lg font-bold text-gray-800 dark:text-gray-100">{{ $previewDistance }}</p>
                        </div>
                        <div>
                            <span class="text-xs text-gray-500 dark:text-gray-400">Phí ship</span>
                            <p class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ number_format($previewFee) }}đ</p>
                        </div>
                    </div>
                @elseif ($previewStatus)
                    <span class="text-sm {{ str_starts_with($previewStatus, '✅') ? 'text-success-600' : (str_starts_with($previewStatus, '❌') ? 'text-danger-600' : 'text-gray-500') }}">
                        {{ $previewStatus }}
                    </span>
                @else
                    <span class="text-sm text-gray-400">Bấm để xem ước tính km và phí ship trước khi đặt.</span>
                @endif
            </div>
        </div>

        <div class="mt-4 flex items-center gap-4">
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
