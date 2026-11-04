<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-shift-changes', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/shift-change.css') }}?v={{ filemtime(public_path('css/shift-change.css')) }}">
    @php
        $R = \App\Filament\Resources\DriverShiftChangeRequestResource::class;
        $o = $this->getOverview();
        $r = $record;
        $driver = $r->driver;
        [$statusLabel, $statusColor] = $R::statusLabel($r->status);
        $pos = fn (int $m) => round($m / 14.4, 2);
        $nowMinute = now()->hour * 60 + now()->minute;
        $currentIds = $o['current']->pluck('id')->all();
        $bars = function ($shifts, bool $new) use ($pos, $currentIds) {
            $html = '';
            foreach ($shifts as $s) {
                foreach (\App\Services\ShiftService::segments($s->start_time, $s->end_time) as [$a, $b]) {
                    $kept = $new && in_array($s->id, $currentIds, true);
                    $html .= '<i'.($kept ? ' class="is-kept"' : '').' style="left:'.$pos($a).'%;width:'.$pos($b - $a).'%" title="'.e($s->name.' '.substr($s->start_time, 0, 5).'–'.substr($s->end_time, 0, 5)).'">'.e($s->name).'</i>';
                }
            }

            return $html;
        };
        $initial = mb_strtoupper(mb_substr((string) $driver?->name, 0, 1)) ?: '?';
    @endphp

    <div class="fs-chg">
        @if ($o['can'] && ! $o['can']['ok'])
            <div class="fs-chg__notice is-red" role="status">
                <x-heroicon-o-no-symbol />
                <span><b>Không duyệt được:</b> {{ $o['can']['reason'] }}. Dùng "Từ chối vì không hợp lệ" để báo tài xế.</span>
            </div>
        @elseif ($o['can'] && $o['can']['note'])
            <div class="fs-chg__notice is-blue" role="status">
                <x-heroicon-o-information-circle />
                <span><b>Duyệt được ngay.</b> {{ $o['can']['note'] }}</span>
            </div>
        @elseif ($o['can'])
            <div class="fs-chg__notice" role="status">
                <x-heroicon-o-check-circle />
                <span><b>Duyệt được ngay.</b> {{ $R::waitLabel($r) }}.</span>
            </div>
        @endif

        <section class="fs-chg__card" aria-label="Yêu cầu">
            <div class="fs-chg__head">
                <div class="fs-chg__who">
                    <span class="fs-chg__avatar" aria-hidden="true">{{ $initial }}</span>
                    <div>
                        <b>{{ $driver?->name ?? 'Tài xế' }}</b>
                        <small>{{ $driver?->phone ?? '—' }} · {{ $driver?->city?->name ?? '—' }} · {{ $driver?->is_online ? 'đang online' : 'offline' }}</small>
                    </div>
                </div>
                <div class="fs-chg__chips">
                    <span class="fs-chg__chip fs-chg__chip--{{ $statusColor }}">{{ $statusLabel }}</span>
                    @foreach ($o['flags'] as $f)<span class="fs-chg__chip fs-chg__chip--{{ $f['level'] === 'danger' ? 'danger' : 'warning' }}">{{ $f['label'] }}</span>@endforeach
                </div>
            </div>

            <div class="fs-chg__swap" aria-label="Ca hiện tại và ca đề nghị trong ngày">
                <div class="fs-chg__scale" aria-hidden="true">@foreach ([0, 3, 6, 9, 12, 15, 18, 21, 24] as $h)<em style="left: {{ $h / 24 * 100 }}%">{{ sprintf('%02d', $h) }}</em>@endforeach</div>
                <div class="fs-chg__swap-row">
                    <span>Ca hiện tại</span>
                    <span class="fs-chg__track fs-chg__track--now">{!! $bars($o['current'], false) !!}<u style="left: {{ $pos($nowMinute) }}%"></u></span>
                </div>
                <div class="fs-chg__swap-row">
                    <span>Ca đề nghị</span>
                    <span class="fs-chg__track fs-chg__track--new">{!! $bars($o['requested'], true) !!}<u style="left: {{ $pos($nowMinute) }}%"></u></span>
                </div>
            </div>
        </section>

        <div class="fs-chg__grid">
            <div class="fs-chg__stack">
                <section class="fs-chg__card" aria-label="Tác động lên từng ca">
                    <header class="fs-chg__card-head"><div><h2>Tác động lên từng ca</h2><p>{{ $r->status === 'pending' ? 'Số tài xế hoạt động và tải đơn của mỗi ca, trước → sau khi duyệt' : 'Chỉ tính cho yêu cầu đang chờ' }}</p></div></header>
                    @if ($o['impact']->isEmpty())
                        <p class="fs-chg__empty">{{ $r->status === 'pending' ? 'Không có dữ liệu tác động.' : 'Yêu cầu đã được xử lý.' }}</p>
                    @else
                        <ul class="fs-chg__impact">
                            @foreach ($o['impact'] as $i)
                                <li>
                                    <span><b>{{ $i['shift']->name }}</b><small>{{ substr($i['shift']->start_time, 0, 5) }}–{{ substr($i['shift']->end_time, 0, 5) }} · {{ $i['before'] }} → {{ $i['after'] }} tài xế</small></span>
                                    <span class="fs-chg__delta {{ $i['delta'] > 0 ? 'is-plus' : ($i['delta'] < 0 ? 'is-minus' : 'is-same') }}">{{ $i['delta'] > 0 ? '+1' : ($i['delta'] < 0 ? '−1' : 'giữ') }}</span>
                                    <span @class(['fs-chg__load', 'is-warn' => $i['warn']])>{{ $i['perBefore'] ?? '—' }} → {{ $i['perAfter'] ?? '—' }} đơn/tài xế/ngày</span>
                                    @if ($i['warn'])<span class="fs-chg__warn">⚠ {{ $i['warn'] }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>

                <section class="fs-chg__card" aria-label="Quá trình xử lý">
                    <header class="fs-chg__card-head"><div><h2>Quá trình xử lý</h2></div></header>
                    <ol class="fs-chg__timeline">
                        <li style="--tone: #4338ca">
                            <span class="fs-chg__dot"><x-heroicon-m-paper-airplane /></span>
                            <b>Tài xế gửi yêu cầu</b>
                            <small>{{ $r->created_at->format('H:i · d/m/Y') }}</small>
                        </li>
                        @if ($r->status === 'pending')
                            <li class="is-pending" style="--tone: #d97706">
                                <span class="fs-chg__dot"><x-heroicon-m-clock /></span>
                                <b>Chờ admin duyệt</b>
                                <small>{{ $R::waitLabel($r) }}</small>
                            </li>
                        @else
                            <li style="--tone: {{ $r->status === 'approved' ? '#16a34a' : '#dc2626' }}">
                                <span class="fs-chg__dot">@if ($r->status === 'approved')<x-heroicon-m-check />@else<x-heroicon-m-x-mark />@endif</span>
                                <b>{{ $statusLabel }}</b>
                                <small>{{ $r->processed_at?->format('H:i · d/m/Y') }} · {{ $r->processor?->name ?? 'Quản trị viên' }}</small>
                                @if ($r->admin_note)<q>{{ $r->admin_note }}</q>@endif
                            </li>
                        @endif
                    </ol>
                </section>
            </div>

            <section class="fs-chg__card" aria-label="Các yêu cầu trước">
                <header class="fs-chg__card-head"><div><h2>Các yêu cầu trước</h2><p>8 yêu cầu gần nhất của tài xế</p></div></header>
                @if ($o['history']->isEmpty())
                    <p class="fs-chg__empty">Đây là yêu cầu đầu tiên.</p>
                @else
                    <ul class="fs-chg__history">
                        @foreach ($o['history'] as $h)
                            @php [$hl, $hc] = $R::statusLabel($h->status); @endphp
                            <li>
                                <a href="{{ $R::getUrl('view', ['record' => $h]) }}" wire:navigate>
                                    <span><b>{{ $h->created_at->format('d/m/Y') }}</b> <small>{{ $h->admin_note ?: '' }}</small></span>
                                    <span class="fs-chg__chip fs-chg__chip--{{ $hc }}">{{ $hl }}</span>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
