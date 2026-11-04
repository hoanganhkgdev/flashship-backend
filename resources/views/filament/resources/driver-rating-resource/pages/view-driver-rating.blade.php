<x-filament-panels::page
    @class(['fi-resource-view-record-page', 'fi-resource-driver-ratings', 'fi-resource-record-'.$record->getKey()])
>
    <link rel="stylesheet" href="{{ asset('css/rating.css') }}?v={{ filemtime(public_path('css/rating.css')) }}">
    @php
        $o = $record;
        $res = \App\Filament\Resources\DriverRatingResource::class;
        $svc = \App\Services\DriverRatingService::class;
        [$statusLabel, $statusColor] = $res::statusLabel($o);
        $handler = $this->getHandler();
        $others = $this->getOtherRatings();
        $logs = $this->getLogs();
        $driverUrl = $this->driverUrl();
        $driver = $svc::driverLabel($o);
        $service = $res::serviceLabels()[$o->service_type] ?? $o->service_type;
        $rating = (int) $o->driver_rating;
        $tone = $rating <= 2 ? '#dc2626' : ($rating === 3 ? '#f59e0b' : '#16a34a');
        $sender = $o->sender?->name ?: $o->sender_name;
        $logTone = fn (string $a) => match ($a) {
            'handled' => ['#16a34a', 'heroicon-m-check'],
            'hidden' => ['#64748b', 'heroicon-m-eye-slash'],
            'unhidden' => ['#2563eb', 'heroicon-m-eye'],
            default => ['#64748b', 'heroicon-m-pencil'],
        };
    @endphp

    <div class="fs-rate">
        @if ($svc::needsAction($o))
            <div class="fs-rate__notice" role="status">
                <x-heroicon-o-exclamation-triangle />
                <span><b>Đánh giá thấp chưa xử lý.</b> Liên hệ tài xế hoặc người gửi, rồi bấm "Đã xử lý" và ghi lại kết quả.</span>
            </div>
        @elseif ($o->rating_hidden)
            <div class="fs-rate__notice is-gray" role="status">
                <x-heroicon-o-eye-slash />
                <span><b>Đánh giá đang bị ẩn khỏi thống kê:</b> không tính vào điểm trung bình của tài xế. Lý do nằm trong nhật ký bên dưới.</span>
            </div>
        @endif

        <section class="fs-rate__card fs-rate__review" aria-label="Đánh giá">
            <div class="fs-rate__review-main">
                <div class="fs-rate__score">
                    <strong>{{ $rating }}<small>/5</small></strong>
                    <span class="fs-rate__stars" style="--rt-star: {{ $tone }}" role="img" aria-label="{{ $rating }} sao"><i style="--fill: {{ $rating * 20 }}%"></i></span>
                    <span class="fs-rate__chip fs-rate__chip--{{ $statusColor }}">{{ $statusLabel }}</span>
                </div>
                <blockquote @class(['fs-rate__quote', 'is-empty' => ! $o->driver_rating_note]) style="--tone: {{ $tone }}">{{ $o->driver_rating_note ?: 'Không để lại nhận xét.' }}</blockquote>
                <span class="fs-rate__meta">{{ $res::sourceLabel($o) }}{{ $sender ? ' · '.$sender : '' }} · đánh giá lúc {{ $o->rated_at?->format('H:i d/m/Y') ?? '—' }}</span>
            </div>
            <dl class="fs-rate__facts">
                <div><dt>Tài xế</dt><dd>@if ($driverUrl)<a href="{{ $driverUrl }}" wire:navigate>{{ $driver['name'] }}</a>@else{{ $driver['name'] }}@endif
                    @if ($driver['deleted'])<small>tài khoản đã bị xóa</small>@endif</dd></div>
                <div><dt>SĐT tài xế</dt><dd>{{ $driver['phone'] ?: '—' }}</dd></div>
                <div><dt>Đơn</dt><dd><a href="{{ \App\Filament\Resources\OrderResource::getUrl('view', ['record' => $o]) }}" wire:navigate>#{{ $o->code }}</a> · {{ $service }}</dd></div>
                <div><dt>Hoàn thành</dt><dd>{{ $o->completed_at?->format('H:i d/m/Y') ?? '—' }}</dd></div>
                <div><dt>SĐT người gửi</dt><dd>{{ $o->sender?->phone ?: '—' }}</dd></div>
            </dl>
        </section>

        <div class="fs-rate__grid">
            <section class="fs-rate__card" aria-label="Xử lý">
                <header class="fs-rate__card-head"><div><h2>Xử lý và nhật ký</h2><p>Đánh giá không bao giờ bị xóa: chỉ đánh dấu đã xử lý hoặc ẩn khỏi thống kê</p></div></header>
                <ol class="fs-rate__timeline">
                    @foreach ($logs as $l)
                        @php [$color, $icon] = $logTone($l->action); @endphp
                        <li style="--tone: {{ $color }}">
                            <span class="fs-rate__dot"><x-dynamic-component :component="$icon" /></span>
                            <b>{{ $svc::LOG_LABELS[$l->action] ?? $l->action }}</b>
                            <small>{{ \Carbon\Carbon::parse($l->created_at)->format('H:i · d/m/Y') }} · {{ $l->by ?? 'Quản trị viên' }}</small>
                            @if ($l->detail)<q>{{ $l->detail }}</q>@endif
                        </li>
                    @endforeach
                    {{-- Đánh giá xử lý trước khi có nhật ký: vẫn hiện thông tin xử lý lưu trên đơn. --}}
                    @if ($o->rating_handled_at && ! $logs->contains('action', 'handled'))
                        <li style="--tone: #16a34a">
                            <span class="fs-rate__dot"><x-heroicon-m-check /></span>
                            <b>Đã xử lý</b>
                            <small>{{ $o->rating_handled_at->format('H:i · d/m/Y') }} · {{ $handler?->name ?? 'Quản trị viên' }}</small>
                            @if ($o->rating_handle_note)<q>{{ $o->rating_handle_note }}</q>@endif
                        </li>
                    @endif
                    <li style="--tone: {{ $tone }}">
                        <span class="fs-rate__dot"><x-heroicon-m-star /></span>
                        <b>{{ $res::sourceLabel($o) }} đánh giá {{ $rating }} sao</b>
                        <small>{{ $o->rated_at?->format('H:i · d/m/Y') ?? '—' }}</small>
                    </li>
                </ol>
            </section>

            <section class="fs-rate__card" aria-label="Đánh giá khác của tài xế">
                <header class="fs-rate__card-head"><div><h2>Đánh giá khác của tài xế</h2><p>{{ $o->delivery_man_id ? '6 đánh giá gần nhất của '.$driver['name'] : 'Tài xế đã bị xóa nên không ghép được các đánh giá khác' }}</p></div></header>
                @if ($others->isEmpty())
                    <p class="fs-rate__empty">{{ $o->delivery_man_id ? 'Chưa có đánh giá nào khác.' : '—' }}</p>
                @else
                    <ul class="fs-rate__others">
                        @foreach ($others as $r)
                            <li>
                                <a href="{{ $res::getUrl('view', ['record' => $r->id]) }}" wire:navigate>
                                    <span class="fs-rate__stars" style="--rt-star: {{ $r->driver_rating <= 2 ? '#dc2626' : ($r->driver_rating == 3 ? '#f59e0b' : '#16a34a') }}"><i style="--fill: {{ (int) $r->driver_rating * 20 }}%"></i></span>
                                    <span>{{ $r->driver_rating_note ?: 'Không có nhận xét' }}</span>
                                    <small>#{{ $r->code }} · {{ $r->rated_at?->format('d/m/Y') }}</small>
                                </a>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</x-filament-panels::page>
