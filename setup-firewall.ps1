<#
.SYNOPSIS
    TeamCraft-MP용 Windows 방화벽 인바운드 규칙을 추가합니다.

.DESCRIPTION
    NetworkWorker가 선점하는 공개 UDP 포트(기본 19132, IPv6는 19133)에 대해
    인바운드 허용 규칙을 등록합니다. 다른 PC나 같은 네트워크의 다른 기기에서
    접속이 안 될 때, 이 스크립트를 "관리자 권한으로 실행"하면 됩니다.

    php.exe 자체를 방화벽에서 완전히 허용하는 방식 대신, 포트 기준으로만
    열어서 필요한 범위로 제한합니다.

.NOTES
    반드시 관리자 권한(Run as Administrator)으로 실행해야 합니다.
    이미 규칙이 있으면 건너뜁니다 (중복 생성 방지).
#>

param(
    [int]$PublicPort = 19132,
    [int]$PublicPortV6 = 19133
)

[Console]::OutputEncoding = [System.Text.Encoding]::UTF8

function Test-Admin {
    $identity = [System.Security.Principal.WindowsIdentity]::GetCurrent()
    $principal = New-Object System.Security.Principal.WindowsPrincipal($identity)
    return $principal.IsInRole([System.Security.Principal.WindowsBuiltInRole]::Administrator)
}

if (-not (Test-Admin)) {
    Write-Host "이 스크립트는 관리자 권한이 필요합니다." -ForegroundColor Yellow
    Write-Host "PowerShell을 '관리자 권한으로 실행'한 뒤 다시 시도해주세요." -ForegroundColor Yellow
    exit 1
}

$ruleName = "TeamCraft-MP (UDP $PublicPort/$PublicPortV6)"

$existing = Get-NetFirewallRule -DisplayName $ruleName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "이미 방화벽 규칙이 존재합니다: $ruleName" -ForegroundColor Cyan
    Write-Host "변경하려면 먼저 기존 규칙을 삭제해주세요: Remove-NetFirewallRule -DisplayName '$ruleName'"
    exit 0
}

New-NetFirewallRule `
    -DisplayName $ruleName `
    -Direction Inbound `
    -Protocol UDP `
    -LocalPort @($PublicPort, $PublicPortV6) `
    -Action Allow `
    -Profile Any | Out-Null

Write-Host "방화벽 인바운드 규칙을 추가했습니다: $ruleName (UDP $PublicPort, $PublicPortV6)" -ForegroundColor Green
Write-Host "이제 같은 네트워크의 다른 기기에서 이 PC의 IP로 접속을 시도해보세요."