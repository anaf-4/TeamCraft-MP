# TeamCraft-MP 번역 문자열

이 폴더에는 TeamCraft-MP(원본 PocketMine-MP에서 물려받음)에서 사용하는 번역 문자열이 들어있습니다.

## 번역 기여 (비영어권)

> [!NOTE]
> TeamCraft-MP는 아직 자체 Crowdin 프로젝트를 운영하지 않습니다. 원본 PMMP는 [Crowdin](http://translate.pocketmine.net/)을 통해 번역을 관리하지만, 이 포크는 현재 `eng.ini`(영어 원본)만 직접 관리하고 있습니다.
>
> 다른 언어 번역을 기여하고 싶으시면, GitHub Issue나 Pull Request로 직접 제안해주세요. 추후 번역 기여자가 많아지면 별도 프로젝트를 구성할 예정입니다.

## 관리자용 안내

### 새 문자열 추가하기

> [!CAUTION]
> `eng.ini`만 직접 수정하세요. 다른 언어 파일은 수동으로 고치지 마세요 (자체 번역 관리 체계가 아직 없어서, 나중에 정식 번역 프로세스가 생기면 그때 동기화됩니다).

새 문자열을 추가할 때:
- 바닐라 관련 문자열은 [Mojang의 en_US.lang](https://raw.githubusercontent.com/Mojang/bedrock-samples/refs/heads/main/resource_pack/texts/en_US.lang)과 같은 키를 사용하되, 파라미터 표기는 `%1$s` 대신 `{%paramName}` 형식으로 바꿔주세요.
- TeamCraft-MP 전용 문자열은 원하는 키를 써도 되지만, `pocketmine.`으로 시작해야 합니다 (원본 코드베이스와의 호환성 유지 목적).

`eng.ini` 수정 후 `composer update-codegen`을 실행하면 `KnownTranslationFactory` 등이 재생성됩니다.