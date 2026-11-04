<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-withdraw-requests', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/withdraw.css') }}?v={{ filemtime(public_path('css/withdraw.css')) }}">
    @php
        $r = $record;
        $driver = $r->driver;
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $history = $this->getHistory();
        $labels = ['pending' => ['Chờ duyệt', 'warning'], 'approved' => ['Đã duyệt', 'success'], 'rejected' => ['Từ chối', 'danger']];
        [$statusLabel, $statusColor] = $labels[$r->status] ?? [$r->status, 'gray'];
        $res = \App\Filament\Resources\WithdrawRequestResource::class;
        $mismatch = $res::nameMismatch($r);
        $stale = $res::isStale($r);
        $inFlight = \App\Services\WithdrawService::payosInFlight($r);
        $stateLabel = $res::payoutStateLabel($r);
        $methods = ['payos' => 'PayOS', 'manual' => 'Chuyển khoản tay', 'cash' => 'Tiền mặt'];
        $overdue = $driver ? \Modules\Driver\Services\DriverWalletService::overdueDebt($driver) : 0;
        $balance = (float) ($driver?->wallet?->balance ?? 0);
        $bankChangedAt = \App\Services\DriverBankService::changedBefore($r);
    @endphp

    <div class="fs-wd">
        @if ($inFlight)
            <div class="fs-wd__notice is-blue" role="status">
                <x-heroicon-o-arrow-path />
                <span><b>{{ $stateLabel }}</b> (lệnh {{ $r->payout_reference }}). PayOS có thể đã chuyển tiền nên không chuyển tay hay từ chối cho tới khi có kết quả. Hệ thống tự kiểm tra mỗi 5 phút, hoặc bấm "Kiểm tra PayOS".</span>
            </div>
        @elseif ($r->status === 'pending' && ($mismatch || $stale || (int) $driver?->status !== 1 || $r->last_payout_error || $bankChangedAt))
            <div @class(['fs-wd__notice', 'is-red' => $mismatch || (int) $driver?->status !== 1 || $bankChangedAt]) role="status">
                <x-heroicon-o-exclamation-triangle />
                <span>
                    @if ($stale)<b>{{ $res::waitLabel($r) }} — đã quá hạn.</b>@endif
                    @if ($bankChangedAt)<b>Số tài khoản vừa đổi lúc {{ $bankChangedAt->format('H:i d/m') }}</b>, {{ $bankChangedAt->diffForHumans($r->created_at, true) }} trước khi tạo yêu cầu. Gọi xác nhận với tài xế trước khi chuyển. @endif
                    @if ($mismatch) Tên chủ tài khoản ({{ $r->account_name }}) không khớp tên tài xế ({{ $driver?->name }}). @endif
                    @if ((int) $driver?->status !== 1) Tài khoản tài xế đang tạm ngưng: không chuyển tự động được. @endif
                    @if ($r->last_payout_error) Lần gửi PayOS gần nhất ({{ $r->last_payout_attempt_at?->format('H:i d/m') }}) không thành công: {{ $r->last_payout_error }} @endif
                </span>
            </div>
        @endif

        <section class="fs-wd__card fs-wd__summary" aria-label="Yêu cầu rút tiền">
            <div class="fs-wd__amount">
                <span>Số tiền rút</span>
                <strong>{{ $money($r->amount) }}</strong>
                <div class="fs-wd__chips">
                    <span class="fs-wd__chip fs-wd__chip--{{ $statusColor }}">{{ $statusLabel }}</span>
                    @if ($stateLabel)<span class="fs-wd__chip fs-wd__chip--blue">{{ $stateLabel }}</span>@endif
                    @if ($r->status === 'approved' && $r->payout_method)<span class="fs-wd__chip fs-wd__chip--gray">{{ $methods[$r->payout_method] ?? $r->payout_method }}</span>@endif
                </div>
                <small>Tạo lúc {{ $r->created_at->format('H:i d/m/Y') }} · {{ $r->status === 'pending' ? $res::waitLabel($r) : 'xử lý '.$r->processed_at?->format('H:i d/m/Y') }}</small>
            </div>
            <dl class="fs-wd__bank">
                <div><dt>Ngân hàng</dt><dd>{{ $r->bank_name ?: 'Chưa có bản lưu' }}</dd></div>
                <div><dt>Số tài khoản</dt><dd class="is-mono">{{ $r->account_number ?: '—' }}</dd></div>
                <div><dt>Chủ tài khoản</dt><dd>{{ $r->account_name ?: '—' }}@if ($mismatch)<span class="fs-wd__chip fs-wd__chip--danger">không khớp</span>@endif</dd></div>
                <div><dt>Tài xế</dt><dd><a href="{{ \App\Filament\Resources\DriverResource::getUrl('view', ['record' => $r->driver_id]) }}" wire:navigate>{{ $driver?->name ?? '—' }}</a> · {{ $driver?->phone }}</dd></div>
            </dl>
        </section>

        <div class="fs-wd__grid">
            <section class="fs-wd__card" aria-label="Quá trình xử lý">
                <header class="fs-wd__card-head"><div><h2>Quá trình xử lý</h2><p>Tài khoản nhận là bản lưu lúc tài xế tạo yêu cầu</p></div></header>
                <ol class="fs-wd__timeline">
                    <li style="--tone: #2563eb">
                        <span class="fs-wd__dot"><x-heroicon-m-plus /></span>
                        <b>Tài xế tạo yêu cầu</b>
                        <small>{{ $r->created_at->format('H:i · d/m/Y') }} · giữ {{ $money($r->amount) }} khỏi ví</small>
                    </li>
                    @if ($r->last_payout_attempt_at || $r->payout_method === 'payos')
                        <li style="--tone: {{ $r->payout_state === 'failed' ? '#dc2626' : '#2563eb' }}" @class(['is-pending' => $inFlight])>
                            <span class="fs-wd__dot"><x-heroicon-m-bolt /></span>
                            <b>Gửi lệnh PayOS {{ $r->payout_reference }}</b>
                            <small>{{ $r->last_payout_attempt_at?->format('H:i · d/m/Y') }}{{ $r->payout_id ? ' · mã PayOS '.$r->payout_id : '' }}{{ $stateLabel ? ' · '.$stateLabel : '' }}</small>
                            @if ($r->last_payout_error)<q>{{ $r->last_payout_error }}</q>@endif
                        </li>
                    @endif
                    @if ($r->status === 'pending')
                        <li class="is-pending" style="--tone: #d97706">
                            <span class="fs-wd__dot"><x-heroicon-m-clock /></span>
                            <b>{{ $inFlight ? 'Chờ PayOS báo kết quả' : 'Chờ admin xử lý' }}</b>
                            <small>{{ $res::waitLabel($r) }}</small>
                        </li>
                    @else
                        <li style="--tone: {{ $r->status === 'approved' ? '#16a34a' : '#dc2626' }}">
                            <span class="fs-wd__dot">@if ($r->status === 'approved')<x-heroicon-m-check />@else<x-heroicon-m-x-mark />@endif</span>
                            <b>{{ $r->status === 'approved' ? 'Đã trả · '.($methods[$r->payout_method] ?? 'không rõ cách') : 'Từ chối · tiền đã hoàn vào ví' }}</b>
                            <small>{{ $r->processed_at?->format('H:i · d/m/Y') }} · {{ $r->processor?->name ?? 'Hệ thống' }}{{ $r->status === 'approved' && $r->payout_method !== 'cash' && $r->payout_reference ? ' · mã GD '.$r->payout_reference : '' }}</small>
                            @if ($r->admin_note)<q>{{ $r->admin_note }}</q>@endif
                        </li>
                    @endif
                </ol>
            </section>

            <div class="fs-wd__stack">
                <section class="fs-wd__card" aria-label="Ví tài xế">
                    <header class="fs-wd__card-head"><div><h2>Ví tài xế</h2><p>Hiện tại, sau khi đã giữ tiền rút</p></div></header>
                    <dl class="fs-wd__rows">
                        <div><dt>Số dư ví</dt><dd>{{ $money($balance) }}</dd></div>
                        <div><dt>Nợ quá hạn</dt><dd @class(['is-alert' => $overdue > 0])>{{ $money($overdue) }}</dd></div>
                    </dl>
                </section>

                <section class="fs-wd__card" aria-label="Các lần rút trước">
                    <header class="fs-wd__card-head"><div><h2>Các lần rút trước</h2><p>8 yêu cầu gần nhất của tài xế</p></div></header>
                    @if ($history->isEmpty())
                        <p class="fs-wd__empty">Đây là yêu cầu rút đầu tiên.</p>
                    @else
                        <ul class="fs-wd__history">
                            @foreach ($history as $h)
                                @php [$hl, $hc] = $labels[$h->status] ?? [$h->status, 'gray']; @endphp
                                <li>
                                    <a href="{{ $res::getUrl('view', ['record' => $h]) }}" wire:navigate>
                                        <span><b>{{ $money($h->amount) }}</b> <small>· {{ $h->created_at->format('d/m/Y') }}{{ $h->status === 'approved' && $h->payout_method ? ' · '.($methods[$h->payout_method] ?? '') : '' }}</small></span>
                                        <span class="fs-wd__chip fs-wd__chip--{{ $hc }}">{{ $hl }}</span>
                                    </a>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </div>
    </div>
</x-filament-panels::page>
