# Lịch sử sửa lỗi

Nhật ký các lỗi đã tìm ra và sửa — tra trước khi điều tra 1 hiện tượng "lạ",
có thể đã từng gặp và sửa rồi. Mới nhất ở trên cùng. Mỗi mục gồm: triệu
chứng → nguyên nhân gốc → cách sửa (file) → trạng thái.

**Quy tắc**: mỗi khi sửa xong 1 bug thật (không phải thêm tính năng), thêm 1
mục vào đây TRƯỚC khi coi là xong việc.

---

## 2026-08-19 (c) — Backend: sửa nốt DriverScoreService::onShiftOnlineRate() không khoá + thêm test tự động chống race condition

**Bối cảnh**: nốt lỗ hổng còn lại trong audit mục (b) dưới — `onShiftOnlineRate()`
(gọi từ `ScoreShiftSessionsCommand` cuối mỗi ca) là hàm chấm điểm DUY NHẤT
không khoá dòng driver trước khi cộng/trừ, khác với `onComplete`/`onDecline`/
`onOfferUnviewed` đều có `lockForUpdate()`.

**Cách sửa**: bọc `onShiftOnlineRate()` trong `DB::transaction()` +
`lockForUpdate()` trên dòng driver trước khi gọi `adjust()` — cùng pattern
với `adjustWithStreakReset()` đã có sẵn trong file.

**Thêm test tự động** (`tests/Feature/Driver/ScoreAndWalletRaceConditionTest.php`,
7 test) cho `DriverScoreService::onShiftOnlineRate()` và `DriverWalletService::adjust()`
— 2 test race condition thật (dùng MySQL thật + tiến trình `proc_open` riêng
để tái hiện đúng tình huống "giao dịch khác đổi giá trị nhưng chưa commit",
kiểm tra giá trị cuối cùng không bị mất update) và 5 test đúng logic (bracket
điểm, kẹp MIN_SCORE, cộng/trừ tiền tuần tự, chặn rút vượt số dư, idempotent
theo reference). **Đã tự kiểm chứng cả 2 chiều**: revert code khoá thật →
2 test race fail đúng với giá trị sai dự đoán được trước (90 thay vì 50,
250000 thay vì 200000 — chứng minh test thật sự bắt được bug, không phải
test giả); khôi phục code → cả 7 test pass.

**Phát hiện thêm (chưa sửa, ngoài phạm vi)**: khi set up DB MySQL test riêng,
`php artisan migrate` chạy trên DB rỗng bị lỗi thứ tự phụ thuộc —
`2026_06_30_144531_add_shift_id_to_users_table.php` tham chiếu bảng `shifts`
chưa tồn tại tại thời điểm đó trong thứ tự migrate. Đã né bằng cách clone
schema từ DB dev thay vì chạy migrate từ đầu — nhưng nghĩa là **cài đặt mới
hoàn toàn (fresh install) từ migrations hiện đang bị hỏng**, đáng sửa riêng
trước khi cần setup môi trường mới (CI, máy dev mới, staging...).

**Trạng thái**: Đã sửa `onShiftOnlineRate()`, `php -l` sạch. Test tự động đã
chạy pass, cần DB MySQL test riêng (`flashship_backend_test`) — xem hướng
dẫn setup trong docblock đầu file test. Chưa deploy lên VPS. Lỗi thứ tự
migration nêu trên CHƯA sửa.

---

## 2026-08-19 (b) — Backend: audit chủ động, sửa 4 lỗi race condition trước khi mở khu Phú Quốc

**Bối cảnh**: sau khi sửa bug phiên online chồng lấp (mục dưới), chủ động chạy
audit tìm các bug cùng họ (race condition đọc-sửa-ghi không khoá, tổng hợp dữ
liệu không gộp chồng lấp) trước deadline mở dịch vụ khu Phú Quốc cuối tháng
8/2026. Tìm ra và sửa 4 lỗi mức cao/nối tiếp:

**1-2. Ví tài xế không khoá khi cộng/trừ tiền (mức cao — liên quan tiền thật)**
`DriverWalletService::adjust()` đọc `$wallet->balance` không khoá rồi ghi đè —
đây là hàm dùng cho MỌI giao dịch (rút tiền, hoàn công nợ, thưởng, freeship...).
2 giao dịch gần như đồng thời (double-tap rút tiền, hoặc đơn hoàn thành cộng
tiền đúng lúc đang rút) có thể làm mất 1 giao dịch (lost update) hoặc rút vượt
số dư thật. `WalletController::withdraw()` chỉ kiểm tra số dư 1 lần trước khi
mở transaction (không phải nguồn xác thực chính).
**Cách sửa**: `DriverWalletService::adjust()` dùng
`DriverWallet::where(...)->lockForUpdate()->first()` thay vì `firstOrCreate()`
không khoá. `WalletController::withdraw()` giữ kiểm tra nhanh (UX, phản hồi lỗi
sớm) nhưng bọc `try/catch` quanh transaction để bắt đúng lỗi "Số dư không đủ"
ném ra từ `adjust()` khi race xảy ra, trả JSON lỗi thay vì lỗi 500.

**3-4. `toggleOnline()` và `CloseStaleOnlineSessionsCommand` chỉ đóng phiên
online gần nhất, không đóng hết (mức cao — nối tiếp bug phiên chồng lấp)**
Cả 2 nơi dùng `->whereNull('ended_at')->latest('started_at')->first()` để tìm
phiên cần đóng — nếu driver có NHIỀU phiên đang mở cùng lúc (do bug chồng lấp
cũ, hoặc bug khác trong tương lai), chỉ phiên gần nhất được đóng, phiên cũ hơn
bị bỏ quên mở MÃI MÃI — ăn "online" vào mọi ca sau này vô thời hạn, nặng hơn cả
lỗi chồng lấp ban đầu (lỗi chồng lấp còn giới hạn theo 1 ca, lỗi này thì không).
**Cách sửa**: đổi `->first()?->update()` thành đóng TẤT CẢ phiên đang mở
(`->get()` rồi update từng phiên, hoặc bulk `->update()`).
`CloseStaleOnlineSessionsCommand` xử lý riêng từng phiên, kẹp thời điểm đóng
tối thiểu về đúng `started_at` của phiên đó (tránh đóng ở mốc trước cả lúc mở
nếu áp chung 1 mốc GPS-cuối-tươi cho nhiều phiên có started_at khác nhau).

