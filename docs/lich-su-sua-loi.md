# Lịch sử sửa lỗi

Nhật ký các lỗi đã tìm ra và sửa — tra trước khi điều tra 1 hiện tượng "lạ",
có thể đã từng gặp và sửa rồi. Mới nhất ở trên cùng. Mỗi mục gồm: triệu
chứng → nguyên nhân gốc → cách sửa (file) → trạng thái.

**Quy tắc**: mỗi khi sửa xong 1 bug thật (không phải thêm tính năng), thêm 1
mục vào đây TRƯỚC khi coi là xong việc.

---

## 2026-08-12 (c) — Farm điểm online-rate bằng cách tắt GPS/tắt mạng

**Triệu chứng**: phát hiện lúc điều tra tài xế #107 (mục ngay dưới) — tài xế
online liên tục 15 tiếng, GPS chết hẳn suốt thời gian đó, vẫn được chấm
`shift_online_high` +3đ/ca đều đặn dù không hề chạy 1 đơn nào và không hề bị
dispatch phát đơn (vô hình với dispatch vì GPS không tươi — mục dưới). Từ đó
suy ra: tài xế có thể CHỦ ĐỘNG bật online rồi tắt định vị/tắt mạng, farm điểm
mỗi ca mà không cần chạy xe, không rủi ro gì (không có đơn để mà bỏ lỡ nên
không bị `offer_unviewed_x3` trừ bù lại).

**Nguyên nhân gốc**: `DriverController::toggleOnline()` chỉ mở 1 dòng
`DriverShiftSession`, chỉ chính tài xế tự tắt mới đóng lại — không có gì khác
đóng session. `ScoreShiftSessionsCommand` chấm % online hoàn toàn theo giờ
đồng hồ `started_at`→`ended_at` của session, **không đối chiếu GPS còn sống
hay không** — 2 khái niệm "đã bấm nút online" và "GPS thật sự tươi" bị lẫn
làm một, trong khi dispatch (`DriverLocationService::freshLocationsFor`) đã
tách riêng và dùng ngưỡng GPS tươi ≤10 phút từ trước.

**Cách sửa**: thêm lệnh định kỳ mới `drivers:close-stale-sessions` (chạy mỗi
5 phút, cùng lịch với `drivers:score-shift-sessions`) — quét mọi tài xế
`is_online=true`, đối chiếu GPS Firebase, tài xế nào quá ngưỡng
`DriverLocationService::POS_MAX_AGE_SECS` (10 phút) không có fix mới thì tự
chuyển `is_online=false` và đóng `DriverShiftSession` tại **đúng thời điểm
GPS cuối cùng còn tươi** (không phải "bây giờ") — quãng thời gian mất tín
hiệu không bị tính là online khi chấm điểm ca. Tài xế chưa từng có 1 fix GPS
nào thì đóng ngay tại lúc mở ca (0 giây online được tính).
File: `backend/Modules/Driver/app/Console/Commands/CloseStaleOnlineSessionsCommand.php`,
`backend/Modules/Driver/app/Providers/DriverServiceProvider.php`.

**Trạng thái**: Đã viết xong, `php -l` sạch. **CHƯA commit/deploy** — chờ xác
nhận trước khi đẩy lên production vì đây là hành vi tự động thay đổi trạng
thái online của tài xế thật.

---

## 2026-08-12 (b) — 4 lỗi nuốt/mất thông báo đơn (rà theo yêu cầu, sau vụ #351)

Rà toàn bộ đường thông báo (iOS + Android) sau khi tìm ra bug cờ offer kẹt
(mục ngay trên). Tìm thêm 4 lỗi thật, không liên quan cờ kẹt:

**1. [Nặng nhất] Bấm vào thông báo không mở được đơn — cả 2 nền tảng, mọi
trạng thái app.** `getInitialMessage()` (app bị kill) chưa từng được gọi;
`onMessageOpenedApp` (app ở nền) callback rỗng; `_localNotif.initialize()`
thiếu `onDidReceiveNotificationResponse` (bấm local notification lúc app
đang mở). Toàn bộ việc mở màn hình offer phụ thuộc 100% vào luồng RTDB
(`OfferListenerService`) tự bắt kịp — không đảm bảo nếu app khởi động chậm
hơn thời hạn offer (25s). Sửa: thêm hàm dùng chung
`_navigateToOfferFromNotification()`, nối vào cả 3 nguồn tap.

