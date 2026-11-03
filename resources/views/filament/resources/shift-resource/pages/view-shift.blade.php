<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-shifts', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/shift.css') }}?v={{ filemtime(public_path('css/shift.css')) }}">
    @php
        $o = $this->getOverview();
        $r = $o['row'];
        $num = fn ($v) => number_format((float) $v, 0, ',', '.');
        $dec = fn ($v) => number_format((float) $v, 1, ',', '.');
        $hours = $o['hours'];
        $maxH = max(0.1, ...array_values($hours ?: [0.1]));
        $roster = $o['roster'];
        $now = $record->is_active && $record->isNowInShift();
        [$pillLabel, $pillClass] = ! $record->is_active ? ['Đã tắt', 'gray'] : ($now ? ['Đang trong ca', 'success'] : ['Ngoài giờ ca', 'info']);
        $tags = [1 => ['Hoạt động', 'active'], 2 => ['Đã khóa', 'locked'], 0 => ['Chờ duyệt', 'pending']];
        $tone = fn (string $a) => match ($a) {
            'activated' => ['#16a34a', 'heroicon-m-play'],
            'deactivated' => ['#64748b', 'heroicon-m-pause'],
            'time_changed' => ['#d97706', 'heroicon-m-clock'],
            'deleted' => ['#dc2626', 'heroicon-m-trash'],
            default => ['#2563eb', 'heroicon-m-plus'],
        };
    @endphp

    <div class="fs-shift">
        @if (! $record->is_active)
            <div class="fs-shift__alert" role="status">
                <x-heroicon-o-pause-circle />
                <span>Ca đang <b>tắt</b>: không cho tài xế đăng ký mới và không tính phạt Offline. {{ $r['active'] > 0 ? 'Còn '.$num($r['active']).' tài xế hoạt động đã đăng ký, cần xếp họ sang ca khác.' : '' }}</span>
            </div>
        @elseif ($r['active'] === 0)
            <div class="fs-shift__alert" role="status">
                <x-heroicon-o-exclamation-triangle />
                <span>Ca đang bật nhưng <b>chưa có tài xế hoạt động nào đăng ký</b>. Khung giờ này chưa có ai phục vụ.</span>
            </div>
        @endif

        <section class="fs-shift__card fs-shift__summary">
            <div class="fs-shift__window">
                <span class="fs-shift__pill fs-shift__pill--{{ $pillClass }}">{{ $pillLabel }}</span>
                <strong>{{ substr($record->start_time, 0, 5) }} → {{ substr($record->end_time, 0, 5) }}</strong>
                {!! \App\Filament\Resources\ShiftResource::miniBar($record) !!}
            </div>
            <div>
                <span>Tài xế hoạt động</span>
                <strong>{{ $num($r['active']) }}</strong>
                <small>{{ $num($r['online']) }} online{{ $r['locked'] ? ' · '.$num($r['locked']).' đã khóa' : '' }}</small>
            </div>
            <div>
                <span>Đơn / ngày</span>
                <strong>{{ $dec($r['perDay']) }}</strong>
                <small>trung bình {{ \App\Services\ShiftService::DEMAND_DAYS }} ngày qua, theo giờ đặt</small>
            </div>
            <div>
                <span>Đơn / tài xế / ngày</span>
                <strong @class(['is-alert' => $r['overloaded']])>{{ $r['perDriver'] !== null ? $dec($r['perDriver']) : '—' }}</strong>
                <small>{{ $r['overloaded'] ? 'quá tải (từ '.$o['overload'].')' : 'trong ngưỡng' }}</small>
            </div>
        </section>

        <div class="fs-shift__grid">
            <div class="fs-shift__stack">
                <section class="fs-shift__card">
                    <header class="fs-shift__card-head"><div><h2>Đơn theo giờ</h2><p>Đơn hoàn thành trung bình mỗi ngày, tính theo giờ khách đặt</p></div></header>
                    <div class="fs-shift__chart" role="img" aria-label="Biểu đồ đơn theo giờ trong ca">
                        @foreach ($hours as $h => $v)
                            <div @class(['fs-shift__col', 'is-peak' => $v > 0 && $v >= $maxH])>
                                <b>{{ $v > 0 ? $dec($v) : '' }}</b>
                                <span class="fs-shift__col-bar"><i style="height: {{ max(2, round($v / $maxH * 100)) }}%"></i></span>
                                <small>{{ sprintf('%02d', $h) }}h</small>
                            </div>
                        @endforeach
                    </div>
                    @if ($maxH > 0.1)
                        <p class="fs-shift__chart-note">Giờ cao điểm: {{ collect($hours)->filter(fn ($v) => $v >= $maxH)->keys()->map(fn ($h) => sprintf('%02d:00', $h))->join(', ') }} · {{ $dec($maxH) }} đơn/ngày</p>
                    @endif
                </section>

                <section class="fs-shift__card">
                    <header class="fs-shift__card-head"><div><h2>Tài xế đăng ký ca</h2><p>{{ $num($roster->count()) }} người · tài xế hoạt động xếp trước</p></div></header>
                    @if ($roster->isEmpty())
                        <p class="fs-shift__empty">Chưa có tài xế nào đăng ký ca này.</p>
                    @else
                        <div class="fs-shift__roster">
                            @foreach ($roster as $d)
                                @php [$tagLabel, $tagKey] = $tags[$d['status']] ?? ['Không rõ', 'active']; @endphp
                                <a href="{{ $d['url'] }}" wire:navigate>
                                    <b>{{ $d['name'] }}</b>
                                    <small>{{ $d['phone'] }}</small>
                                    @if ($d['online'])
                                        <span class="fs-shift__tag fs-shift__tag--online">Online</span>
                                    @else
                                        <span class="fs-shift__tag fs-shift__tag--{{ $tagKey }}">{{ $tagLabel }}</span>
                                    @endif
                                </a>
                            @endforeach
                        </div>
                    @endif
                </section>
            </div>

            <section class="fs-shift__card">
                <header class="fs-shift__card-head"><div><h2>Nhật ký</h2><p>Tạo, đổi giờ, bật/tắt ca · mới nhất ở trên</p></div></header>
                @if ($o['logs']->isEmpty())
                    <p class="fs-shift__empty">Chưa có thay đổi nào được ghi lại.</p>
                @else
                    <ol class="fs-shift__timeline">
                        @foreach ($o['logs'] as $l)
                            @php [$color, $icon] = $tone($l['action']); @endphp
                            <li style="--tone: {{ $color }}">
                                <span class="fs-shift__dot"><x-dynamic-component :component="$icon" /></span>
                                <b>{{ $l['label'] }}</b>
                                <small>{{ $l['when']->format('H:i · d/m/Y') }} · {{ $l['who'] }}</small>
                                @if ($l['detail'])<q>{{ $l['detail'] }}</q>@endif
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
