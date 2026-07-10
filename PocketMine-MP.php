<?php

/**
 * TeamCraft-MP Triangle Multi-Process Launcher
 *
 * 마스터 프로세스: 단일 CMD 창으로 NetworkWorker + PocketMine-MP.phar를 자식 프로세스로 구동합니다.
 *
 * 중요한 설계 결정 (아키텍처 노트):
 * -----------------------------------------------------------------------
 * PocketMine-MP.phar는 STDIN으로 "게임 네트워크 패킷"을 받지 않습니다.
 * phar 내부의 RakLib 스레드가 자체적으로 UDP 소켓을 여는 구조이기 때문에,
 * 외부에서 패킷을 주입할 훅이 존재하지 않습니다. STDIN/STDOUT은 콘솔
 * 명령어(관리자 커맨드) 용도로만 사용됩니다.
 *
 * 따라서 이 런처는 다음 전략을 씁니다:
 *   - NetworkWorker.php가 공개 포트(server.properties의 원래 포트)를 선점하고
 *     클라이언트와 직접 통신합니다.
 *   - phar는 pmmp가 지원하는 CLI 오버라이드(--server-port=, --server-portv6=)를
 *     통해 127.0.0.1 내부 전용 포트에서만 리슨하도록 설정됩니다. 이 오버라이드는
 *     src/ServerConfigGroup.php의 getopt() 기반 설정 오버라이드 기능을 그대로
 *     사용하는 것이라 phar 소스는 완전히 원본 그대로입니다.
 *   - NetworkWorker는 패킷을 해석하지 않고 순수 UDP 릴레이(NAT처럼)만
 *     수행하여 공개 포트 <-> phar 내부 포트 사이를 연결합니다.
 *   - phar는 STDIN/STDOUT/STDERR을 마스터의 콘솔에 그대로 상속(inherit)받습니다.
 *     즉 phar를 직접 실행했을 때와 똑같은 경험(상태표시줄 갱신, 콘솔 명령어
 *     입력 등)을 그대로 재현합니다. (참고: STDIN만 상속하고 STDOUT을 파일로
 *     리다이렉트하는 어중간한 상태에서는, pmmp가 "콘솔에 완전히 붙어있지 않다"고
 *     판단해 별도의 "Console Reader" 창을 새로 띄우는 부작용이 있었습니다.
 *     세 스트림을 전부 상속시키면 이 문제가 사라지고, 상태표시줄도 정상 갱신됩니다.
 *     pmmp 자체가 이미 server.log에 로그를 저장하므로, 마스터가 별도로 phar의
 *     출력을 파일로 받아 로테이션할 필요도 없습니다 - 그래서 phar에 대해서는
 *     이제 로그 파일 기반 tail 방식을 쓰지 않습니다. NetworkWorker는 사용자
 *     상호작용이 필요 없는 백그라운드 프로세스라 기존 파일 tail 방식을 그대로
 *     유지합니다.)
 * -----------------------------------------------------------------------
 */

declare(strict_types=1);

const TICK_INTERVAL_US = 50_000; // 50ms = 20 ticks/sec

const INTERNAL_HOST = "127.0.0.1";
const INTERNAL_HOST_V6 = "::1";
// pmmp 기본 IPv6 포트(19133)와 절대 겹치지 않도록 완전히 다른 대역을 사용
const INTERNAL_PORT_V4 = 30132;
const INTERNAL_PORT_V6 = 30133;

$logDir = __DIR__ . DIRECTORY_SEPARATOR . "master-logs";
$logArchiveDir = $logDir . DIRECTORY_SEPARATOR . "archive";
if (!is_dir($logDir)) {
	mkdir($logDir, 0777, true);
}
if (!is_dir($logArchiveDir)) {
	mkdir($logArchiveDir, 0777, true);
}
$workerLogPath = $logDir . DIRECTORY_SEPARATOR . "worker.log";

const LOG_MAX_BYTES = 10 * 1024 * 1024; // 10MB - 이보다 커지면 실행 중에도 자동 회전
const LOG_ARCHIVE_KEEP = 5; // 보관할 이전 실행 세트 수 (그 이상은 자동 삭제)

