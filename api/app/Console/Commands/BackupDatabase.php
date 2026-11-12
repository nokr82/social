<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

/**
 * DB 백업 (매일 새벽, 스케줄러).
 * mariadb-dump → gzip → 사진과 같은 저장소(운영: Lightsail 버킷)의 backups/db/ 에 올리고 14일 지난 것은 삭제.
 * 서버 디스크가 통째로 망가져도 버킷에 남은 백업으로 복구할 수 있게 서버 밖에 둔다.
 *
 * 복구: gunzip -c 파일.sql.gz | docker compose -f docker-compose.prod.yml exec -T mariadb mariadb -u root -p 데이터베이스
 */
class BackupDatabase extends Command
{
    public const KEEP_DAYS = 14;
    public const DIR = 'backups/db';

    protected $signature = 'social:backup-db';

    protected $description = 'DB를 덤프해 저장소(버킷)에 올리고 오래된 백업을 지웁니다';

    public function handle(): int
    {
        $db = config('database.connections.'.config('database.default'));
        $tmp = storage_path('app/backup-'.now()->format('YmdHis').'.sql.gz');

        // --single-transaction: 서비스를 멈추지 않고 일관된 시점으로 덤프 (InnoDB)
        // 비밀번호는 명령줄 대신 환경변수(MYSQL_PWD)로 → 프로세스 목록에 노출되지 않음
        $result = Process::timeout(600)
            ->env(['MYSQL_PWD' => (string) $db['password']])
            ->run(sprintf(
                'mariadb-dump --single-transaction --quick --routines --skip-lock-tables -h %s -P %s -u %s %s | gzip -6 > %s',
                escapeshellarg($db['host']), escapeshellarg((string) $db['port']), escapeshellarg($db['username']),
                escapeshellarg($db['database']), escapeshellarg($tmp),
            ));

        if (! $result->successful() || ! is_file($tmp) || filesize($tmp) < 100) {
            @unlink($tmp);
            $this->error('백업 실패: '.trim($result->errorOutput()));

            return self::FAILURE;
        }

        $disk = Storage::disk(config('social.photos.disk'));
        $name = self::DIR.'/'.now('Asia/Seoul')->format('Y-m-d_His').'.sql.gz';
        $stream = fopen($tmp, 'r');
        $disk->writeStream($name, $stream);
        is_resource($stream) && fclose($stream);
        $size = round(filesize($tmp) / 1024 / 1024, 1);
        unlink($tmp);

        // 보관 기간 지난 백업 삭제 (파일명 = 날짜)
        $cut = now('Asia/Seoul')->subDays(self::KEEP_DAYS)->format('Y-m-d');
        $old = collect($disk->files(self::DIR))->filter(fn ($f) => substr(basename($f), 0, 10) < $cut)->values();
        $disk->delete($old->all());

        $this->info("백업 완료: {$name} ({$size}MB), 오래된 백업 {$old->count()}개 삭제");

        return self::SUCCESS;
    }
}
