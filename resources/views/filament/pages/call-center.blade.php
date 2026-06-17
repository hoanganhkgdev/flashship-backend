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

    {{-- CỘT TRÁI: AI + Form --}}
    <div class="xl:col-span-3">

        {{-- AI PARSE CARD --}}
        <div class="mb-5 overflow-hidden rounded-2xl border border-violet-200 bg-gradient-to-br from-violet-50 to-purple-50 shadow-sm dark:border-violet-800 dark:from-violet-950/40 dark:to-purple-950/40">
            <div class="border-b border-violet-200/70 px-5 py-4 dark:border-violet-700/50">
                <div class="flex items-center gap-2">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-violet-600 shadow-sm">
                        <x-heroicon-o-sparkles class="h-4 w-4 text-white" />
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-violet-800 dark:text-violet-200">AI tự động điền đơn</h3>
                        <p class="text-xs text-violet-500 dark:text-violet-400">Dán tin nhắn khách → Gemini phân tích</p>
                    </div>
                </div>
            </div>
            <div class="p-5">
                <textarea
                    wire:model="rawText"
                    rows="6"
                    placeholder="Dán nội dung tin nhắn / mẫu đặt hàng vào đây...&#10;&#10;Ví dụ:&#10;Lấy: 11 Lý Thường Kiệt ☎ 0942526271&#10;Giao: 355 Nguyễn Trung Trực ☎ 0942373073&#10;COD: 150.000đ"
                    class="w-full resize-none rounded-xl border border-violet-200 bg-white/80 p-4 text-sm leading-relaxed text-gray-700 shadow-inner placeholder:text-gray-400 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200 dark:border-violet-700 dark:bg-gray-900/80 dark:text-gray-200 dark:focus:ring-violet-800"
                ></textarea>
                <div class="mt-3 flex items-center justify-between">
                    <x-filament::button
                        wire:click="parseWithAI"
                        wire:loading.attr="disabled"
                        icon="heroicon-o-sparkles"
                        color="primary"
                        size="sm"
                        style="background: linear-gradient(135deg, #7c3aed, #6d28d9); border: none;"
                    >
                        <span wire:loading.remove wire:target="parseWithAI">Phân tích đơn</span>
                        <span wire:loading wire:target="parseWithAI">⏳ Đang phân tích...</span>
                    </x-filament::button>
                    @if ($aiStatus)
                    <span class="text-xs font-medium
                        {{ str_starts_with($aiStatus, '✅') ? 'text-success-600 dark:text-success-400'
                         : (str_starts_with($aiStatus, '❌') ? 'text-danger-600 dark:text-danger-400'
                         : 'text-violet-500 dark:text-violet-400') }}">
                        {{ $aiStatus }}
                    </span>
                    @endif
                </div>
            </div>
        </div>

        {{-- FORM --}}
        <form wire:submit="placeOrder">
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
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
                    ['icon' => 'heroicon-o-sparkles',        'color' => 'bg-violet-100 text-violet-600 dark:bg-violet-900 dark:text-violet-300', 'text' => 'Dán text → AI tự điền địa chỉ'],
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

        {{-- ĐỊNH DẠNG MẪU --}}
        <div class="rounded-2xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-800 dark:bg-amber-950/30">
            <h4 class="mb-3 flex items-center gap-2 text-sm font-semibold text-amber-700 dark:text-amber-300">
                <x-heroicon-o-document-text class="h-4 w-4" />
                Mẫu tin nhắn đặt hàng
            </h4>
            <div class="rounded-xl bg-white/80 p-3 font-mono text-xs leading-relaxed text-gray-600 dark:bg-gray-900/60 dark:text-gray-400">
                <p class="text-gray-400">Lấy hàng:</p>
                <p>11 Lý Thường Kiệt ☎ 0942526271</p>
                <br>
                <p class="text-gray-400">Giao hàng:</p>
                <p>355 Nguyễn Trung Trực ☎ 0942373073</p>
                <br>
                <p class="text-gray-400">COD: 150.000đ</p>
            </div>
            <p class="mt-2 text-[11px] text-amber-600 dark:text-amber-400">
                💡 Khu vực đã chọn sẽ tự thêm vào địa chỉ khi geocode.
            </p>
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
