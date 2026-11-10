/**
 * 아바타 파츠 코드 ↔ 화면 값. 서버(config/social.php avatar)와 코드가 1:1.
 */
export const AVATAR_COLORS = [
    { code: 'apricot', hex: '#FF9F7A', label: '살구' },
    { code: 'mint', hex: '#A8E6CF', label: '민트' },
    { code: 'sky', hex: '#B5C7FF', label: '하늘' },
    { code: 'pink', hex: '#FFD3E0', label: '분홍' },
    { code: 'yellow', hex: '#FFE58F', label: '노랑' },
    { code: 'lavender', hex: '#C9B8FF', label: '라벤더' },
];

// 표정 = 입 모양 SVG path (viewBox 0 0 30 30 기준)
export const AVATAR_FACES = [
    { code: 'smile', label: '방긋', mouth: 'M12.5 19.5q2.5 2 5 0' },
    { code: 'grin', label: '활짝', mouth: 'M11.5 19q3.5 3.5 7 0z' },
    { code: 'calm', label: '시크', mouth: 'M12.5 20h5' },
];

export function colorHex(code) {
    return AVATAR_COLORS.find((c) => c.code === code)?.hex ?? AVATAR_COLORS[0].hex;
}

export function mouthPath(code) {
    return AVATAR_FACES.find((f) => f.code === code)?.mouth ?? AVATAR_FACES[0].mouth;
}

// 카테고리별 타일 바닥색 (디자인 C안 타일의 ground)
export const CATEGORY_GROUND = {
    talk: '#E9E4FF',
    game: '#FFD9E6',
    food: '#FFF0B8',
    sports: '#D3F2E2',
    study: '#DCE6FF',
};

/**
 * 광장 안 좌표를 사용자 ID로 결정적으로 생성 (이동 전 초기 위치).
 * 같은 사람은 모든 접속자 화면에서 같은 자리에 서 있게 된다.
 */
export function seededSpot(userId, width, height, pad = 40) {
    // 정수 해시 (murmur3 마무리 단계). 주의: 일반 곱셈(n * 큰수)은 2^53을 넘어
    // 정밀도가 깨지므로 반드시 32비트 정수 곱셈 Math.imul을 쓴다.
    const h = (n) => {
        let x = Math.imul(n ^ (n >>> 16), 0x85ebca6b);
        x = Math.imul(x ^ (x >>> 13), 0xc2b2ae35);
        x ^= x >>> 16;
        return (x >>> 0) / 4294967296; // 0~1
    };
    return {
        x: Math.round(pad + h(userId) * (width - pad * 2)),
        y: Math.round(pad + h(userId + 7919) * (height - pad * 2)),
    };
}
