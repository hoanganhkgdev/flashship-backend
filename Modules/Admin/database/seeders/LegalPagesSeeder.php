<?php

namespace Modules\Admin\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Admin\Models\Page;

class LegalPagesSeeder extends Seeder
{
    public function run(): void
    {
        Page::upsert([
            [
                'title'     => 'Chính sách bảo mật',
                'slug'      => 'privacy-policy',
                'is_active' => true,
                'content'   => $this->privacyPolicy(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'title'     => 'Điều khoản sử dụng',
                'slug'      => 'terms-of-service',
                'is_active' => true,
                'content'   => $this->termsOfService(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['slug'], ['title', 'content', 'is_active', 'updated_at']);
    }

    private function privacyPolicy(): string
    {
        return <<<HTML
<h1>Chính sách bảo mật</h1>
<p><strong>Cập nhật lần cuối:</strong> tháng 5 năm 2026</p>

<h2>1. Thông tin chúng tôi thu thập</h2>
<p>Khi bạn sử dụng ứng dụng FlashShip Driver, chúng tôi thu thập các thông tin sau:</p>
<ul>
  <li><strong>Thông tin cá nhân:</strong> họ tên, số điện thoại, email, ảnh đại diện, CCCD/CMND.</li>
  <li><strong>Vị trí địa lý:</strong> vị trí thời gian thực khi bạn đang hoạt động để phân phối đơn hàng.</li>
  <li><strong>Dữ liệu hoạt động:</strong> lịch sử đơn hàng, điểm hiệu suất, thời gian online.</li>
  <li><strong>Thiết bị:</strong> token thiết bị để gửi thông báo đẩy.</li>
</ul>

<h2>2. Mục đích sử dụng thông tin</h2>
<p>Chúng tôi sử dụng thông tin thu thập để:</p>
<ul>
  <li>Vận hành và cải thiện dịch vụ giao hàng.</li>
  <li>Phân phối đơn hàng phù hợp đến tài xế gần nhất.</li>
  <li>Tính toán thu nhập và quản lý ví tài xế.</li>
  <li>Gửi thông báo liên quan đến đơn hàng và tài khoản.</li>
  <li>Tuân thủ các yêu cầu pháp lý.</li>
</ul>

<h2>3. Chia sẻ thông tin</h2>
<p>Chúng tôi <strong>không bán</strong> thông tin cá nhân của bạn. Thông tin chỉ được chia sẻ với:</p>
<ul>
  <li>Khách hàng đặt đơn (họ tên, số điện thoại để liên hệ giao hàng).</li>
  <li>Đối tác kỹ thuật vận hành hệ thống (bản đồ, thanh toán) theo hợp đồng bảo mật.</li>
  <li>Cơ quan nhà nước khi có yêu cầu hợp pháp.</li>
</ul>

<h2>4. Bảo mật dữ liệu</h2>
<p>Dữ liệu được mã hóa trong quá trình truyền tải (HTTPS) và lưu trữ trên máy chủ bảo mật. Chúng tôi áp dụng các biện pháp kỹ thuật phù hợp để bảo vệ thông tin khỏi truy cập trái phép.</p>

<h2>5. Quyền của bạn</h2>
<p>Bạn có quyền:</p>
<ul>
  <li>Truy cập và chỉnh sửa thông tin cá nhân trong ứng dụng.</li>
  <li>Yêu cầu xóa tài khoản thông qua mục Cài đặt → Xóa tài khoản.</li>
  <li>Từ chối nhận thông báo đẩy trong cài đặt thiết bị.</li>
</ul>

<h2>6. Vị trí địa lý</h2>
<p>Ứng dụng yêu cầu quyền truy cập vị trí khi sử dụng để phân phối đơn hàng chính xác. Vị trí chỉ được ghi lại khi bạn bật trạng thái <em>Đang hoạt động</em>.</p>

<h2>7. Liên hệ</h2>
<p>Mọi thắc mắc về chính sách bảo mật, vui lòng liên hệ:<br>
<strong>Email:</strong> support@flashship.vn<br>
<strong>Hotline:</strong> 1900 xxxx</p>
HTML;
    }

    private function termsOfService(): string
    {
        return <<<HTML
<h1>Điều khoản sử dụng</h1>
<p><strong>Cập nhật lần cuối:</strong> tháng 5 năm 2026</p>

<h2>1. Chấp nhận điều khoản</h2>
<p>Bằng cách đăng ký và sử dụng ứng dụng FlashShip Driver, bạn đồng ý tuân thủ toàn bộ các điều khoản dưới đây. Nếu không đồng ý, vui lòng không sử dụng ứng dụng.</p>

<h2>2. Điều kiện tham gia</h2>
<p>Để trở thành tài xế FlashShip, bạn phải:</p>
<ul>
  <li>Đủ 18 tuổi trở lên.</li>
  <li>Có giấy phép lái xe hợp lệ phù hợp với loại phương tiện đăng ký.</li>
  <li>Cung cấp thông tin cá nhân trung thực, chính xác.</li>
  <li>Hoàn thành quy trình xác minh danh tính (CCCD/CMND).</li>
</ul>

<h2>3. Nghĩa vụ của tài xế</h2>
<ul>
  <li>Thực hiện giao hàng đúng hẹn, an toàn và chuyên nghiệp.</li>
  <li>Duy trì thái độ lịch sự với khách hàng.</li>
  <li>Không từ chối đơn hàng liên tục — hành vi này ảnh hưởng đến điểm hiệu suất.</li>
  <li>Cập nhật trạng thái đơn hàng chính xác trên ứng dụng.</li>
  <li>Bảo mật thông tin tài khoản, không chia sẻ cho người khác.</li>
</ul>

<h2>4. Thu nhập và thanh toán</h2>
<p>Thu nhập được tính dựa trên phí vận chuyển và thưởng hiệu suất theo chính sách hiện hành. FlashShip có quyền điều chỉnh mức phí với thông báo trước tối thiểu 7 ngày.</p>

<h2>5. Hệ thống điểm hiệu suất</h2>
<p>Tài xế được đánh giá qua điểm hiệu suất. Hành vi từ chối đơn, không phản hồi, hoặc bị đánh giá kém sẽ trừ điểm và ảnh hưởng đến thứ tự nhận đơn. FlashShip có thể tạm khóa tài khoản nếu điểm quá thấp.</p>

<h2>6. Hành vi bị cấm</h2>
<ul>
  <li>Gian lận vị trí, giả mạo giao hàng.</li>
  <li>Sách nhiễu hoặc có hành vi không phù hợp với khách hàng.</li>
  <li>Sử dụng tài khoản người khác.</li>
  <li>Chia sẻ thông tin đơn hàng hoặc khách hàng ra ngoài.</li>
</ul>
<p>Vi phạm các điều trên sẽ dẫn đến chấm dứt tài khoản.</p>

<h2>7. Chấm dứt tài khoản</h2>
<p>FlashShip có quyền tạm khóa hoặc chấm dứt tài khoản tài xế trong các trường hợp vi phạm điều khoản, gian lận, hoặc hành vi gây hại cho khách hàng. Bạn cũng có thể yêu cầu xóa tài khoản trong mục Cài đặt.</p>

<h2>8. Giới hạn trách nhiệm</h2>
<p>FlashShip là nền tảng kết nối và không chịu trách nhiệm trực tiếp về thiệt hại phát sinh từ hành vi của tài xế hoặc sự cố ngoài tầm kiểm soát (thiên tai, sự cố mạng, v.v.).</p>

<h2>9. Thay đổi điều khoản</h2>
<p>Chúng tôi có thể cập nhật điều khoản này. Phiên bản mới sẽ được thông báo qua ứng dụng. Tiếp tục sử dụng sau khi thay đổi có hiệu lực đồng nghĩa với việc bạn chấp nhận điều khoản mới.</p>

<h2>10. Liên hệ</h2>
<p><strong>Email:</strong> support@flashship.vn<br>
<strong>Hotline:</strong> 1900 xxxx</p>
HTML;
    }
}
