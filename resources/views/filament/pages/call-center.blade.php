<x-filament-panels::page>

{{-- ══════════════════════════════════════════════════════ --}}
{{-- BANNER KẾT QUẢ                                        --}}
{{-- ══════════════════════════════════════════════════════ --}}
@if ($resultOrderCode)
<div class="mb-6 overflow-hidden rounded-2xl bg-gradient-to-r from-success-500 to-emerald-600 shadow-lg">
    <div class="flex items-center justify-between p-6">
        <div class="flex items-center gap-6">
            <div class="flex h-14 w-14 items-center justify-center rounded-full bg-white/20">
                <x-heroicon-o-check-circle class="h-8 w-8 text-white" />
            </div>
            <div>
                <p class="text-sm font-medium text-white/80">Đặt đơn thành công</p>
                <p class="text-3xl font-bold tracking-wider text-white">{{ $resultOrderCode }}</p>
            </div>
            @if ($resultFee !== null || $resultDistance)
            <div class="ml-4 flex gap-6 border-l border-white/30 pl-6">
                @if ($resultDistance)
                <div>
                    <p class="text-xs text-white/70">Khoảng cách</p>
                    <p class="text-xl font-bold text-white">{{ $resultDistance }}</p>
                </div>
                @endif
                @if ($resultFee !== null)
                <div>
                    <p class="text-xs text-white/70">Phí ship</p>
                    <p class="text-xl font-bold text-white">{{ number_format($resultFee) }}đ</p>
                </div>
                @endif
            </div>
            @endif
        </div>
        <button wire:click="clearResult" class="rounded-full p-1 text-white/70 transition hover:bg-white/20 hover:text-white">
            <x-heroicon-o-x-mark class="h-5 w-5" />
        </button>
    </div>
</div>
@endif

@if ($resultError)
<div class="mb-6 flex items-center gap-3 rounded-2xl border border-danger-200 bg-danger-50 p-4 dark:border-danger-800 dark:bg-danger-950/60">
    <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-danger-100 dark:bg-danger-900">
        <x-heroicon-o-exclamation-circle class="h-5 w-5 text-danger-600 dark:text-danger-400" />
    </div>
    <p class="text-sm font-medium text-danger-700 dark:text-danger-300">{{ $resultError }}</p>
</div>
@endif

