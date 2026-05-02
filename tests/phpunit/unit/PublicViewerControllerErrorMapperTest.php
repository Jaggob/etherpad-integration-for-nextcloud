<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Controller\PublicViewerControllerErrorMapper;
use OCA\EtherpadNextcloud\Exception\EtherpadClientException;
use OCA\EtherpadNextcloud\Exception\InvalidShareFilePathException;
use OCA\EtherpadNextcloud\Exception\InvalidShareTokenException;
use OCA\EtherpadNextcloud\Exception\MissingBindingException;
use OCA\EtherpadNextcloud\Exception\NotAPadFileException;
use OCA\EtherpadNextcloud\Exception\PadFileFormatException;
use OCA\EtherpadNextcloud\Exception\ShareFileNotInShareException;
use OCA\EtherpadNextcloud\Exception\ShareReadForbiddenException;
use OCA\EtherpadNextcloud\Service\PublicShareUrlBuilder;
use OCA\EtherpadNextcloud\Util\PathNormalizer;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\TestCase;

class PublicViewerControllerErrorMapperTest extends TestCase {
	public function testRunForDataReturnsSuccessResponse(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => ['ok' => true],
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertTrue((bool)$response->getData()['ok']);
	}

	public function testRunForDataMapsInvalidShareToNotFound(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new InvalidShareTokenException('This share link is invalid or has expired.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame('This share link is invalid or has expired.', $response->getData()['message']);
	}

	public function testRunForDataMapsReadForbidden(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new ShareReadForbiddenException('This share link does not allow reading files.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
	}

	public function testRunForDataMapsInvalidFilePath(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new InvalidShareFilePathException('Invalid file path.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Invalid file path.', $response->getData()['message']);
	}

	public function testRunForDataMapsMissingFolderFile(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new ShareFileNotInShareException('The selected file does not exist in this share.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
	}

	public function testRunForDataMapsNotPadFile(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new NotAPadFileException('The selected file is not a .pad document.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('The selected file is not a .pad document.', $response->getData()['message']);
	}

	public function testRunForDataMapsMissingFrontmatterMessage(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new PadFileFormatException('Missing YAML frontmatter.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('The selected .pad file is missing required metadata.', $response->getData()['message']);
	}

	public function testRunForDataMapsMissingBindingMessageForPublicAudience(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new MissingBindingException('No binding exists for this file.'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame(
			'The selected .pad file is a copied file without an active pad binding. Please open the original shared .pad file.',
			$response->getData()['message']
		);
	}

	public function testRunForDataMapsEtherpadFailure(): void {
		$response = $this->buildMapper()->runForData(
			static fn(): array => throw new EtherpadClientException('down'),
			static fn(array $result): DataResponse => new DataResponse($result),
		);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('Etherpad is currently unavailable for this shared pad.', $response->getData()['message']);
	}

	public function testRunForTemplateReturnsRedirectOnSuccess(): void {
		$response = $this->buildMapper('/nc')->runForTemplate(
			static fn(): string => '/nc/s/token?dir=%2F',
			static fn(string $target): RedirectResponse => new RedirectResponse($target),
			'etherpad_nextcloud',
			'token',
		);

		$this->assertInstanceOf(RedirectResponse::class, $response);
		$this->assertSame('/nc/s/token?dir=%2F', $response->getRedirectURL());
	}

	public function testRunForTemplateReturnsNoViewerTemplateOnError(): void {
		$response = $this->buildMapper('/nc')->runForTemplate(
			static fn(): string => throw new NotAPadFileException('The selected file is not a .pad document.'),
			static fn(string $target): RedirectResponse => new RedirectResponse($target),
			'etherpad_nextcloud',
			'token',
		);

		$this->assertInstanceOf(TemplateResponse::class, $response);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('noviewer', $response->getTemplateName());
		$this->assertSame('The selected file is not a .pad document.', $response->getParams()['error']);
		$this->assertSame('/nc/s/token', $response->getParams()['back_url']);
		$this->assertSame('Back to shared files', $response->getParams()['back_label']);
	}

	private function buildMapper(string $webroot = ''): PublicViewerControllerErrorMapper {
		$urlGenerator = $this->createMock(IURLGenerator::class);
		$urlGenerator->method('getWebroot')->willReturn($webroot);
		return new PublicViewerControllerErrorMapper(new PublicShareUrlBuilder($urlGenerator, new PathNormalizer()));
	}
}
