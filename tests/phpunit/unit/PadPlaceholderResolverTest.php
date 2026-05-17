<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\PadPlaceholderResolver;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

class PadPlaceholderResolverTest extends TestCase {
	private const FIXED_NOW = 1778976000; // 2026-05-17 (Sunday) 00:00 UTC

	public function testReplacesDateWithDefaultFormat(): void {
		$result = $this->resolver()->apply('Heute ist {{date}}.', $this->user('Jacob Bühler'));
		$this->assertSame('Heute ist 2026-05-17.', $result);
	}

	public function testReplacesDateWithCustomFormat(): void {
		$result = $this->resolver()->apply('Stempel: {{date|d.m.Y}}', $this->user('Jacob Bühler'));
		$this->assertSame('Stempel: 17.05.2026', $result);
	}

	public function testReplacesDateRelativeNextMonday(): void {
		$result = $this->resolver()->apply('Sitzung {{date:next monday|d.m.Y}}', $this->user('Jacob Bühler'));
		// 2026-05-17 is Sunday → next monday is 2026-05-18
		$this->assertSame('Sitzung 18.05.2026', $result);
	}

	public function testReplacesDateOffset(): void {
		$result = $this->resolver()->apply('In sieben Tagen: {{date:+7 days}}', $this->user('Jacob Bühler'));
		$this->assertSame('In sieben Tagen: 2026-05-24', $result);
	}

	public function testReplacesUserDisplayName(): void {
		$result = $this->resolver()->apply('Autor: {{user}}', $this->user('Jacob Bühler'));
		$this->assertSame('Autor: Jacob Bühler', $result);
	}

	public function testReplacesUserUid(): void {
		$result = $this->resolver()->apply('UID: {{user.uid}}', $this->user('Jacob Bühler', 'jaggob'));
		$this->assertSame('UID: jaggob', $result);
	}

	public function testLeavesUnknownDirectivesAsLiteral(): void {
		$result = $this->resolver()->apply('Weather: {{forecast}}', $this->user('x'));
		$this->assertSame('Weather: {{forecast}}', $result);
	}

	public function testLeavesUnknownUserPropertyAsLiteral(): void {
		$result = $this->resolver()->apply('Whoami: {{user.phone}}', $this->user('x'));
		$this->assertSame('Whoami: {{user.phone}}', $result);
	}

	public function testLeavesUnparseableDateExpressionAsLiteral(): void {
		$result = $this->resolver()->apply('Datum: {{date:complete-nonsense-xyz}}', $this->user('x'));
		$this->assertSame('Datum: {{date:complete-nonsense-xyz}}', $result);
	}

	public function testReplacesMultiplePlaceholdersInOneLine(): void {
		$result = $this->resolver()->apply(
			'# Protokoll {{date:next monday|d.m.Y}} ({{user}})',
			$this->user('Jacob Bühler'),
		);
		$this->assertSame('# Protokoll 18.05.2026 (Jacob Bühler)', $result);
	}

	public function testTolerantToWhitespaceInsideBraces(): void {
		$result = $this->resolver()->apply('{{ date | Y }}', $this->user('x'));
		$this->assertSame('2026', $result);
	}

	public function testNoUserSilentlySkipsUserDirectives(): void {
		// Public-share / anonymous context: user is null. Leave the directive
		// alone rather than render the empty string.
		$result = $this->resolver()->apply('Autor: {{user}}, Datum: {{date}}', null);
		$this->assertSame('Autor: {{user}}, Datum: 2026-05-17', $result);
	}

	private function resolver(): PadPlaceholderResolver {
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getTime')->willReturn(self::FIXED_NOW);
		return new PadPlaceholderResolver($time);
	}

	private function user(string $displayName, string $uid = 'user1'): IUser {
		$user = $this->createMock(IUser::class);
		$user->method('getDisplayName')->willReturn($displayName);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
