<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-leaves', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/leave.css') }}?v={{ filemtime(public_path('css/leave.css')) }}">
    @php
        $R = \App\Filament\Resources\DriverLeaveRequestResource::class;
        $o = $this->getOverview();
        $l = $record;
        $driver = $l->driver;
        $weekday = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'][$l->leave_date->dayOfWeek];
        $tone = fn (string $a) => match ($a) {
            'approved', 'created' => ['#16a34a', 'heroicon-m-check'],
            'requested' => ['#d97706', 'heroicon-m-paper-airplane'],
            'rejected' => ['#dc2626', 'heroicon-m-x-mark'],
            'cancelled', 'withdrawn' => ['#64748b', 'heroicon-m-arrow-uturn-left'],
            default => ['#2563eb', 'heroicon-m-pencil'],
        };
    @endphp

    <div class="fs-lv">
        @if ($l->isPending() && $l->leave_date->lt(today()))
            <div class="fs-lv__notice is-gray" role="status">
                <x-heroicon-o-archive-box-x-mark />
                <span><b>Đã qua ngày mà chưa được duyệt.</b> Phiếu không có hiệu lực: tài xế đã phải đi làm như thường. Có thể từ chối để dọn.</span>
            </div>
        @elseif ($l->isPending())
            <div class="fs-lv__notice" role="status">
                <x-heroicon-o-clock />
                <span><b>Chờ duyệt.</b> Khi chưa được duyệt, tài xế vẫn phải đi làm theo ca và vẫn bị tính phạt Offline.</span>
            </div>
        @elseif ($l->isApproved() && $l->leave_date->isToday())
            <div class="fs-lv__notice is-teal" role="status">
                <x-heroicon-o-calendar-days />
                <span><b>Đang nghỉ hôm nay.</b> Tài xế không bật được online và không bị tính phạt Offline. Hủy phiếu thì phạt chỉ tính từ lúc hủy.</span>
            </div>
        @endif

        <section class="fs-lv__card fs-lv__summary" aria-label="Phiếu nghỉ">
            <div class="fs-lv__date">
                <span class="fs-lv__sheet" aria-hidden="true">
                    <span>Tháng {{ $l->leave_date->month }}</span>
                    <b>{{ $l->leave_date->format('d') }}</b>
                    <small>{{ $l->leave_date->format('Y') }}</small>
                </span>
                <div class="fs-lv__date-info">
                    <strong>{{ $weekday }}, {{ $l->leave_date->format('d/m/Y') }}</strong>
                    <span>{{ $R::dateDescription($l->leave_date) }}</span>
                    <div class="fs-lv__chips">
                        <span class="fs-lv__chip fs-lv__chip--{{ $l->statusColor() }}">{{ $l->statusLabel() }}</span>
                        @foreach ($o['flags'] as $f)<span class="fs-lv__chip fs-lv__chip--{{ $f['level'] }}">{{ $f['label'] }}</span>@endforeach
                    </div>
                </div>
            </div>
            <dl class="fs-lv__facts">
                <div><dt>Tài xế</dt><dd><a href="{{ \App\Filament\Resources\DriverResource::getUrl('view', ['record' => $l->driver_id]) }}" wire:navigate>{{ $driver?->name ?? '—' }}</a> · {{ $driver?->phone }}</dd></div>
                <div><dt>Ca được miễn phạt Offline</dt><dd>{{ $o['shifts']->map(fn ($s) => $s->name.' '.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5))->implode(', ') ?: 'Chưa đăng ký ca' }}</dd></div>
                <div><dt>Đơn hoàn thành trong ngày</dt><dd>{{ $o['orders'] }}{{ $o['ordersBefore'] ? ' · '.$o['ordersBefore'].' xong trước khi ghi nhận' : '' }}</dd></div>
                <div><dt>{{ $l->created_by === $l->driver_id ? 'Tài xế tự xin' : 'Ghi nhận bởi' }}</dt><dd>{{ $l->creator?->name ?? 'Không xác định' }} · {{ $l->created_at?->format('H:i d/m/Y') }}</dd></div>
            </dl>
        </section>

        <div class="fs-lv__grid">
            <section class="fs-lv__card" aria-label="Lý do và nhật ký">
                <header class="fs-lv__card-head"><div><h2>Lý do và nhật ký</h2><p>Mọi thao tác trên phiếu nghỉ này, mới nhất ở trên</p></div></header>
                <blockquote class="fs-lv__quote">{{ $l->note ?: 'Không ghi lý do.' }}</blockquote>
                @if ($o['logs']->isEmpty())
                    <p class="fs-lv__empty">Phiếu tạo trước khi có nhật ký.</p>
                @else
                    <ol class="fs-lv__timeline">
                        @foreach ($o['logs'] as $g)
                            @php [$color, $icon] = $tone($g['action']); @endphp
                            <li style="--tone: {{ $color }}">
                                <span class="fs-lv__dot"><x-dynamic-component :component="$icon" /></span>
                                <b>{{ $g['label'] }}</b>
                                <small>{{ $g['when']->format('H:i · d/m/Y') }} · {{ $g['who'] }}</small>
                                @if ($g['note'])<q>{{ $g['note'] }}</q>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>

            <section class="fs-lv__card" aria-label="Lịch sử nghỉ">
                <header class="fs-lv__card-head"><div><h2>Lịch sử nghỉ 90 ngày</h2><p>{{ $o['history']->count() }} lượt của tài xế</p></div></header>
                @if ($o['history']->isEmpty())
                    <p class="fs-lv__empty">Chưa có lượt nghỉ nào.</p>
                @else
                    <ul class="fs-lv__history">
                        @foreach ($o['history'] as $h)
                            <li>
                                <a href="{{ $R::getUrl('view', ['record' => $h]) }}" wire:navigate>
                                    <span><b>{{ $h->leave_date->format('d/m/Y') }}</b> <small>{{ \Illuminate\Support\Str::limit($h->note ?: '', 34) }}</small></span>
                                    <span class="fs-lv__chip fs-lv__chip--{{ $h->statusColor() }}">{{ $h->statusLabel() }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
