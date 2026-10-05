<?php

namespace Tests\Feature;

use App\Jobs\SendNotificationCampaignJob;
use App\Models\NotificationCampaign;
use App\Services\NotificationCampaignService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Models\City;
use Modules\Core\Models\User;
use Tests\TestCase;

/** Service dùng cho test: không gọi Firebase thật, ghi lại những gì sẽ gửi. */
class FakeCampaignService extends NotificationCampaignService
{
    public array $pushed = [];

    public array $pinged = [];

    public bool $pushFails = false;

    protected function push(array $tokens, string $title, string $body): array
    {
        if ($this->pushFails) {
            throw new \RuntimeException('FCM hỏng');
        }
        $this->pushed = $tokens;

        return ['sent' => count($tokens) - 1, 'failed' => 1];
    }

    protected function ping(array $userIds): void
    {
        $this->pinged = array_merge($this->pinged, $userIds);
    }
}

class NotificationCampaignTest extends TestCase
{
    use DatabaseTransactions;

    private City $cityA;

    private City $cityB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertStringEndsWith('_test', DB::connection()->getDatabaseName());
        $this->cityA = City::create(['name' => 'TP A', 'slug' => 'camp-a-'.uniqid()]);
        $this->cityB = City::create(['name' => 'TP B', 'slug' => 'camp-b-'.uniqid()]);
    }

    private function customer(City $city, array $attrs = []): User
    {
        return User::create($attrs + [
            'name' => 'Khách chiến dịch', 'email' => 'camp-'.uniqid().'@test.local', 'phone' => '09'.random_int(10000000, 99999999),
            'password' => 'test-password', 'user_type' => 'customer', 'status' => 1, 'city_id' => $city->id,
        ]);
    }

    private function campaign(array $attrs = []): NotificationCampaign
    {
        return NotificationCampaign::create($attrs + ['title' => 'Ưu đãi', 'body' => 'Giảm phí ship', 'scope' => 'city', 'city_id' => $this->cityA->id, 'status' => 'queued']);
    }

    public function test_inbox_goes_to_every_active_customer_in_city_even_without_push_token(): void
    {
        $withToken = $this->customer($this->cityA, ['fcm_token' => 'tok-1']);
        $noToken = $this->customer($this->cityA);
        $locked = $this->customer($this->cityA, ['status' => 2, 'fcm_token' => 'tok-locked']);
        $otherCity = $this->customer($this->cityB, ['fcm_token' => 'tok-other']);

        $service = new FakeCampaignService;
        $campaign = $this->campaign();
        $service->deliver($campaign);

        $inboxFor = fn (User $u) => DB::table('customer_notifications')->where('user_id', $u->id)->where('campaign_id', $campaign->id)->count();
        $this->assertSame(1, $inboxFor($withToken));
        $this->assertSame(1, $inboxFor($noToken), 'khách không có token vẫn phải có thông báo trong hộp thư');
        $this->assertSame(0, $inboxFor($locked));
        $this->assertSame(0, $inboxFor($otherCity));

        $this->assertSame(['tok-1'], $service->pushed, 'push chỉ gửi cho thiết bị có token của khách đang hoạt động trong khu vực');
        $this->assertEqualsCanonicalizing([$withToken->id, $noToken->id], $service->pinged);

        $campaign->refresh();
        $this->assertSame('sent', $campaign->status);
        $this->assertSame(2, $campaign->inbox_count);
        $this->assertSame(1, $campaign->push_target);
        $this->assertSame(0, $campaign->push_sent);
        $this->assertSame(1, $campaign->push_failed);
        $this->assertNotNull($campaign->finished_at);
    }

    public function test_scope_all_reaches_every_city(): void
    {
        $a = $this->customer($this->cityA);
        $b = $this->customer($this->cityB);

        $campaign = $this->campaign(['scope' => 'all', 'city_id' => null]);
        (new FakeCampaignService)->deliver($campaign);

        foreach ([$a, $b] as $u) {
            $this->assertSame(1, DB::table('customer_notifications')->where('user_id', $u->id)->where('campaign_id', $campaign->id)->count());
        }
    }

    public function test_push_failure_marks_campaign_failed_but_keeps_inbox(): void
    {
        $u = $this->customer($this->cityA, ['fcm_token' => 'tok-x']);
        $service = new FakeCampaignService;
        $service->pushFails = true;
        $campaign = $this->campaign();

        $service->deliver($campaign);

        $campaign->refresh();
        $this->assertSame('failed', $campaign->status);
        $this->assertStringContainsString('FCM hỏng', $campaign->error);
        $this->assertSame(1, DB::table('customer_notifications')->where('user_id', $u->id)->where('campaign_id', $campaign->id)->count());
    }

    public function test_delivering_twice_does_not_send_duplicates(): void
    {
        $u = $this->customer($this->cityA, ['fcm_token' => 'tok-dup']);
        $service = new FakeCampaignService;
        $campaign = $this->campaign();

        $service->deliver($campaign);
        $service->deliver($campaign->fresh());

        $this->assertSame(1, DB::table('customer_notifications')->where('user_id', $u->id)->where('campaign_id', $campaign->id)->count());
    }

    public function test_cancelled_campaign_is_never_delivered(): void
    {
        $u = $this->customer($this->cityA);
        $campaign = $this->campaign(['status' => 'cancelled']);

        (new FakeCampaignService)->deliver($campaign);

        $this->assertSame(0, DB::table('customer_notifications')->where('user_id', $u->id)->count());
        $this->assertSame('cancelled', $campaign->fresh()->status);
    }

    public function test_dispatch_due_queues_only_due_scheduled_campaigns_once(): void
    {
        Queue::fake();
        $due = $this->campaign(['status' => 'scheduled', 'scheduled_at' => now()->subMinute()]);
        $later = $this->campaign(['status' => 'scheduled', 'scheduled_at' => now()->addHour()]);

        $service = new NotificationCampaignService;
        $this->assertSame(1, $service->dispatchDue());
        $this->assertSame(0, $service->dispatchDue(), 'lần chạy thứ hai không được gửi lại chiến dịch đã vào hàng đợi');

        Queue::assertPushed(SendNotificationCampaignJob::class, fn ($job) => $job->campaignId === $due->id);
        Queue::assertPushed(SendNotificationCampaignJob::class, 1);
        $this->assertSame('queued', $due->fresh()->status);
        $this->assertSame('scheduled', $later->fresh()->status);
    }

    public function test_counts_report_inbox_and_push_separately(): void
    {
        $this->customer($this->cityA, ['fcm_token' => 't1']);
        $this->customer($this->cityA, ['fcm_token' => 't2']);
        $this->customer($this->cityA);
        $this->customer($this->cityB, ['fcm_token' => 't3']);

        $counts = (new NotificationCampaignService)->counts('city', $this->cityA->id);
        $this->assertSame(['inbox' => 3, 'push' => 2], $counts);
    }
}
