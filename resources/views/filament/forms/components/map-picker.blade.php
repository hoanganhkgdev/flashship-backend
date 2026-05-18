@once
<script>
    function flashshipMapPicker() {
        return {
            map: null,
            marker: null,

            init() {
                const lat = parseFloat(this.$wire.data.lat) || 10.7769;
                const lng = parseFloat(this.$wire.data.lng) || 106.7009;
                const hasCoords = !!(this.$wire.data.lat && this.$wire.data.lng);

                this.map = new google.maps.Map(this.$refs.mapEl, {
                    center: { lat, lng },
                    zoom: hasCoords ? 14 : 6,
                    mapTypeControl: false,
                    streetViewControl: false,
                    fullscreenControl: false,
                });

                if (hasCoords) {
                    this.placeMarker(lat, lng);
                }

                this.map.addListener('click', (e) => {
                    const lat = e.latLng.lat();
                    const lng = e.latLng.lng();
                    this.placeMarker(lat, lng);
                    this.sync(lat, lng);
                });
            },

            placeMarker(lat, lng) {
                if (this.marker) {
                    this.marker.setPosition({ lat, lng });
                } else {
                    this.marker = new google.maps.Marker({
                        position: { lat, lng },
                        map: this.map,
                        draggable: true,
                        animation: google.maps.Animation.DROP,
                    });
                    this.marker.addListener('dragend', (e) => {
                        this.sync(e.latLng.lat(), e.latLng.lng());
                    });
                }
            },

            sync(lat, lng) {
                this.$wire.set('data.lat', lat.toFixed(7));
                this.$wire.set('data.lng', lng.toFixed(7));
            },
        };
    }
</script>
@endonce

<div
    x-data="flashshipMapPicker()"
    wire:ignore
    class="col-span-full"
>
    <div x-ref="mapEl" class="w-full rounded-xl overflow-hidden border border-gray-200 dark:border-gray-700" style="height: 420px;"></div>
    <p class="mt-1 text-xs text-gray-400">Nhấn vào bản đồ để ghim vị trí, hoặc kéo điểm đánh dấu để điều chỉnh</p>
</div>
