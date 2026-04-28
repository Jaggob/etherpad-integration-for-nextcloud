<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\ViewerController;
use OCA\EtherpadNextcloud\Service\UserNodeResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\Files\File;
use OCP\Files\NotFoundException;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

class ViewerControllerTest extends TestCase {
	public function testShowPadResolvesFileByPathViaUserNodeResolver(): void {
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '']);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('files.view.index')->willReturn('/apps/files');

		$file = $this->createMock(File::class);
		$file->method('getId')->willReturn(138);

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Folder/Test.pad')
			->willReturn($file);

		$controller = new ViewerController(
			'etherpad_nextcloud',
			$request,
			$urlGenerator,
			$userSession,
			new PathNormalizer(),
			$userNodeResolver,
		);

		$response = $controller->showPad('/Folder/Test.pad');

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/apps/files/138?dir=%2FFolder&editing=false&openfile=true', $response->getRedirectURL());
	}

	public function testShowPadReturnsErrorWhenResolverCannotFindPath(): void {
		$request = $this->createConfiguredMock(IRequest::class, ['getParam' => '']);
		$user = $this->createConfiguredMock(IUser::class, ['getUID' => 'alice']);
		$userSession = $this->createConfiguredMock(IUserSession::class, ['getUser' => $user]);

		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('linkToRoute')->with('files.view.index')->willReturn('/apps/files');

		$userNodeResolver = $this->createMock(UserNodeResolver::class);
		$userNodeResolver->expects($this->once())
			->method('resolveUserFileNodeByPath')
			->with('alice', '/Missing.pad')
			->willThrowException(new NotFoundException('missing'));

		$controller = new ViewerController(
			'etherpad_nextcloud',
			$request,
			$urlGenerator,
			$userSession,
			new PathNormalizer(),
			$userNodeResolver,
		);

		$response = $controller->showPad('/Missing.pad');

		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('Cannot open selected .pad file.', $response->getParams()['error']);
	}
}
