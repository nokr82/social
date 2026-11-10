<script setup>
/**
 * 상대 프로필 (디자인: Profile.dc.html)
 * - 공개 사진: 사진마다 하트 (내가 누르면 +1, 다시 누르면 취소)
 * - 친구에게만 공개: 성별·나이·비공개 사진 (친구 전에는 잠금)
 * - 하단: 친구 요청 / 요청 보냄 / 받은 요청 수락하러 가기 / 친구
 */
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AvatarFace from '../components/AvatarFace.vue';
import FriendRequestSheet from '../components/FriendRequestSheet.vue';
import HeartIcon from '../components/HeartIcon.vue';
import LockIcon from '../components/LockIcon.vue';
import { ApiError, get, post } from '../lib/api';
import { comingSoon, showToast } from '../lib/toast';

const props = defineProps({ id: { type: String, required: true } });
const route = useRoute();
const router = useRouter();

const user = ref(null);
const requesting = ref(false);
const liking = ref(new Set()); // 연타 방지

// 공개 사진 하트 합계는 내가 누를 때마다 바로 반영
const totalHearts = computed(() => user.value?.photos.reduce((sum, p) => sum + p.like_count, 0) ?? 0);
const isFriend = computed(() => user.value?.relation === 'friends');

async function load() {
    try {
        user.value = (await get(`/users/${props.id}`)).data;
    } catch (e) {
        showToast(e instanceof ApiError ? e.message : '프로필을 불러오지 못했어요');
        router.back();
    }
}

async function toggleLike(photo) {
    if (liking.value.has(photo.id)) return;
    liking.value.add(photo.id);
    // 낙관적 업데이트: 화면 먼저 바꾸고 서버 응답으로 확정
    const before = { liked: photo.liked, like_count: photo.like_count };
    photo.liked = !photo.liked;
    photo.like_count += photo.liked ? 1 : -1;
    try {
        Object.assign(photo, await post(`/photos/${photo.id}/like`));
    } catch (e) {
        Object.assign(photo, before);
        showToast(e instanceof ApiError ? e.message : '잠시 후 다시 시도해 주세요');
    } finally {
        liking.value.delete(photo.id);
    }
}

function onSent() {
    requesting.value = false;
    user.value.relation = 'sent';
    showToast('친구 요청을 보냈어요');
}

function openReport() {
    const u = user.value;
    router.push({ name: 'report', params: { userId: u.id }, query: { nickname: u.nickname, color: u.avatar?.color, face: u.avatar?.face, from: route.query.from } });
}

onMounted(load);
</script>

<template>
    <div class="profile">
        <div class="head">
            <button class="icon" aria-label="뒤로가기" @click="router.back()">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="#1F1B3A" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6" /></svg>
            </button>
            <button v-if="user && user.relation !== 'self'" class="icon" aria-label="더보기 (신고·차단)" @click="openReport">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="#1F1B3A" aria-hidden="true"><circle cx="5" cy="12" r="2" /><circle cx="12" cy="12" r="2" /><circle cx="19" cy="12" r="2" /></svg>
            </button>
        </div>

        <div v-if="user" class="body scroll">
            <!-- 기본 정보 -->
            <div class="basic">
                <span class="face"><AvatarFace :parts="user.avatar" :size="84" /></span>
                <h1 class="title" style="font-size: 24px">{{ user.nickname }}</h1>
                <span class="hearts">
                    <HeartIcon :size="16" />{{ totalHearts }}
                    <span>· 사진 하트 합계</span>
                </span>
                <p v-if="user.bio" class="bio">{{ user.bio }}</p>
                <div v-if="user.interests.length" class="tags">
                    <span v-for="t in user.interests" :key="t">{{ t }}</span>
                </div>
            </div>

            <!-- 공개 사진 -->
            <section class="sec">
                <div class="sec-head">
                    <h2>공개 사진</h2>
                    <span>하트를 눌러 마음을 표시해요</span>
                </div>
                <div class="grid2">
                    <div v-for="(p, i) in user.photos" :key="p.id" class="photo big">
                        <img :src="p.url" :alt="`공개 사진 ${i + 1}`" loading="lazy">
                        <button
                            v-if="user.relation !== 'self'" class="like"
                            :aria-pressed="p.liked" :aria-label="`${p.liked ? '하트 취소' : '하트 누르기'}, 현재 ${p.like_count}개`"
                            @click="toggleLike(p)"
                        >
                            <HeartIcon :size="18" :filled="p.liked" />{{ p.like_count }}
                        </button>
                    </div>
                </div>
                <p v-if="!user.photos.length" class="empty">아직 공개 사진이 없어요</p>
            </section>

            <!-- 친구에게만 공개 -->
            <section class="sec">
                <div class="sec-head">
                    <h2>친구에게만 공개</h2>
                    <span v-if="!isFriend" class="lock-note"><LockIcon color="#6B6785" />수락하면 공개</span>
                </div>
                <div class="facts">
                    <div><span>성별</span><b v-if="isFriend">{{ user.gender === 'F' ? '여' : '남' }}</b><b v-else class="locked"><LockIcon :size="13" />비공개</b></div>
                    <div><span>나이</span><b v-if="isFriend">{{ user.age }}세</b><b v-else class="locked"><LockIcon :size="13" />비공개</b></div>
                </div>

                <template v-if="isFriend">
                    <div v-if="user.private_photos.length" class="grid3">
                        <div v-for="p in user.private_photos" :key="p.id" class="photo mid">
                            <img :src="p.url" alt="친구 공개 사진" loading="lazy">
                            <button class="like sm" :aria-pressed="p.liked" @click="toggleLike(p)"><HeartIcon :size="14" :filled="p.liked" />{{ p.like_count }}</button>
                        </div>
                    </div>
                </template>
                <template v-else-if="user.private_count">
                    <span class="count">비공개 사진 {{ user.private_count }}장</span>
                    <div class="grid3 locked-grid">
                        <div v-for="i in Math.min(3, user.private_count)" :key="i" class="ph" :style="{ background: ['#DCD5FF', '#E6E1FF', '#D3CBFA'][i - 1] }" aria-hidden="true" />
                        <div class="lock-over">
                            <span class="lock-circle"><LockIcon :size="20" /></span>
                            <b>{{ user.relation === 'sent' ? '수락되면 바로 보여요' : '친구 요청이 수락되면 볼 수 있어요' }}</b>
                        </div>
                    </div>
                </template>
            </section>
        </div>

        <!-- 하단 고정: 관계에 따라 -->
        <div v-if="user && user.relation !== 'self'" class="bottom">
            <button v-if="user.relation === 'none'" class="btn-primary" @click="requesting = true">친구 요청</button>
            <button v-else-if="user.relation === 'sent'" class="waiting" disabled>요청 보냄 · 수락 기다리는 중</button>
            <button v-else-if="user.relation === 'received'" class="btn-primary" @click="router.push({ name: 'friend-requests' })">받은 요청 확인하기</button>
            <button v-else class="btn-primary" @click="comingSoon('1:1 대화')">대화하기</button>
        </div>

        <FriendRequestSheet v-if="requesting" :user="user" :plaza-id="route.query.from ?? null" @close="requesting = false" @sent="onSent" />
    </div>
