<?php

/**
 * NetworkWorker
 *
 * 역할: 공개 포트(클라이언트가 접속하는 실제 포트)를 선점하고,
 * phar가 내부적으로 리슨 중인 내부 포트로 UDP 데이터그램을 "그대로"
 * 릴레이합니다. 패킷 내용(암호화/압축 여부)은 해석하지 않습니다.
 *
 * IPv4와 IPv6을 각각 독립된 "채널"로 취급합니다 - 공개 소켓, 내부 대상
 * host:port, 클라이언트 세션 테이블을 채널별로 따로 관리합니다. phar가
 * IPv4/IPv6을 서로 다른 포트로 리슨하기 때문에, 릴레이도 그에 맞춰
 * 완전히 분리된 두 개의 파이프라인으로 동작합니다.
 *
 * 왜 내용을 해석하지 않는가:
 * RakNet 세션 암호화 키는 phar 내부의 접속 처리 로직(핸드셰이크) 시점에만
 * 생성/보관됩니다. 이 프로세스 바깥에서 패킷을 복호화하려면 phar의 내부
 * 상태를 그대로 복제해야 하므로, "소스 무수정" 제약 하에서는 불가능합니다.
 *
 * 다중 클라이언트 처리 방식:
 * 클라이언트마다 전용 "내부 소켓"을 하나씩 새로 만듭니다. 이 내부 소켓의
 * ephemeral 포트가 phar 입장에서는 그 클라이언트의 세션 식별자 역할을
 * 합니다. phar가 그 내부 소켓으로 응답을 보내면, 워커는 그 응답이 어느
 * 내부 소켓에서 왔는지로 클라이언트와 채널을 정확히 특정해서 되돌려줍니다.
 */

declare(strict_types=1);

function parseArgs(array $argv): array {
	$opts = [];
	foreach ($argv as $arg) {
		if (preg_match('/^--([\w-]+)=(.*)$/', $arg, $m)) {
			$opts[$m[1]] = $m[2];
		}
	}
	return $opts;
}

/**
 * 컨트롤 플레인 전용 바이너리 프레이밍 (마스터로 통계/로그 전송용).
 * 포맷: [4바이트: 전체 길이(LE)] [4바이트: 메시지 타입 ID(LE)]
 *       [8바이트: 타임스탬프(microtime, double)] [나머지: UTF-8 payload]
 */
function encodeControlMessage(int $typeId, string $payload): string {
	$timestamp = microtime(true);
	$body = pack("V", $typeId) . pack("d", $timestamp) . $payload;
	$totalLength = strlen($body);
	return pack("V", $totalLength) . $body;
}

const MSG_TYPE_STATS = 1;
const MSG_TYPE_ERROR = 2;
const MSG_TYPE_INFO = 3;
const NAT_IDLE_TIMEOUT = 120; // 초 - 이 시간 동안 활동 없으면 세션 정리

$opts = parseArgs($argv);

/**
 * 채널 정의: 각 채널은 독립된 공개 소켓 + 내부 릴레이 대상을 가집니다.
 * bindAddr는 stream_socket_server용 주소 문자열(IPv6은 대괄호 필요).
 */
$channelDefs = [
	"v4" => [
		"publicPort" => (int) ($opts["public-port"] ?? 19132),
		"bindAddr" => "udp://0.0.0.0:" . (int) ($opts["public-port"] ?? 19132),
		"internalHost" => $opts["internal-host"] ?? "127.0.0.1",
		"internalPort" => (int) ($opts["internal-port"] ?? 19133),
	],
	"v6" => [
		"publicPort" => (int) ($opts["public-port-v6"] ?? 19133),
		"bindAddr" => "udp://[::]:" . (int) ($opts["public-port-v6"] ?? 19133),
		"internalHost" => $opts["internal-host-v6"] ?? "::1",
		"internalPort" => (int) ($opts["internal-port-v6"] ?? 19134),
	],
];

/**
 * 실제 활성화된 채널들. 소켓 바인딩에 성공한 채널만 여기 들어갑니다.
 * 각 채널: [
 *   "publicSocket" => resource,
 *   "internalHost" => string, "internalPort" => int,
 *   "sessions" => [clientAddr => ["socket" => resource, "lastSeen" => int]],
 * ]
 */
$channels = [];

foreach ($channelDefs as $name => $def) {
	$socket = @stream_socket_server($def["bindAddr"], $errno, $errstr, STREAM_SERVER_BIND);
	if ($socket === false) {
		// IPv6이 시스템에서 비활성화되어 있는 등의 이유로 바인딩이 실패할 수 있음.
		// 이 경우 해당 채널만 건너뛰고 계속 진행 (IPv4만으로도 서버는 정상 동작).
		fwrite(STDOUT, encodeControlMessage(MSG_TYPE_ERROR, "채널 [$name] 공개 포트 바인딩 실패 ({$def['publicPort']}): $errstr ($errno) - 이 채널은 비활성화됩니다"));
		continue;
	}
	stream_set_blocking($socket, false);
	$channels[$name] = [
		"publicSocket" => $socket,
		"internalHost" => $def["internalHost"],
		"internalPort" => $def["internalPort"],
		"sessions" => [],
	];
	fwrite(STDOUT, encodeControlMessage(MSG_TYPE_INFO, "채널 [$name] 공개 포트 {$def['publicPort']} 리스닝 시작, phar 대상: {$def['internalHost']}:{$def['internalPort']}"));
}

