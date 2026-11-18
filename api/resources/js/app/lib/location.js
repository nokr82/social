/**
 * 현재 위치. 좌표는 요청에만 쓰고 서버는 저장하지 않는다(거리 라벨 계산용).
 * 권한 거부·실패 시 기본 위치(역삼역)로 대체하고 그 사실을 화면에 알린다 (fallback: true — 서버에는 보내지 않음).
 *
 * 캐시 규칙 (2026-11-18 수정):
 * - 진짜 위치만 1분 캐시. 예전에는 실패(기본 위치)까지 앱을 새로 열 때까지 캐시해서,
 *   사용자가 나중에 위치 권한을 켜도 계속 '위치 없음'으로 동작했음
 * - { fresh: true }: 캐시를 무시하고 지금 위치를 다시 받음 ('내 위치' 버튼처럼 바로 지금 위치가 필요할 때)
 * - 동시에 여러 화면이 부르면 요청 하나를 같이 기다림
 */
const FALLBACK = { lat: 37.5006, lng: 127.0364, label: '역삼동', fallback: true };
const CACHE_MS = 60 * 1000;

let cached = null;   // { ...위치, at }
let pending = null;

export function getLocation({ fresh = false } = {}) {
    if (!fresh && cached && Date.now() - cached.at < CACHE_MS) return Promise.resolve(cached);
    if (pending) return pending;
    if (!navigator.geolocation) return Promise.resolve(FALLBACK);

    pending = new Promise((resolve) => {
        navigator.geolocation.getCurrentPosition(
            (pos) => resolve((cached = {
                lat: pos.coords.latitude,
                lng: pos.coords.longitude,
                // 동 이름은 역지오코딩(카카오 로컬 API 등) 연동 전까지 '내 주변'
                label: '내 주변',
                fallback: false,
                at: Date.now(),
            })),
            () => resolve(FALLBACK), // 실패는 캐시하지 않음 → 권한을 켜면 다음 호출부터 바로 진짜 위치
            // fresh면 기기에 남은 예전 위치도 쓰지 않음
            { enableHighAccuracy: fresh, timeout: fresh ? 10000 : 5000, maximumAge: fresh ? 0 : CACHE_MS },
        );
    }).finally(() => { pending = null; });

    return pending;
}
