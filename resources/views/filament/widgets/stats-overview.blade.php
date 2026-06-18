<x-filament-widgets::widget>
    <style>
        .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;padding:0.25rem;}
        @media(max-width:900px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
        @media(max-width:480px){.stats-grid{grid-template-columns:1fr;}}
        .stat-card{border-radius:16px;padding:20px 20px 16px;position:relative;overflow:hidden;}
        .stat-bubble{position:absolute;right:-14px;bottom:-14px;width:76px;height:76px;border-radius:50%;}
        .stat-top{display:flex;align-items:center;gap:10px;margin-bottom:14px;}
        .stat-icon{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
        .stat-label{font-size:0.75rem;font-weight:600;line-height:1.3;}
        .stat-number{font-size:2.1rem;font-weight:800;color:#111827;line-height:1;}
        .stat-desc{font-size:0.68rem;margin-top:5px;}
    </style>

    <div class="stats-grid">

        <div class="stat-card" style="background:linear-gradient(135deg,#fff7ed,#ffedd5);border:1px solid #fed7aa;">
            <div class="stat-bubble" style="background:#f9731625;"></div>
            <div class="stat-top">
                <div class="stat-icon" style="background:#f97316;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 11H4L5 9z"/></svg>
                </div>
                <span class="stat-label" style="color:#9a3412;">Tổng đơn hôm nay</span>
            </div>
            <div class="stat-number">{{ $totalOrders }}</div>
            <div class="stat-desc" style="color:#c2410c;">đơn được tạo trong ngày</div>
        </div>

        <div class="stat-card" style="background:linear-gradient(135deg,#f0fdf4,#dcfce7);border:1px solid #bbf7d0;">
            <div class="stat-bubble" style="background:#22c55e25;"></div>
            <div class="stat-top">
                <div class="stat-icon" style="background:#22c55e;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <span class="stat-label" style="color:#14532d;">Đơn hoàn thành</span>
            </div>
            <div class="stat-number">{{ $completedOrders }}</div>
            <div class="stat-desc" style="color:#16a34a;">{{ $totalOrders > 0 ? round($completedOrders / $totalOrders * 100) : 0 }}% tỉ lệ hoàn thành</div>
        </div>

        <div class="stat-card" style="background:linear-gradient(135deg,#eff6ff,#dbeafe);border:1px solid #bfdbfe;">
            <div class="stat-bubble" style="background:#3b82f625;"></div>
            <div class="stat-top">
                <div class="stat-icon" style="background:#3b82f6;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                </div>
                <span class="stat-label" style="color:#1e3a8a;">Tài xế online</span>
            </div>
            <div class="stat-number">{{ $driversOnline }}</div>
            <div class="stat-desc" style="color:#2563eb;">sẵn sàng nhận đơn</div>
        </div>

        <div class="stat-card" style="background:linear-gradient(135deg,#fefce8,#fef9c3);border:1px solid #fde68a;">
            <div class="stat-bubble" style="background:#f59e0b25;"></div>
            <div class="stat-top">
                <div class="stat-icon" style="background:#f59e0b;">
                    <svg width="16" height="16" fill="none" viewBox="0 0 24 24" stroke="white" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <span class="stat-label" style="color:#78350f;">Doanh thu hôm nay</span>
            </div>
            <div class="stat-number">{{ $revenue }}</div>
            <div class="stat-desc" style="color:#d97706;">phí giao hàng hoàn thành</div>
        </div>

    </div>
</x-filament-widgets::widget>
