<!DOCTYPE html>
<html lang="vi">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $page->title }} – FlashShip</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 800px; margin: 0 auto; padding: 24px 16px; color: #1c1c1e; line-height: 1.7; }
  h1 { color: #FF6B35; font-size: 28px; }
  h2 { font-size: 18px; margin-top: 32px; color: #1c1c1e; }
  p, li { color: #3a3a3c; font-size: 15px; }
  ul, ol { padding-left: 20px; }
  .updated { color: #8e8e93; font-size: 13px; margin-bottom: 32px; }
  a { color: #FF6B35; }
</style>
</head>
<body>
<h1>⚡ {{ $page->title }}</h1>
<p class="updated">Cập nhật lần cuối: {{ $page->updated_at?->format('d/m/Y') }}</p>
{!! $page->content !!}
</body>
</html>
