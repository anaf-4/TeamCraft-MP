<p align="center">
	<b>TeamCraft-MP</b><br>
	<b>A highly customisable, open source server software for Minecraft: Bedrock Edition written in PHP</b><br>
	<sub>An unofficial community fork of <a href="https://github.com/pmmp/PocketMine-MP">PocketMine-MP</a></sub>
</p>

<p align="center">
	<a href="https://github.com/anaf-4/TeamCraft-MP/releases/latest"><img alt="GitHub release (latest SemVer)" src="https://img.shields.io/github/v/release/anaf-4/TeamCraft-MP?label=release&sort=semver"></a>
	<!-- 디스코드 링크가 준비되면 아래 배지의 href/src를 서버 초대 링크로 교체하세요 -->
	<a href="#"><img src="https://img.shields.io/badge/discord-TBD-7289DA?logo=discord" alt="Discord (준비 중)" /></a>
</p>

## 이 프로젝트는 무엇인가요?

TeamCraft-MP는 [PocketMine-MP](https://github.com/pmmp/PocketMine-MP)의 **비공식 커뮤니티 포크**입니다.

PMMP 팀의 메인 개발자 dktapps가 은퇴를 선언하면서 공식 팀이 더 이상 새 마인크래프트 버전 업데이트를 제공하지 않게 되었고, 이 포크는 그 공백을 메우기 위해 시작되었습니다. PMMP 팀이 남겨준 [업데이트 프로세스 문서](https://doc.pmmp.io/en/rtfd/developers/internals-docs/updating-minecraft-protocol.html)를 참고해 신규 버전 대응을 이어가는 것을 목표로 합니다.

- 🧩 **강력한 플러그인 API** - 게임플레이를 자유롭게 확장/커스터마이징
- 🗺️ **멀티월드 지원** - 서버 노드 이동 없이 다양한 게임 경험 제공
- 🏎️ **성능** - 하드웨어와 플러그인에 따라 한 서버에 100명 이상 수용 가능
- 🔧 **단일 실행 진입점** - 삼각 멀티프로세스 런처로 CMD/터미널 창 하나에서 구동 (TeamCraft-MP 자체 추가 기능)

## ⚠️ TeamCraft-MP는 바닐라 마인크래프트 서버 소프트웨어가 아닙니다

원본 PocketMine-MP와 마찬가지로, 바닐라 서바이벌 서버 운영에는 적합하지 않습니다. 바닐라 월드 생성, 레드스톤, 몹 AI 등 바닐라 게임의 여러 기능이 구현되어 있지 않습니다.

순수 바닐라 서바이벌 멀티플레이를 원하신다면 [공식 Minecraft: Bedrock 서버 소프트웨어](https://minecraft.net/download/server/bedrock)를 사용하시는 걸 권장합니다.

## 시작하기

- 원본 PMMP 문서: [Documentation](https://pmmp.readthedocs.org/), [Installation instructions](https://pmmp.readthedocs.io/en/rtfd/installation.html) (대부분의 내용이 이 포크에도 그대로 적용됩니다)
- [소스 코드](https://github.com/anaf-4/TeamCraft-MP)
- [이슈/버그 보고](https://github.com/anaf-4/TeamCraft-MP/issues)

## 커뮤니티 & 지원

Discord 서버 링크는 준비 중입니다 (추후 업데이트 예정).

원본 PMMP 관련 질문은 [PMMP 공식 Discord](https://discord.gg/bmSAZBG)나 [StackOverflow](https://stackoverflow.com/tags/pocketmine) (`pocketmine` 태그)에서도 도움을 받으실 수 있습니다.

## 플러그인 개발

원본 PMMP 개발 문서가 대부분 그대로 적용됩니다:

- [Developer documentation](https://devdoc.pmmp.io)
- [DevTools](https://github.com/pmmp/DevTools/) - 플러그인 개발용 도구
- [ExamplePlugin](https://github.com/pmmp/ExamplePlugin/) - 기본 API 기능을 보여주는 예제 플러그인

## 기여하기

- [Building and running TeamCraft-MP from source](BUILDING.md)
- [Contributing Guidelines](CONTRIBUTING.md)

## 감사의 말

이 프로젝트는 지난 10년 넘게 PocketMine-MP를 만들고 지켜온 dktapps를 비롯한 모든 PMMP 팀원, 커뮤니티 모더레이터, 코드 리뷰어, 그리고 기여자분들의 노력 위에 서 있습니다. 원본 프로젝트의 은퇴 공지 전문은 [changelogs 또는 과거 커밋 히스토리](https://github.com/anaf-4/TeamCraft-MP/commits/stable)에서 확인하실 수 있습니다.

## 라이선스 정보

이 프로젝트는 LGPL-3.0 라이선스 하에 배포됩니다. 자세한 내용은 [LICENSE](/LICENSE) 파일을 참고해주세요.

TeamCraft-MP는 PocketMine-MP 팀과 무관한 비공식 포크이며, Mojang과도 관련이 없습니다. 모든 브랜드와 상표는 각 소유자에게 귀속됩니다.
TeamCraft-MP는 PocketMine-MP 팀과 무관한 비공식 포크이며, Mojang과도 관련이 없습니다. 모든 브랜드와 상표는 각 소유자에게 귀속됩니다.
