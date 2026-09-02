<div class="fs-topbar-controls">
    @if (filament()->hasTenancy() && filament()->hasTenantMenu() && filament()->getTenant())
        <div class="fs-topbar-tenant">
            <x-filament-panels::tenant-menu />
        </div>
    @endif

    @livewire('rain-mode-control')
</div>
