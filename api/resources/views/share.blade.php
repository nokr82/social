{{--
    공유 링크 미리보기 (로그인 없이). og 태그는 서버에서 넣어야 카카오톡이 읽는다.
    $p: ShareService::plazaPreview / meetupPreview — 밖에 보여도 되는 정보만 들어 있음
--}}
@php
    $ogTitle = $p['ended'] ? "{$p['title']} (끝남)" : "[{$p['kind']}] {$p['title']}";
    $ogImage = url('/icons/app-icon-1024.png');
@endphp
<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    {{-- 검색엔진에는 올리지 않음: 링크를 받은 사람만 보는 페이지 --}}
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $ogTitle }} · 소셜링</title>
    <meta name="description" content="{{ $p['og_description'] }}">

    {{-- og:url은 지금 주소와 같아야 카카오가 다른 페이지로 넘어가지 않는다 --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="소셜링">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $p['og_description'] }}">
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1024">
    <meta property="og:image:height" content="1024">
    <meta name="twitter:card" content="summary">

    <link rel="icon" href="/favicon.ico">
    <style>
        :root { --ink: #1F1B3A; --muted: #6B6785; --primary: #6C5CE7; --line: #ECE9F6; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #F6F4FD; color: var(--ink); font-family: -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Pretendard', 'Noto Sans KR', sans-serif; display: flex; justify-content: center; }
        main { width: 100%; max-width: 420px; padding: 32px 20px 40px; display: flex; flex-direction: column; gap: 16px; }
        .brand { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 15px; color: var(--primary); }
        .brand img { width: 28px; height: 28px; border-radius: 8px; }
        .card { background: #fff; border-radius: 24px; padding: 24px 20px; box-shadow: 0 6px 24px rgba(31, 27, 58, .06); display: flex; flex-direction: column; gap: 10px; }
        .kind { display: inline-flex; gap: 6px; align-items: center; font-size: 12px; font-weight: 700; color: var(--primary); }
        .badge { background: #EEEBFF; border-radius: 10px; padding: 2px 8px; }
        h1 { margin: 0; font-size: 22px; line-height: 1.35; word-break: keep-all; }
        .lines { margin: 0; padding: 0; list-style: none; display: flex; flex-direction: column; gap: 4px; font-size: 14px; color: var(--muted); }
        .desc { margin: 4px 0 0; font-size: 14px; line-height: 1.6; white-space: pre-line; word-break: keep-all; }
        .ended { font-size: 15px; color: var(--muted); }
        .cta { display: block; text-align: center; text-decoration: none; background: var(--primary); color: #fff; font-weight: 700; font-size: 16px; padding: 16px; border-radius: 16px; }
        .note { margin: 0; text-align: center; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
<main>
    <div class="brand"><img src="/icons/app-icon-192.png" alt="">소셜링</div>

    <section class="card">
        <span class="kind">
            {{ $p['kind'] }}
            @if ($p['badge'])<span class="badge">{{ $p['badge'] }}</span>@endif
        </span>
        <h1>{{ $p['title'] }}</h1>

        @if ($p['ended'])
            <p class="ended">이미 끝났거나 닫혔어요. 소셜링에서 다른 {{ $p['kind'] === '광장' ? '광장을' : '번개를' }} 찾아보세요.</p>
        @else
            @if ($p['lines'])
                <ul class="lines">
                    @foreach ($p['lines'] as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @endif
            @if ($p['description'])<p class="desc">{{ $p['description'] }}</p>@endif
        @endif
    </section>

    {{-- 로그인 안 했으면 앱이 가입 화면으로 보냈다가, 끝나면 이 광장·번개로 돌려보낸다 --}}
    <a class="cta" href="{{ $p['ended'] ? url('/') : url($p['app_path']) }}">{{ $p['ended'] ? '소셜링 둘러보기' : '앱에서 보기' }}</a>
    @unless ($p['ended'])<p class="note">{{ $p['note'] }}</p>@endunless
</main>
</body>
</html>