{{-- ══════════════════════════════════════════════════════ --}}
{{-- LAYOUT 2 CỘT                                          --}}
{{-- ══════════════════════════════════════════════════════ --}}
<div class="grid grid-cols-1 gap-6 xl:grid-cols-5">

    {{-- CỘT TRÁI: Form --}}
    <div class="xl:col-span-3">

        {{-- FORM --}}
        <form wire:submit="placeOrder">
            <div>
                {{ $this->form }}
            </div>

            {{-- TÍNH PHÍ + ĐẶT ĐƠN --}}
            <div class="mt-4 flex items-center gap-3 rounded-2xl border border-gray-200 bg-gray-50 px-5 py-4 dark:border-gray-700 dark:bg-gray-800/50">

                <x-filament::button
                    wire:click.prevent="calculateFee"
                    wire:loading.attr="disabled"
                    icon="heroicon-o-calculator"
                    color="gray"
                    size="sm"
                    outlined
                >
                    <span wire:loading.remove wire:target="calculateFee">Tính phí</span>
                    <span wire:loading wire:target="calculateFee">Đang tính...</span>
                </x-filament::button>

                @if ($previewFee !== null)
                <div class="flex items-center gap-4 rounded-xl bg-white px-4 py-2 shadow-sm dark:bg-gray-800">
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">Khoảng cách</p>
                        <p class="text-base font-bold text-gray-700 dark:text-gray-200">{{ $previewDistance }}</p>
                    </div>
                    <div class="h-8 w-px bg-gray-200 dark:bg-gray-600"></div>
                    <div class="text-center">
                        <p class="text-[10px] font-medium uppercase tracking-wide text-gray-400">Phí ship</p>
                        <p class="text-base font-bold text-primary-600 dark:text-primary-400">{{ number_format($previewFee) }}đ</p>
                    </div>
                </div>
                @elseif ($previewStatus)
                <span class="text-xs {{ str_starts_with($previewStatus, '❌') ? 'text-danger-600' : 'text-gray-500' }}">
                    {{ $previewStatus }}
                </span>
                @else
                <span class="text-xs text-gray-400">Bấm để xem km & phí trước khi đặt</span>
                @endif

                <div class="ml-auto">
                    <x-filament::button
                        type="submit"
                        wire:loading.attr="disabled"
                        icon="heroicon-o-paper-airplane"
                        size="lg"
                        color="success"
                    >
                        <span wire:loading.remove wire:target="placeOrder">Đặt đơn ngay</span>
                        <span wire:loading wire:target="placeOrder">Đang xử lý...</span>
                    </x-filament::button>
                </div>
            </div>
        </form>
    </div>

    {{-- CỘT PHẢI: Hướng dẫn + Lịch sử --}}
    <div class="xl:col-span-2 space-y-5">

        {{-- HƯỚNG DẪN --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h4 class="mb-4 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <x-heroicon-o-information-circle class="h-4 w-4 text-primary-500" />
                Quy trình đặt đơn
            </h4>
            <ol class="space-y-3">
                @foreach ([
                    ['icon' => 'heroicon-o-map-pin',         'color' => 'bg-blue-100 text-blue-600 dark:bg-blue-900 dark:text-blue-300',    'text' => 'Chọn khu vực & điền tên shop'],
                    ['icon' => 'heroicon-o-pencil-square',   'color' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',    'text' => 'Nhập địa chỉ lấy hàng & giao hàng'],
                    ['icon' => 'heroicon-o-calculator',      'color' => 'bg-amber-100 text-amber-600 dark:bg-amber-900 dark:text-amber-300',  'text' => 'Bấm "Tính phí" để kiểm tra km'],
                    ['icon' => 'heroicon-o-paper-airplane',  'color' => 'bg-success-100 text-success-600 dark:bg-success-900 dark:text-success-300', 'text' => 'Đặt đơn — tài xế nhận ngay'],
                ] as $i => $step)
                <li class="flex items-start gap-3">
                    <div class="flex h-7 w-7 flex-shrink-0 items-center justify-center rounded-full {{ $step['color'] }}">
                        <x-dynamic-component :component="$step['icon']" class="h-3.5 w-3.5" />
                    </div>
                    <div class="pt-0.5">
                        <span class="text-xs font-medium text-gray-600 dark:text-gray-400">Bước {{ $i + 1 }}.</span>
                        <span class="ml-1 text-xs text-gray-500 dark:text-gray-500">{{ $step['text'] }}</span>
                    </div>
                </li>
                @endforeach
            </ol>
        </div>

        {{-- GHI CHÚ NHANH --}}
        <div class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-gray-700 dark:text-gray-300">
                <x-heroicon-o-light-bulb class="h-4 w-4 text-amber-500" />
                Lưu ý
            </h4>
            <ul class="space-y-2 text-xs text-gray-500 dark:text-gray-400">
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-success-500">✓</span>
                    <span>Phí ship tính tự động theo khoảng cách thực (Google Maps)</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-success-500">✓</span>
                    <span>Khu vực đã chọn giữ nguyên sau khi đặt đơn</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-success-500">✓</span>
                    <span>Tài xế nhận đơn trong vòng bán kính 1–3km</span>
                </li>
                <li class="flex items-start gap-2">
                    <span class="mt-0.5 text-amber-500">!</span>
                    <span>Địa chỉ ngắn nên chọn đúng khu vực để AI geocode chính xác</span>
                </li>
            </ul>
        </div>
    </div>
</div>

<x-filament-actions::modals />

</x-filament-panels::page>