**2. iOS: thiếu quyền "Time Sensitive"** — code khai
`interruptionLevel: timeSensitive` nhưng `Runner.entitlements` thiếu key
`com.apple.developer.usernotifications.time-sensitive`. Thiếu key này,
thông báo đơn bị Chế độ Tập trung/Lái xe của iOS chặn hoàn toàn như thông
báo thường — tài xế bật chế độ này lúc đang chạy xe (rất phổ biến) sẽ mất
hẳn thông báo. **Cần bật capability "Time Sensitive Notifications" cho
App ID trên Apple Developer trước khi build, nếu không Xcode báo lỗi ký.**

**3. `firebaseBackgroundHandler` tự show notification trùng lặp + rủi ro
plugin chưa init.** Backend đã gửi kèm khối `notification` (không chỉ
`data`) nên OS tự hiển thị thông báo hệ thống khi app nền/bị kill — hàm
nền gọi thêm `_localNotif.show()` tạo ra **2 thông báo cho 1 đơn**, và bản
thân lệnh gọi đó rủi ro vì plugin local-notifications chưa từng
`initialize()` trong tiến trình nền riêng biệt. Sửa: bỏ hẳn, giữ hàm rỗng.

**4. Thông báo khác (đơn huỷ, công nợ...) không có channel_id trên
Android** — bị đẩy vào kênh mặc định "Miscellaneous" (chuông im, dễ bỏ
sót). Sửa: thêm kênh `general_channel` + khai
`default_notification_channel_id` trong `AndroidManifest.xml` — FCM tự áp
dụng cho MỌI thông báo không chỉ định channel riêng, không cần sửa backend.

File: `app/driver/lib/core/services/notification_service.dart`,
`app/driver/android/app/src/main/AndroidManifest.xml`,
`app/driver/ios/Runner/Runner.entitlements`.

**[Sửa lại lỗi 1 — do chính bản vá đầu tiên gây ra, phát hiện lúc tự soát
lại]** Bản đầu của `_navigateToOfferFromNotification()` tự dựng dữ liệu đơn
từ payload thông báo (chỉ có order_id/order_code/expires_at, thiếu địa
chỉ/tiền công...) rồi điều hướng thẳng bằng dữ liệu nghèo đó — màn hình
hiện ra toàn ô trống. Tệ hơn, ở trường hợp phổ biến nhất (app đang mở, RTDB
đã mở sẵn màn hình ĐÚNG), việc bấm thêm vào thông báo còn **ghi đè mất màn
hình tốt bằng bản trống**. Sửa lại: bỏ hẳn việc tự dựng dữ liệu, giao cho
`OfferListenerService.ensureOfferVisible(driverId)` — đọc thẳng RTDB 1 lần
(nguồn dữ liệu đầy đủ) rồi tái dùng đúng logic điều hướng đã có, không tạo
đường điều hướng thứ 2 chạy song song.

**Tiện sửa luôn 2 lỗi kẹt cờ còn sót phát hiện lúc soát lại:**
- `_offerVisible` (bool) đổi thành `_visibleOrderId` (int?) — bool cũ không
  phân biệt được đơn nào đang hiện, nên nếu đơn A hết hạn và đơn B được ghi
  gần như cùng lúc mà Firebase chỉ giao sự kiện cuối (đơn B), code cũ thấy
  "đang hiện rồi" và bỏ qua hẳn đơn B.
- Kiểm tra `order_id` hợp lệ chuyển lên TRƯỚC khi đụng vào trạng thái "đang
  hiện" (trước đây bật cờ rồi mới kiểm tra bên trong `_navigateToOffer()`,
  payload thiếu order_id sẽ làm cờ kẹt vĩnh viễn dù màn hình chưa từng mở).
- `markOfferHandled()` giờ nhận `orderId`, chỉ xoá trạng thái nếu đúng đơn
  đang hiện — tránh 1 lệnh gọi trễ của màn hình đơn CŨ xoá nhầm trạng thái
  của đơn MỚI hơn.

File thêm: `app/driver/lib/core/services/offer_listener_service.dart`.

**[Bổ sung] Lỗ hổng thứ 5 phát hiện lúc tự soát lại: không có cơ chế phục
hồi listener nhận đơn khi app mở lại từ nền.** `didChangeAppLifecycleState`
ở `home_screen.dart` chỉ gọi `LocationService.instance.restart()` cho GPS —
listener RTDB nhận đơn (`OfferListenerService`) không có gì cứu nếu đã
chết/treo sau thời gian dài ở nền (Doze, mất mạng, iOS đóng băng socket).
Đúng kiểu bất đối xứng đã gây ra bug GPS "chết vĩnh viễn" trước đây — GPS
được vá, đường nhận đơn thì bỏ sót. Sửa: thêm
`OfferListenerService.instance.ensureOfferVisible(uid)` ngay cạnh dòng
restart GPS đó.
File: `app/driver/lib/features/home/screens/home_screen.dart`.

