# App Tài xế — Bản đồ nghiệp vụ

Tài liệu tra cứu khi debug app Tài xế (Flutter, `app/driver/`). Mỗi flow gồm: các
nghiệp vụ bên trong, file liên quan, và (nếu đã audit) checklist lỗi/điểm cần
chú ý. Cập nhật dần mỗi khi audit xong 1 flow mới — **không xoá phần cũ khi
audit phần mới**, chỉ bổ sung.

> Lưu ý: tài liệu này SỐNG TRONG REPO (git-tracked), khác với việc chỉ nói
> trong chat rồi mất — nếu thấy thiếu 1 flow đã từng audit, kiểm tra lại
> `git log -- backend/docs/app-driver-nghiep-vu.md` trước khi coi là mất.

---

## Danh sách flow (theo router `lib/core/router/app_router.dart`)

| Flow | Thư mục | Trạng thái audit |
|---|---|---|
| Xác thực (đăng ký/đăng nhập/quên mật khẩu/OTP/chờ duyệt) | `features/auth` | Chưa |
| **Trang chủ / Bật-tắt online** | `features/home` | **Đã audit — xem chi tiết bên dưới** |
| Đơn hàng (nhận offer/đơn đang chạy/lịch sử) | `features/orders` | Chưa |
| Ví & Thu nhập (ví/thu nhập/công nợ/ngân hàng) | `features/wallet` | Chưa |
| Điểm tích luỹ | `features/score` | Chưa |
| Ca làm việc | `features/shifts` | Chưa |
| Hồ sơ & Xác minh (profile/KYC/đổi mật khẩu/pháp lý) | `features/profile` | Chưa |
| Force update (kiểm tra phiên bản tối thiểu) | `features/version` | Chưa |

---

## Flow: Trang chủ / Bật-tắt online

File chính: `lib/features/home/screens/home_screen.dart` (2 State: `_HomeScreenState` — vỏ ngoài + điều hướng, `_DashboardPageState` — nội dung tab đầu tiên).

### Các nghiệp vụ bên trong

**1. Khởi tạo phiên (lúc mở app)** — `_HomeScreenState.initState()`
- Theo dõi GPS bật/tắt real-time qua `Geolocator.getServiceStatusStream()` — bắt được cả trường hợp kéo thanh notification tắt GPS mà không rời app (resume không tự kích hoạt lại được)
- Xin quyền thông báo — có dialog "mồi" (`_showNotifPrimingDialog`) trước khi xin quyền thật, tuân thủ yêu cầu Apple (không xin quyền đột ngột)
- Khởi động `SessionGuardService.instance.start(uid)` — theo dõi bị đăng nhập máy khác (`onForceLogout`) / bị khoá tài khoản (`onAccountLocked`), cả 2 đều tự gọi `logout()` rồi điều hướng `/login`
- Tải đơn đang chạy (`activeOrderProvider`), ví (`walletProvider`), nhãn dịch vụ (`Fmt.ensureLabelsLoaded()`)

**2. Vòng đời app (resume từ nền)** — tách làm 2 nơi, KHÔNG gộp (xem điểm audit bên dưới)
- `_HomeScreenState.didChangeAppLifecycleState`: làm mới user, đơn đang chạy, ví, quyền thông báo, tình trạng GPS, restart `LocationService` nếu đang online
- `_DashboardPageState.didChangeAppLifecycleState`: làm mới thu nhập, số đơn, điểm, ca làm việc (`_loadAll()`)

**3. Bật/tắt online** — `_DashboardPageState._toggleOnline()` / `_forceOffline()`
- Kiểm tra quyền vị trí trước khi cho bật (`_ensureLocationPermission`)
- Gọi API `/driver/toggle-status`, song song khởi động/dừng `LocationPushService` + `OfferListenerService`
- Chặn double-tap bằng cờ `_togglingOnline`
- Lỗi công nợ quá hạn (`debt_overdue`) có banner riêng dẫn thẳng tới màn công nợ