/**
 * 지정된 로그 파일을 타임스탬프를 붙여 archive 폴더로 옮깁니다.
 * 파일이 없거나 비어있으면 아무것도 하지 않습니다.
 */
function archiveLogFile(string $path, string $archiveDir, string $timestamp): void {
	if (!is_file($path) || filesize($path) === 0) {
		return;
	}
	$baseName = pathinfo($path, PATHINFO_FILENAME);
	$ext = pathinfo($path, PATHINFO_EXTENSION);
	$archivedName = "{$baseName}.{$timestamp}.{$ext}";
	@rename($path, $archiveDir . DIRECTORY_SEPARATOR . $archivedName);
}

/**
 * archive 폴더에서 오래된 로그 세트를 정리합니다.
 * 세트 = 같은 타임스탬프를 공유하는 phar.*, phar-err.*, worker.* 묶음.
 * 최신 $keep개 세트만 남기고 나머지는 삭제합니다.
 */
function pruneOldLogArchives(string $archiveDir, int $keep): void {
	$files = glob($archiveDir . DIRECTORY_SEPARATOR . "*.log");
	if ($files === false || count($files) === 0) {
		return;
	}
	// 타임스탬프 기준으로 그룹화
	$timestamps = [];
	foreach ($files as $file) {
		if (preg_match('/\.(\d{8}-\d{6})\./', basename($file), $m)) {
			$timestamps[$m[1]] = true;
		}
	}
	$sorted = array_keys($timestamps);
	sort($sorted);
	$toDelete = array_slice($sorted, 0, max(0, count($sorted) - $keep));
	foreach ($toDelete as $ts) {
		foreach (glob($archiveDir . DIRECTORY_SEPARATOR . "*.{$ts}.log") as $oldFile) {
			@unlink($oldFile);
		}
	}
}

/**
 * 실행 중 로그 파일이 LOG_MAX_BYTES를 넘으면 archive로 옮기고 새 빈 파일로 교체합니다.
 * $posRef는 새 파일 기준 0으로 리셋됩니다 (rotate 이후 읽기 위치 초기화).
 */
function rotateLogIfTooBig(string $path, string $archiveDir, int &$posRef): void {
	if (!is_file($path)) {
		return;
	}
	$size = filesize($path);
	if ($size === false || $size < LOG_MAX_BYTES) {
		return;
	}
	$timestamp = date("Ymd-His") . "-rotate";
	archiveLogFile($path, $archiveDir, $timestamp);
	file_put_contents($path, "");
	pruneOldLogArchives($archiveDir, LOG_ARCHIVE_KEEP);
	$posRef = 0;
}

// 이전 실행의 로그를 보관하고, 오래된 보관본은 정리
$startupTimestamp = date("Ymd-His");
archiveLogFile($workerLogPath, $logArchiveDir, $startupTimestamp);
file_put_contents($workerLogPath, "");
pruneOldLogArchives($logArchiveDir, LOG_ARCHIVE_KEEP);

/** @var resource|null */
$networkWorkerProc = null;
/** @var resource|null */
$pharProc = null;

/** @var array<int, resource> */
$networkWorkerPipes = [];
/** @var array<int, resource> */
$pharPipes = [];

$shuttingDown = false;

function detectPhpBinary(): string {
	// WSL2 개발 환경 및 실제 배포 환경 모두 커버
	$candidates = [];

	if (stripos(PHP_OS, "WIN") === 0) {
		// 배포 시 Windows 순정 환경: 프로젝트 동봉 php.exe 우선
		$candidates[] = __DIR__ . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "php" . DIRECTORY_SEPARATOR . "php.exe";
		$candidates[] = "php.exe";
	} else {
		// Linux/WSL2/macOS: pmmp 전용 빌드 우선
		$candidates[] = __DIR__ . "/bin/php7/bin/php";
		$candidates[] = "php";
	}

	foreach ($candidates as $candidate) {
		if (is_file($candidate) && is_executable($candidate)) {
			return $candidate;
		}
	}
	// PATH 상의 php에 맡김 (마지막 안전망)
	return end($candidates);
}