**Trạng thái**: Đã sửa toàn bộ (bản gốc + 2 lần tự soát lại), `flutter
analyze` sạch, `flutter test` pass. **CHƯA build/release.** Lỗi liên quan
điều hướng khi bấm thông báo / phục hồi khi resume không thể verify bằng
dữ liệu Firebase như các bug GPS trước — cần test tay thật sau khi build.
Lỗi iOS cần xác nhận riêng capability "Time Sensitive" đã bật cho App ID
trước khi build.

---

## 2026-08-12 — Tài xế bị trừ điểm oan vì không thấy thông báo đơn — cờ "đang hiện offer" kẹt vĩnh viễn

**Triệu chứng**: nhiều tài xế báo không thấy thông báo đơn mới, để trôi mất
nhiều đơn liên tiếp rồi bị trừ điểm (luật "cứ 3 đơn không xem -1 điểm").
Điều tra tài xế #351: mất liên tiếp 41/41 đơn trong 1 buổi sáng (6h-11h),
trong khi GPS vẫn tươi 0 giây suốt (app hoàn toàn còn sống, không phải tắt
máy/hết pin/mất mạng). Đúng 12h đột ngột xem và nhận đơn ngay lần đầu chạm
máy. Tổng cộng 23 tài xế bị trừ 172 điểm trong ~1 tuần từ khi bật luật này.

**Nguyên nhân gốc**: `OfferListenerService._offerVisible` là cờ chặn mở
trùng 2 màn hình offer cùng lúc — chỉ được mở lại (`markOfferHandled()`) ở
3 nhánh hành động bên trong `order_offer_screen.dart` (nhận/từ chối/hết
giờ). Nếu 1 offer tới máy nhưng ĐÃ HẾT HẠN ngay lúc mở màn hình (mạng
chậm, app vừa mở lại từ nền), màn hình phát hiện hết hạn và thoát thẳng về
trang chủ — **không đi qua nhánh nào trong 3 nhánh trên**, cờ kẹt `true`
vĩnh viễn. Từ đó mọi offer tiếp theo bị `OfferListenerService._onEvent()`
nuốt im lặng (điều kiện `if (!_offerVisible)` không bao giờ đúng nữa) —
không mở màn hình, không chuông, tài xế không hề biết. Chỉ tự hết kẹt khi
tắt/bật lại online hoặc khởi động lại app hẳn.

