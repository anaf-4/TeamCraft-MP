# TeamCraft-MP Docker image

이 폴더는 원본 PocketMine-MP의 `pmmp/pocketmine-mp` Docker 이미지 빌드/테스트 파일을 그대로 물려받은 것입니다.

> [!WARNING]
> **TeamCraft-MP는 아직 자체 Docker Hub/GHCR 이미지를 배포하지 않습니다.**
> 아래 안내는 원본 PMMP 이미지(`ghcr.io/pmmp/pocketmine-mp`) 사용법을 참고용으로 남겨둔 것이며,
> TeamCraft-MP 자체 이미지가 준비되면 이 문서를 갱신할 예정입니다.
> 지금 TeamCraft-MP를 Docker로 돌리고 싶다면, 이 폴더의 `Dockerfile`을 직접 빌드해서 사용해주세요:
>
> ```bash
> docker build -t teamcraft-mp:local --build-arg GIT_HASH=$(git rev-parse HEAD) .
> ```

Docker는 컨테이너 안에서 안전하게 소프트웨어를 실행하는 방법입니다. 의존성을 직접 빌드할 필요 없이, 이미지 버전만 바꾸면 업데이트도 간단합니다.

## 사전 준비물
[공식 Docker 문서](https://docs.docker.com/engine/install/)를 참고해 Docker를 설치해주세요.

## 로컬에서 빌드한 이미지 실행하기

```bash
mkdir wherever-you-want
cd wherever-you-want
mkdir data plugins
sudo chown -R 1000:1000 data plugins
docker run -it -p 19132:19132/udp -v $PWD/data:/data -v $PWD/plugins:/plugins teamcraft-mp:local
```

## 서버 포트 변경
`server.properties`를 수정하는 대신, Docker의 포트 매핑을 사용하세요.

위 실행 명령어에서 `19132:19132/udp`를 `<원하는 포트>:19132/udp`로 바꾸면 됩니다. **뒤쪽 숫자는 바꾸지 마세요.**

## 서버 데이터 수정
서버 데이터(월드, `server.properties` 등)는 위에서 만든 `data` 폴더에 저장됩니다.

새 파일/폴더를 추가했다면 소유권을 맞춰주세요:
```bash
sudo chown -R 1000:1000 <추가한 파일/폴더>
```

## 플러그인 추가
`plugins` 폴더에 넣어주시면 됩니다 (마찬가지로 소유권 조정 필요).

## 백그라운드 실행
실행 명령어의 `-it`를 `-itd`로 바꾸면 콘솔을 닫아도 백그라운드에서 계속 실행됩니다.

콘솔 다시 열기: `docker attach <컨테이너 이름>` (나가기: `Ctrl p` → `Ctrl q`)
로그 보기: `docker logs --tail=100 <컨테이너 이름>`

## Volumes
- `/data` - 읽기/쓰기, 설정/플레이어 데이터/월드/플러그인 설정 저장
- `/plugins` - 읽기 전용, 플러그인 로드 위치

## 고급: phar에 인자 전달하기
`POCKETMINE_ARGS` 환경변수가 `TeamCraft-MP.phar` 실행 시 그대로 전달됩니다.

## 참고: 삼각 멀티프로세스 런처와 Docker

TeamCraft-MP의 `PocketMine-MP.php` 런처(NetworkWorker + phar 동시 구동)는 아직 이 Docker 이미지에 통합되지 않았습니다. 현재 이미지는 원본과 동일하게 `TeamCraft-MP.phar`를 직접 실행하는 방식입니다.

## 이미지 빌드하기
Dockerfile은 `TeamCraft-MP.phar` 빌드 시 git 해시 메타데이터를 채우기 위해 `GIT_HASH` build-arg가 필요합니다. 이는 `/version`, 크래시 리포트, 로그 등에 정확한 서버 버전이 표시되도록 하기 위함입니다.