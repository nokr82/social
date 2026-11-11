/**
 * API 호출 래퍼.
 * - 토큰이 있으면 Authorization: Bearer 자동 첨부
 * - 서버 에러 {code, message}를 ApiError로 던져 화면이 code로 분기하고 message를 그대로 띄우게 함
 * - 401이면 토큰을 지우고 온보딩으로 (토큰 만료·로그아웃)
 */
import { auth } from './auth';
import { showToast } from './toast';

export class ApiError extends Error {
    constructor(status, code, message, errors = null) {
        super(message);
        this.status = status;
        this.code = code;       // 예: PLAZA_FULL, UNDER_AGE
        this.errors = errors;   // 422 검증 에러 {필드: [메시지]}
    }
}

export async function api(method, path, body = null, { keepalive = false } = {}) {
    const headers = { Accept: 'application/json' };
    const isForm = body instanceof FormData;
    // FormData(파일 업로드)는 브라우저가 boundary 포함 Content-Type을 직접 붙여야 함
    if (body && !isForm) headers['Content-Type'] = 'application/json';
    if (auth.token) headers.Authorization = `Bearer ${auth.token}`;
    // 실시간으로 보낸 내 메시지가 나에게 중복 수신되지 않도록 (서버의 toOthers)
    if (window.Echo?.socketId?.()) headers['X-Socket-ID'] = window.Echo.socketId();

    const res = await fetch(`/api${path}`, {
        method,
        headers,
        body: body ? (isForm ? body : JSON.stringify(body)) : undefined,
        keepalive, // 페이지를 떠날 때(광장 퇴장) 요청이 취소되지 않게
    });

    const data = res.status === 204 ? null : await res.json().catch(() => null);

    if (!res.ok) {
        if (res.status === 401) auth.clear();
        // 이용 제한 계정(ACCOUNT_SUSPENDED 등): 화면마다 처리하지 않아도 사유가 보이게
        if (res.status === 403 && data?.code?.startsWith('ACCOUNT_')) showToast(data.message, 4000);
        // 422 검증 에러는 첫 메시지를 대표로
        const firstError = data?.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new ApiError(res.status, data?.code ?? `HTTP_${res.status}`, firstError ?? data?.message ?? '잠시 후 다시 시도해 주세요', data?.errors);
    }

    return data;
}

export const get = (path) => api('GET', path);
export const post = (path, body) => api('POST', path, body ?? {});
export const put = (path, body) => api('PUT', path, body);
export const del = (path) => api('DELETE', path);
export const patch = (path, body) => api('PATCH', path, body);
/** 파일 업로드: FormData를 그대로 보냄 */
export const upload = (path, formData) => api('POST', path, formData);