function readOriginalPublicPort(string $path): int {
	if (!is_file($path)) {
		return 19132; // pmmp 기본값
	}
	foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
		if (preg_match('/^server-port\s*=\s*(\d+)/', $line, $m)) {
			return (int) $m[1];
		}
	}
	return 19132;
}

function readOriginalPublicPortV6(string $path): int {
	if (!is_file($path)) {
		return 19133; // pmmp 기본값
	}
	foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
		if (preg_match('/^server-portv6\s*=\s*(\d+)/', $line, $m)) {
			return (int) $m[1];
		}
	}
	return 19133;
}

function spawnProcess(array $cmd, ?string $cwd, string $stdoutLog, string $stderrLog): array {
	$descriptors = [
		0 => ["pipe", "r"],
		1 => ["file", $stdoutLog, "a"], // stdout -> 로그 파일
		2 => ["file", $stderrLog, "a"], // stderr -> 로그 파일
	];
	$pipes = [];
	$proc = proc_open($cmd, $descriptors, $pipes, $cwd);
	if ($proc === false) {
		fwrite(STDERR, "[Master] 프로세스 실행 실패: " . implode(" ", $cmd) . "\n");
		exit(1);
	}
	if (isset($pipes[0])) {
		stream_set_blocking($pipes[0], false);
	}
	return [$proc, $pipes];
}

/**
 * phar 전용: STDIN/STDOUT/STDERR을 마스터의 콘솔에 통째로 상속시켜 실행합니다.
 * "phar를 직접 실행하는 것"과 완전히 동일한 콘솔 경험(상태표시줄, 명령어 입력 등)을
 * 만들기 위한 함수입니다. 파이프가 전혀 생성되지 않으므로 반환되는 pipes는 항상 빈 배열입니다.
 */
function spawnProcessFullInherit(array $cmd, ?string $cwd): array {
	$descriptors = [
		0 => STDIN,
		1 => STDOUT,
		2 => STDERR,
	];
	$pipes = [];
	$proc = proc_open($cmd, $descriptors, $pipes, $cwd);
	if ($proc === false) {
		fwrite(STDERR, "[Master] 프로세스 실행 실패: " . implode(" ", $cmd) . "\n");
		exit(1);
	}
	return [$proc, []];
}

/**
 * 로그 파일의 새로 추가된 부분만 읽어서 반환합니다 (tail -f와 유사).
 * $posRef는 각 파일별로 마지막으로 읽은 바이트 위치를 참조로 유지합니다.
 */
function tailLogFile(string $path, int &$posRef): string {
	if (!is_file($path)) {
		return "";
	}
	$size = filesize($path);
	if ($size === false || $size <= $posRef) {
		return "";
	}
	$handle = @fopen($path, "rb");
	if ($handle === false) {
		return "";
	}
	fseek($handle, $posRef);
	$content = stream_get_contents($handle);
	$posRef = ftell($handle);
	fclose($handle);
	return $content === false ? "" : $content;
}

function shutdownAll(): void {
	global $shuttingDown, $networkWorkerProc, $pharProc, $networkWorkerPipes, $pharPipes;
	if ($shuttingDown) {
		return;
	}
	$shuttingDown = true;
	fwrite(STDOUT, "\n[Master] 종료 신호 수신, 자식 프로세스를 정리합니다...\n");

	foreach ([[$pharProc, $pharPipes, "TeamCraft-MP.phar"], [$networkWorkerProc, $networkWorkerPipes, "NetworkWorker"]] as [$proc, $pipes, $label]) {
		if ($proc === null) {
			continue;
		}
		$status = proc_get_status($proc);
		if ($status["running"] ?? false) {
			fwrite(STDOUT, "[Master] $label 정상 종료 시도...\n");
			// 콘솔 명령어로 정상 종료를 먼저 시도 (phar의 경우 "stop"이 우아한 셧다운)
			if (isset($pipes[0]) && is_resource($pipes[0])) {
				@fwrite($pipes[0], "stop\n");
			}
		}
	}

	// 짧게 유예 시간을 준 뒤 강제 종료
	usleep(1_500_000);

	foreach ([[$pharProc, $pharPipes, "TeamCraft-MP.phar"], [$networkWorkerProc, $networkWorkerPipes, "NetworkWorker"]] as [$proc, $pipes, $label]) {
		if ($proc === null) {
			continue;
		}
		if (isset($pipes[0]) && is_resource($pipes[0])) {
			@fclose($pipes[0]);
		}
		$status = proc_get_status($proc);
		if ($status["running"] ?? false) {
			fwrite(STDOUT, "[Master] $label 강제 종료 (proc_terminate)\n");
			proc_terminate($proc, 9);
		}
		proc_close($proc);
	}

	fwrite(STDOUT, "[Master] 모든 자식 프로세스 정리 완료. 종료합니다.\n");
	exit(0);
}

