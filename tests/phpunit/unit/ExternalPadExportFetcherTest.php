<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\ExternalPadException;
use OCA\EtherpadNextcloud\Exception\ExternalPadExportNotFoundException;
use OCA\EtherpadNextcloud\Exception\ExternalPadHttpErrorException;
use OCA\EtherpadNextcloud\Exception\EtherpadTooLargeException;
use OCA\EtherpadNextcloud\Http\ExportAttemptSink;
use OCA\EtherpadNextcloud\Service\ExternalPadExportFetcher;
use OCP\IConfig;
use OCA\EtherpadNextcloud\Tests\Support\FixedClock;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ExternalPadExportFetcherTest extends TestCase {
	public function testNormalizeAndValidateExternalPublicPadUrlCanonicalizesHttpsUrl(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/My Pad');

		$this->assertSame('https://1.1.1.1', $result['origin']);
		$this->assertSame('My Pad', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/My%20Pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlKeepsLiteralPlusInPadId(): void {
		// `+` is literal in URL path segments. Using urldecode() previously
		// turned `team+pad` into pad-id `team pad`, then re-emitted
		// `/p/team%20pad` which hits a different / non-existent pad.
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team+meeting');
		$this->assertSame('team+meeting', $result['pad_id']);
		$this->assertSame('https://1.1.1.1/p/team%2Bmeeting', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlDecodesPercentEncodedPlus(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());
		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/team%2Bmeeting');
		$this->assertSame('team+meeting', $result['pad_id']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlAcceptsMatchingAllowlistedOriginWithPort(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$result = $fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:8443/p/public-pad');

		$this->assertSame('https://1.1.1.1:8443', $result['origin']);
		$this->assertSame('https://1.1.1.1:8443/p/public-pad', $result['pad_url']);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsNonMatchingAllowlistedOriginPort(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig('https://1.1.1.1:8443'));

		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('External pad host is not in the allowlist.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1:9443/p/public-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsAPadIdWithANewline(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$this->expectException(ExternalPadException::class);
		$fetcher->normalizeAndValidateExternalPublicPadUrl(
			'https://1.1.1.1/p/a%0Apad_id:%20g.victim$secret',
		);
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsAControlCharacterBeforeThePadId(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$this->expectException(ExternalPadException::class);
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/base%01/p/demo');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsProtectedPadIds(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('Only public pad URLs can be linked from external servers.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/g.group$protected-pad');
	}

	public function testNormalizeAndValidateExternalPublicPadUrlRejectsWhenDisabledByAdmin(): void {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = ''): string {
				if ($appName === 'etherpad_nextcloud' && $key === 'allow_external_pads') {
					return 'no';
				}
				return $default;
			}
		);

		$fetcher = $this->fetcher($config);

		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('External pad linking is disabled by admin settings.');
		$fetcher->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/public-pad');
	}

	/**
	 * Many of the refused addresses pass PHP's filter on some supported
	 * version or in one way of writing them; the check must depend on
	 * neither. The addresses just outside a block stay public.
	 */
	#[DataProvider('addressesAndWhetherTheyArePublic')]
	public function testRefusesAddressesThatAreNotPublic(string $address, bool $public): void {
		$records = [[str_contains($address, ':') ? 'ipv6' : 'ip' => $address]];
		try {
			$validated = $this->fetcher($this->buildExternalEnabledConfig(), $records)
				->normalizeAndValidateExternalPublicPadUrl('https://pad.example/p/pad');
			$this->assertTrue($public, $address . ': not refused');
			$this->assertSame('https://pad.example/p/pad', $validated['pad_url']);
		} catch (ExternalPadException $e) {
			$this->assertFalse($public, $address . ': refused');
			$this->assertSame(ExternalPadException::LOCAL_ADDRESS, $e->reason());
		}
	}

	/** @return array<string, array{string, bool}> */
	public static function addressesAndWhetherTheyArePublic(): array {
		return [
			'"this network"' => ['0.1.2.3', false],
			'the last of "this network"' => ['0.255.255.255', false],
			'private' => ['10.0.0.7', false],
			'private, the last of 10.0.0.0/8' => ['10.255.255.255', false],
			'shared address space, as carrier-grade NAT and Tailscale use it' => ['100.64.0.1', false],
			'the last shared address' => ['100.127.255.255', false],
			'loopback, its last address' => ['127.255.255.255', false],
			'link-local, its last address' => ['169.254.255.255', false],
			'private, the last of 172.16.0.0/12' => ['172.31.255.255', false],
			'IETF protocol assignments' => ['192.0.0.8', false],
			'an anycast address inside a block that counts as a whole' => ['192.0.0.9', false],
			'the last IETF protocol assignment' => ['192.0.0.255', false],
			'the first documentation block, its last address' => ['192.0.2.255', false],
			'the 6a44 relay' => ['192.88.99.2', false],
			'private, the last of 192.168.0.0/16' => ['192.168.255.255', false],
			'benchmarking, its last address' => ['198.19.255.255', false],
			'the second documentation block, its last address' => ['198.51.100.255', false],
			'the third documentation block, its last address' => ['203.0.113.255', false],
			'multicast' => ['224.0.0.1', false],
			'multicast, its last address' => ['239.255.255.255', false],
			'reserved' => ['240.0.0.1', false],
			'the limited broadcast address' => ['255.255.255.255', false],
			'something the system does not parse as an address' => ['999.1.1.1', false],
			'loopback' => ['::1', false],
			'loopback, IPv4-compatible and written in hex' => ['::7f00:1', false],
			'loopback, IPv4-mapped' => ['::ffff:7f00:1', false],
			'loopback, IPv4-mapped and dotted' => ['::ffff:127.0.0.1', false],
			'a public address, IPv4-mapped' => ['::ffff:1.1.1.1', false],
			'a private address, IPv4-translated' => ['::ffff:0:a00:1', false],
			'NAT64 to a private address' => ['64:ff9b::a00:5', false],
			'NAT64 to shared address space' => ['64:ff9b::6440:1', false],
			'NAT64 to link-local, dotted' => ['64:ff9b::169.254.169.254', false],
			'NAT64\'s last address, the limited broadcast address' => ['64:ff9b::ffff:ffff', false],
			'just past the NAT64 prefix' => ['64:ff9b::1:101:101', false],
			'6to4 for a private address' => ['2002:a00:1::1', false],
			'6to4 for loopback' => ['2002:7f00:1::1', false],
			'6to4 for a private address above 128.0.0.0' => ['2002:c0a8:1::1', false],
			'6to4 for a private address followed by public-looking bytes' => ['2002:a01:101:101::1', false],
			'6to4 for the limited broadcast address' => ['2002:ffff:ffff::1', false],
			'local-use IPv4/IPv6 translation' => ['64:ff9b:1::a00:1', false],
			'unique-local with a dotted tail' => ['fd00::10.0.0.1', false],
			'link-local with a dotted tail' => ['fe80::1.2.3.4', false],
			'reserved space below global unicast' => ['5f::1', false],
			'just below global unicast' => ['1fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', false],
			'just above global unicast' => ['4000::', false],
			'IPv6 multicast' => ['ff02::1', false],
			'discard-only' => ['100::1', false],
			'the dummy prefix' => ['100:0:0:1::1', false],
			'Teredo, inside the IETF block' => ['2001::1', false],
			'deprecated ORCHID, inside the IETF block' => ['2001:10::1', false],
			'IPv6 benchmarking' => ['2001:2::1', false],
			'the last address of the IETF block' => ['2001:1ff:ffff:ffff:ffff:ffff:ffff:ffff', false],
			'IPv6 documentation, its last address' => ['2001:db8:ffff:ffff:ffff:ffff:ffff:ffff', false],
			'the newer IPv6 documentation block, its last address' => ['3fff:fff:ffff:ffff:ffff:ffff:ffff:ffff', false],
			'SRv6 segment identifiers' => ['5f00::1', false],
			'site-local' => ['fec0::1', false],
			'a public IPv4 address' => ['1.1.1.1', true],
			'just past "this network"' => ['1.0.0.0', true],
			'just past 10.0.0.0/8' => ['11.0.0.0', true],
			'just below shared address space' => ['100.63.255.255', true],
			'just above shared address space' => ['100.128.0.0', true],
			'just below loopback' => ['126.255.255.255', true],
			'just past link-local' => ['169.255.0.0', true],
			'just below 172.16.0.0/12' => ['172.15.255.255', true],
			'just above 172.16.0.0/12' => ['172.32.0.0', true],
			'just past the IETF protocol assignments' => ['192.0.1.0', true],
			'just past the first documentation block' => ['192.0.3.0', true],
			'next to the 6a44 relay' => ['192.88.99.3', true],
			'just past 192.168.0.0/16' => ['192.169.0.0', true],
			'just below benchmarking' => ['198.17.255.255', true],
			'just above benchmarking' => ['198.20.0.0', true],
			'just past the second documentation block' => ['198.51.101.0', true],
			'just below the third documentation block' => ['203.0.112.255', true],
			'just below multicast' => ['223.255.255.255', true],
			'NAT64 to a public address' => ['64:ff9b::101:101', true],
			'NAT64 to a public address above 128.0.0.0' => ['64:ff9b::8efa:b54e', true],
			'6to4 for a public address' => ['2002:101:101::1', true],
			'just past 6to4' => ['2003:a00:1::1', true],
			'the first global unicast address' => ['2000::', true],
			'just past the IETF block' => ['2001:200::', true],
			'just past IPv6 documentation' => ['2001:db9::', true],
			'just past the newer documentation block' => ['3fff:1000::', true],
			'the last global unicast address' => ['3fff:ffff:ffff:ffff:ffff:ffff:ffff:ffff', true],
			'global unicast PHP 8.1 and 8.2 refuse' => ['3ff3::1', true],
			'a public IPv6 address' => ['2606:4700::1111', true],
		];
	}

	/**
	 * Each refusal carries the reason the user is told, whatever its
	 * message says for the log.
	 */
	public function testEachRefusalCarriesItsReason(): void {
		$cases = [
			'no https' => ['http://1.1.1.1/p/pad', '', ExternalPadException::INVALID_URL],
			'another scheme' => ['ftp://1.1.1.1/p/pad', '', ExternalPadException::INVALID_URL],
			'no host' => ['https:///p/pad', '', ExternalPadException::INVALID_URL],
			'credentials' => ['https://user:secret@1.1.1.1/p/pad', '', ExternalPadException::CREDENTIALS_IN_URL],
			'no /p/' => ['https://1.1.1.1/pad/pad', '', ExternalPadException::NOT_A_PAD_URL],
			'only the server' => ['https://1.1.1.1', '', ExternalPadException::NOT_A_PAD_URL],
			'a group pad' => ['https://1.1.1.1/p/g.group$pad', '', ExternalPadException::NOT_PUBLIC],
			'not on the allowlist' => ['https://1.1.1.1:9443/p/pad', 'https://1.1.1.1:8443', ExternalPadException::NOT_ALLOWED],
			'a local host' => ['https://pad.localhost/p/pad', '', ExternalPadException::LOCAL_ADDRESS],
			'a private address' => ['https://10.0.0.7/p/pad', '', ExternalPadException::LOCAL_ADDRESS],
			'a shared address' => ['https://100.64.0.7/p/pad', '', ExternalPadException::LOCAL_ADDRESS],
			'loopback written as IPv6' => ['https://[::ffff:127.0.0.1]/p/pad', '', ExternalPadException::LOCAL_ADDRESS],
			'no pad after /p/' => ['https://1.1.1.1/p/%20', '', ExternalPadException::NOT_A_PAD_URL],
			'a name without records' => ['https://pad.example/p/pad', '', ExternalPadException::UNRESOLVED, []],
			'a name without an address' => ['https://pad.example/p/pad', '', ExternalPadException::UNRESOLVED, [['host' => 'pad.example', 'type' => 'A']]],
			'a name for a private address' => ['https://pad.example/p/pad', '', ExternalPadException::LOCAL_ADDRESS, [['ip' => '1.1.1.1'], ['ipv6' => 'fd00::7']]],
		];
		foreach ($cases as $case => $row) {
			// What a name lookup finds, where the case gets that far.
			[$url, $allowlist, $reason, $records] = $row + [3 => null];
			try {
				$this->fetcher($this->buildExternalEnabledConfig($allowlist), $records)->normalizeAndValidateExternalPublicPadUrl($url);
				$this->fail($case . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertSame($reason, $e->reason(), $case);
			}
		}

		$disabled = $this->createMock(IConfig::class);
		$disabled->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $key === 'allow_external_pads' ? 'no' : $default);
		try {
			$this->fetcher($disabled)->normalizeAndValidateExternalPublicPadUrl('https://1.1.1.1/p/pad');
			$this->fail('switched off: not refused');
		} catch (ExternalPadException $e) {
			$this->assertSame(ExternalPadException::DISABLED, $e->reason());
		}

		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());
		foreach ([
			[500, ExternalPadException::HTTP_ERROR, 500],
			[401, ExternalPadException::HTTP_ERROR, 401],
			[400, ExternalPadException::HTTP_ERROR, 400],
			[300, ExternalPadException::REDIRECTED, null],
			[302, ExternalPadException::REDIRECTED, null],
			[307, ExternalPadException::REDIRECTED, null],
			[399, ExternalPadException::REDIRECTED, null],
			[404, ExternalPadException::NOT_FOUND, null],
		] as [$status, $reason, $httpStatus]) {
			try {
				(new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertSuccessfulExportStatus'))->invoke($fetcher, $status);
				$this->fail($status . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertSame([$reason, $httpStatus], [$e->reason(), $e instanceof ExternalPadHttpErrorException ? $e->httpStatus() : null], (string)$status);
			}
		}
		foreach ([['', 'txt'], ['text/html', 'txt'], ['image/png', 'txt'], ['application/json', 'html']] as [$contentType, $format]) {
			try {
				(new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertAllowedExternalExportContentType'))->invoke($fetcher, $contentType, $format);
				$this->fail($contentType . ' for ' . $format . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertSame(ExternalPadException::UNEXPECTED_ANSWER, $e->reason(), $contentType . ' for ' . $format);
			}
		}

		// No answer at all: a certificate not trusted, which a later try does
		// not mend, or the server not reached. Untested here: what cURL
		// itself returns, which needs a server, a cURL that cannot start a
		// request, and PHP without cURL.
		$transport = new \ReflectionMethod(ExternalPadExportFetcher::class, 'transportReason');
		// One address with a certificate not trusted, another not answering:
		// the certificate is why, whichever was tried first.
		$this->assertSame(ExternalPadException::UNTRUSTED_CERTIFICATE, $transport->invoke(null, [60, 7]));
		$this->assertSame(ExternalPadException::UNTRUSTED_CERTIFICATE, $transport->invoke(null, [7, 60]));
		$this->assertSame(ExternalPadException::UNTRUSTED_CERTIFICATE, $transport->invoke(null, [51]));
		$this->assertSame(ExternalPadException::UNREACHABLE, $transport->invoke(null, [7, 28]));
		$this->assertSame(ExternalPadException::UNREACHABLE, $transport->invoke(null, []));
	}

	/**
	 * The budget is shared with everything before the request, name
	 * resolution included, so an attempt that no longer fits is not made at
	 * all rather than started with the full timeout.
	 */
	public function testAnExhaustedBudgetStopsBeforeAnyAttempt(): void {
		$send = new \ReflectionMethod(ExternalPadExportFetcher::class, 'sendPinnedPublicGetRequest');
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		try {
			$send->invoke(
				$fetcher,
				'https://1.1.1.1/p/Test/export/html',
				443,
				['1.1.1.1'],
				'html',
				// Already spent: a slow lookup leaves nothing for the transfer.
				// Against the clock the fetcher reads, not the wall clock — the
				// two are the same only by accident.
				FixedClock::NOW - 1.0,
			);
			$this->fail('an attempt was made');
		} catch (ExternalPadException $e) {
			$this->assertStringContainsString('no time left', $e->getMessage());
			// No answer from the server, which a later try may get.
			$this->assertSame(ExternalPadException::UNREACHABLE, $e->reason());
		}
	}

	public function testEachAttemptIsKeptToWhatWasChecked(): void {
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig(), [['ip' => '1.1.1.1']], [
			static fn (array $options): array => self::answer($options, 200, 'text/plain', 'pad text'),
		]);

		$this->assertSame('pad text', $fetcher->normalizeAndFetchExternalPublicPadText('https://pad.example:8443/p/pad')['text']);
		[$url, $options] = $fetcher->performed[0];
		$this->assertSame('https://pad.example:8443/p/pad/export/txt', $url);
		$this->assertSame(['::1.1.1.1:8443'], $options[CURLOPT_CONNECT_TO]);
		$this->assertFalse($options[CURLOPT_FOLLOWLOCATION]);
		$this->assertSame(0, $options[CURLOPT_MAXREDIRS]);
		$this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS]);
		$this->assertSame(CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS]);
		$this->assertTrue($options[CURLOPT_SSL_VERIFYPEER]);
		$this->assertSame(2, $options[CURLOPT_SSL_VERIFYHOST]);
		$this->assertTrue($options[CURLOPT_HTTPGET]);
		$this->assertFalse($options[CURLOPT_RETURNTRANSFER]);
		$this->assertSame($options[CURLOPT_TIMEOUT], $options[CURLOPT_CONNECTTIMEOUT]);
		$this->assertSame(['Accept: text/plain, application/octet-stream;q=0.9, */*;q=0.1'], $options[CURLOPT_HTTPHEADER]);
		// One option cURL refuses would stop every fetch.
		$this->assertTrue(curl_setopt_array(curl_init(), $options), 'cURL takes every option');
	}

	/**
	 * An address that stops answering gives way to the next, each attempt
	 * pinned to its own. The first one's half answer stays with it.
	 */
	public function testTriesTheNextAddressAfterOneThatDoesNotAnswer(): void {
		$fetcher = $this->fetcher(
			$this->buildExternalEnabledConfig(),
			[['ip' => '1.1.1.1'], ['ipv6' => '2606:4700::1111']],
			[
				static function (array $options): array {
					$options[CURLOPT_HEADERFUNCTION](null, "Content-Type: text/plain\r\n");
					$options[CURLOPT_WRITEFUNCTION](null, 'half ');
					return ['completed' => false, 'status' => 0, 'error' => 'Operation timed out', 'errno' => 28];
				},
				static fn (array $options): array => self::answer($options, 200, 'text/plain', 'pad text'),
			],
		);

		$result = $fetcher->normalizeAndFetchExternalPublicPadText('https://pad.example/p/pad');

		$this->assertSame(['https://pad.example', 'pad', 'https://pad.example/p/pad', 'pad text'], [$result['origin'], $result['pad_id'], $result['pad_url'], $result['text']]);
		$this->assertSame(
			[['https://pad.example/p/pad/export/txt', ['::1.1.1.1:443']], ['https://pad.example/p/pad/export/txt', ['::[2606:4700::1111]:443']]],
			array_map(static fn (array $request): array => [$request[0], $request[1][CURLOPT_CONNECT_TO]], $fetcher->performed),
		);
	}

	/**
	 * The whole fetch has one budget: an address is tried only while at
	 * least two whole seconds of it are left - 1.9 is not two - and the
	 * ones after it are not tried either.
	 */
	public function testTriesAnAddressOnlyWhileTwoSecondsOfTheBudgetAreLeft(): void {
		foreach ([
			'exactly two left' => [18.0, 2],
			'less than two left' => [18.1, 1],
		] as $case => [$firstAttemptTakes, $attempts]) {
			$clock = new FixedClock();
			$refused = static function (array $options) use ($clock, $firstAttemptTakes): array {
				$clock->advanceMicros((int)($firstAttemptTakes * 1000000));
				return ['completed' => false, 'status' => 0, 'error' => 'Connection refused', 'errno' => 7];
			};
			$fetcher = $this->fetcher(
				$this->buildExternalEnabledConfig(),
				[['ip' => '1.1.1.1'], ['ip' => '1.0.0.1'], ['ip' => '1.0.0.2']],
				[$refused, $refused, $refused],
				$clock,
			);

			try {
				$fetcher->normalizeAndFetchExternalPublicPadText('https://pad.example/p/pad');
				$this->fail($case . ': not refused');
			} catch (ExternalPadException $e) {
				$this->assertCount($attempts, $fetcher->performed, $case);
				if ($attempts === 2) {
					$this->assertSame(2, $fetcher->performed[1][1][CURLOPT_TIMEOUT], $case . ': the second attempt has what is left');
				}
				if ($attempts === 1) {
					$this->assertSame('Public export transport error: transport via 1.1.1.1: Connection refused; no time left to try 1.0.0.1', $e->getMessage(), $case);
				}
			}
		}
	}

	/** The HTML export asks for HTML at its own address; a pad not exported is no failure for the text one that may be empty. */
	public function testFetchesTheHtmlExportAndAnEmptyTextForAPadNotExported(): void {
		$html = $this->fetcher($this->buildExternalEnabledConfig(), null, [
			static fn (array $options): array => self::answer($options, 200, 'text/html', '<p>pad</p>'),
		]);
		$this->assertSame('<p>pad</p>', $html->fetchExternalPublicPadHtml('https://1.1.1.1/p/pad'));
		$this->assertSame('https://1.1.1.1/p/pad/export/html', $html->performed[0][0]);
		$this->assertSame(['Accept: text/html, application/xhtml+xml;q=0.9, */*;q=0.1'], $html->performed[0][1][CURLOPT_HTTPHEADER]);

		foreach ([[404, '', true], [200, 'pad text', false]] as [$status, $text, $unavailable]) {
			$result = $this->fetcher($this->buildExternalEnabledConfig(), null, [
				static fn (array $options): array => self::answer($options, $status, 'text/plain', 'pad text'),
			])->normalizeAndFetchExternalPublicPadTextOrEmpty('https://1.1.1.1/p/pad');
			$this->assertSame(['https://1.1.1.1', 'https://1.1.1.1/p/pad', $text, $unavailable], [$result['origin'], $result['pad_url'], $result['text'], $result['snapshot_unavailable']]);
		}
	}

	/**
	 * A body past the limit ends the fetch: no other address is asked for
	 * the same export. The body arrives as cURL hands it over, chunk after
	 * chunk until the sink takes less than it was given.
	 */
	public function testStopsAtABodyPastTheLimit(): void {
		$fetcher = $this->fetcher(
			$this->buildExternalEnabledConfig(),
			[['ip' => '1.1.1.1'], ['ip' => '1.0.0.1']],
			[static function (array $options): array {
				$chunk = str_repeat('x', 1 << 20);
				$stopped = false;
				for ($i = 0; $i < 64 && !$stopped; $i++) {
					$stopped = $options[CURLOPT_WRITEFUNCTION](null, $chunk) < strlen($chunk);
				}
				Assert::assertTrue($stopped, 'the sink stops the body within 64 MiB');
				return ['completed' => false, 'status' => 200, 'error' => 'Failed writing body', 'errno' => 23];
			}],
		);

		try {
			$fetcher->normalizeAndFetchExternalPublicPadText('https://pad.example/p/pad');
			$this->fail('Not refused.');
		} catch (EtherpadTooLargeException) {
			$this->assertCount(1, $fetcher->performed);
		}
	}

	/** No address answering: the transport error, with the reason the attempts' cURL errors give. */
	public function testGivesUpWhenNoAddressAnswers(): void {
		$fetcher = $this->fetcher(
			$this->buildExternalEnabledConfig(),
			[['ip' => '1.1.1.1'], ['ip' => '1.0.0.1']],
			[
				static fn (array $options): array => ['completed' => false, 'status' => 0, 'error' => 'SSL certificate problem', 'errno' => 60],
				static fn (array $options): array => ['completed' => false, 'status' => 0, 'error' => '', 'errno' => 7],
			],
		);

		try {
			$fetcher->normalizeAndFetchExternalPublicPadText('https://pad.example/p/pad');
			$this->fail('Not refused.');
		} catch (ExternalPadException $e) {
			$this->assertSame(ExternalPadException::UNTRUSTED_CERTIFICATE, $e->reason());
			$this->assertSame('Public export transport error: transport via 1.1.1.1: SSL certificate problem; transport via 1.0.0.1: unknown error', $e->getMessage());
		}
	}

	/** An answer is checked before it is taken: a redirect, or a type the format did not ask for, is refused. */
	public function testChecksAnAnswerBeforeTakingIt(): void {
		foreach ([
			'a redirect' => [302, 'text/plain', ExternalPadException::REDIRECTED],
			'an HTML page for the text export' => [200, 'text/html', ExternalPadException::UNEXPECTED_ANSWER],
		] as $case => [$status, $contentType, $reason]) {
			$fetcher = $this->fetcher($this->buildExternalEnabledConfig(), null, [
				static fn (array $options): array => self::answer($options, $status, $contentType, 'Please sign in'),
			]);
			try {
				$fetcher->normalizeAndFetchExternalPublicPadText('https://1.1.1.1/p/pad');
				$this->fail($case . ': taken');
			} catch (ExternalPadException $e) {
				$this->assertSame($reason, $e->reason(), $case);
			}
		}
	}

	/**
	 * The one method that runs cURL; the tests of the fetch answer in its
	 * place. Here it runs against what needs no network: this file, and a
	 * local port nothing listens on.
	 */
	public function testPerformsARequestThroughCurl(): void {
		$sink = new ExportAttemptSink(1 << 20);
		$this->assertSame(
			['completed' => true, 'status' => 0, 'error' => '', 'errno' => 0],
			$this->performedForReal('file://' . __FILE__, [CURLOPT_WRITEFUNCTION => $sink->chunk(...)]),
		);
		$this->assertSame(file_get_contents(__FILE__), $sink->body());

		// No proxy from the environment: the port asked is this machine's.
		// A time limit too, for a host that drops the packets rather than refuse them.
		$refused = $this->performedForReal('https://127.0.0.1:1/', [CURLOPT_PROXY => '', CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_WRITEFUNCTION => $sink->chunk(...)]);
		$this->assertSame([false, 0, 7], [$refused['completed'], $refused['status'], $refused['errno']]);
		$this->assertStringContainsString('127.0.0.1', $refused['error']);
	}

	/**
	 * The fetch runs for real, through a stand-in proxy on this machine that
	 * notes what it is asked and refuses the tunnel.
	 *
	 * @param list<array<string, string>> $records
	 */
	#[DataProvider('namesAProxyMustNotBeAskedFor')]
	public function testAProxyFromTheEnvironmentIsAskedForTheCheckedAddress(string $url, array $records, string $asked): void {
		// Keeps startup warnings off stdout, which carries the stand-in's port.
		$proxy = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/../Support/connect-recorder.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($proxy);
		try {
			$port = (int)fgets($pipes[1]);
			if ($port === 0) {
				$this->fail('The stand-in proxy did not start: ' . stream_get_contents($pipes[2]));
			}
			$environment = ['https_proxy' => 'http://127.0.0.1:' . $port, 'HTTPS_PROXY' => null, 'no_proxy' => null, 'NO_PROXY' => null];
			$before = array_map(static fn (string $name): string|false => getenv($name), array_combine(array_keys($environment), array_keys($environment)));
			foreach ($environment as $name => $value) {
				putenv($value === null ? $name : $name . '=' . $value);
			}
			try {
				$fetcher = new class($this->buildExternalEnabledConfig(), new FixedClock(), $records) extends ExternalPadExportFetcher {
					/** @param list<array<string, string>> $records */
					public function __construct(IConfig $config, FixedClock $clock, private array $records) {
						parent::__construct($config, $clock);
					}

					protected function lookUp(string $host): array {
						return $this->records;
					}
				};
				try {
					$fetcher->normalizeAndFetchExternalPublicPadText($url);
					$this->fail('The stand-in proxy refuses every tunnel.');
				} catch (ExternalPadException $e) {
					// What the API reference tells the user of a proxy that refuses.
					$this->assertSame(ExternalPadException::UNREACHABLE, $e->reason());
				}
			} finally {
				foreach ($before as $name => $value) {
					putenv($value === false ? $name : $name . '=' . $value);
				}
			}

			$this->assertSame($asked, trim((string)fgets($pipes[1])));
		} finally {
			// After a failure the stand-in would wait out its accept timeout.
			foreach ($pipes as $pipe) {
				fclose($pipe);
			}
			proc_terminate($proxy);
			proc_close($proxy);
		}
	}

	/** @return array<string, array{string, list<array<string, string>>, string}> */
	public static function namesAProxyMustNotBeAskedFor(): array {
		return [
			'a name as written' => ['https://pad.example/p/pad', [['ip' => '1.1.1.1']], 'CONNECT 1.1.1.1:443 HTTP/1.1'],
			'an international name, which cURL turns into punycode' => ['https://bücher.example/p/pad', [['ip' => '1.1.1.1']], 'CONNECT 1.1.1.1:443 HTTP/1.1'],
			'a percent-encoded name, which cURL decodes' => ['https://%70ad.example/p/pad', [['ip' => '1.1.1.1']], 'CONNECT 1.1.1.1:443 HTTP/1.1'],
			'an IPv6 address, on a port of its own' => ['https://pad.example:8443/p/pad', [['ipv6' => '2606:4700::1111']], 'CONNECT [2606:4700::1111]:8443 HTTP/1.1'],
			'an IPv6 address in the URL' => ['https://[2606:4700::1111]/p/pad', [], 'CONNECT [2606:4700::1111]:443 HTTP/1.1'],
		];
	}

	/** 99 is no HTTP version cURL knows: the request never starts. */
	public function testRunsNoRequestWithAnOptionCurlRefuses(): void {
		$this->expectException(ExternalPadException::class);
		$this->expectExceptionMessage('Could not set up external export request.');

		$this->performedForReal('https://127.0.0.1:1/', [CURLOPT_HTTP_VERSION => 99]);
	}

	/**
	 * @param array<int, mixed> $options
	 * @return array{completed: bool, status: int, error: string, errno: int}
	 */
	private function performedForReal(string $url, array $options): array {
		$fetcher = new class($this->buildExternalEnabledConfig(), new FixedClock()) extends ExternalPadExportFetcher {
			/**
			 * @param array<int, mixed> $options
			 * @return array{completed: bool, status: int, error: string, errno: int}
			 */
			public function performNow(string $url, array $options): array {
				return $this->perform($url, $options);
			}
		};
		return $fetcher->performNow($url, $options);
	}

	/**
	 * An answer as cURL delivers it: the Content-Type header line, then the
	 * body, through the attempt's callbacks.
	 *
	 * @param array<int, mixed> $options
	 * @return array{completed: bool, status: int, error: string, errno: int}
	 */
	private static function answer(array $options, int $status, string $contentType, string $body): array {
		$options[CURLOPT_HEADERFUNCTION](null, 'HTTP/1.1 ' . $status . "\r\n");
		$options[CURLOPT_HEADERFUNCTION](null, 'Content-Type: ' . $contentType . "\r\n");
		$options[CURLOPT_WRITEFUNCTION](null, $body);
		return ['completed' => true, 'status' => $status, 'error' => '', 'errno' => 0];
	}

	/**
	 * The rule that keeps an error page from being read as pad content.
	 *
	 * Widening it for the HTML export must not widen it for the text one:
	 * a foreign server answering a text export with an HTML error page and
	 * a 200 is the case it was written for.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('contentTypeCases')]
	public function testContentTypeIsAcceptedPerExportFormat(string $format, string $contentType, bool $accepted): void {
		$assert = new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertAllowedExternalExportContentType');
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		if (!$accepted) {
			$this->expectException(ExternalPadException::class);
		}
		$assert->invoke($fetcher, $contentType, $format);

		if ($accepted) {
			$this->addToAssertionCount(1);
		}
	}

	/**
	 * Redirects are not followed, and their bodies are not read either.
	 * A "Please sign in" page behind a 302 used to arrive as pad content
	 * once the HTML export stopped rejecting it on content type.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider('statusCases')]
	public function testOnlyASuccessfulExportStatusIsAccepted(int $status, ?string $expectedException): void {
		$assert = new \ReflectionMethod(ExternalPadExportFetcher::class, 'assertSuccessfulExportStatus');
		$fetcher = $this->fetcher($this->buildExternalEnabledConfig());

		if ($expectedException !== null) {
			$this->expectException($expectedException);
		}
		$assert->invoke($fetcher, $status);

		if ($expectedException === null) {
			$this->addToAssertionCount(1);
		}
	}

	/** @return array<string,array{0:int,1:?string}> */
	public static function statusCases(): array {
		return [
			'200 is the export' => [200, null],
			'204 is still a success' => [204, null],
			'299 is the last success' => [299, null],
			'301 is a redirect, not content' => [301, ExternalPadException::class],
			'302 is a redirect, not content' => [302, ExternalPadException::class],
			'307 is a redirect, not content' => [307, ExternalPadException::class],
			'401 is a login wall' => [401, ExternalPadException::class],
			'404 says the pad is not exportable' => [404, ExternalPadExportNotFoundException::class],
			'500 is the far side failing' => [500, ExternalPadException::class],
		];
	}

	/** @return array<string,array{0:string,1:string,2:bool}> */
	public static function contentTypeCases(): array {
		return [
			'text export keeps refusing html' => ['txt', 'text/html; charset=utf-8', false],
			'text export takes plain text' => ['txt', 'text/plain; charset=utf-8', true],
			'text export takes a byte stream' => ['txt', 'application/octet-stream', true],
			'html export takes html' => ['html', 'text/html; charset=utf-8', true],
			'html export takes xhtml' => ['html', 'application/xhtml+xml', true],
			'a type in capitals' => ['txt', 'Text/Plain', true],
			'html with a space before the parameters is still html' => ['txt', 'text/html ; charset=utf-8', false],
			'html export takes html with a space before the parameters' => ['html', 'text/html ; charset=utf-8', true],
			'html export refuses plain text' => ['html', 'text/plain', false],
			'html export refuses json' => ['html', 'application/json', false],
			'html export refuses a byte stream' => ['html', 'application/octet-stream', false],
			'a missing header is refused either way' => ['html', '', false],
		];
	}

	/**
	 * What a pad link may be, against the allowlist as an admin may have
	 * stored it: the origin and the canonical link the fetcher goes on with.
	 *
	 * @param ?list<array<string, string>> $records
	 */
	#[DataProvider('acceptedLinks')]
	public function testAcceptsAndCanonicalizesALink(string $url, string $allowlist, ?array $records, string $origin, string $padUrl): void {
		$result = $this->fetcher($this->buildExternalEnabledConfig($allowlist), $records)->normalizeAndValidateExternalPublicPadUrl($url);

		$this->assertSame([$origin, $padUrl], [$result['origin'], $result['pad_url']]);
	}

	/** @return array<string, array{string, string, ?list<array<string, string>>, string, string}> */
	public static function acceptedLinks(): array {
		$public = [['ip' => '1.1.1.1'], ['ipv6' => '2606:4700::1111']];
		return [
			'spaces around it' => [' https://1.1.1.1/p/pad ', '', null, 'https://1.1.1.1', 'https://1.1.1.1/p/pad'],
			'scheme and host in capitals' => ['HTTPS://PAD.EXAMPLE/p/pad', '', $public, 'https://pad.example', 'https://pad.example/p/pad'],
			'a name with public addresses only' => ['https://pad.example/p/pad', '', $public, 'https://pad.example', 'https://pad.example/p/pad'],
			'a base path' => ['https://1.1.1.1/etherpad/p/pad', '', null, 'https://1.1.1.1', 'https://1.1.1.1/etherpad/p/pad'],
			'a base path ending in a slash' => ['https://1.1.1.1/etherpad//p/pad', '', null, 'https://1.1.1.1', 'https://1.1.1.1/etherpad/p/pad'],
			'the highest port, allowlisted' => ['https://1.1.1.1:65535/p/pad', 'https://1.1.1.1:65535', null, 'https://1.1.1.1:65535', 'https://1.1.1.1:65535/p/pad'],
			'an origin allowlisted without its port' => ['https://1.1.1.1/p/pad', 'https://1.1.1.1', null, 'https://1.1.1.1', 'https://1.1.1.1/p/pad'],
			'an origin allowlisted in capitals' => ['https://1.1.1.1:8443/p/pad', 'HTTPS://1.1.1.1:8443', null, 'https://1.1.1.1:8443', 'https://1.1.1.1:8443/p/pad'],
			'an allowlisted host' => ['https://pad.example/p/pad', 'pad.example', $public, 'https://pad.example', 'https://pad.example/p/pad'],
			'a host allowlisted in capitals with a dot' => ['https://pad.example/p/pad', 'PAD.EXAMPLE.', $public, 'https://pad.example', 'https://pad.example/p/pad'],
			'a host after an empty entry, a malformed and another origin' => ['https://pad.example/p/pad', ',https://host:bad https://other.example pad.example', $public, 'https://pad.example', 'https://pad.example/p/pad'],
			'an allowlist of spaces, which is none' => ['https://1.1.1.1/p/pad', '   ', null, 'https://1.1.1.1', 'https://1.1.1.1/p/pad'],
		];
	}

	/** @param ?list<array<string, string>> $records */
	#[DataProvider('refusedLinks')]
	public function testRefusesALinkForItsReason(string $url, string $allowlist, ?array $records, string $reason): void {
		try {
			$this->fetcher($this->buildExternalEnabledConfig($allowlist), $records)->normalizeAndValidateExternalPublicPadUrl($url);
			$this->fail('Not refused.');
		} catch (ExternalPadException $e) {
			$this->assertSame($reason, $e->reason());
		}
	}

	/** @return array<string, array{string, string, ?list<array<string, string>>, string}> */
	public static function refusedLinks(): array {
		return [
			'a user without a password' => ['https://user@1.1.1.1/p/pad', '', null, ExternalPadException::CREDENTIALS_IN_URL],
			'port 0' => ['https://1.1.1.1:0/p/pad', '', null, ExternalPadException::INVALID_URL],
			'more after the pad name' => ['https://1.1.1.1/p/pad/export', '', null, ExternalPadException::NOT_A_PAD_URL],
			'an origin allowlisted as http' => ['https://1.1.1.1:8443/p/pad', 'http://1.1.1.1:8443', null, ExternalPadException::NOT_ALLOWED],
			'a host not on the allowlist' => ['https://pad.example/p/pad', 'other.example', null, ExternalPadException::NOT_ALLOWED],
			'an IPv4 address in brackets' => ['https://[1.2.3.4]/p/pad', '', null, ExternalPadException::INVALID_URL],
			'an IPv6 address with a zone' => ['https://[fe80::1%25eth0]/p/pad', '', null, ExternalPadException::INVALID_URL],
		];
	}

	/**
	 * The addresses a request may go to: each one once, from the records
	 * that carry one as text; an address given as the host is its own.
	 */
	public function testResolvesTheAddressesARequestMayGoTo(): void {
		$resolve = new \ReflectionMethod(ExternalPadExportFetcher::class, 'resolveAndValidateExternalHost');
		$records = [['ip' => '1.1.1.1'], ['ip' => '1.1.1.1'], ['ip' => ''], ['ip' => 5], ['ipv6' => '2606:4700::1111']];

		$this->assertSame(['1.1.1.1', '2606:4700::1111'], $resolve->invoke($this->fetcher($this->buildExternalEnabledConfig(), $records), 'pad.example', 'https://pad.example'));
		$this->assertSame(['1.1.1.1'], $resolve->invoke($this->fetcher($this->buildExternalEnabledConfig()), '1.1.1.1', 'https://1.1.1.1'));
	}

	/**
	 * The fetcher, with name lookups and requests answered by the test, so
	 * that no test depends on the network: $records stands in for every
	 * lookup, and each request takes the next of $answers, which may feed
	 * the attempt's callbacks as cURL would, and is kept in $performed. A
	 * lookup or request the test gave no answer for fails it.
	 *
	 * @param ?list<array<array-key, mixed>> $records
	 * @param list<\Closure(array<int, mixed>): array{completed: bool, status: int, error: string, errno: int}> $answers
	 */
	private function fetcher(IConfig $config, ?array $records = null, array $answers = [], ?FixedClock $clock = null): ExternalPadExportFetcher {
		return new class($config, $clock ?? new FixedClock(), $records, $answers) extends ExternalPadExportFetcher {
			/** @var list<array{string, array<int, mixed>}> */
			public array $performed = [];

			/**
			 * @param ?list<array<array-key, mixed>> $records
			 * @param list<\Closure(array<int, mixed>): array{completed: bool, status: int, error: string, errno: int}> $answers
			 */
			public function __construct(IConfig $config, FixedClock $clock, private ?array $records, private array $answers) {
				parent::__construct($config, $clock);
			}

			protected function lookUp(string $host): array {
				if ($this->records === null) {
					throw new \LogicException('A lookup of ' . $host . ' the test gave no answer for.');
				}
				return $this->records;
			}

			protected function perform(string $url, array $options): array {
				$answer = array_shift($this->answers);
				if ($answer === null) {
					throw new \LogicException('A request to ' . $url . ' the test gave no answer for.');
				}
				$this->performed[] = [$url, $options];
				return $answer($options);
			}
		};
	}

	private function buildExternalEnabledConfig(string $externalPadAllowlist = ''): IConfig {
		$config = $this->createMock(IConfig::class);
		$config->method('getAppValue')->willReturnCallback(
			static function (string $appName, string $key, string $default = '') use ($externalPadAllowlist): string {
				if ($appName !== 'etherpad_nextcloud') {
					return $default;
				}
				if ($key === 'allow_external_pads') {
					return 'yes';
				}
				if ($key === 'external_pad_allowlist') {
					return $externalPadAllowlist;
				}
				return $default;
			}
		);

		return $config;
	}
}
