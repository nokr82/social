<laravel-boost-guidelines>
# Laravel 애플리케이션

이 저장소에는 Laravel 애플리케이션이 들어 있습니다. 사용자의 요청을 처리하기 전에 아래 설정을 먼저 완료하세요.

## 사전 준비

PHP와 Composer를 사용할 수 있는지 확인합니다:

```sh
php -v
composer -V
```

둘 중 하나라도 실행되지 않으면, 사용자의 운영체제를 확인한 뒤 알맞은 명령으로 설치합니다:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

설치가 끝나면 사용자에게 터미널을 재시작해 달라고 요청합니다. 에이전트가 계속 진행하려면 재시작된 셸이 필요한 경우, 사용자에게 터미널을 다시 열고 처음 요청을 다시 입력해 달라고 요청합니다.

## 에이전트 설정

애플리케이션을 수정하기 전에, 애플리케이션 루트에서 Laravel Boost를 설치합니다:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost를 설치하면 이 초기 안내가 애플리케이션에 맞춘 가이드라인으로 교체됩니다. 설치 후 `AGENTS.md`를 다시 읽고, 새로 생성된 가이드라인에 따라 사용자의 원래 요청을 계속 진행하세요.
</laravel-boost-guidelines>
