/**
 * 업로드 전 사진 줄이기 (브라우저에서).
 * - 폰 원본(5~15MB)을 긴 변 1600px JPEG로 → 업로드 시간 단축, 서버 10MB 제한 회피
 * - 캔버스로 다시 그리므로 EXIF(위치 정보)도 여기서 1차로 떨어짐 (서버에서 한 번 더 제거)
 * - createImageBitmap은 EXIF 회전을 반영해 그려줌 (세로 사진이 눕지 않음)
 */
const MAX_SIDE = 1600;

export async function shrinkImage(file) {
    if (!file.type.startsWith('image/')) throw new Error('이미지 파일만 올릴 수 있어요');

    let bitmap;
    try {
        bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        return file; // 브라우저가 못 읽는 형식이면 원본 그대로 (서버가 판단)
    }

    const scale = Math.min(1, MAX_SIDE / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close?.();

    const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.9));
    return new File([blob], 'photo.jpg', { type: 'image/jpeg' });
}

/** 사진 1장 업로드 → 서버가 돌려준 사진 정보 */
export async function uploadPhoto(file, uploadFn) {
    const form = new FormData();
    form.append('photo', await shrinkImage(file));
    return (await uploadFn('/me/photos', form)).data;
}