</template>

<style scoped>
.profile { height: 100%; display: flex; flex-direction: column; position: relative; }
.head { display: flex; align-items: center; justify-content: space-between; padding: 14px 12px 4px 8px; }
.icon { width: 44px; height: 44px; border: none; background: none; display: flex; align-items: center; justify-content: center; }
.body { flex: 1; min-height: 0; padding: 0 20px 120px; display: flex; flex-direction: column; gap: 22px; }

.basic { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
.face { width: 96px; height: 96px; border-radius: 48px; background: var(--c-surface); display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 14px rgba(108,92,231,.14); }
.hearts { display: inline-flex; align-items: center; gap: 5px; background: var(--c-surface); border-radius: 14px; padding: 5px 12px; font-size: 14px; font-weight: 700; color: #D63E5C; }
.hearts span { font-weight: 500; color: var(--c-muted); }
.bio { margin: 2px 0 0; font-size: 14px; color: var(--c-ink-2); }
.tags { display: flex; gap: 6px; flex-wrap: wrap; justify-content: center; }
.tags span { background: var(--c-primary-soft); color: var(--c-primary-ink); font-size: 12px; font-weight: 700; padding: 3px 10px; border-radius: 10px; }

.sec { display: flex; flex-direction: column; gap: 10px; }
.sec-head { display: flex; justify-content: space-between; align-items: baseline; }
.sec-head h2 { margin: 0; font-size: 16px; font-weight: 700; }
.sec-head span { font-size: 12px; color: var(--c-muted); }
.lock-note { display: flex; align-items: center; gap: 4px; }
.grid2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
.grid3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 8px; position: relative; }
.photo { position: relative; border-radius: 20px; overflow: hidden; background: #E6E1FF; }
.photo.big { height: 170px; }
.photo.mid { height: 108px; border-radius: 16px; }
.photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.like { position: absolute; right: 8px; bottom: 8px; height: 36px; padding: 0 10px; border: none; border-radius: 18px; background: rgba(255,255,255,.92); display: flex; align-items: center; gap: 4px; font-size: 13px; font-weight: 700; color: var(--c-ink); }
.like.sm { height: 28px; right: 6px; bottom: 6px; font-size: 11px; padding: 0 8px; }
.empty { margin: 0; font-size: 13px; color: var(--c-muted); text-align: center; padding: 20px; background: var(--c-surface); border-radius: 16px; }

.facts { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
.facts div { background: var(--c-surface); border-radius: 14px; padding: 10px 12px; display: flex; align-items: center; justify-content: space-between; }
.facts span { font-size: 13px; color: var(--c-muted); }
.facts b { font-size: 13px; color: var(--c-ink); }
.facts b.locked { display: flex; align-items: center; gap: 4px; color: var(--c-primary-deep); }
.count { font-size: 12px; color: var(--c-muted); }
.ph { height: 108px; border-radius: 16px; }
.lock-over { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 6px; background: rgba(247,245,255,.55); border-radius: 16px; }
.lock-circle { width: 40px; height: 40px; border-radius: 20px; background: var(--c-surface); display: flex; align-items: center; justify-content: center; }
.lock-over b { font-size: 13px; color: var(--c-primary-deep); }

.bottom { position: absolute; left: 0; right: 0; bottom: 0; padding: 12px 20px 26px; background: var(--c-surface); border-top: 1px solid var(--c-divider); display: flex; }
.bottom .btn-primary { height: 54px; }
.waiting { flex: 1; height: 54px; border: none; border-radius: 18px; background: #ECE8FF; color: var(--c-primary-deep); font-size: 15px; font-weight: 700; }
</style>
