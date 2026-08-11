# Lịch sử sửa lỗi

Nhật ký các lỗi đã tìm ra và sửa — tra trước khi điều tra 1 hiện tượng "lạ",
có thể đã từng gặp và sửa rồi. Mới nhất ở trên cùng. Mỗi mục gồm: triệu
chứng → nguyên nhân gốc → cách sửa (file) → trạng thái.

**Quy tắc**: mỗi khi sửa xong 1 bug thật (không phải thêm tính năng), thêm 1
mục vào đây TRƯỚC khi coi là xong việc.

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
