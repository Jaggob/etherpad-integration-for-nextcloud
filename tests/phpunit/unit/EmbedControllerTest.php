<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OC\Security\CSRF\CsrfToken;
use OC\Security\CSRF\CsrfTokenManager;
use OCA\EtherpadNextcloud\Controller\EmbedController;
use OCA\EtherpadNextcloud\Service\AppConfigService;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCP\Files\File;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class EmbedControllerTest extends TestCase {
	public function testShowByIdUsesInjectedCsrfTokenAndTrustedOriginsInTemplateData(): void {
		$request = $this->createMock(IRequest::class);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->willReturnMap([
			['etherpad_nextcloud.pad.openById', [], '/open-by-id'],
			['etherpad_nextcloud.pad.initializeById', ['fileId' => '__FILE_ID__'], '/initialize/__FILE_ID__'],
		]);

		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		$appConfigService = $this->createMock(AppConfigService::class);
		$appConfigService->method('getTrustedEmbedOrigins')->willReturn(['https://portal.example.test']);

		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn('Example.pad');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeById')
			->with('alice', 42)
			->willReturn($file);

		$controller = new EmbedController(
			'etherpad_nextcloud',
			$request,
			$userSession,
			$urlGenerator,
			$l10n,
			new CsrfTokenManager(new CsrfToken('csrf-token-value')),
			$appConfigService,
			$userNodeResolver,
		);

		$response = $controller->showById(42);
		$params = $response->getParams();

		$this->assertSame('embed', $response->getTemplateName());
		$this->assertSame('blank', $response->getRenderAs());
		$this->assertSame('csrf-token-value', $params['requesttoken']);
		$this->assertSame(['https://portal.example.test'], $params['trusted_embed_origins']);
		$this->assertSame(42, $params['file_id']);
		$this->assertSame(['/open-by-id', '/initialize/__FILE_ID__'], [
			$params['open_by_id_url'],
			$params['initialize_by_id_url_template'],
		]);
	}
}
