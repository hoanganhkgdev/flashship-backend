<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-withdraw-requests', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $r = $record;
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $history = $this->getHistory();
        $labels = ['pending' => ['Chờ duyệt', 'warning'], 'approved' => ['Đã duyệt', 'success'], 'rejected' => ['Từ chối', 'danger']];
        [$statusLabel, $statusColor] = $labels[$r->status] ?? [$r->status, 'gray'];
        $mismatch = \App\Filament\Resources\WithdrawRequestResource::nameMismatch($r);
        $stale = \App\Filament\Resources\WithdrawRequestResource::isStale($r);
    @endphp

    @if ($r->status === 'pending')
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                <b>{{ \App\Filament\Resources\WithdrawRequestResource::waitLabel($r) }}{{ $stale ? ' — đã quá hạn' : '' }}.</b>
                @if ($mismatch) ⚠ Tên chủ tài khoản ({{ $r->account_name }}) không khớp tên tài xế ({{ $r->driver?->name }}). @endif
                @if ((int) $r->driver?->status !== 1) ⛔ Tài khoản tài xế đang tạm ngưng: không thể chuyển tự động. @endif
                @if ($r->last_payout_error) Lần chuyển PayOS gần nhất lỗi ({{ $r->last_payout_attempt_at?->format('H:i d/m') }}): {{ $r->last_payout_error }} @endif
            </span>
        </div>
    @endif

    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Số tiền rút</span><strong>{{ $money($r->amount) }}</strong><small><span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span></small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Ví hiện tại</span><strong>{{ $money($r->driver?->wallet?->balance ?? 0) }}</strong><small>sau khi đã giữ tiền rút</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tạo lúc</span><strong style="font-size: var(--fs-lg)">{{ $r->created_at->format('H:i d/m/Y') }}</strong><small>{{ $r->status === 'pending' ? \App\Filament\Resources\WithdrawRequestResource::waitLabel($r) : 'đã xử lý' }}</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Cách chuyển</span><strong style="font-size: var(--fs-lg)">{{ ['payos' => 'PayOS', 'manual' => 'Thủ công'][$r->payout_method] ?? '—' }}</strong><small>{{ $r->payout_reference ? 'Mã GD '.$r->payout_reference : 'chưa chuyển' }}</small></article>
    </div>

    <div class="fs-cp-grid">
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Tài khoản nhận</h2><p>Bản lưu tại thời điểm tạo yêu cầu</p></div></header>
            <div class="fs-dr-row"><span>Ngân hàng</span><b>{{ $r->bank_name ?: 'Chưa có bản lưu' }}</b></div>
            <div class="fs-dr-row"><span>Số tài khoản</span><b>{{ $r->account_number ?: '—' }}</b></div>
            <div class="fs-dr-row"><span>Chủ tài khoản</span><b>{{ $r->account_name ?: '—' }} @if ($mismatch)<small class="fs-sc-down">⚠ không khớp</small>@endif</b></div>
            <div class="fs-dr-row"><span>Tài xế</span><b>{{ $r->driver?->name }} · {{ $r->driver?->phone }}</b></div>

            <header class="fs-rr-card__head" style="margin-top:1.25rem"><div><h2>Kết quả xử lý</h2></div></header>
            @if ($r->status === 'pending')
                <p class="fs-rr-note">Chưa xử lý.</p>
            @else
                <div class="fs-cp-addr">
                    <b>{{ $statusLabel }}</b>
                    <span>{{ $r->processed_at?->format('H:i · d/m/Y') }} · {{ $r->processor?->name ?? 'Quản trị viên' }}{{ $r->admin_note ? ' · '.$r->admin_note : '' }}</span>
                </div>
            @endif
        </section>

        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Các lần rút trước</h2><p>8 yêu cầu gần nhất của tài xế</p></div></header>
            @forelse ($history as $h)
                @php [$hl, $hc] = $labels[$h->status] ?? [$h->status, 'gray']; @endphp
                <a class="fs-dr-row" href="{{ \App\Filament\Resources\WithdrawRequestResource::getUrl('view', ['record' => $h]) }}" wire:navigate>
                    <span>{{ $h->created_at->format('d/m/Y') }} · <span class="fs-order-pill fs-order-pill--{{ $hc }}">{{ $hl }}</span></span>
                    <b>{{ $money($h->amount) }}</b>
                </a>
            @empty
                <p class="fs-rr-note">Đây là yêu cầu rút đầu tiên.</p>
            @endforelse
        </section>
    </div>
</x-filament-panels::page>
