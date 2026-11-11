<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#F7F5FF">
    <title>소셜링</title>
    {{-- 홈 화면에 추가(PWA): iPhone은 이렇게 설치해야 웹 푸시를 받을 수 있음 --}}
    <link rel="manifest" href="/manifest.webmanifest">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="소셜링">
    {{-- 디자인 폰트: 제목 Jua, 본문 Noto Sans KR --}}
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Jua&family=Noto+Sans+KR:wght@400;500;700&display=swap">
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/social.css', 'resources/js/app/main.js'])
    @endif
</head>
<body>
    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        <div id="app"></div>
    @else
        {{-- 프런트 빌드 전 안내 (테스트 환경에서도 500 대신 이 화면) --}}
        <p style="font-family: sans-serif; padding: 24px">프런트엔드가 아직 빌드되지 않았어요. <code>npm run build</code>를 실행하세요.</p>
    @endif
</body>
</html>
