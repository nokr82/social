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

/** 번개 시간: '오늘 저녁 8:00' / '내일 오후 2:30' */
export function meetupWhen(iso) {
    const d = new Date(iso);
    const today = new Date();
    const tomorrow = new Date(); tomorrow.setDate(today.getDate() + 1);
    const day = d.toDateString() === today.toDateString() ? '오늘'
        : d.toDateString() === tomorrow.toDateString() ? '내일'
        : `${d.getMonth() + 1}/${d.getDate()}`;
    const h = d.getHours();
    const part = h < 6 ? '새벽' : h < 12 ? '오전' : h < 18 ? '오후' : '저녁';
    const h12 = h % 12 === 0 ? 12 : h % 12;
    return `${day} ${part} ${h12}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/** 남은 시간: '2시간 40분 후' / '30분 후' / '진행 중' */
export function until(iso) {
    const m = Math.round((new Date(iso).getTime() - Date.now()) / 60000);
    if (m <= 0) return '진행 중';
    if (m < 60) return `${m}분 후`;
    const h = Math.floor(m / 60);
    return m % 60 ? `${h}시간 ${m % 60}분 후` : `${h}시간 후`;
}

/** 오늘/내일 칩 표시용 */
export function dayLabel(iso) {
    const d = new Date(iso);
    return d.toDateString() === new Date().toDateString() ? '오늘' : '내일';
}
