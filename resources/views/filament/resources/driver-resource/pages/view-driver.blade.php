<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-drivers', 'fi-resource-record-'.$record->getKey()])
>
    @php
        $driver = $this->record;
        $summary = $this->getSummary();
        $wallet = $this->getWallet();
        $docs = $this->getDocuments();
        $orders = $this->getRecentOrders();
        $logs = $this->getLogs();
        $approval = (int) $driver->status === 0 ? $this->getApproval() : null;
        $relationManagers = $this->getRelationManagers();
        $money = fn ($v) => number_format((int) $v, 0, ',', '.') . '₫';
        $num = fn ($v) => number_format((int) $v, 0, ',', '.');
        $statusMap = [0 => ['Chờ duyệt', 'warning'], 1 => ['Hoạt động', 'success'], 2 => ['Bị khóa', 'danger']];
        [$statusLabel, $statusColor] = $statusMap[(int) $driver->status] ?? ['Không rõ', 'gray'];
        $initial = mb_strtoupper(mb_substr((string) $driver->name, 0, 1));
        $photo = $driver->profile_photo_path ? \Illuminate\Support\Facades\Storage::url($driver->profile_photo_path) : null;
        $lastLock = $logs->firstWhere('key', 'locked');
    @endphp

    @if ($approval)
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-exclamation-triangle class="h-5 w-5" />
            <span>
                <b>Hồ sơ chờ duyệt {{ (int) $driver->created_at->diffInDays(now()) }} ngày.</b>
                @foreach ($approval['blockers'] as $b) <span style="color:#dc2626">⛔ {{ $b }} — chưa thể duyệt.</span> @endforeach
                @foreach ($approval['warnings'] as $w) <span>⚠ {{ $w }}.</span> @endforeach
                @if ($approval['ok'] && ! $approval['warnings']) ✓ Giấy tờ đã đủ, có thể duyệt. @endif
            </span>
        </div>
    @elseif ((int) $driver->status === 2 && $lastLock)
        <div class="fs-rr-notice" role="status">
            <x-heroicon-o-lock-closed class="h-5 w-5" />
            <span><b>Bị khóa ngày {{ $lastLock['when']->format('d/m/Y') }}</b> bởi {{ $lastLock['by'] }}{{ $lastLock['reason'] ? ' — '.$lastLock['reason'] : '' }}.</span>
        </div>
    @endif

    {{-- Hồ sơ --}}
    <section class="fs-rr-card fs-cp-profile">
        @if ($photo)
            <img class="fs-cp-avatar" src="{{ $photo }}" alt="{{ $driver->name }}" onerror="this.style.display='none'; this.nextElementSibling.style.display='grid'">
            <span class="fs-cp-avatar fs-cp-avatar--text" style="display:none">{{ $initial ?: '?' }}</span>
        @else
            <span class="fs-cp-avatar fs-cp-avatar--text">{{ $initial ?: '?' }}</span>
        @endif
        <div class="fs-cp-profile__main">
            <h2>
                {{ $driver->name }}
                <span class="fs-order-pill fs-order-pill--{{ $statusColor }}">{{ $statusLabel }}</span>
                @if ($summary['low_score'] && (int) $driver->status === 1)<span class="fs-order-pill fs-order-pill--danger">Điểm thấp</span>@endif
            </h2>
            <dl>
                <div><dt>Số điện thoại</dt><dd>{{ $driver->phone ?: '—' }}</dd></div>
                <div><dt>CCCD / CMND</dt><dd>{{ $driver->cccd ?: '—' }}</dd></div>
                <div><dt>Phương tiện</dt><dd>{{ trim(($driver->vehicle_type ?: '').' '.($driver->license_plate ?: '')) ?: '—' }}</dd></div>
                <div><dt>Ca đăng ký</dt><dd>{{ $driver->registeredShifts->pluck('name')->implode(', ') ?: 'Chưa đăng ký' }}</dd></div>
                <div><dt>Khu vực</dt><dd>{{ $driver->city?->name ?? '—' }}</dd></div>
                <div><dt>Đăng ký</dt><dd>{{ $driver->created_at?->format('d/m/Y H:i') }}</dd></div>
            </dl>
        </div>
    </section>

    {{-- Hoạt động 30 ngày --}}
    <div class="fs-cp-kpis">
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Đơn 30 ngày</span><strong>{{ $num($summary['completed']) }}</strong><small>hoàn thành · tổng cộng {{ $num($summary['lifetime']) }} đơn</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Thu nhập 30 ngày</span><strong>{{ $money($summary['earnings']) }}</strong><small>phí giao, phụ phí, thưởng mưa, bù giảm giá</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Tỷ lệ hủy 30 ngày</span><strong>{{ $summary['cancel_rate'] }}%</strong><small>{{ $num($summary['cancelled']) }} trên {{ $num($summary['total']) }} đơn nhận</small></article>
        <article class="fs-rr-kpi"><span class="fs-rr-kpi__label">Điểm hoạt động</span><strong @class(['fs-dr-low' => $summary['low_score']])>{{ $summary['score'] }}</strong><small>{{ $summary['low_score'] ? 'dưới ngưỡng '.\App\Filament\Resources\DriverResource::LOW_SCORE.' điểm' : 'đạt yêu cầu' }}</small></article>
    </div>

    <div class="fs-cp-grid">
        {{-- Đơn gần đây --}}
        <section class="fs-rr-card">
            <header class="fs-rr-card__head"><div><h2>Đơn gần đây</h2><p>{{ $orders->count() }} đơn mới nhất tài xế nhận</p></div></header>
            @if ($orders->isEmpty())
                <div class="fs-empty"><div>Tài xế chưa nhận đơn nào.</div></div>
            @else
                <ul class="fs-live-list fs-cp-orders">
                    @foreach ($orders as $o)
                        <li>
                            <a class="fs-live-row" href="{{ $o['url'] }}" wire:navigate>
                                <span class="fs-live-row__main">
                                    <span class="fs-live-row__code">{{ $o['code'] }}</span>
                                    <span class="fs-live-row__service">{{ $o['service'] }}</span>
                                    <span class="fs-order-pill fs-order-pill--{{ $o['color'] }}">{{ $o['status'] }}</span>
                                </span>
                                <span class="fs-live-row__route">{{ $o['route'] }}</span>
                                <span class="fs-live-row__meta"><b>{{ $money($o['fee']) }}</b><small>{{ $o['when']?->format('H:i · d/m/Y') }}</small></span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>

        <div class="fs-cp-side">
            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Giấy tờ</h2><p>Trạng thái bản mới nhất</p></div></header>
                @foreach ($docs as $label => [$text, $color])
                    <div class="fs-dr-row"><span>{{ $label }}</span><span class="fs-order-pill fs-order-pill--{{ $color }}">{{ $text }}</span></div>
                @endforeach
            </section>

            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Ví và công nợ</h2><p>Tình hình tài chính hiện tại</p></div></header>
                <div class="fs-dr-row"><span>Số dư ví</span><b>{{ $money($wallet['balance']) }}</b></div>
                <div class="fs-dr-row"><span>Công nợ chưa thu</span><b @class(['fs-dr-low' => $wallet['debt'] > 0])>{{ $money($wallet['debt']) }}@if ($wallet['debt_items']) <small>({{ $wallet['debt_items'] }} khoản)</small>@endif</b></div>
                <div class="fs-dr-row"><span>Đang xin rút</span><b>{{ $money($wallet['withdraw']) }}@if ($wallet['withdraw_items']) <small>({{ $wallet['withdraw_items'] }} yêu cầu)</small>@endif</b></div>
            </section>

            <section class="fs-rr-card">
                <header class="fs-rr-card__head"><div><h2>Lịch sử tài khoản</h2><p>Duyệt, khóa, mở khóa</p></div></header>
                @forelse ($logs as $l)
                    <div class="fs-cp-addr">
                        <b>{{ $l['action'] }}</b>
                        <span>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['by'] }}{{ $l['reason'] ? ' · '.$l['reason'] : '' }}</span>
                    </div>
                @empty
                    <p class="fs-rr-note">Chưa có thao tác nào được ghi lại. Lịch sử bắt đầu từ lúc tính năng này được bật.</p>
                @endforelse
            </section>
        </div>
    </div>

    @if (count($relationManagers))
        <x-filament-panels::resources.relation-managers
            :active-manager="$this->activeRelationManager ?? array_key_first($relationManagers)"
            :managers="$relationManagers"
            :owner-record="$record"
            :page-class="static::class"
        />
    @endif
</x-filament-panels::page>
