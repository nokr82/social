# 소셜링앱 API

소셜링앱의 백엔드 API 서버입니다. Laravel 프레임워크로 만들었습니다.

## 구성

- **인증**: Laravel Sanctum 토큰 인증
- **실시간 전달**: Laravel Reverb (채팅, 광장 이동)
- **큐**: Redis 기반 큐 워커
- **데이터베이스**: MariaDB

## 실행

이 폴더는 상위 폴더의 Docker Compose 환경에서 실행합니다. 설치 방법은 [../SETUP.md](../SETUP.md)를 참고하세요.

```bash
# 상위 폴더에서 실행
docker compose exec php php artisan migrate     # 마이그레이션
docker compose exec php php artisan test        # 테스트
```

## 주요 폴더

| 폴더 | 내용 |
|---|---|
| `app/` | 모델, 컨트롤러 등 애플리케이션 코드 |
| `routes/` | API·브로드캐스트 라우트 |
| `database/migrations/` | 테이블 마이그레이션 |
| `config/` | 설정 파일 |
| `tests/` | 테스트 코드 |
