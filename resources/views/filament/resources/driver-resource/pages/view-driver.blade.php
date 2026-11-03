<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-drivers', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/driver-page.css') }}?v={{ filemtime(public_path('css/driver-page.css')) }}">
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
        $active = (int) $driver->active_orders_count;
        $tone = fn (string $k) => match ($k) {
            'approved', 'unlocked', 'debt_on' => ['#16a34a', 'heroicon-m-check'],
            'locked' => ['#dc2626', 'heroicon-m-lock-closed'],
            'debt_off' => ['#d97706', 'heroicon-m-no-symbol'],
            'city_change' => ['#2563eb', 'heroicon-m-map-pin'],
            'phone_change' => ['#7c3aed', 'heroicon-m-phone'],
            default => ['#64748b', 'heroicon-m-pencil'],
        };
    @endphp

    <div class="fs-drv">
        @if ($approval)
            <div class="fs-drv__notice" role="status">
                <x-heroicon-o-clock />
                <span>
                    <b>Hồ sơ chờ duyệt {{ (int) $driver->created_at->diffInDays(now()) }} ngày.</b>
                    @foreach ($approval['blockers'] as $b) ⛔ {{ $b }} — chưa thể duyệt. @endforeach
                    @foreach ($approval['warnings'] as $w) ⚠ {{ $w }}. @endforeach
                    {{ $approval['ok'] && ! $approval['warnings'] ? '✓ Giấy tờ đã đủ, có thể duyệt.' : 'Duyệt được, nhưng tài xế chỉ bật online khi CCCD đã được duyệt.' }}
                </span>
            </div>
        @elseif ((int) $driver->status === 2 && $lastLock)
            <div class="fs-drv__notice is-red" role="status">
                <x-heroicon-o-lock-closed />
                <span><b>Bị khóa ngày {{ $lastLock['when']->format('d/m/Y') }}</b> bởi {{ $lastLock['by'] }}{{ $lastLock['reason'] ? ' — '.$lastLock['reason'] : '' }}.</span>
            </div>
        @endif

        {{-- Hồ sơ --}}
        <section class="fs-drv__card fs-drv__profile" aria-label="Hồ sơ tài xế">
            <div class="fs-drv__who">
                {{-- Chữ cái đầu luôn nằm dưới ảnh: ảnh lỗi hoặc chưa có thì vẫn thấy chữ. --}}
                <span class="fs-drv__avatar">
                    {{ $initial ?: '?' }}
                    @if ($photo)
                        <img src="{{ $photo }}" alt="{{ $driver->name }}" onerror="this.remove()">
                    @endif
                </span>
                <div>
                    <h2>{{ $driver->name }}</h2>
                    <p>{{ $driver->phone ?: '—' }} · {{ $driver->city?->name ?? 'Chưa có khu vực' }}</p>
                    <div class="fs-drv__chips">
                        <span class="fs-drv__chip fs-drv__chip--{{ $statusColor }}">{{ $statusLabel }}</span>
                        @if ((int) $driver->status === 1)
                            <span class="fs-drv__chip fs-drv__chip--{{ $driver->is_online ? 'success' : 'gray' }}">{{ $driver->is_online ? 'Đang bật online' : 'Offline' }}</span>
                        @endif
                        @if ($active > 0)<span class="fs-drv__chip fs-drv__chip--blue">{{ $active }} đơn đang chạy</span>@endif
                        @if (! $driver->debt_enabled)<span class="fs-drv__chip fs-drv__chip--gray">Tắt công nợ</span>@endif
                    </div>
                </div>
            </div>
            <dl class="fs-drv__facts">
                <div><dt>CCCD / CMND</dt><dd>{{ $driver->cccd ?: '—' }}</dd></div>
                <div><dt>Phương tiện</dt><dd>{{ trim(($driver->vehicle_type ?: '').' '.($driver->license_plate ?: '')) ?: '—' }}</dd></div>
                <div><dt>Ca đăng ký</dt><dd title="{{ $driver->registeredShifts->pluck('name')->implode(', ') }}">{{ $driver->registeredShifts->pluck('name')->implode(', ') ?: 'Chưa đăng ký' }}</dd></div>
                <div><dt>Mã giới thiệu</dt><dd>{{ $driver->referral_code ?: '—' }}</dd></div>
                <div><dt>Đăng ký</dt><dd>{{ $driver->created_at?->format('d/m/Y H:i') }}</dd></div>
                <div><dt>Tổng đơn hoàn thành</dt><dd>{{ $num($summary['lifetime']) }}</dd></div>
            </dl>
        </section>

        {{-- Hoạt động 30 ngày --}}
        <section class="fs-drv__kpis" aria-label="Hoạt động 30 ngày">
            <article class="fs-drv__kpi fs-drv__kpi--hero">
                <div class="fs-drv__kpi-head"><span>Thu nhập 30 ngày</span><x-heroicon-o-banknotes /></div>
                <strong>{{ $money($summary['earnings']) }}</strong>
                <small>khách trả + công ty chi (freeship, thưởng, bù giảm giá, thưởng mưa)</small>
            </article>
            <article class="fs-drv__kpi fs-drv__kpi--green">
                <div class="fs-drv__kpi-head"><span>Đơn hoàn thành 30 ngày</span><x-heroicon-o-check-circle /></div>
                <strong>{{ $num($summary['completed']) }}</strong>
                <small>trên {{ $num($summary['total']) }} đơn đã nhận</small>
            </article>
            <article class="fs-drv__kpi fs-drv__kpi--{{ $summary['cancel_rate'] >= 10 ? 'red' : 'amber' }}">
                <div class="fs-drv__kpi-head"><span>Tỷ lệ hủy 30 ngày</span><x-heroicon-o-x-circle /></div>
                <strong>{{ $summary['cancel_rate'] }}%</strong>
                <small>{{ $num($summary['cancelled']) }} đơn bị hủy sau khi nhận</small>
            </article>
            <article class="fs-drv__kpi fs-drv__kpi--blue">
                <div class="fs-drv__kpi-head"><span>Số dư ví</span><x-heroicon-o-wallet /></div>
                <strong>{{ $money($wallet['balance']) }}</strong>
                <small>{{ $wallet['debt'] > 0 ? 'còn nợ '.$money($wallet['debt']) : 'không còn nợ' }}{{ $wallet['withdraw'] > 0 ? ' · đang rút '.$money($wallet['withdraw']) : '' }}</small>
            </article>
        </section>

        <div class="fs-drv__grid">
            <section class="fs-drv__card" aria-label="Đơn gần đây">
                <header class="fs-drv__card-head"><div><h2>Đơn gần đây</h2><p>{{ $orders->count() }} đơn mới nhất tài xế nhận</p></div></header>
                @if ($orders->isEmpty())
                    <p class="fs-drv__empty">Tài xế chưa nhận đơn nào.</p>
                @else
                    <ul class="fs-drv__orders">
                        @foreach ($orders as $o)
                            <li>
                                <a class="fs-drv__order" href="{{ $o['url'] }}" wire:navigate>
                                    <span class="fs-drv__order-top">
                                        <b>{{ $o['code'] }}</b>
                                        <small>{{ $o['service'] }}</small>
                                        <span class="fs-drv__chip fs-drv__chip--{{ ['info' => 'blue', 'primary' => 'blue'][$o['color']] ?? $o['color'] }}">{{ $o['status'] }}</span>
                                    </span>
                                    <span class="fs-drv__order-route" title="{{ $o['route'] }}">{{ $o['route'] }}</span>
                                    <span class="fs-drv__order-money"><b>{{ $money($o['fee']) }}</b><small>{{ $o['when']?->format('H:i · d/m/Y') }}</small></span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <div class="fs-drv__stack">
                <section class="fs-drv__card" aria-label="Giấy tờ">
                    <header class="fs-drv__card-head"><div><h2>Giấy tờ</h2><p>Trạng thái bản mới nhất · xét duyệt ở cuối trang</p></div></header>
                    <dl class="fs-drv__rows">
                        @foreach ($docs as $label => [$text, $color])
                            <div><dt>{{ $label }}</dt><dd><span class="fs-drv__chip fs-drv__chip--{{ $color }}">{{ $text }}</span></dd></div>
                        @endforeach
                    </dl>
                </section>

                <section class="fs-drv__card" aria-label="Ví và công nợ">
                    <header class="fs-drv__card-head"><div><h2>Ví và công nợ</h2><p>Tình hình tài chính hiện tại</p></div></header>
                    <dl class="fs-drv__rows">
                        <div><dt>Số dư ví</dt><dd>{{ $money($wallet['balance']) }}</dd></div>
                        <div><dt>Công nợ chưa thu</dt><dd @class(['is-alert' => $wallet['debt'] > 0])>{{ $money($wallet['debt']) }}{{ $wallet['debt_items'] ? ' ' : '' }}@if ($wallet['debt_items'])<small>({{ $wallet['debt_items'] }} khoản)</small>@endif</dd></div>
                        <div><dt>Đang xin rút</dt><dd>{{ $money($wallet['withdraw']) }}{{ $wallet['withdraw_items'] ? ' ' : '' }}@if ($wallet['withdraw_items'])<small>({{ $wallet['withdraw_items'] }} yêu cầu)</small>@endif</dd></div>
                    </dl>
                </section>

                <section class="fs-drv__card" aria-label="Lịch sử tài khoản">
                    <header class="fs-drv__card-head"><div><h2>Lịch sử tài khoản</h2><p>Duyệt, khóa, đổi khu vực, đổi số, công nợ</p></div></header>
                    @if ($logs->isEmpty())
                        <p class="fs-drv__empty">Chưa có thao tác nào được ghi lại.</p>
                    @else
                        <ol class="fs-drv__timeline">
                            @foreach ($logs as $l)
                                @php [$color, $icon] = $tone($l['key']); @endphp
                                <li style="--tone: {{ $color }}">
                                    <span class="fs-drv__dot"><x-dynamic-component :component="$icon" /></span>
                                    <b>{{ $l['action'] }}</b>
                                    <small>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['by'] }}</small>
                                    @if ($l['reason'])<q>{{ $l['reason'] }}</q>@endif
                                </li>
                            @endforeach
                        </ol>
                    @endif
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
    </div>
</x-filament-panels::page>
