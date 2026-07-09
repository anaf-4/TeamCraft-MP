# Building

## 사전 준비물
- bash 셸 (Windows는 Git Bash로 충분)
- [`git`](https://git-scm.com)
- PHP 8.2 이상
- [`composer`](https://getcomposer.org)

## 커스텀 PHP 바이너리

TeamCraft-MP(원본 PocketMine-MP와 동일)는 여러 비표준 PHP 확장과 설정이 필요해서, 일반 시스템 PHP로는 제대로 안 돌아갈 수 있습니다. PMMP 팀이 제공하던 전용 빌드를 그대로 사용하는 걸 권장합니다:

- [사전 빌드된 바이너리](https://github.com/pmmp/PHP-Binaries/releases)

커스텀 바이너리를 쓸 경우, 아래 안내에서 `composer` 대신 `path/to/your/php path/to/your/composer.phar` 형태로 실행하면 됩니다.

## 개발 환경 세팅

```bash
git clone https://github.com/anaf-4/TeamCraft-MP.git
cd TeamCraft-MP
composer install
```

## 릴리즈 빌드 최적화

`composer install`에 `--no-dev --classmap-authoritative` 플래그를 추가하면 빌드 크기가 줄고 오토로딩 속도가 빨라집니다.

## `PocketMine-MP.phar` 빌드하기

```bash
php -d phar.readonly=0 build/server-phar.php
```

현재 작업 폴더에 `PocketMine-MP.phar`가 생성됩니다.

## 소스 코드로 바로 실행하기

```bash
php -d phar.readonly=0 src/PocketMine.php
```

## 삼각 멀티프로세스 런처로 실행하기 (TeamCraft-MP 자체 기능)

phar 빌드가 끝난 뒤, 저장소 루트의 `PocketMine-MP.php`를 실행하면 단일 터미널/CMD 창에서 NetworkWorker + phar가 함께 구동됩니다:

```bash
php PocketMine-MP.php
```

Windows에서 다른 기기의 접속이 막힌다면, 관리자 권한으로 `setup-firewall.ps1`을 한 번 실행해 방화벽 인바운드 규칙을 열어주세요.