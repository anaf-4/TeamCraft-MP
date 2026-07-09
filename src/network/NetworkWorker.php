<?php

/**
 * NetworkWorker
 *
 * 역할: 공개 포트(클라이언트가 접속하는 실제 포트)를 선점하고,
 * phar가 내부적으로 리슨 중인 127.0.0.1:INTERNAL_PORT로 UDP 데이터그램을
 * "그대로" 릴레이합니다. 패킷 내용(암호화/압축 여부)을 해석하지 않습니다.
 *
 * 왜 내용을 해석하지 않는가:
 * RakNet 세션 암호화 키는 phar 내부의 접속 처리 로직(핸드셰이크) 시점에만
 * 생성/보관됩니다. 이 프로세스 바깥에서 패킷을 복호화하려면 phar의 내부
 * 상태를 그대로 복제해야 하므로, "소스 무수정" 제약 하에서는 불가능합니다.
 * 따라서 여기서는 순수 NAT 릴레이 방식을 씁니다 - 이 정도만으로도
 * "공개 포트를 마스터/워커 프로세스가 선점 + phar는 내부에서만 리슨"이라는
 * 목표(포트 충돌 회피, 프로세스 분리)는 완전히 달성됩니다.
 *
 * 클라이언트 <-> phar 사이의 매핑은 "클라이언트 주소:포트" 기준
 * NAT 테이블로 관리합니다.
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

$opts = parseArgs($argv);
$publicPort = (int) ($opts["public-port"] ?? 19132);
$internalHost = $opts["internal-host"] ?? "127.0.0.1";
$internalPort = (int) ($opts["internal-port"] ?? 19133);

// --- 공개 포트 UDP 소켓 (클라이언트가 실제로 접속하는 곳) ---
$publicSocket = @stream_socket_server("udp://0.0.0.0:$publicPort", $errno, $errstr, STREAM_SERVER_BIND);
if ($publicSocket === false) {
	fwrite(STDERR, "[NetworkWorker] 공개 포트 바인딩 실패 ($publicPort): $errstr ($errno)\n");
	exit(1);
}
stream_set_blocking($publicSocket, false);

// --- phar와 통신할 내부용 UDP 소켓 ---
$internalSocket = @stream_socket_server("udp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND);
if ($internalSocket === false) {
	fwrite(STDERR, "[NetworkWorker] 내부 소켓 생성 실패: $errstr ($errno)\n");
	exit(1);
}
stream_set_blocking($internalSocket, false);

const MSG_TYPE_STATS = 1;
const MSG_TYPE_ERROR = 2;
const MSG_TYPE_INFO = 3;

fwrite(STDOUT, encodeControlMessage(MSG_TYPE_INFO, "공개 포트 $publicPort 리스닝 시작, phar 대상: $internalHost:$internalPort"));

/**
 * NAT 매핑 테이블: "클라이언트주소:포트" => 마지막 활동 시각(유휴 정리용)
 * phar -> 클라이언트로 되돌아오는 패킷은 phar가 우리에게 보낸 응답을
 * "가장 최근에 활동한 클라이언트"로 되돌리는 방식이 아니라, phar 응답에
 * 포함된 목적지 정보(내부 소켓의 recvfrom 결과 자체가 이미 어느 내부
 * 소켓 페어로 왔는지 구분되므로) 실제로는 클라이언트별 전용 내부 소켓을
 * 만드는 편이 안전합니다. 아래는 단일 내부 소켓을 쓰는 단순화 버전이며,
 * 다중 동시 접속을 프로덕션에서 다루려면 클라이언트별 ephemeral 내부
 * 소켓을 두는 것을 권장합니다 (주석 하단 TODO 참고).
 */
$natTable = [];
const NAT_IDLE_TIMEOUT = 120; // 초

/**
 * 컨트롤 플레인 전용 바이너리 프레이밍 (마스터로 통계/로그 전송용).
 * 실제 게임 패킷 페이로드에는 사용하지 않습니다 (그건 위에서 설명했듯
 * 순수 UDP 릴레이로 처리됩니다). 이 포맷은 워커 상태를 마스터 콘솔에
 * 보고하는 용도로만 씁니다.
 *
 * 포맷: [4바이트: 전체 길이(LE)] [4바이트: 메시지 타입 ID(LE)]
 *       [8바이트: 타임스탬프(microtime, double)] [나머지: UTF-8 payload]
 */
function encodeControlMessage(int $typeId, string $payload): string {
	$timestamp = microtime(true);
	$body = pack("V", $typeId) . pack("d", $timestamp) . $payload;
	$totalLength = strlen($body);
	return pack("V", $totalLength) . $body;
}

$lastStatsReport = 0;
$packetsRelayedToInternal = 0;
$packetsRelayedToPublic = 0;

while (true) {
	$read = [$publicSocket, $internalSocket];
	$write = null;
	$except = null;

	// 100ms 타임아웃 - 마스터 틱(50ms)보다 살짝 여유 있게, 논블로킹 루프 유지
	$changed = @stream_select($read, $write, $except, 0, 100_000);
	if ($changed === false) {
		continue;
	}

	foreach ($read as $socket) {
		if ($socket === $publicSocket) {
			// 클라이언트 -> phar 방향
			$data = stream_socket_recvfrom($publicSocket, 65535, 0, $clientAddr);
			if ($data === false || $data === "") {
				continue;
			}
			$natTable[$clientAddr] = time();
			@stream_socket_sendto($internalSocket, $data, 0, "$internalHost:$internalPort");
			$packetsRelayedToInternal++;
		} elseif ($socket === $internalSocket) {
			// phar -> 클라이언트 방향
			$data = stream_socket_recvfrom($internalSocket, 65535, 0, $fromAddr);
			if ($data === false || $data === "") {
				continue;
			}
			// 단일 내부 소켓 단순화 버전: 가장 최근 활동한 클라이언트에게 브로드캐스트하지 않고,
			// 실제로는 phar가 어느 클라이언트에게 보내는지 구분할 수 없으므로
			// 프로덕션에서는 클라이언트별 전용 내부 소켓 페어를 사용해야 합니다.
			foreach (array_keys($natTable) as $clientAddr) {
				@stream_socket_sendto($publicSocket, $data, 0, $clientAddr);
			}
			$packetsRelayedToPublic++;
		}
	}

	// 유휴 NAT 엔트리 정리
	$now = time();
	foreach ($natTable as $addr => $lastSeen) {
		if ($now - $lastSeen > NAT_IDLE_TIMEOUT) {
			unset($natTable[$addr]);
		}
	}

	// 5초마다 통계를 마스터로 출력 (컨트롤 플레인 메시지)
	if ($now - $lastStatsReport >= 5) {
		$statsPayload = json_encode([
			"clients" => count($natTable),
			"to_internal" => $packetsRelayedToInternal,
			"to_public" => $packetsRelayedToPublic,
		]);
		fwrite(STDOUT, encodeControlMessage(MSG_TYPE_STATS, $statsPayload));
		$lastStatsReport = $now;
	}
}

/**
 * TODO (프로덕션 전환 시 반드시 검토):
 * 1. 클라이언트별 전용 내부 소켓 페어를 두어 다중 동시 접속을 정확히 구분할 것
 *    (현재 단순화 버전은 내부 소켓 하나를 공유하므로 phar 응답을 어느
 *    클라이언트에게 보내야 할지 완전히 특정할 수 없습니다).
 * 2. IPv6(server-portv6) 릴레이 추가.
 * 3. 스푸핑 방지를 위한 최소한의 소스 검증(rate limiting 등) 고려.
 */
