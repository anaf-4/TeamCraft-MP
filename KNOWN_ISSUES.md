# Known Issues

## Windows 10 CMD에서 한국어 명령어(say 등)가 안 먹힘

**증상**: Windows 10의 기본 CMD/PowerShell 콘솔에서 `say 안녕하세요` 같은 한국어 포함 명령어를 입력하면 정상 작동하지 않음.

**원인**: Windows 10의 기본 콘솔 코드페이지가 UTF-8이 아님 (한국어 환경 기본값은 CP949). `chcp 65001`로 UTF-8 전환을 시도해봤지만 완전히 해결되지 않음 — PHP가 Windows 콘솔 입력을 읽는 방식 자체에 더 깊은 인코딩 이슈가 있는 것으로 보임.

**영향받지 않는 환경**: Windows 11 (콘솔 UTF-8 처리 개선됨), WSL2/Linux

**임시 해결책**:
- 가능하면 Windows 11 사용
- 또는 Windows 10에서 [Windows Terminal](https://apps.microsoft.com/detail/9n0dx20hk701) 앱 사용 (기본 CMD 대신, UTF-8 처리가 더 안정적)
- 영어 명령어/닉네임은 문제없이 작동함

**상태**: 조사 중, 근본 해결책 찾는 대로 갱신 예정