if (count($channels) === 0) {
	fwrite(STDERR, "[NetworkWorker] 모든 채널의 공개 포트 바인딩에 실패했습니다. 종료합니다.\n");
	exit(1);
}

/**
 * 내부 소켓(resource ID) -> [채널명, 클라이언트주소] 역매핑.
 * stream_select에서 어떤 내부 소켓이 readable했는지 알려주는데,
 * 그 소켓이 어느 채널의 어느 클라이언트 것인지 빠르게 찾기 위해 사용합니다.
 */
$socketToSession = [];

function createClientInternalSocket(): mixed {
	$socket = @stream_socket_server("udp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND);
	if ($socket === false) {
		fwrite(STDERR, "[NetworkWorker] 클라이언트 세션용 내부 소켓 생성 실패: $errstr ($errno)\n");
		return null;
	}
	stream_set_blocking($socket, false);
	return $socket;
}

$lastStatsReport = 0;
$packetsRelayedToInternal = 0;
$packetsRelayedToPublic = 0;

while (true) {
	$read = [];
	foreach ($channels as $channelName => $channel) {
		$read[] = $channel["publicSocket"];
		foreach ($channel["sessions"] as $session) {
			$read[] = $session["socket"];
		}
	}
	$write = null;
	$except = null;

	// 100ms 타임아웃 - 마스터 틱(50ms)보다 살짝 여유 있게, 논블로킹 루프 유지
	$changed = @stream_select($read, $write, $except, 0, 100_000);
	if ($changed === false) {
		continue;
	}

	foreach ($read as $socket) {
		$matchedChannel = null;
		foreach ($channels as $channelName => $channel) {
			if ($socket === $channel["publicSocket"]) {
				$matchedChannel = $channelName;
				break;
			}
		}

		if ($matchedChannel !== null) {
			// 클라이언트 -> phar 방향
			$channelName = $matchedChannel;
			$data = stream_socket_recvfrom($channels[$channelName]["publicSocket"], 65535, 0, $clientAddr);
			if ($data === false || $data === "") {
				continue;
			}
			if (!isset($channels[$channelName]["sessions"][$clientAddr])) {
				$newSocket = createClientInternalSocket();
				if ($newSocket === null) {
					continue; // 세션 생성 실패, 이 패킷은 드롭
				}
				$channels[$channelName]["sessions"][$clientAddr] = ["socket" => $newSocket, "lastSeen" => time()];
				$socketToSession[(int) $newSocket] = [$channelName, $clientAddr];
			}
			$channels[$channelName]["sessions"][$clientAddr]["lastSeen"] = time();
			$target = $channels[$channelName]["internalHost"] . ":" . $channels[$channelName]["internalPort"];
			@stream_socket_sendto($channels[$channelName]["sessions"][$clientAddr]["socket"], $data, 0, $target);
			$packetsRelayedToInternal++;
			continue;
		}

		// phar -> 특정 클라이언트 방향 (이 소켓이 어느 채널/클라이언트 것인지 역매핑으로 특정)
		$socketId = (int) $socket;
		if (!isset($socketToSession[$socketId])) {
			continue;
		}
		[$channelName, $clientAddr] = $socketToSession[$socketId];
		$data = stream_socket_recvfrom($socket, 65535, 0, $fromAddr);
		if ($data === false || $data === "") {
			continue;
		}
		@stream_socket_sendto($channels[$channelName]["publicSocket"], $data, 0, $clientAddr);
		$packetsRelayedToPublic++;
	}

	// 유휴 세션 정리 (소켓도 함께 닫아서 자원 누수 방지)
	$now = time();
	$totalClients = 0;
	foreach ($channels as $channelName => $channel) {
		foreach ($channel["sessions"] as $clientAddr => $session) {
			if ($now - $session["lastSeen"] > NAT_IDLE_TIMEOUT) {
				$socketId = (int) $session["socket"];
				@fclose($session["socket"]);
				unset($socketToSession[$socketId]);
				unset($channels[$channelName]["sessions"][$clientAddr]);
			}
		}
		$totalClients += count($channels[$channelName]["sessions"]);
	}

	// 5초마다 통계를 마스터로 출력 (컨트롤 플레인 메시지)
	if ($now - $lastStatsReport >= 5) {
		$statsPayload = json_encode([
			"clients" => $totalClients,
			"to_internal" => $packetsRelayedToInternal,
			"to_public" => $packetsRelayedToPublic,
			"channels" => array_keys($channels),
		]);
		fwrite(STDOUT, encodeControlMessage(MSG_TYPE_STATS, $statsPayload));
		$lastStatsReport = $now;
	}
}

/**
 * 참고 (남은 개선 여지):
 * 1. 스푸핑 방지를 위한 최소한의 소스 검증(rate limiting 등)은
 *    아직 없음 - 공인 IP로 노출할 경우 고려 필요.
 * 2. 채널 순회(foreach)로 매칭하는 방식이라 채널 수가 매우 많아지면
 *    비효율적일 수 있으나, IPv4/IPv6 2개 채널 규모에서는 문제없음.
 */