**4. Tự động tắt online khi phát sinh công nợ quá hạn** — `ref.listen<WalletState>` phát hiện cạnh chuyển từ "không quá hạn" → "quá hạn" thì tự gọi `_forceOffline()`

**5. Làm mới dữ liệu khi đơn hoàn tất/huỷ** — `ref.listen<ActiveOrderState>`, số đơn active giảm thì tự load lại thu nhập + ví

**6. Banner cảnh báo điều kiện hoạt động** (hiện đồng thời nếu có nhiều vấn đề): GPS tắt/thiếu quyền, thiếu quyền thông báo, chưa đăng ký ca, công nợ quá hạn

**7. Thẻ tổng quan**: thu nhập hôm nay/hôm qua + số đơn + đánh giá, điểm tích luỹ (đồng bộ real-time qua RTDB — `scoreProvider.subscribeRTDB`), ví + công nợ, hỗ trợ (link liên hệ cấu hình từ backend)

**8. Điều hướng 4 tab** qua `IndexedStack` — giữ nguyên trạng thái từng tab khi chuyển qua lại, không load lại từ đầu

### Checklist lỗi / điểm cần chú ý (audit ngày hôm nay)

1. **[Lỗi thật, CHƯA SỬA] "Online ma" khi API tắt online lỗi mạng** — `_toggleOnline()` (nhánh tắt) và `_forceOffline()` đều dừng `LocationPushService`/`OfferListenerService` **TRƯỚC** khi gọi API `/driver/toggle-status`. Nếu request đó thất bại (`DioException`/lỗi khác), `updateOnlineStatus()` không chạy nên `isOnline` local **vẫn giữ `true`** — UI vẫn hiện "Đang nhận đơn" nhưng GPS/lắng nghe đơn đã dừng thật. Tài xế bị dispatch coi là ứng viên hợp lệ (server cũng chưa nhận request) nhưng không hề nhận được thông báo đơn, cho tới khi vị trí đủ cũ mới bị freshness-gate loại. Không tự phục hồi trừ khi tài xế tình cờ bấm lại toggle. **Hướng sửa**: nếu API lỗi, khởi động lại các service đã dừng trước đó (coi như không có gì đổi).
2. **[Lỗi nhỏ, CHƯA SỬA] `SessionGuardService` không dừng hẳn khi rời trang chủ** — `dispose()` chỉ gán `null` cho 2 callback (`onForceLogout`/`onAccountLocked`), không gọi `SessionGuardService.instance.stop()`. 2 listener Firebase (`session_device`, `account_locked`) của tài khoản vừa đăng xuất vẫn treo tới lần đăng nhập kế tiếp mới tự dọn (vì `start()` tự `stop()` trước). Không tích luỹ qua nhiều lần, nhưng là gap dọn dẹp thật.
3. **[Cấu trúc, chưa phải bug] 2 `WidgetsBindingObserver` chồng chéo** — `_HomeScreenState` và `_DashboardPageState` đều tự phản ứng resume với logic khác nhau, khó nhớ "mở lại app thì cái gì được làm mới". Nên gộp về 1 nơi khi refactor.
4. **[Gap nhỏ, chưa phải bug] Lỗi xin quyền thông báo bị nuốt im lặng** — nếu `NotificationService.init(ref)` ném exception, khối `try/finally` vẫn chạy phần kiểm tra GPS ở `finally`, nhưng `_notifDeniedProvider` không bao giờ được set (mặc định `false` — coi như "ổn"). Khả năng xảy ra thấp.

### File liên quan (ngoài `home_screen.dart`)
- `core/services/location_service.dart` — luồng GPS thô (đã fix bug đa luồng song song ngày hôm nay)
- `core/services/location_push_service.dart` — đẩy vị trí lên Firebase (đã fix bug "đồng hồ ma" ngày hôm nay — xem comment đầu file)
- `core/services/offer_listener_service.dart` — lắng nghe offer đơn mới
- `core/services/session_guard_service.dart` — bảo vệ phiên đăng nhập
- `features/auth/providers/auth_provider.dart` — `logout()` gom tất cả các bước dừng service khi đăng xuất
