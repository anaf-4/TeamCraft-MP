# Contributing

TeamCraft-MP는 개인이 유지보수하는 [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 비공식 포크입니다. 원본 프로젝트와 달리 별도의 리뷰 팀이나 다중 브랜치 정책(`minor-next`/`major-next` 등)은 운영하지 않고, `stable` 브랜치 하나로 관리합니다.

기여는 [GitHub Pull Requests](https://github.com/anaf-4/TeamCraft-MP/pulls)를 통해 받습니다.

## 기본 절차

1. [저장소를 GitHub에서 포크](https://github.com/anaf-4/TeamCraft-MP/fork)합니다.
2. 포크에 변경사항을 위한 새 브랜치를 만듭니다.
3. 원하는 변경을 만듭니다.
4. [Pull Request](https://github.com/anaf-4/TeamCraft-MP/pull/new)를 보냅니다.

큰 변경(신규 기능 추가 등)을 계획 중이라면, PR을 보내기 전에 [Issue](https://github.com/anaf-4/TeamCraft-MP/issues)로 먼저 의견을 나누는 걸 권장합니다 — 혼자 운영하는 프로젝트라 방향이 안 맞으면 큰 PR이 그대로 반려될 수 있어서, 미리 상의하는 게 서로 시간을 아낄 수 있어요.

## 의존성 저장소 (원본 PMMP 관리)

TeamCraft-MP는 원본 PocketMine-MP의 여러 하위 의존성을 그대로 사용합니다. 다음 저장소에서 온 클래스/네임스페이스를 찾고 있다면 참고하세요.

| 출처 | 네임스페이스/클래스 |
|:--|:--|
| [pmmp/BedrockProtocol](https://github.com/pmmp/BedrockProtocol) | `pocketmine\network\mcpe\protocol` |
| [pmmp/BinaryUtils](https://github.com/pmmp/BinaryUtils) | `pocketmine\utils\BinaryStream` 등 |
| [pmmp/RakLib](https://github.com/pmmp/RakLib) | `raklib` |
| [pmmp/NBT](https://github.com/pmmp/NBT) | `pocketmine\nbt` |
| [pmmp/Math](https://github.com/pmmp/Math) | `pocketmine\math` |

(그 외 의존성은 `composer.json`을 참고해주세요.)

## 코드 품질 도구

원본과 동일한 도구를 사용합니다. 커밋 전에 로컬에서 미리 돌려보면 좋아요.

| 도구 | 용도 | 실행 |
|:--|:--|:--|
| [PHPStan](https://phpstan.org) | 타입 오류, 정의되지 않은 함수 등 탐지 | `vendor/bin/phpstan` |
| [PHPUnit](https://phpunit.de) | 동작 검증 테스트 | `vendor/bin/phpunit tests/phpunit` |
| [PHP-CS-Fixer](https://github.com/PHP-CS-Fixer/PHP-CS-Fixer) | 코드 스타일 자동 정리 | `php php-cs-fixer.phar fix` |

## PR 요구사항 (최소 기준)

- 모든 코드는 [LGPLv3 라이선스](LICENSE) 또는 호환 라이선스여야 합니다.
- 관련 없는 변경을 한 PR에 섞지 말아주세요.
- 어떤 테스트/플레이테스트를 했는지 설명해주세요 (가능하면 PHPUnit 테스트, 인게임 기능이면 스크린샷/영상).
- 가능하면 `final`, `private`, `readonly`를 적극적으로 사용해주세요 — 나중에 하위 호환성 깨지 않고 바꿀 여지가 넓어집니다.

## 참고

이 프로젝트는 혼자 관리하고 있어서, PR 리뷰가 원본 PMMP 팀보다 느릴 수 있어요. 양해 부탁드립니다 🙏

**TeamCraft-MP에 기여해주셔서 감사합니다!**