**Cách sửa**: chuyển `markOfferHandled()` từ 3 nhánh hành động rải rác vào
`dispose()` của `OrderOfferScreen` — nơi CHẮC CHẮN luôn chạy khi màn hình
biến mất, bất kể thoát bằng cách nào (kể cả nhánh hết hạn ngay lúc mở, hay
bất kỳ nhánh thoát nào phát sinh sau này). Loại bỏ hẳn cả nhóm lỗi "quên
gọi ở 1 nhánh" thay vì chỉ vá đúng chỗ vừa tìm thấy.
File: `app/driver/lib/features/orders/screens/order_offer_screen.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. **CHƯA build/release.**
**CHƯA xử lý hậu quả**: 172 điểm đã trừ oan của 23 tài xế chưa được hoàn
lại — chờ quyết định của user (đã hỏi, đang chờ xác nhận riêng cho phần
này). Cũng phát hiện kèm theo: chứng chỉ APNs (đẩy thông báo iOS) của app
Shop đang lỗi 401 "Invalid APNs credential" — vấn đề khác, chưa xử lý.

---

## 2026-08-10/11 — GPS "nhảy vị trí" — đồng hồ ma sống ngoài vòng đời phiên

**Triệu chứng**: vị trí tài xế trên bản đồ/dispatch nhảy loạn giữa nhiều toạ
độ, kể cả sau khi đã fix bug đa thiết bị và bug luồng GPS nhân bản trước đó
(bản 10.0.9). Xác nhận bằng dữ liệu Firebase thật: nhiều tài xế cùng lúc,
chu kỳ nhảy khớp đúng 20 giây, lặp lại theo đúng thứ tự.

**Nguyên nhân gốc**: `_pushLocation()` (khi còn nằm trong widget
`_DashboardPageState`) sau mỗi lần ghi Firebase thành công đều tự hẹn lại
đồng hồ 20 giây tiếp theo. Nếu widget bị huỷ (tắt online/đăng xuất) đúng lúc
1 lệnh ghi đang dở dang (chờ mạng trả lời), lệnh đó vẫn hoàn tất sau đó và
vẫn tự hẹn lại đồng hồ MỚI — đồng hồ này ôm theo toạ độ cũ, cứ 20s ghi đè
Firebase 1 lần, tự hẹn lại chính nó mãi mãi, không ai dừng được (chốt chặn
cũ dựa trên `LocationService.isRunning` vô tác dụng vì đó là state dùng
chung, phiên mới vẫn đang chạy).

**Cách sửa**:
- Tách toàn bộ logic gửi GPS ra `app/driver/lib/core/services/location_push_service.dart`
  (trước nằm lẫn trong `home_screen.dart`)
- Thêm `_generation` — tăng mỗi lần `start()`/`stop()`; mọi tác vụ bất đồng
  bộ chụp lại giá trị này lúc bắt đầu, tự bỏ qua nếu đã lệch khi tỉnh dậy
  sau `await` — lệnh ghi dở dang của phiên cũ không còn hẹn lại đồng hồ hay
  ghi đè trạng thái phiên mới được nữa
- Verify bằng `app/driver/test/location_push_service_test.dart` — verify
  2 chiều: bỏ chặn `_generation` → test tự fail đúng chỗ, có chặn → pass

**Trạng thái**: ✅ Đã sửa, đã build/release, **đã xác nhận hết hẳn trên
production** — theo dõi trực tiếp Firebase 10 tài xế đang online liên tục
60 giây, 0 lần nhảy vị trí (trước fix: bắt được gần như 100% tài xế online
nhảy trong vòng 1 phút theo dõi). Người dùng xác nhận không còn ai báo
nhảy vị trí nữa.

---

## (trước đó, cùng phiên) — GPS nhảy vị trí — đa thiết bị cùng đăng nhập

**Triệu chứng**: vị trí tài xế nhảy giữa 2 toạ độ thật khác nhau xen kẽ
(khác kiểu lỗi "đồng hồ ma" ở trên — đây là 2 máy CÙNG lúc gửi vị trí thật
của chính nó, không phải máy đã tắt).

**Nguyên nhân gốc**: đăng nhập máy mới không có cách nào ép máy cũ dừng gửi
GPS ngay lập tức — máy cũ nếu đang ở nền, không kịp nhận tín hiệu logout,
vẫn tiếp tục ghi Firebase bằng phiên đăng nhập cũ.

**Cách sửa**:
- Backend: cấp Firebase custom token gắn với `driver_{id}_{device_id}`
  (không phải chỉ theo user) — `AuthController::firebaseToken()`,
  `RTDBService::createCustomAuthToken()`
- Security Rules (`app/driver/database.rules.json`): chỉ cho ghi
  `locations/driver_{id}` nếu `auth.uid` khớp đúng
  `driver_{id}_{session_device hiện tại}` — **chặn cứng ở tầng Firebase**,
  không phụ thuộc app tự giác kiểm tra
- App: `LocationPushService._push()` bắt lỗi `permission-denied` từ
  Firebase → phân biệt "chưa có Firebase Auth" (lỗi hạ tầng, không logout)
  vs "có Auth mà vẫn bị từ chối" (chắc chắn bị đăng nhập đè → tự logout)

**Trạng thái**: Đã sửa, đã có trong bản 10.0.9 (đã release).

---

## 2026-08-06 — Luật điểm & phát đơn — tổng hợp các thay đổi trong 1 đợt chốt luật

Không hẳn là "bug" — là đợt thiết kế lại luật chấm điểm/phát đơn theo yêu
cầu, nhưng có kèm vài lỗi thật được sửa trong lúc làm:

- **Log sai giá trị điểm trừ**: `DispatchService::handleTimeout()` log cứng
  "-3 điểm" trong khi hằng số `SCORE_VIEWED_TIMEOUT` đã đổi thành -2 từ
  trước — sửa thành lấy động từ constant, tránh lệch lại lần sau.
- **Comment lạc hậu**: `DriverScoreService` so sánh "-2 nặng hơn -1" sau khi
  cả 2 mức đã được chỉnh về cùng -2 — sửa lại comment cho khớp thực tế.
- **`onShiftViolation` (-15 khi bật rồi tắt hẳn giữa ca) bị thay hẳn** bằng
  tier 0%-online trong `onShiftOnlineRate()` — không còn 2 nhánh phạt tách
  rời cho cùng 1 nhóm hành vi.

**Trạng thái**: Đã sửa, đã deploy backend.

---

## Mẫu để copy khi thêm mục mới

```
## YYYY-MM-DD — Tên ngắn gọn

**Triệu chứng**: ...
**Nguyên nhân gốc**: ...
**Cách sửa**: ... (file cụ thể)
**Trạng thái**: Đã sửa & verify / Đã sửa, chưa deploy / Đã sửa & deploy, chưa release app (nếu liên quan app)
```
