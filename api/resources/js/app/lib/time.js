/** 상대 시간 표시: 방금 / 10분 전 / 3시간 전 / 어제 / 3일 전 */
export function ago(iso) {
    if (!iso) return '';
    const m = Math.floor((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) return '방금';
    if (m < 60) return `${m}분 전`;
    if (m < 60 * 24) return `${Math.floor(m / 60)}시간 전`;
    const d = Math.floor(m / 1440);
    return d === 1 ? '어제' : `${d}일 전`;
}

/** 친구 상태 문구 (디자인: '○○에 있어요' / '3시간 전 접속') */
export function presenceText(f) {
    if (f.plaza_name) return `${f.plaza_name}에 있어요`;
    if (f.online) return '지금 접속 중';
    return f.last_seen_at ? `${ago(f.last_seen_at)} 접속` : '접속 기록 없음';
}

/** 성별·나이 배지 (디자인: '남 · 31') */
export const genderAge = (u) => (u?.gender ? `${u.gender === 'F' ? '여' : '남'} · ${u.age}` : '');
