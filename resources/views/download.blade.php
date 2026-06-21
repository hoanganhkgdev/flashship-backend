<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tải Flash Ship</title>
    <style>
        *{margin:0;padding:0;box-sizing:border-box}
        body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:linear-gradient(135deg,#ff6b35,#ff8f5e);min-height:100vh;display:flex;align-items:center;justify-content:center}
        .card{background:#fff;border-radius:24px;padding:40px 28px;max-width:340px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.15)}
        .logo{width:72px;height:72px;border-radius:18px;margin:0 auto 16px;background:linear-gradient(135deg,#ff6b35,#ff8f5e);display:flex;align-items:center;justify-content:center}
        .logo svg{width:36px;height:36px;fill:#fff}
        h1{font-size:20px;font-weight:800;color:#1a1a1a;margin-bottom:6px}
        .sub{font-size:13px;color:#888;margin-bottom:24px}
        .btn{display:block;width:100%;padding:14px;border-radius:14px;font-size:15px;font-weight:700;text-decoration:none;color:#fff;border:none;cursor:pointer;margin-bottom:10px;transition:transform .15s}
        .btn:active{transform:scale(.97)}
        .btn-ios{background:#000}
        .btn-android{background:#34a853}
        .divider{display:flex;align-items:center;gap:10px;margin:16px 0;color:#ccc;font-size:11px}
        .divider::before,.divider::after{content:'';flex:1;height:1px;background:#eee}
        .open-browser{display:block;padding:12px;border-radius:12px;background:#f5f5f5;font-size:13px;font-weight:600;color:#666;text-decoration:none;border:none;cursor:pointer;width:100%}
        .note{font-size:11px;color:#bbb;margin-top:16px;line-height:1.5}
        .copied{color:#22c55e !important;font-weight:700}
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <svg viewBox="0 0 24 24"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
        </div>
        <h1>Flash Ship</h1>
        <p class="sub">Tải ứng dụng đặt đơn giao hàng</p>

        <button class="btn btn-ios" onclick="openStore('ios')">Tải trên App Store</button>
        <button class="btn btn-android" onclick="openStore('android')">Tải trên Google Play</button>

        <div class="divider">hoặc</div>

        <button class="open-browser" id="copyBtn" onclick="copyLink()">
            Sao chép link tải app
        </button>

        <p class="note">Nếu không mở được store, hãy dán link vào Safari hoặc Chrome</p>
    </div>

    <script>
        var links = {
            ios: 'https://apps.apple.com/vn/app/flash-ship-%C4%91%E1%BA%B7t-%C4%91%C6%A1n/id6768362686',
            android: 'https://play.google.com/store/apps/details?id=vn.flashship.customer'
        };

        function detectOS() {
            var ua = navigator.userAgent.toLowerCase();
            if (/iphone|ipad|ipod/.test(ua)) return 'ios';
            if (/android/.test(ua)) return 'android';
            return null;
        }

        function openStore(os) {
            var url = links[os];
            // Thử nhiều cách mở
            var w = window.open(url, '_blank');
            if (!w) {
                window.location.href = url;
            }
        }

        function copyLink() {
            var os = detectOS() || 'android';
            var url = links[os];
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() { showCopied(); });
            } else {
                var input = document.createElement('input');
                input.value = url;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                showCopied();
            }
        }

        function showCopied() {
            var btn = document.getElementById('copyBtn');
            btn.textContent = 'Đã sao chép!';
            btn.classList.add('copied');
            setTimeout(function() {
                btn.textContent = 'Sao chép link tải app';
                btn.classList.remove('copied');
            }, 2000);
        }
    </script>
</body>
</html>
