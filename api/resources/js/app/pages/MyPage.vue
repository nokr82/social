<script setup>
/**
 * MY — 내 프로필·사진 관리 (디자인: My.dc.html)
 * - 사진마다 [공개]/[비공개] 탭해서 전환 (대표사진=첫 장은 항상 공개)
 * - 비공개로 바꿔도 하트 유지 · 최대 9장
 * - 디자인에 없는 동작(사진 삭제, 로그아웃)은 사진 탭 메뉴 / 설정 버튼에 배치
 */
import { computed, onMounted, reactive, ref } from 'vue';
import { useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import HeartIcon from '../components/HeartIcon.vue';
import LockIcon from '../components/LockIcon.vue';
import TabBar from '../components/TabBar.vue';
import { ApiError, del, get, patch, post, put, upload } from '../lib/api';
import { auth } from '../lib/auth';
import { AVATAR_COLORS, AVATAR_FACES } from '../lib/avatar';
import { disconnectEcho } from '../lib/echo';
import { uploadPhoto } from '../lib/image';
import { comingSoon, showToast } from '../lib/toast';

const MAX = 9;
const router = useRouter();

const me = ref(null);
const photos = ref([]);
const uploading = ref(false);
const fileInput = ref(null);
const menuPhoto = ref(null);       // 사진 탭 → 메뉴
const editing = ref(false);        // 프로필 편집 시트
const settings = ref(false);
const form = reactive({ bio: '', color: 'apricot', face: 'smile' });

const pubN = computed(() => photos.value.filter((p) => p.visibility === 'public').length);
const privN = computed(() => photos.value.length - pubN.value);

function errMsg(e, fallback) {
    return e instanceof ApiError ? e.message : fallback;
}

async function load() {
    const [m, p] = await Promise.all([get('/me'), get('/me/photos')]);
    me.value = m.data;
    auth.me = m.data;
    photos.value = p.data;
}

/** 공개 ↔ 비공개 */
async function toggleVisibility(photo) {
    if (photo.is_main) return showToast('대표사진은 항상 공개돼요');
    const next = photo.visibility === 'public' ? 'private' : 'public';
    try {
        Object.assign(photo, (await patch(`/me/photos/${photo.id}`, { visibility: next })).data);
    } catch (e) {
        showToast(errMsg(e, '바꾸지 못했어요'));
    }
}

async function onPick(event) {
    const file = event.target.files?.[0];
    event.target.value = ''; // 같은 파일을 다시 골라도 change가 일어나게
    if (!file) return;
    uploading.value = true;
    try {
        photos.value.push(await uploadPhoto(file, upload));
        me.value.stats.photos++;
    } catch (e) {
        showToast(errMsg(e, '사진을 올리지 못했어요'));
    } finally {
        uploading.value = false;
    }
}

async function removePhoto(photo) {
    menuPhoto.value = null;
    try {
        await del(`/me/photos/${photo.id}`);
        await load(); // 대표사진이 바뀔 수 있어 다시 불러옴
    } catch (e) {
        showToast(errMsg(e, '삭제하지 못했어요'));
    }
}

function openEdit() {
    form.bio = me.value.bio ?? '';
    form.color = me.value.avatar?.color ?? 'apricot';
    form.face = me.value.avatar?.face ?? 'smile';
    editing.value = true;
}

async function saveEdit() {
    try {
        await Promise.all([
            api_patchProfile(),
            put('/me/avatar', { parts: { color: form.color, face: form.face } }),
        ]);
        editing.value = false;
        await load();
    } catch (e) {
        showToast(errMsg(e, '저장하지 못했어요'));
    }
}
const api_patchProfile = () => patch('/me/profile', { bio: form.bio.trim() || null });

async function logout() {
    try { await post('/auth/logout'); } catch { /* 토큰이 이미 만료돼도 로컬은 정리 */ }
    disconnectEcho();
    auth.clear();
    router.replace({ name: 'onboarding' });
}

onMounted(() => load().catch((e) => showToast(errMsg(e, '정보를 불러오지 못했어요'))));
</script>

<template>
    <div class="my">
        <div class="head">
            <h1 class="title" style="font-size: 26px">MY</h1>
            <button class="round" aria-label="설정" @click="settings = true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="3" /><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z" /></svg>
            </button>
        </div>

        <div v-if="me" class="body scroll">
            <!-- 내 정보 카드 -->
            <div class="card">
                <div class="row">
                    <div class="avatar-wrap">
                        <span class="avatar"><AvatarFace :parts="me.avatar" :size="62" /></span>
                        <button class="pen" aria-label="아바타 꾸미기" @click="openEdit">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9" /><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z" /></svg>
                        </button>
                    </div>
                    <div class="info">
                        <span class="name-row">
                            <b>{{ me.nickname }}</b>
                            <span class="verified">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="#1E7A4C" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12l5 5L20 7" /></svg>본인인증
                            </span>
                        </span>
                        <span class="bio">{{ me.bio || '한 줄 소개를 적어보세요' }}</span>
                        <span class="private"><LockIcon :size="12" color="#6B6785" />{{ me.gender === 'F' ? '여' : '남' }} · {{ me.age }}세 <i>— 친구에게만 보여요</i></span>
                        <button class="link" @click="openEdit">프로필 편집</button>
                    </div>
                </div>
                <div class="stats">
                    <div><b class="heart"><HeartIcon :size="15" />{{ me.stats.hearts }}</b><span>받은 하트</span></div>
                    <div class="mid"><b>{{ me.stats.friends }}</b><span>친구</span></div>
                    <div><b class="purple">{{ me.stats.received_requests }}</b><span>받은 친구 요청</span></div>
                </div>
            </div>

            <!-- 내 사진 -->
            <section class="sec">
                <div class="sec-head">
                    <h2>내 사진</h2>
                    <span>공개 {{ pubN }} · 비공개 {{ privN }} · {{ photos.length }}/{{ MAX }}장</span>
                </div>
                <div class="grid">
                    <div v-for="(p, i) in photos" :key="p.id" class="photo" @click.self="menuPhoto = p">
                        <img :src="p.url" :alt="`내 사진 ${i + 1}`" loading="lazy" @click="menuPhoto = p">
                        <div v-if="p.visibility === 'private'" class="shade" aria-hidden="true" @click="menuPhoto = p" />
                        <span v-if="p.is_main" class="main-tag">대표</span>
                        <button
                            class="vis" :class="p.visibility"
                            :aria-label="p.is_main ? '대표사진은 항상 공개' : `사진 ${i + 1} ${p.visibility === 'public' ? '비공개' : '공개'}로 바꾸기`"
                            @click.stop="toggleVisibility(p)"
                        >
                            <svg v-if="p.visibility === 'public'" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="#FFFFFF" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12z" /><circle cx="12" cy="12" r="3" /></svg>
                            <LockIcon v-else :size="12" color="#FFFFFF" />
                            {{ p.visibility === 'public' ? '공개' : '비공개' }}
                        </button>
                        <span class="hearts"><HeartIcon :size="11" />{{ p.like_count }}</span>
                    </div>
                    <button v-if="photos.length < MAX" class="add" aria-label="사진 추가" :disabled="uploading" @click="fileInput.click()">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><path d="M12 5v14M5 12h14" /></svg>
                        {{ uploading ? '올리는 중…' : '사진 추가' }}
                    </button>
                </div>
                <input ref="fileInput" type="file" accept="image/*" hidden @change="onPick">
                <div class="note">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#5B4BD6" stroke-width="2.2" stroke-linecap="round" aria-hidden="true" style="flex-shrink: 0; margin-top: 1px"><circle cx="12" cy="12" r="9" /><path d="M12 11v5M12 8h.01" /></svg>
                    <span>비공개 사진은 친구 요청을 수락한 사람만 볼 수 있어요. 대표사진은 항상 공개돼요. 비공개로 바꿔도 받은 하트는 유지돼요.</span>
                </div>
            </section>

            <!-- 메뉴 -->
            <div class="menu">
                <button @click="router.push({ name: 'friend-requests' })">
                    친구 요청 관리
                    <span class="right">
                        <span v-if="me.stats.received_requests" class="badge">{{ me.stats.received_requests }}</span>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A85A3" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                    </span>
                </button>
                <button v-for="m in ['친구 목록', '차단 목록', '알림 설정']" :key="m" @click="comingSoon(m)">
                    {{ m }}
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8A85A3" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6" /></svg>
                </button>
            </div>
        </div>

        <TabBar active="my" />

        <!-- 사진 메뉴 -->
        <div v-if="menuPhoto" class="dim" @click.self="menuPhoto = null">
            <div class="sheet" role="dialog" aria-label="사진 메뉴">
                <div class="grip" />
                <button v-if="!menuPhoto.is_main" class="sheet-row" @click="toggleVisibility(menuPhoto); menuPhoto = null">
                    {{ menuPhoto.visibility === 'public' ? '비공개로 바꾸기' : '공개로 바꾸기' }}
                </button>
                <button class="sheet-row danger" @click="removePhoto(menuPhoto)">사진 삭제</button>
                <button class="sheet-cancel" @click="menuPhoto = null">취소</button>
            </div>
        </div>

        <!-- 프로필 편집 -->
        <div v-if="editing" class="dim" @click.self="editing = false">
            <div class="sheet" role="dialog" aria-label="프로필 편집">
                <div class="grip" />
                <div class="preview"><AvatarFace :parts="form" :size="88" /></div>
                <div class="field">
                    <span class="label">색</span>
                    <div class="chips">
                        <button v-for="c in AVATAR_COLORS" :key="c.code" class="swatch" :class="{ on: form.color === c.code }" :aria-label="`${c.label} 색`" @click="form.color = c.code"><span :style="{ background: c.hex }" /></button>
                    </div>
                </div>
                <div class="field">
                    <span class="label">표정</span>
                    <div class="chips">
                        <button v-for="f in AVATAR_FACES" :key="f.code" class="chip" :class="{ on: form.face === f.code }" @click="form.face = f.code">{{ f.label }}</button>
                    </div>
                </div>
                <label class="field">
                    <span class="label">한 줄 소개</span>
                    <input v-model="form.bio" class="input" maxlength="300" placeholder="예) 주말엔 맛집 탐방, 평일엔 퇴근 수다">
                </label>
                <button class="btn-primary" @click="saveEdit">저장</button>
            </div>
        </div>

        <!-- 설정 -->
        <div v-if="settings" class="dim" @click.self="settings = false">
            <div class="sheet" role="dialog" aria-label="설정">
                <div class="grip" />
                <button class="sheet-row" @click="logout">로그아웃</button>
                <button class="sheet-cancel" @click="settings = false">닫기</button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.my { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; justify-content: space-between; padding: 18px 12px 6px 20px; }
.round { width: 44px; height: 44px; border-radius: 22px; border: none; background: var(--c-surface); display: flex; align-items: center; justify-content: center; box-shadow: var(--shadow-soft); }
.body { flex: 1; min-height: 0; padding: 4px 20px 110px; display: flex; flex-direction: column; gap: 18px; }

.card { background: var(--c-surface); border-radius: 24px; padding: 16px; display: flex; flex-direction: column; gap: 14px; box-shadow: 0 2px 10px rgba(108,92,231,.07); }
.row { display: flex; align-items: center; gap: 14px; }
.avatar-wrap { position: relative; flex-shrink: 0; }
.avatar { width: 72px; height: 72px; border-radius: 36px; background: var(--c-primary-soft); display: flex; align-items: center; justify-content: center; }
.pen { position: absolute; right: -6px; bottom: -6px; width: 32px; height: 32px; border-radius: 16px; border: 2px solid #fff; background: var(--c-primary); display: flex; align-items: center; justify-content: center; padding: 0; }
.info { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 4px; }
.name-row { display: flex; align-items: center; gap: 6px; }
.name-row b { font-size: 18px; }
.verified { display: inline-flex; align-items: center; gap: 3px; background: #E6F8EF; color: var(--c-ok); font-size: 11px; font-weight: 700; padding: 2px 7px; border-radius: 8px; }
.bio { font-size: 13px; color: var(--c-ink-2); }
.private { display: flex; align-items: center; gap: 4px; font-size: 12px; color: var(--c-muted); }
.private i { font-style: normal; color: var(--c-placeholder); }
.link { align-self: flex-start; border: none; background: none; padding: 4px 0; color: var(--c-primary-ink); font-size: 13px; font-weight: 700; }
.stats { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); background: var(--c-bg); border-radius: 16px; padding: 10px 0; }
.stats div { display: flex; flex-direction: column; align-items: center; gap: 2px; }
.stats .mid { border-left: 1px solid var(--c-divider); border-right: 1px solid var(--c-divider); }
.stats b { font-size: 18px; }
.stats b.heart { display: flex; align-items: center; gap: 3px; color: #D63E5C; }
.stats b.purple { color: var(--c-primary-ink); }
.stats span { font-size: 11px; color: var(--c-muted); }

.sec { display: flex; flex-direction: column; gap: 10px; }
.sec-head { display: flex; justify-content: space-between; align-items: baseline; }
.sec-head h2 { margin: 0; font-size: 16px; font-weight: 700; }
.sec-head span { font-size: 12px; color: var(--c-muted); }
.grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; }
.photo { position: relative; height: 112px; border-radius: 16px; overflow: hidden; background: #E6E1FF; cursor: pointer; }
.photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.shade { position: absolute; inset: 0; background: rgba(31,27,58,.35); }
.main-tag { position: absolute; left: 6px; top: 6px; background: #fff; color: var(--c-primary-deep); font-size: 10px; font-weight: 700; padding: 2px 6px; border-radius: 6px; }
.vis { position: absolute; right: 6px; top: 6px; height: 30px; padding: 0 8px; border: none; border-radius: 15px; color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; gap: 3px; }
.vis.public { background: var(--c-primary); }
.vis.private { background: var(--c-ink); }
.hearts { position: absolute; left: 6px; bottom: 6px; display: flex; align-items: center; gap: 3px; background: rgba(255,255,255,.92); border-radius: 10px; padding: 2px 7px; font-size: 11px; font-weight: 700; color: var(--c-ink); }
.add { height: 112px; border-radius: 16px; border: 2px dashed #CFC6FF; background: var(--c-surface); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 4px; color: var(--c-primary-ink); font-size: 12px; font-weight: 700; }
.note { display: flex; gap: 8px; background: var(--c-primary-soft); border-radius: 14px; padding: 10px 12px; font-size: 12px; color: var(--c-primary-deep); line-height: 1.5; }

.menu { background: var(--c-surface); border-radius: 20px; display: flex; flex-direction: column; overflow: hidden; }
.menu button { height: 54px; border: none; background: none; display: flex; align-items: center; justify-content: space-between; padding: 0 16px; font-size: 15px; color: var(--c-ink); border-bottom: 1px solid var(--c-primary-soft); }
.menu button:last-child { border-bottom: none; }
.right { display: flex; align-items: center; gap: 6px; }
.badge { min-width: 20px; height: 20px; border-radius: 10px; background: #E04A36; color: #fff; font-size: 11px; font-weight: 700; display: flex; align-items: center; justify-content: center; padding: 0 6px; }

.dim { position: absolute; inset: 0; background: rgba(31,27,58,.45); display: flex; align-items: flex-end; z-index: 30; }
.sheet { width: 100%; background: var(--c-surface); border-radius: 28px 28px 0 0; padding: 10px 20px 30px; display: flex; flex-direction: column; gap: 10px; }
.grip { align-self: center; width: 40px; height: 5px; border-radius: 3px; background: var(--c-line); margin-bottom: 4px; }
.sheet-row { height: 56px; border: none; background: var(--c-bg); border-radius: 16px; font-size: 16px; font-weight: 700; color: var(--c-ink); }
.sheet-row.danger { color: var(--c-danger); }
.sheet-cancel { height: 50px; border: none; background: none; font-size: 15px; font-weight: 700; color: var(--c-ink-2); }
.preview { align-self: center; width: 108px; height: 108px; border-radius: 54px; background: #E9E4FF; display: flex; align-items: center; justify-content: center; }
.field { display: flex; flex-direction: column; gap: 8px; }
.label { font-size: 13px; font-weight: 700; color: var(--c-ink-2); }
.chips { display: flex; gap: 8px; flex-wrap: wrap; }
.swatch { width: 40px; height: 40px; border-radius: 20px; border: 3px solid transparent; background: var(--c-surface); padding: 3px; }
.swatch.on { border-color: var(--c-primary); }
.swatch span { display: block; width: 100%; height: 100%; border-radius: 50%; }
.chip { height: 38px; padding: 0 16px; border: 1.5px solid var(--c-line); border-radius: 19px; background: var(--c-surface); color: var(--c-ink-2); font-size: 14px; }
.chip.on { border-color: var(--c-primary); background: var(--c-primary); color: #fff; font-weight: 700; }
.input { height: 50px; border: 1.5px solid var(--c-line); border-radius: 14px; padding: 0 14px; font-size: 15px; color: var(--c-ink); background: var(--c-surface); outline: none; }
.input:focus { border-color: var(--c-primary); }
</style>