// --- 시그널 핸들링 (Linux/WSL2 + Windows 공용) ---
if (function_exists("pcntl_async_signals")) {
	pcntl_async_signals(true);
	pcntl_signal(SIGINT, fn() => shutdownAll());
	pcntl_signal(SIGTERM, fn() => shutdownAll());
}
if (function_exists("sapi_windows_set_ctrl_handler")) {
	sapi_windows_set_ctrl_handler(function (int $event) {
		shutdownAll();
	}, true);
}
register_shutdown_function(function () use (&$shuttingDown) {
	if (!$shuttingDown) {
		shutdownAll();
	}
});

// --- 메인 진입점 ---
/**
 * NetworkWorker가 STDOUT으로 보내는 컨트롤 메시지 프레임을 디코딩합니다.
 * 포맷: [4바이트 길이(LE)] [4바이트 타입ID(LE)] [8바이트 timestamp(double)] [payload]
 * 여러 프레임이 한 번에 몰려오거나 중간에 끊길 수 있으므로 버퍼에 누적하며 파싱합니다.
 */
function decodeWorkerFrames(string &$buffer): array {
	$messages = [];
	while (true) {
		if (strlen($buffer) < 4) {
			break;
		}
		$lenData = unpack("Vlen", substr($buffer, 0, 4));
		$bodyLen = $lenData["len"];
		if (strlen($buffer) < 4 + $bodyLen) {
			break; // 아직 프레임 전체가 도착 안 함
		}
		$body = substr($buffer, 4, $bodyLen);
		$typeId = unpack("Vtype", substr($body, 0, 4))["type"];
		$timestamp = unpack("dts", substr($body, 4, 8))["ts"];
		$payload = substr($body, 12);
		$messages[] = ["type" => $typeId, "timestamp" => $timestamp, "payload" => $payload];
		$buffer = substr($buffer, 4 + $bodyLen);
	}
	return $messages;
}

$workerReadBuffer = "";

function printWorkerMessage(array $msg): void {
	if ($msg["type"] === 1) {
		return; // STATS는 콘솔에 안 띄움
	}
	$label = match ($msg["type"]) {
		2 => "ERROR",
		3 => "INFO",
		default => "UNKNOWN(" . $msg["type"] . ")",
	};
	fwrite(STDOUT, "[worker:$label] " . $msg["payload"] . "\n");
}

$phpBinary = detectPhpBinary();
$rootDir = __DIR__;
$originalProperties = $rootDir . DIRECTORY_SEPARATOR . "server.properties";

$publicPort = readOriginalPublicPort($originalProperties);
$publicPortV6 = readOriginalPublicPortV6($originalProperties);

fwrite(STDOUT, "[Master] PHP 바이너리: $phpBinary\n");
fwrite(STDOUT, "[Master] 공개 포트(NetworkWorker, IPv4): $publicPort\n");
fwrite(STDOUT, "[Master] 공개 포트(NetworkWorker, IPv6): $publicPortV6\n");
fwrite(STDOUT, "[Master] 내부 포트(phar, IPv4): " . INTERNAL_PORT_V4 . "\n");
fwrite(STDOUT, "[Master] 내부 포트(phar, IPv6): " . INTERNAL_PORT_V6 . "\n");