**Các lỗi khác tìm được nhưng CHƯA sửa** (mức trung bình/thấp, để sau theo
quyết định user): `DriverScoreService::onShiftOnlineRate()` không khoá (các
hàm chấm điểm khác đều có `lockForUpdate`); `OtpService::verify()` đọc-sửa
không khoá (race cho phép dùng lại OTP); Shop tạo đơn — mã giảm giá có thể
dùng vượt `per_user_limit` nếu double-tap submit; Shop huỷ đơn — race không
atomic với tài xế nhận đơn có thể để đơn ở trạng thái sai. Audit app driver
(cờ trạng thái kiểu `_offerVisible` bị kẹt) — không tìm thêm được lỗ hổng nào
đáng kể, app đã được vá kỹ từ các sự cố trước.

**Trạng thái**: Đã sửa 4 lỗi mức cao/nối tiếp, `php -l` sạch cả 4 file. Chưa
deploy lên VPS.

---

## 2026-08-19 — Backend: phiên online chồng lấp làm mất thời gian offline trong ca (thấy ở khu Rạch Giá test)

**Triệu chứng**: Tài xế trong ca (đặc biệt thấy rõ ở tài khoản test khu "Rạch
Giá (Test)") không bị tính thời gian offline dù thực tế có lúc tắt online
giữa ca — hệ thống tính ra gần như 100% online.

**Nguyên nhân gốc**: Hai lớp:
1. `DriverController::toggleOnline()` đọc `is_online` hiện tại rồi đảo ngược,
   không có lock — 2 request bật online gần như đồng thời (double-tap, app
   gọi trùng do mạng chậm) có thể cùng đọc `is_online=false`, cùng tạo mới
   `DriverShiftSession` thay vì đóng phiên cũ trước, sinh 2 phiên chồng lấp
   thời gian nhau. Xác nhận thật trên VPS: driver_id 788 có 2 phiên
   `driver_shift_sessions` mở đồng thời (22:28:04 và 22:53:30, cùng chưa
   đóng).
2. `ScoreShiftSessionsCommand::scoreDriverShift()` cộng dồn thời lượng từng
   phiên riêng lẻ, KHÔNG gộp các phiên chồng lấp trước khi cộng — thời gian
   online bị đếm trùng, tổng có thể vượt cả thời lượng ca rồi bị
   `min(1.0, ...)` kẹp thành 100% online, che mất khoảng offline thật.

**Cách sửa**:
- `Modules/Driver/app/Http/Controllers/DriverController.php`: bọc phần đọc
  `is_online` + tạo/đóng `DriverShiftSession` + save trong
  `DB::transaction()` với `User::lockForUpdate()` — chỉ 1 request bật/tắt
  online của đúng tài xế được xử lý tại 1 thời điểm, không còn tạo phiên
  chồng lấp mới nữa.
- `Modules/Driver/app/Console/Commands/ScoreShiftSessionsCommand.php`: gộp
  (merge) các khoảng `[start, end]` chồng lấp trước khi cộng dồn
  `onlineSeconds` — thuật toán quét theo thời gian bắt đầu, chỉ cộng phần
  không trùng. Verify bằng script PHP độc lập với 3 case (chồng lấp, có
  khoảng offline thật, dính liền nhau) — đều ra đúng kết quả.

**Trạng thái**: Đã sửa, `php -l` sạch cả 2 file, verify thuật toán merge
bằng script riêng. Chưa deploy lên VPS. Dữ liệu phiên chồng lấp cũ đã có sẵn
trên production (driver_id 788) chưa được dọn — fix ở ScoreShiftSessionsCommand
đã tự xử lý đúng cho các lần chấm điểm tiếp theo mà không cần dọn dữ liệu cũ,
nhưng nếu muốn dữ liệu driver_shift_sessions sạch hoàn toàn thì cần xử lý
riêng.

---

## 2026-08-19 — Shop app: gõ số điện thoại có số 0 đầu bị báo sai tài khoản khi đăng nhập

**Triệu chứng**: Đăng nhập/đăng ký/quên mật khẩu ở app shop, nếu người dùng
gõ số điện thoại có số 0 ở đầu (VD "0912345678", thói quen phổ biến) thì bị
báo sai tài khoản/mật khẩu dù số đúng.
**Nguyên nhân gốc**: `PhoneField` (`app/shop/lib/core/widgets/app_form_widgets.dart`)
dùng 1 `TextEditingController` nội bộ hiển thị phần số sau số 0, rồi
`_syncToController()` LUÔN prepend cứng `'0'` vào giá trị hiển thị để ra số
đầy đủ gửi lên. Trước đây ô có hiện "+84" ngay trước, ngầm báo người dùng
không cần gõ số 0. Sau khi đổi "+84" thành icon phone (theo yêu cầu chỉ phục
vụ VN), tín hiệu đó mất — người dùng gõ cả số 0, ra chuỗi "00912345678" gửi
lên backend, không khớp số thật nào.
**Cách sửa**: `_syncToController()` tự bóc số 0 đầu (nếu có) trong giá trị
đang gõ trước khi prepend `'0'`, chuẩn hoá luôn ra đúng 1 số 0 dù người dùng
gõ có hay không có số 0 đầu.
**Trạng thái**: Đã sửa & verify (test trên simulator, đăng nhập với SĐT có
số 0 đầu thành công), chưa deploy/release app.

---

## 2026-08-17 — Backend: đơn tạo qua tổng đài (CallCenterPage) không lưu breakdown night_surcharge

**Triệu chứng**: phát hiện lúc soát các nơi tạo `Order` — cột `night_surcharge`
có thật và được `PricingService`/`ShopPricingService::estimateFromCoords()`
tính đúng để hiện preview phí cho tổng đài viên, nhưng không được lưu lại
khi tạo đơn.

**Nguyên nhân gốc**: `CallCenterPage::suggestShippingFee()` chỉ lấy
`$pricing['fee']` (tổng đã gộp sẵn phụ phí đêm) vào `$previewFee`, không
giữ lại riêng `$pricing['night_surcharge']`. `Order::create()` sau đó cũng
không có key `night_surcharge` — tổng tiền đơn tạo qua tổng đài vẫn đúng,
nhưng breakdown phụ phí đêm bị mất, khác với đơn tạo qua app khách/shop
(đã lưu đúng field này).

**Cách sửa**: thêm property `$previewNightSurcharge`, gán trong
`suggestShippingFee()` cạnh `$previewFee`, thêm vào `Order::create()` cạnh
`shipping_fee`.

**Lưu ý còn tồn tại (chưa sửa, ngoài phạm vi yêu cầu)**: `$previewNightSurcharge`
không được reset về 0 ở các chỗ `$previewFee` bị reset (`selectService()`,
`setPickupLocation()`, `setDeliveryLocation()`) — nếu tổng đài viên đổi loại
dịch vụ/địa điểm mà không kích hoạt tính lại preview trước khi submit, giá
trị phụ phí đêm cũ có thể bị lưu nhầm cho đơn mới. Rủi ro thấp (chỉ ảnh
hưởng breakdown hiển thị, không ảnh hưởng tổng tiền do tổng đài tự gõ tay),
nhưng nên cân nhắc reset cùng lúc nếu muốn triệt để.

**File**: `app/Filament/Pages/CallCenterPage.php`.

**Trạng thái**: Đã sửa, `php -l` sạch. Chưa deploy lên VPS, chưa test tay.

---

## 2026-08-16 (g) — Backend: badge "+Xđ đêm" trên màn offer luôn hiện 0 do thiếu field trong payload

**Triệu chứng**: phát hiện lúc thêm badge thưởng mưa vào màn offer — soát
payload RTDB thấy thiếu `night_surcharge` dù app (`OfferHeader`,
`order_model.dart`) đã đọc field này từ lâu để hiện badge "+Xđ đêm".

**Nguyên nhân gốc**: `DispatchOfferSender::commitOffer()` build payload
offer gửi cho tài xế (RTDB node `dispatch/driver_{id}/offer`) không có key
`night_surcharge`, dù cột này có thật trên `orders` (migration
`2026_05_29_000001_add_night_surcharge_to_orders.php`, fillable trên
`Order` model) và được tính đúng ở `OrderController`/`PricingService` lúc
tạo đơn. App đọc `j['night_surcharge']` không thấy key này nên luôn mặc
định 0 — badge phụ phí đêm trên màn offer vì vậy chưa từng hiện đúng số
tiền thật từ khi widget này tồn tại.

**Cách sửa**: thêm `'night_surcharge' => (int) ($order->night_surcharge ?? 0)`
vào payload trong `commitOffer()`, cạnh `bonus_fee`.

**File**: `Modules/Order/app/Services/DispatchOfferSender.php`.

**Trạng thái**: Đã sửa, `php -l` sạch. Chưa deploy lên VPS, chưa test tay.

---

## 2026-08-16 (f) — Backend: upload lại CCCD/bằng lái sau khi đã approved làm tài xế tụt về pending

**Triệu chứng**: phát hiện lúc soát flow duyệt hồ sơ tài xế — chưa ghi nhận
sự cố thật, nhưng lỗ hổng dễ trúng (chỉ cần tài xế đã duyệt bấm nhầm nút
upload lại, hoặc gọi thẳng API).

**Nguyên nhân gốc**: `uploadCccdImage()`/`uploadLicense()` không kiểm tra
trạng thái hiện tại trước khi tạo bản ghi `status='pending'` mới — chặn duy
nhất nằm ở phía app (`_DocCard.canUpload`), không có gì ở backend. Trong khi
đó `profile()` lấy license/CCCD hiện tại bằng
`sortByDesc('id')->first()` (bản ghi MỚI NHẤT theo id), không phải bản ghi
`approved`. Tài xế đã được duyệt mà upload thêm 1 lần (qua app cũ chưa cập
nhật UI, hoặc gọi thẳng API) sẽ tạo bản ghi pending mới đè lên, khiến
`profile()` trả về `status=pending` — tụt hạng dù trước đó đã duyệt.

**Cách sửa**: thêm chốt chặn ngay đầu 2 hàm — nếu đã có bản ghi
`status=approved` cho user đó thì trả lỗi 422, không cho tạo bản ghi mới,
đặt trước bước lưu file để không tốn công lưu ảnh sẽ bị từ chối ngay sau.

**File**: `Modules/Driver/app/Http/Controllers/DriverController.php`.

**Trạng thái**: Đã sửa, `php -l` sạch. Chưa deploy lên VPS, chưa test tay.

---

## 2026-08-16 (e) — App tài xế: bấm "Bỏ qua" đơn offer luôn về Home dù API từ chối thất bại

**Triệu chứng**: phát hiện lúc soát màn hình offer đơn — chưa ghi nhận sự
cố thật từ tài xế.

**Nguyên nhân gốc**: `_decline()` trong `order_offer_screen.dart` gọi
`activeOrderProvider.notifier.decline()` (đã trả `Future<bool>`) nhưng bỏ
qua giá trị trả về, luôn `context.go('/home')` bất kể API thành công hay
thất bại — tài xế không biết đơn có thực sự bị từ chối hay không, và mất
luôn màn hình nên không thể bấm lại. Nút "Bỏ qua" cũng là `GestureDetector`
trần, không có cờ khoá như nút "Nhận đơn ngay" (`_accepting`), bấm nhanh
nhiều lần có thể gọi `decline()` chồng nhau.

**Cách sửa**: thêm `_declining` (giống `_accepting`), disable nút "Bỏ qua"
trong lúc gọi API (hiện icon loading nhỏ thay chữ). `_decline()` giờ kiểm
tra `ok` — thất bại thì hiện snackbar lỗi, mở khoá nút, KHÔNG điều hướng về
home để tài xế bấm lại được; chỉ về home khi API xác nhận từ chối thành
công.

**File**: `app/driver/lib/features/orders/screens/order_offer_screen.dart`,
`app/driver/lib/features/orders/widgets/offer_actions.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-16 (d) — Backend: PayOS handlePaid() có thể cộng tiền/công nợ trùng 2 lần

**Triệu chứng**: người dùng phát hiện lúc soát flow thanh toán công nợ qua
PayOS — chưa ghi nhận sự cố thật từ tài xế nhưng cửa sổ race khả thi và dễ
trúng (không cần app poll trùng lúc webhook, PayOS tự retry webhook cũng đủ
kích hoạt).

**Nguyên nhân gốc**: `PaymentController::handlePaid()` được gọi từ 2 nguồn
độc lập cho cùng 1 order — `status()` (app poll `/driver/payment/{code}/status`
mỗi 3s) và `webhook()` (`POST /payment/webhook/payos`). Cả 2 nơi gọi đều
check `$order->status !== 'pending'` bằng SELECT thường (không khoá) trước
khi gọi `handlePaid()`, và bên trong hàm này UPDATE chỉ có `WHERE id = ?`
(không có `WHERE status = 'pending'`) — không phải compare-and-swap, không
có cách nào phát hiện mình là request thua cuộc. Với `debt_payment`, dòng
`$newPaid = $debt->amount_paid + $order->amount` chạy trùng khiến công nợ
được cộng "đã trả" gấp đôi số tiền thật. `topup` vô tình không dính lỗi này
vì `driver_wallet_transactions.reference` có unique index chặn request thứ
2 (nhưng type này không còn dùng, chỉ giữ nhánh code cho payment cũ).

**Cách sửa**: đổi UPDATE trong `handlePaid()` thành compare-and-swap thật —
thêm `WHERE status = 'pending'` + `lockForUpdate()`, kiểm tra số dòng bị
ảnh hưởng (`$updated`); nếu `0` nghĩa là đã có request khác xử lý trước,
return sớm không cộng tiền/công nợ lần 2. Đồng thời thêm `lockForUpdate()`
khi đọc lại `$debt` trước khi cộng `amount_paid`, phòng race hiếm hơn nếu 2
`payment_orders` khác nhau cùng trỏ 1 `ref_id`.

**File**: `Modules/Driver/app/Http/Controllers/PaymentController.php`.

**Trạng thái**: Đã sửa, `php -l` sạch. Chưa deploy lên VPS, chưa test tay
kịch bản race thật (poll + webhook đồng thời).

---

## 2026-08-16 (c) — Backend: đổi 5 mốc chấm điểm online-ca suýt gây chấm điểm trùng ca

**Triệu chứng**: phát hiện lúc đổi ngưỡng % online/ca trong
`onShiftOnlineRate()` theo yêu cầu — đổi tên reason string đi kèm (vd
`shift_online_high` → `shift_online_normal`) nhưng
`ScoreShiftSessionsCommand::SCORE_REASONS` (dùng để check "ca này đã chấm
điểm chưa" trong `alreadyScored()`) vẫn còn trỏ tới 5 reason cũ. Nếu không
sửa, lần chạy cron kế tiếp sẽ không nhận ra ca vừa chấm bằng reason mới,
dẫn tới chấm điểm (cộng/trừ điểm) trùng lần thứ 2 cho cùng 1 ca.

**Nguyên nhân gốc**: reason string vừa là dữ liệu hiển thị (label lịch sử
điểm) vừa là khoá dùng cho logic idempotency (`alreadyScored()`) — đổi tên
reason ở nơi tạo ra (`DriverScoreService`) mà không rà hết các nơi khác
đang so khớp chuỗi đó thì phần logic (không chỉ phần hiển thị) cũng vỡ theo.

**Cách sửa**: cập nhật `SCORE_REASONS` gồm cả 5 reason mới lẫn cũ (giữ cũ
để không chấm trùng nếu log cũ còn rơi vào cửa sổ kiểm tra ngay sau khi
deploy). Đồng thời cập nhật `ScoreController::reasonLabel()` và Filament
`ScoreLogsRelationManager::reasonLabel()/reasonColor()` cho khớp 5 mốc mới,
giữ nguyên mapping cũ để log lịch sử trước đó vẫn hiển thị đúng.

**File**: `Modules/Driver/app/Services/DriverScoreService.php`,
`Modules/Driver/app/Console/Commands/ScoreShiftSessionsCommand.php`,
`Modules/Driver/app/Http/Controllers/ScoreController.php`,
`app/Filament/Resources/DriverScoreResource/RelationManagers/ScoreLogsRelationManager.php`.

**Trạng thái**: Đã sửa, `php -l` sạch cả 4 file. Chưa deploy lên VPS, chưa
chạy thử lệnh `drivers:score-shift-sessions` trên môi trường thật.

---

## 2026-08-16 (b) — App tài xế: lịch sử điểm hiện reason code thô thay vì nhãn tiếng Việt

**Triệu chứng**: người dùng báo trong màn "Điểm tích lũy" → tab lịch sử, một
số dòng hiện thẳng mã lý do (vd `viewed_timeout`, `shift_online_mid`) thay vì
câu tiếng Việt dễ hiểu như các dòng khác.

**Nguyên nhân gốc**: `ScoreController::reasonLabel()` map `reason` lưu trong
`driver_score_logs` sang nhãn hiển thị, nhưng thiếu map cho đúng các `reason`
mà `DriverScoreService` đang thực sự tạo ra — `onViewedTimeout()` ghi
`'viewed_timeout'` nhưng hàm chỉ có map cho `'timeout'` (không còn nơi nào
tạo ra reason này); `onShiftOnlineRate()` ghi 1 trong 4 reason
`shift_online_high/neutral/mid/low` nhưng hàm chỉ map mỗi
`shift_never_online`. Các trường hợp không khớp map rơi vào nhánh
`default => $reason`, hiện thẳng mã thô cho tài xế.

**Cách sửa**: thêm map cho `viewed_timeout` (gộp chung nhãn với `timeout` cũ)
và 4 reason `shift_online_*`, đồng thời đổi nhãn `shift_never_online` cho
khớp đúng chữ đang dùng ở `RulesCard` trên app ("Không online suốt cả ca").

**File**: `backend/Modules/Driver/app/Http/Controllers/ScoreController.php`.

**Trạng thái**: Đã sửa, `php -l` sạch. Chưa deploy lên VPS, chưa test tay
trên thiết bị thật.

---

## 2026-08-16 — App tài xế: màn "Điểm tích lũy" mô tả sai luật trừ điểm online

**Triệu chứng**: phát hiện lúc dọn lại UI màn Score theo yêu cầu người dùng
(bỏ card trùng lặp). Chưa ghi nhận khiếu nại từ tài xế, nhưng nội dung hiển
thị sai lệch với luật tính điểm thật đang chạy trên server.

**Nguyên nhân gốc**: `RulesCard` (`app/driver/lib/features/score/widgets/score_rules.dart`)
liệt kê 3 luật trừ điểm cũ đã bị gỡ bỏ khỏi backend từ lâu — "Không giao đơn 1
ngày -5", "Online dưới 8 giờ/ngày -5", "Không giao đơn 2+ ngày -10" — trong
khi `DriverScoreService::onShiftOnlineRate()` đã thay hẳn 3 luật đó bằng chấm
điểm theo % thời gian online/tổng thời lượng ca (+3 nếu ≥90%, -5 nếu 50-69%,
-10 nếu <50%, -15 nếu không online phút nào), và còn thiếu hẳn luật trừ -1
điểm khi bỏ lỡ 3 đơn không xem liên tiếp (`onOfferUnviewed`). Tài xế xem màn
này sẽ hiểu sai hoàn toàn cách mình bị trừ điểm.

**Cách sửa**: cập nhật danh sách `items` trong `RulesCard` cho khớp đúng các
hằng số/nhánh hiện có trong `DriverScoreService.php` (`Modules/Driver/app/Services/DriverScoreService.php`).
Không đổi logic tính điểm ở backend, chỉ sửa phần hiển thị.

**File**: `app/driver/lib/features/score/widgets/score_rules.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-14 (g) — App tài xế: banner "GPS đang tắt" đôi khi không tự tắt sau khi bật lại GPS

**Triệu chứng**: tài xế báo banner "GPS đang tắt" trên dashboard bị đứng lại
dù đã bật GPS lên rồi.

**Nguyên nhân gốc**: banner dựa vào `Geolocator.getServiceStatusStream()`
(lắng nghe realtime GPS service bật/tắt) để tự cập nhật. Stream này không
đáng tin cậy 100% lúc GPS được BẬT LẠI trên 1 số máy/OS — đặc biệt khi tài
xế đang offline (không có location manager nào đang hoạt động lúc đó) —
khiến sự kiện "đã bật lại" đôi khi không bắn ra, banner kẹt ở trạng thái cũ
vô thời hạn (chỉ tự khỏi khi app resume từ nền, không phải lúc nào tài xế
cũng làm việc đó).

**Cách sửa**: thêm 1 timer poll nhẹ mỗi 5s làm lưới an toàn — nhưng chỉ thật
sự gọi `Geolocator` khi đang có banner hiển thị (`_locationIssueProvider !=
null`), lúc mọi thứ bình thường thân timer gần như không tốn gì. Đảm bảo
banner tự khỏi trong tối đa 5s kể từ lúc GPS được bật lại, không phụ thuộc
vào độ tin cậy của stream nữa.

**File**: `app/driver/lib/features/home/screens/home_screen.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-14 (f) — App tài xế: bấm tắt online đúng lúc backend force-offline vì GPS chết có thể bị bật lại nhầm

**Triệu chứng**: phát hiện lúc audit logic tắt online của màn `home`. Chưa
ghi nhận sự cố thật từ tài xế — cửa sổ race hẹp nhưng khả thi, không phải lý
thuyết suông. Cùng lớp "tài xế ma" như mục (e) ở trên, nhưng theo hướng
ngược: lần này là tự bật nhầm lại thay vì kẹt online ảo.

**Nguyên nhân gốc**: `/driver/toggle-status` ở backend là API **toggle**
(đảo trạng thái), không phải "set trạng thái cụ thể". Nếu tài xế bấm tắt
online đúng lúc backend phát hiện GPS chết >10 phút và bắn RTDB
`is_online=false` (→ app nhận qua `_handleForceOffline()`): `_toggleOnline()`
đọc `currentlyOnline=true` (chưa kịp nhận sự kiện), gửi request
`/driver/toggle-status` đi. Trong lúc đang chờ phản hồi, `_handleForceOffline()`
chạy trước (đồng bộ, cập nhật `authProvider.isOnline=false` ngay), backend
cũng đã tự set `is_online=false` trước khi request kia tới nơi — nên "toggle"
đảo nó thành `true` lại. App nhận `isOnline=true` từ response và bật lại GPS
push + offer listener, dù lý do gốc (GPS chết) chưa chắc đã hết.

**Cách sửa**: thêm biến đếm `_externalOfflineEpoch` (tăng mỗi lần
`_handleForceOffline()` tự chạy — nguồn DUY NHẤT có thể đổi `is_online` phía
backend mà không qua 1 request app đang theo dõi). Gộp logic gọi
`/driver/toggle-status` với ý định tắt online vào `_postToggleOffline()`:
chụp lại epoch lúc bắt đầu, nếu response trả về `is_online=true` NHƯNG epoch
đã đổi trong lúc chờ → biết chắc vừa trúng race, tự gọi lại API 1 lần nữa để
đưa về đúng ý định ban đầu (tắt). Áp dụng cho cả 2 nơi gọi tắt online:
`_toggleOnline()` (bấm tay) và `_forceOffline()` (công nợ quá hạn/mất GPS).

**File**: `app/driver/lib/features/home/screens/home_screen.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật (race hẹp, khó tái hiện thủ công).

---

## 2026-08-14 (e) — App tài xế: bật online qua nút thì force-offline từ backend không cập nhật được UI

**Triệu chứng**: phát hiện lúc audit logic màn `home` (toggle online/offline,
GPS, fetch dữ liệu). Chưa ghi nhận sự cố thật từ tài xế, nhưng đúng lớp bug
"tài xế ma" từng gặp trước đây (#68/#107/#148/#232 — xem comment trong
`location_push_service.dart`).

**Nguyên nhân gốc**: `_DashboardPageState.initState()` chỉ gọi
`_syncOnlineTimer()`, và hàm này gán 2 callback
(`OfferListenerService.instance.onOfferDismissed`,
`LocationPushService.instance.onForceOffline`) **bên trong nhánh có điều
kiện** `if (driver?.isOnline != true) return;` — nghĩa là callback chỉ được
wire nếu tài xế ĐÃ online sẵn tại thời điểm mount màn hình. Nếu tài xế mở app
lúc đang offline rồi bấm nút bật online sau đó, `start()` của 2 service vẫn
chạy bình thường (đi qua nhánh khác trong `_toggleOnline`), nhưng 2 field
callback chưa từng được gán → khi backend tự phát hiện GPS chết quá 10 phút
và bắn `is_online=false` qua RTDB, `LocationPushService` gọi
`onForceOffline` nhưng field đang là `null`, không có gì xảy ra: tài xế vẫn
hiển thị "Đang nhận đơn" trên UI, `authProvider` vẫn giữ `isOnline=true`,
trong khi backend đã âm thầm coi tài xế là offline — đúng kịch bản "tài xế
ma" (app tưởng online, hệ thống không giao đơn được).

**Cách sửa**: chuyển 2 dòng gán callback ra khỏi `_syncOnlineTimer()`, gán 1
lần không điều kiện ngay trong `initState()` — `.start()`/`.stop()` của 2
service này không hề đụng tới field callback nên gán 1 lần là đủ dùng cho cả
phiên sống của màn hình, bất kể tài xế online/offline bao nhiêu lần sau đó.
`_syncOnlineTimer()` giờ chỉ còn phần `.start()` có điều kiện. Thêm dọn dẹp
`onOfferDismissed = null;`/`onForceOffline = null;` vào `dispose()` (giống
pattern `SessionGuardService.instance.onForceLogout = null;` đã có sẵn), vì 2
service này là singleton toàn app, không tự huỷ theo vòng đời màn hình.

**File**: `app/driver/lib/features/home/screens/home_screen.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-14 (d) — App tài xế: bấm "Đăng nhập" ở màn chờ duyệt có thể im lặng không làm gì

**Triệu chứng**: phát hiện lúc audit tiếp `pending_screen.dart`. Chưa ghi
nhận sự cố thật từ tài xế.

**Nguyên nhân gốc**: `_login()` gọi `AuthNotifier.refreshUser()` — hàm này
nuốt mọi lỗi mạng âm thầm (`catch (_) {}`), đúng cho các lần gọi nền khác
(polling 30s, lúc app resume) nhưng sai cho lần gọi do tài xế CHỦ ĐỘNG bấm
nút "Đăng nhập" sau khi đã được duyệt. Nếu mất mạng đúng lúc bấm,
`authProvider.user.status` không được cập nhật, router không redirect về
`/home` được, nút chỉ tắt loading rồi đứng im — không có phản hồi gì, tài xế
không hiểu vì sao bấm không có tác dụng.

**Cách sửa**: sau khi `refreshUser()` xong, kiểm tra lại
`ref.read(authProvider).isPending` — nếu vẫn còn true (nghĩa là chưa được
redirect) thì hiện SnackBar báo thử lại. Không đụng gì tới `refreshUser()`
dùng chung (các lần gọi nền khác vẫn im lặng đúng như cũ).

**File**: `app/driver/lib/features/auth/screens/pending_screen.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-14 (c) — App tài xế: màn login hiện nhầm lỗi của màn khác

**Triệu chứng**: phát hiện lúc audit tiếp `login_screen.dart` +
`forgot_password_screen.dart`. Chưa ghi nhận sự cố thật từ tài xế.

**Nguyên nhân gốc**: `login_screen` là màn auth DUY NHẤT dùng
`ref.watch(authProvider).error` để hiện banner lỗi trực tiếp (3 màn kia —
register/otp/forgot-password — đều tự giữ `_error` cục bộ). `authProvider`
dùng 1 field `error` chung cho cả `login()`/`sendOtp()`/
`verifyOtpAndRegister()`, chỉ xoá lúc BẮT ĐẦU lệnh gọi mới, không xoá khi
chuyển màn. Kịch bản: vào Đăng ký → `sendOtp()` lỗi (set `authProvider.error`)
→ bấm "Đăng nhập" quay lại `/login` → login hiện ngay banner lỗi của lần
`sendOtp()` thất bại đó, dù chưa hề bấm đăng nhập.

Đồng thời phát hiện `login_screen._submit()` và
`forgot_password_screen._sendOtp()`/`_resetPassword()` không có chốt chặn
gọi lại (cùng loại bug OTP đã sửa ở mục 2026-08-14 (b)) — field vẫn bấm
"Done"/enter được trong lúc request đang chạy.

**Cách sửa**: `login_screen.dart` đổi sang giữ `_loading`/`_error` cục bộ
giống 3 màn kia (đọc `authProvider.error` 1 lần ngay lúc thất bại thay vì
`watch` phản ứng theo state dùng chung); thêm `if (_loading) return;` đầu
`_submit()`. Thêm `if (_loading) return;`/`return false;` đầu
`_sendOtp()`/`_resetPassword()` trong `forgot_password_screen.dart`.

**File**: `app/driver/lib/features/auth/screens/login_screen.dart`,
`app/driver/lib/features/auth/screens/forgot_password_screen.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-14 (b) — App tài xế: OTP có thể gọi xác thực 2 lần song song

**Triệu chứng**: phát hiện lúc audit tiếp code màn `otp_screen.dart` sau đợt
sửa bug flow auth ở mục ngay trên. Chưa ghi nhận sự cố thật từ tài xế.

**Nguyên nhân gốc**: `_submit()` được gọi từ 2 nguồn — nút "Xác nhận OTP"
(đã disable đúng lúc `_loading=true`) và tự động khi gõ đủ 6 số
(`OtpInputRow.onFilled`). Ô nhập OTP không hề bị khoá trong lúc đang xác
thực và `_submit()` không có chốt chặn gọi lại — nếu user xoá rồi gõ lại số
cuối ngay trong lúc request `verifyOtpAndRegister` đầu tiên còn đang chạy,
`onFilled` bắn lần nữa → 2 request xác thực chạy song song, request về sau
ghi đè kết quả (điều hướng/lỗi) của request trước.

**Cách sửa**: thêm `if (_loading) return;` đầu `_submit()`; thêm param
`enabled` cho `OtpInputRow` (mặc định `true`), khoá `TextField` +
`GestureDetector` khi `enabled=false`; `otp_screen.dart` truyền
`enabled: !_loading`.

**File**: `app/driver/lib/features/auth/screens/otp_screen.dart`,
`app/driver/lib/features/auth/widgets/otp_input_row.dart`.

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật.

---

## 2026-08-14 — App tài xế: 3 bug logic phát hiện lúc audit code flow auth

**Triệu chứng**: không phải do tài xế báo — phát hiện lúc chủ động rà lại
code chức năng (không phải UI) của luồng đăng nhập/đăng ký/quên mật khẩu sau
đợt làm mới giao diện. Cả 3 chưa từng gây sự cố production được ghi nhận,
nhưng đều là bug thật, sẽ lộ ra khi gặp đúng điều kiện.

**3 bug**:
1. `forgot_password_screen.dart` — `_sendOtp()`/`_resetPassword()` gọi
   `setState()` sau `await` network mà không check `mounted` trước (4 màn
   auth còn lại đều có check này). Thoát màn giữa lúc request đang chạy →
   `setState` trên State đã dispose → crash/assert lúc debug.
2. `AuthNotifier.copyWith()` (auth_provider.dart) — param `error` gán thẳng
   `error: error` thay vì `error ?? this.error`, phá vỡ hợp đồng "giữ nguyên
   field không truyền" mà mọi field khác của `copyWith` đều tuân theo. Bất
   kỳ lệnh gọi `copyWith(...)` nào không nhắc tới `error` sẽ vô tình xoá
   error đang có trong state.
3. `AuthNotifier.updateOnlineStatus()` — thiếu `try/finally` quanh cờ
   `_toggleInFlight`. Nếu `_persistUser()` (ghi SharedPreferences) throw,
   cờ kẹt `true` vĩnh viễn → `refreshUser()` bị chặn đồng bộ profile
   (`is_online`, `balance`...) cho tới khi tài xế tắt/mở lại app.

**Cách sửa**:
1. Thêm `if (!mounted) return;`/`if (mounted) setState(...)` ở đúng các
   điểm sau `await` trong `forgot_password_screen.dart`.
2. Đổi `copyWith` sang dùng sentinel (`static const _unset = Object()`) để
   phân biệt "không truyền error" (giữ nguyên) với "truyền `error: null`"
   (xoá) — `AuthState copyWith({..., Object? error = _unset})`.
3. Bọc thân hàm `updateOnlineStatus()` trong `try { ... } finally { _toggleInFlight = false; }`.

Tiện thể gộp luôn logic đọc message lỗi từ backend (trước đó viết lặp lại 3
nơi, chỉ 1 bản đọc được field `errors` kiểu Laravel) vào
`core/utils/api_error.dart` (`parseApiError`), dùng chung cho
`auth_provider.dart` + `forgot_password_screen.dart` — login/register/otp
giờ cũng hiện đúng message lỗi validate cụ thể thay vì message chung chung.
Và sửa `register_screen.dart` dùng `ApiClient(null)` thay vì `Dio()` trần khi
tải danh sách khu vực — bản `Dio()` trần bỏ qua timeout + adapter bypass
bad-cert ở debug mode mà `ApiClient` có, có thể fail SSL riêng ở màn đăng ký
nếu backend dev/staging dùng cert tự ký.

**File**: `app/driver/lib/features/auth/providers/auth_provider.dart`,
`app/driver/lib/features/auth/screens/forgot_password_screen.dart`,
`app/driver/lib/features/auth/screens/register_screen.dart`,
`app/driver/lib/core/utils/api_error.dart` (mới).

**Trạng thái**: Đã sửa, `flutter analyze` sạch. Chưa test tay trên thiết bị
thật (theo yêu cầu user — không tự build/verify simulator khi không được
hỏi).

---

## 2026-08-13 — Antigravity tự push thẳng 3 commit lên main/production, gây crash dispatch 13.5 tiếng

**Triệu chứng**: chủ dự án dùng thử công cụ AI khác (Antigravity) trên cùng
repo local. Công cụ này tự commit + push thẳng lên GitHub `main`, và VPS
production tự `git pull` các commit đó qua deploy flow sẵn có — không qua
review. Phát hiện gián tiếp khi điều tra tiếp câu hỏi cũ về "$maxKm"/lỗi
không quét được tài xế.

**3 commit liên quan** (`a2f4270`, `f3744db`, `70901d9`), deploy lên VPS lúc
00:57 → 14:25 hôm nay:
- `a2f4270` ("tối ưu toàn diện hệ thống phát đơn"): refactor
  `DispatchService::getCandidates()` — đổi trần khoảng cách theo loại dịch
  vụ, đổi trọng số xếp hạng, thêm lọc sơ bộ haversine trước Google API,
  thêm thông báo khách khi 15p chưa có tài xế, thêm migration 2 cột
  `dispatch_found_at`/`dispatch_duration_secs` NHƯNG không có code nào ghi
  giá trị vào 2 cột đó (tính năng chỉ có vỏ, không có ruột). **Đồng thời tự
  gây ra 1 bug nghiêm trọng**: dùng biến `$maxKm` để tính `$haversineThreshold`
  TRƯỚC khi khai báo nó — `Undefined variable $maxKm`, crash ngay giữa
  `getCandidates()`, TRƯỚC cả bước hẹn giờ quét lại → đơn bị crash không có
  bất kỳ retry tự động nào, đứng im vĩnh viễn.
- `f3744db`: fix 1 bug thật khác (tài xế 8km vẫn nhận đơn khi Google
  Distance Matrix lỗi, do fallback cũ để `_road_km=null` lọt qua bộ lọc) —
  không đụng tới bug `$maxKm` ở trên, bug đó vẫn sống tiếp.
- `70901d9`: mới thật sự khai báo lại `$maxKm` đúng chỗ, dừng crash.

**Hậu quả đo được trên dữ liệu thật (production)**: bug `$maxKm` sống từ
00:57 đến 14:25 (~13.5 tiếng). **20 đơn** trong cửa sổ này có
`dispatch_started_at` (đã bắt đầu tìm tài xế) nhưng **0 lượt hỏi tài xế nào**
(`dispatch_attempts=0`, không có dòng `order_dispatch_logs` nào) — đúng
nghĩa "không quét được tài xế nào cả". 15/20 đơn phải chờ admin phát hiện và
tự huỷ tay; số còn lại tự huỷ theo cách khác. Không còn đơn nào kẹt ở trạng
thái active tính tới lúc điều tra.

**Quyết định của chủ dự án**: revert nguyên vẹn cả 3 commit — chấp nhận mất
luôn 2 fix thật (bug 8km, bug ghép đơn `keyBy`→`groupBy` chỉ kiểm tra đơn
active cuối) và các tính năng mới (trần khoảng cách linh hoạt theo loại xe,
thông báo khách lúc 15p), đổi lấy quay về đúng trạng thái đã biết rõ và tin
tưởng trước đó — không muốn giữ lại code chưa được review kỹ dù một phần có
ích.

**Cách sửa**: `git revert --no-commit 70901d9 f3744db a2f4270` rồi commit
gộp 1 lần (`0b125da`) — xác nhận `git diff` với commit trước đó
(`5369b56`) rỗng tuyệt đối. Trên VPS: rollback riêng migration
`2026_08_13_000001_add_dispatch_duration_to_orders` (`php artisan
migrate:rollback --step=1`) TRƯỚC khi pull code (cần file migration còn tồn
tại để chạy `down()`), sau đó mới pull + cache clear + reload.
File: `backend/Modules/Order/app/Services/DispatchService.php`.

**Trạng thái**: Đã revert, đã deploy, đã verify `git diff` rỗng và 2 cột
chết đã biến mất khỏi DB thật. **Lưu ý cho sau này**: cần khoá lại quyền
push thẳng lên `main`/production của các công cụ AI khác nếu muốn tránh lặp
lại kiểu sự cố này.

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

## 2026-08-14 — Lặp chữ "Ca" trong dòng trạng thái ca làm việc ở header trang home

**Triệu chứng**: Header trang home driver hiển thị "Ca Ca tối (18:00–00:00) ·
còn 11p" — lặp chữ "Ca".
**Nguyên nhân gốc**: `DashboardHeader` (widget vừa được redesign) prefix cứng
`TextSpan(text: 'Ca ')` trước `active.$1.name`, trong khi `ShiftModel.name`
đã tự chứa chữ "Ca" (VD "Ca tối", "Ca sáng").
**Cách sửa**: Bỏ `TextSpan` prefix "Ca " thừa trong
`app/driver/lib/features/home/widgets/dashboard_header.dart`, chỉ còn hiển
thị `${active.$1.name} (...)`.
**Trạng thái**: Đã sửa, chưa deploy/release app.

---

## 2026-08-14 — Ảnh đại diện không hiện ở Home sau khi upload ở Profile

**Triệu chứng**: Driver upload ảnh đại diện ở màn Profile (báo "Cập nhật ảnh
thành công"), nhưng avatar ở header trang Home vẫn hiện chữ cái viết tắt
(initials) thay vì ảnh thật, kể cả sau khi quay lại Home.
**Nguyên nhân gốc**: `_pickAndUpload()` trong
`app/driver/lib/features/profile/screens/profile_screen.dart` sau khi POST
`/driver/profile/update` thành công chỉ set `_photoUrl` vào **state cục bộ**
của `ProfileScreen` — không cập nhật `authProvider` (nơi
`DashboardHeader` ở Home đọc `user.profilePhotoUrl` qua
`ref.watch(authProvider).user`). State toàn cục vẫn giữ ảnh cũ (null) cho
tới khi app restart.
**Cách sửa**: Gọi thêm `ref.read(authProvider.notifier).refreshUser()` ngay
sau khi upload thành công, để đồng bộ `authProvider` với ảnh mới.
**Trạng thái**: Đã sửa, chưa deploy/release app.

---

## 2026-08-15 — RenderFlex overflow ~0.2px ở bottom nav khi chuyển tab

**Triệu chứng**: Console báo "A RenderFlex overflowed by 0.174/0.299 pixels
on the right" khi chuyển tab ở bottom nav (phát hiện ngay sau khi redesign
`BottomNav` để hiện icon+nhãn cạnh nhau cho tab đang chọn).
**Nguyên nhân gốc**: `AnimatedSize` lồng trong `AnimatedContainer` bọc
`Row(mainAxisSize: min, [Icon, Text?])` — trong lúc `AnimatedSize` đang nội
suy kích thước khung giữa 2 trạng thái (chỉ icon ↔ icon+nhãn), có khung hình
mà `Row` render đúng kích thước tự nhiên (đã lớn hơn) trong khi khung chứa
chưa kịp lớn theo, gây tràn vài phần trăm pixel.
**Cách sửa**: Bỏ `AnimatedSize` ở
`app/driver/lib/features/home/widgets/bottom_nav.dart` — chỉ giữ
`AnimatedContainer` animate màu/padding, nhãn xuất hiện tức thời (không cần
animate riêng kích thước Row).
**Trạng thái**: Đã sửa, chưa deploy/release app.

---

## Mẫu để copy khi thêm mục mới

```
## YYYY-MM-DD — Tên ngắn gọn

**Triệu chứng**: ...
**Nguyên nhân gốc**: ...
**Cách sửa**: ... (file cụ thể)
**Trạng thái**: Đã sửa & verify / Đã sửa, chưa deploy / Đã sửa & deploy, chưa release app (nếu liên quan app)
```
