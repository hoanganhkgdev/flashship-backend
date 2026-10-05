<?php

namespace Tests\Feature;

use App\Filament\Resources\DriverRatingResource;
use App\Filament\Resources\DriverRatingResource\Pages\ListDriverRatings;
use App\Filament\Resources\DriverRatingResource\Pages\ViewDriverRating;
use App\Services\DriverRatingService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Modules\Order\Models\Order;
use Tests\TestCase;

class DriverRatingTest extends TestCase
{
    use DatabaseTransactions;

    private City $city;

    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->city = City::create(['name' => 'Rating city', 'slug' => 'rating-city-'.uniqid(), 'is_active' => true]);
        $admin = $this->user('admin');
        $this->driver = $this->user('driver');
        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->city);
    }

    private function user(string $type): User
    {
        return User::create([
            'name' => ucfirst($type).' rating', 'email' => 'rt-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => $type, 'status' => 1, 'city_id' => $this->city->id,
        ]);
    }

    private function rated(int $stars, ?string $note = null, ?int $driverId = null): Order
    {
        return Order::create([
            'code' => 'RT'.uniqid(), 'status' => 'completed', 'delivery_man_id' => $driverId ?? $this->driver->id, 'city_id' => $this->city->id,
            'pickup_address' => 'a', 'delivery_address' => 'b', 'service_type' => 'delivery',
            'driver_rating' => $stars, 'driver_rating_note' => $note, 'rated_at' => now(), 'completed_at' => now(),
        ]);
    }

    public function test_pending_counts_only_unhandled_low_ratings(): void
    {
        $low = $this->rated(1, 'chậm');
        $this->rated(2);
        $this->rated(5);
        $this->assertSame(2, DriverRatingService::pendingCount($this->city->id));

        DriverRatingService::handle($low, 'Đã gọi tài xế', 1);
        $this->assertSame(1, DriverRatingService::pendingCount($this->city->id));
        $this->assertNotNull($low->fresh()->rating_handled_at);
        $this->assertSame('chậm', $low->fresh()->driver_rating_note, 'xử lý không được xóa nhận xét');
    }

    public function test_hidden_rating_leaves_stats_but_keeps_content_and_can_be_restored(): void
    {
        $bad = $this->rated(1, 'tệ');
        $this->rated(5);
        $this->assertSame(3.0, DriverRatingService::summary($this->city->id)['avg']);

        DriverRatingService::hide($bad, 'Khách nhầm đơn', 1);
        $s = DriverRatingService::summary($this->city->id);
        $this->assertSame(5.0, $s['avg']);
        $this->assertSame(1, $s['count']);
        $this->assertSame(0, $s['pending']);
        $this->assertSame('tệ', $bad->fresh()->driver_rating_note);

        DriverRatingService::unhide($bad->fresh());
        $this->assertSame(3.0, DriverRatingService::summary($this->city->id)['avg']);
        $this->assertSame(1, DriverRatingService::pendingCount($this->city->id));
    }

    public function test_watch_list_needs_minimum_reviews_and_orders_by_average(): void
    {
        $other = $this->user('driver');
        $this->rated(2, null, $this->driver->id);
        $this->rated(3, null, $this->driver->id);
        $this->rated(1, null, $other->id); // chỉ 1 lượt: chưa đủ để xếp hạng

        $list = DriverRatingService::watchList($this->city->id);
        $this->assertCount(1, $list);
        $this->assertSame($this->driver->id, $list[0]['id']);
        $this->assertSame(2.5, $list[0]['avg']);
    }

    public function test_pages_render_and_actions_require_a_note(): void
    {
        $o = $this->rated(1, 'giao chậm');

        Livewire::test(ListDriverRatings::class)->assertSuccessful()->assertCanSeeTableRecords([$o]);

        Livewire::test(ListDriverRatings::class)
            ->callTableAction('handle', $o, ['note' => ''])->assertHasTableActionErrors(['note' => 'required']);

        Livewire::test(ListDriverRatings::class)
            ->callTableAction('handle', $o, ['note' => 'Đã nhắc nhở'])->assertHasNoTableActionErrors();
        $this->assertNotNull($o->fresh()->rating_handled_at);
        $this->assertNotNull($o->fresh()->driver_rating, 'không còn nút xóa đánh giá');

        Livewire::test(ListDriverRatings::class)->assertTableActionDoesNotExist('delete_rating');
        Livewire::test(ViewDriverRating::class, ['record' => $o->id])->assertSuccessful()->assertSee('giao chậm');
    }
}