if (stripos(PHP_OS, "WIN") === 0) {
	fwrite(STDOUT, "[Master] Windows 환경: 다른 PC에서 접속이 안 될 경우, setup-firewall.ps1을 관리자 권한으로 한 번 실행해 방화벽 인바운드 규칙을 열어주세요.\n");
}

// 1) NetworkWorker 기동 - 공개 포트(IPv4/IPv6)를 선점하고 phar의 내부 포트로 릴레이
[$networkWorkerProc, $networkWorkerPipes] = spawnProcess([
	$phpBinary,
	$rootDir . "/NetworkWorker.php",
	"--public-port=" . $publicPort,
	"--internal-host=" . INTERNAL_HOST,
	"--internal-port=" . INTERNAL_PORT_V4,
	"--public-port-v6=" . $publicPortV6,
	"--internal-host-v6=" . INTERNAL_HOST_V6,
	"--internal-port-v6=" . INTERNAL_PORT_V6,
], null, $workerLogPath, $workerLogPath);

// NetworkWorker가 내부 소켓을 준비할 시간을 살짝 줌
usleep(300_000);

// 2) PocketMine-MP.phar 기동 - server.properties는 원본 그대로 두고,
//    ServerConfigGroup의 getopt() 기반 오버라이드로 포트만 내부용으로 바꿈.
//    (src/ServerConfigGroup.php: getopt("", ["server-port::"]) 확인됨)
//    이 방식은 phar 소스를 전혀 건드리지 않는, pmmp가 공식 지원하는 오버라이드 경로임.
[$pharProc, $pharPipes] = spawnProcessFullInherit([
	$phpBinary,
	"-d",
	"phar.readonly=0",
	$rootDir . "/TeamCraft-MP.phar",
	"--no-wizard",
	"--server-port=" . INTERNAL_PORT_V4,
	"--server-portv6=" . INTERNAL_PORT_V6,
], $rootDir);

$workerLogPos = 0;

fwrite(STDOUT, "[Master] 두 자식 프로세스 기동 완료. phar가 콘솔을 직접 사용합니다 (예: stop, list, op <player>)\n");

// --- 메인 틱 루프 (20 TPS) ---
while (!$shuttingDown) {
	$tickStart = microtime(true);

	if (function_exists("pcntl_signal_dispatch")) {
		pcntl_signal_dispatch();
	}

	// 자식 생존 여부 체크
	$netStatus = proc_get_status($networkWorkerProc);
	$pharStatus = proc_get_status($pharProc);
	if (!($netStatus["running"] ?? false)) {
		fwrite(STDERR, "[Master] NetworkWorker가 예기치 않게 종료됨 (exit code: " . ($netStatus["exitcode"] ?? "?") . ")\n");
		shutdownAll();
		break;
	}
	if (!($pharStatus["running"] ?? false)) {
		fwrite(STDERR, "[Master] TeamCraft-MP.phar가 예기치 않게 종료됨 (exit code: " . ($pharStatus["exitcode"] ?? "?") . ")\n");
		shutdownAll();
		break;
	}

	// NetworkWorker의 로그/통계 출력도 함께 중계 (바이너리 프레임 디코딩)
	// (phar는 이제 콘솔을 직접 상속받아 스스로 출력하므로, 마스터가 따로 중계할 필요 없음)
	$workerChunk = tailLogFile($workerLogPath, $workerLogPos);
	if ($workerChunk !== "") {
		$workerReadBuffer .= $workerChunk;
		foreach (decodeWorkerFrames($workerReadBuffer) as $msg) {
			printWorkerMessage($msg);
		}
	}

	// 약 5초(100틱)마다 로그 파일 크기를 확인해 필요하면 회전
	static $tickCounter = 0;
	$tickCounter++;
	if ($tickCounter % 100 === 0) {
		rotateLogIfTooBig($workerLogPath, $logArchiveDir, $workerLogPos);
	}

	// 틱 속도 유지 (남은 시간만큼 슬립)
	$elapsed = microtime(true) - $tickStart;
	$remaining = (TICK_INTERVAL_US / 1_000_000) - $elapsed;
	if ($remaining > 0) {
		usleep((int) ($remaining * 1_000_000));
	}
}