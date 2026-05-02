<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\NoShareFileSelectedException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareItemUnavailableException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\PublicShareResolver;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\Constants;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\Share\Exceptions\ShareNotFound;
use OCP\Share\IManager;
use OCP\Share\IShare;
use PHPUnit\Framework\TestCase;

class PublicShareResolverTest extends TestCase {
	public function testResolveShareReturnsCachedShareWithoutManagerLookup(): void {
		$cached = $this->createMock(IShare::class);
		$manager = $this->createMock(IManager::class);
		$manager->expects($this->never())->method('getShareByToken');

		$this->assertSame($cached, (new PublicShareResolver($manager, new PathNormalizer()))->resolveShare('token', $cached));
	}

	public function testResolveShareMapsMissingToken(): void {
		$manager = $this->createMock(IManager::class);
		$manager->method('getShareByToken')->willThrowException(new ShareNotFound());

		$this->expectException(InvalidShareTokenException::class);
		$this->expectExceptionMessage('This share link is invalid or has expired.');

		(new PublicShareResolver($manager, new PathNormalizer()))->resolveShare('token');
	}

	public function testResolvePadFileReturnsSingleFileShare(): void {
		$file = $this->padFile('Shared.pad', 42);
		$share = $this->share($file, Constants::PERMISSION_READ);

		$resolved = $this->buildResolver()->resolvePadFile($share, '', 'token');

		$this->assertSame($file, $resolved->node);
		$this->assertFalse($resolved->isFolderShare);
		$this->assertSame('', $resolved->selectedRelativePath);
		$this->assertTrue($resolved->readOnly);
		$this->assertSame('Shared.pad', $resolved->name);
	}

	public function testResolvePadFileReturnsWritableFolderSelection(): void {
		$file = $this->padFile('Shared.pad', 42);
		$folder = $this->createMock(Folder::class);
		$folder->expects($this->once())->method('get')->with('Folder/Shared.pad')->willReturn($file);
		$share = $this->share($folder, Constants::PERMISSION_READ | Constants::PERMISSION_UPDATE);

		$resolved = $this->buildResolver()->resolvePadFile($share, '/Folder/Shared.pad', 'token');

		$this->assertSame($file, $resolved->node);
		$this->assertTrue($resolved->isFolderShare);
		$this->assertSame('Folder/Shared.pad', $resolved->selectedRelativePath);
		$this->assertFalse($resolved->readOnly);
	}

	public function testResolvePadFileRejectsShareWithoutReadPermission(): void {
		$share = $this->share($this->padFile('Shared.pad', 42), Constants::PERMISSION_UPDATE);

		$this->expectException(ShareReadForbiddenException::class);
		$this->expectExceptionMessage('This share link does not allow reading files.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileMapsMissingShareNode(): void {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn(Constants::PERMISSION_READ);
		$share->method('getNode')->willThrowException(new NotFoundException('missing'));

		$this->expectException(ShareItemUnavailableException::class);
		$this->expectExceptionMessage('This shared item is no longer available.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileRejectsFolderShareWithoutSelectedFile(): void {
		$share = $this->share($this->createMock(Folder::class), Constants::PERMISSION_READ);

		$this->expectException(NoShareFileSelectedException::class);
		$this->expectExceptionMessage('No .pad file selected.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	public function testResolvePadFileRejectsInvalidFolderPath(): void {
		$share = $this->share($this->createMock(Folder::class), Constants::PERMISSION_READ);

		$this->expectException(InvalidShareFilePathException::class);
		$this->expectExceptionMessage('Invalid file path.');

		$this->buildResolver()->resolvePadFile($share, '../Shared.pad', 'token');
	}

	public function testResolvePadFileMapsMissingFolderFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willThrowException(new NotFoundException('missing'));
		$share = $this->share($folder, Constants::PERMISSION_READ);

		$this->expectException(ShareFileNotInShareException::class);
		$this->expectExceptionMessage('The selected file does not exist in this share.');

		$this->buildResolver()->resolvePadFile($share, 'Missing.pad', 'token');
	}

	public function testResolvePadFileRejectsFolderSelectionThatIsNotAFile(): void {
		$folder = $this->createMock(Folder::class);
		$folder->method('get')->willReturn($this->createMock(Folder::class));
		$share = $this->share($folder, Constants::PERMISSION_READ);

		$this->expectException(ShareFileNotInShareException::class);
		$this->expectExceptionMessage('The selected item is not a file.');

		$this->buildResolver()->resolvePadFile($share, 'NestedFolder', 'token');
	}

	public function testResolvePadFileRejectsNonPadFile(): void {
		$share = $this->share($this->padFile('Text.txt', 42), Constants::PERMISSION_READ);

		$this->expectException(NotAPadFileException::class);
		$this->expectExceptionMessage('The selected file is not a .pad document.');

		$this->buildResolver()->resolvePadFile($share, '', 'token');
	}

	private function buildResolver(?IManager $manager = null): PublicShareResolver {
		return new PublicShareResolver($manager ?? $this->createMock(IManager::class), new PathNormalizer());
	}

	private function padFile(string $name, int $id): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn($id);
		return $file;
	}

	private function share(File|Folder $node, int $permissions): IShare {
		$share = $this->createMock(IShare::class);
		$share->method('getPermissions')->willReturn($permissions);
		$share->method('getNode')->willReturn($node);
		return $share;
	}
}
