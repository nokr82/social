{{--
    공유 링크 미리보기 (로그인 없이). og 태그는 서버에서 넣어야 카카오톡이 읽는다.
    $p: ShareService::plazaPreview / meetupPreview — 밖에 보여도 되는 정보만 들어 있음
    $ogImage: 카톡 카드 이미지 주소 (1200×630, ?v=내용 해시 → 내용이 바뀌면 주소도 바뀜)
--}}
@php
    $ogTitle = $p['ended'] ? "{$p['title']} (끝남)" : "[{$p['kind']}] {$p['title']}";
    $progress = $p['progress'] ?? null;
    $percent = $progress ? min(100, (int) round($progress['joined'] / max(1, $progress['capacity']) * 100)) : 0;
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
    <meta name="theme-color" content="#6C5CE7">

    {{-- og:url은 지금 주소와 같아야 카카오가 다른 페이지로 넘어가지 않는다 --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="소셜링">
    <meta property="og:url" content="{{ url()->current() }}">
    <meta property="og:title" content="{{ $ogTitle }}">
    <meta property="og:description" content="{{ $p['og_description'] }}">
    {{-- 가로형(1.91:1) 이미지 → 카톡이 큰 카드로 보여줌 (정사각형 아이콘이면 작은 썸네일) --}}
    <meta property="og:image" content="{{ $ogImage }}">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ $ogTitle }}">
    <meta name="twitter:card" content="summary_large_image">

    <link rel="icon" href="/favicon.ico">
    <style>
        :root { --ink: #1F1B3A; --ink2: #4A4566; --muted: #6B6785; --primary: #6C5CE7; --deep: #4B3CC4; --soft: #EEEBFF; --line: #ECE9F6; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; background: #F6F4FD; color: var(--ink); font-family: -apple-system, BlinkMacSystemFont, 'Apple SD Gothic Neo', 'Pretendard', 'Noto Sans KR', sans-serif; display: flex; justify-content: center; -webkit-font-smoothing: antialiased; }
        main { width: 100%; max-width: 440px; padding: 20px 16px 40px; display: flex; flex-direction: column; gap: 14px; }
        .top { display: flex; align-items: center; justify-content: space-between; }
        .brand { display: flex; align-items: center; gap: 8px; font-weight: 800; font-size: 16px; color: var(--primary); text-decoration: none; }
        .brand img { width: 30px; height: 30px; border-radius: 9px; }
        .top small { font-size: 12px; color: var(--muted); }
        .hero { display: block; width: 100%; aspect-ratio: 1200 / 630; border-radius: 20px; object-fit: cover; background: var(--primary); box-shadow: 0 10px 30px rgba(108, 92, 231, .25); }
        .ended .hero { filter: grayscale(1); box-shadow: none; opacity: .8; }
        .card { background: #fff; border-radius: 20px; padding: 20px; box-shadow: 0 4px 18px rgba(31, 27, 58, .05); display: flex; flex-direction: column; gap: 12px; }
        .kind { display: inline-flex; align-self: flex-start; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; color: var(--deep); background: var(--soft); border-radius: 999px; padding: 4px 10px; }
        h1 { margin: 0; font-size: 22px; line-height: 1.35; word-break: keep-all; }
        .chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 0; padding: 0; list-style: none; }
        .chips li { font-size: 13px; font-weight: 600; color: var(--ink2); background: #F4F2FA; border-radius: 999px; padding: 6px 12px; }
        .bar { display: flex; flex-direction: column; gap: 6px; }
        .bar-track { height: 8px; border-radius: 4px; background: var(--line); overflow: hidden; }
        .bar-fill { height: 100%; border-radius: 4px; background: var(--primary); }
        .bar small { font-size: 12px; color: var(--muted); }
        .desc { margin: 0; font-size: 15px; line-height: 1.65; color: var(--ink2); white-space: pre-line; word-break: keep-all; border-top: 1px solid var(--line); padding-top: 12px; }
        .ended-text { margin: 0; font-size: 15px; line-height: 1.6; color: var(--muted); }
        .cta { display: flex; align-items: center; justify-content: center; gap: 6px; text-decoration: none; background: var(--primary); color: #fff; font-weight: 800; font-size: 17px; height: 58px; border-radius: 16px; box-shadow: 0 6px 16px rgba(108, 92, 231, .3); }
        .cta:active { transform: scale(.98); }
        .ended .cta { background: var(--ink); box-shadow: none; }
        .note { margin: 0; text-align: center; font-size: 12px; color: var(--muted); }
        .about { margin: 8px 0 0; text-align: center; font-size: 13px; line-height: 1.6; color: var(--muted); }
        .about b { color: var(--ink2); }
    </style>
</head>
<body>
<main class="{{ $p['ended'] ? 'ended' : '' }}">
    <div class="top">
        <a class="brand" href="{{ url('/') }}"><img src="/icons/app-icon-192.png" alt="">소셜링</a>
        <small>친구가 보낸 초대 링크</small>
    </div>

    {{-- 카톡 카드와 같은 그림을 맨 위에 크게 --}}
    <img class="hero" src="{{ $ogImage }}" alt="{{ $ogTitle }}" width="1200" height="630">

    <section class="card">
        <span class="kind">{{ $p['kind'] === '번개' ? '⚡ 번개' : '광장' }}@if ($p['badge'] && $p['kind'] === '광장') · {{ $p['badge'] }}@endif</span>
        <h1>{{ $p['title'] }}</h1>

        @if ($p['ended'])
            <p class="ended-text">이미 끝났거나 닫혔어요. 소셜링에서 다른 {{ $p['kind'] === '광장' ? '광장을' : '번개를' }} 찾아보세요.</p>
        @else
            @if ($p['lines'])
                <ul class="chips">
                    @foreach ($p['lines'] as $line)<li>{{ $line }}</li>@endforeach
                </ul>
            @endif

            @if ($progress)
                <div class="bar" aria-label="참여 인원">
                    <div class="bar-track"><div class="bar-fill" style="width: {{ $percent }}%"></div></div>
                    <small>{{ $progress['joined'] }}명 참여 · {{ max(0, $progress['capacity'] - $progress['joined']) }}자리 남음</small>
                </div>
            @endif

            @if ($p['description'])<p class="desc">{{ $p['description'] }}</p>@endif
        @endif
    </section>

    {{-- 로그인 안 했으면 앱이 가입 화면으로 보냈다가, 끝나면 이 광장·번개로 돌려보낸다 --}}
    <a class="cta" href="{{ $p['ended'] ? url('/') : url($p['app_path']) }}">{{ $p['ended'] ? '소셜링 둘러보기' : '앱에서 보기' }}</a>
    @unless ($p['ended'])<p class="note">{{ $p['note'] }}</p>@endunless

    <p class="about"><b>소셜링</b> · 내 주변 사람들과 광장에서 이야기하고<br>마음이 맞으면 번개로 만나요</p>
</main>
</body>
</html>
