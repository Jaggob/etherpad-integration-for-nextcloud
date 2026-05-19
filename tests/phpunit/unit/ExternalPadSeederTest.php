<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Service\EtherpadClient;
use OCA\EtherpadNextcloud\Service\ExternalPadSeeder;
use OCA\EtherpadNextcloud\Service\PadFileService;
use OCP\Files\File;
use PHPUnit\Framework\TestCase;

class ExternalPadSeederTest extends TestCase {
	public function testSeedWritesExtFrontmatterAndSnapshotIntoFile(): void {
		$fileNode = $this->createMock(File::class);
		$fileNode->expects($this->once())
			->method('putContent')
			->with('seeded-frontmatter');

		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->expects($this->once())
			->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->with('https://pad.remote.test/p/RemotePad')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => 'snapshot-body',
			]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->expects($this->once())
			->method('buildInitialDocument')
			->with(
				999,
				'ext.RemotePad',
				BindingService::ACCESS_PUBLIC,
				'',
				'https://pad.remote.test/p/RemotePad',
				[
					'pad_origin' => 'https://pad.remote.test',
					'remote_pad_id' => 'RemotePad',
				],
			)
			->willReturn('initial-doc');
		$padFileService->expects($this->once())
			->method('withExportSnapshot')
			->with('initial-doc', 'snapshot-body', '', 0, false)
			->willReturn('seeded-frontmatter');

		$result = (new ExternalPadSeeder($padFileService, $etherpadClient))
			->seed($fileNode, 999, 'https://pad.remote.test/p/RemotePad');

		$this->assertSame([
			'file_id' => 999,
			'pad_id' => 'ext.RemotePad',
			'access_mode' => BindingService::ACCESS_PUBLIC,
			'pad_url' => 'https://pad.remote.test/p/RemotePad',
		], $result);
	}

	public function testSeedSurfacesSnapshotUnavailableWarningCode(): void {
		$etherpadClient = $this->createMock(EtherpadClient::class);
		$etherpadClient->method('normalizeAndFetchExternalPublicPadTextOrEmpty')
			->willReturn([
				'pad_url' => 'https://pad.remote.test/p/RemotePad',
				'origin' => 'https://pad.remote.test',
				'pad_id' => 'RemotePad',
				'text' => '',
				'snapshot_unavailable' => true,
			]);

		$padFileService = $this->createMock(PadFileService::class);
		$padFileService->method('buildInitialDocument')->willReturn('doc');
		$padFileService->method('withExportSnapshot')->willReturn('doc');

		$result = (new ExternalPadSeeder($padFileService, $etherpadClient))
			->seed($this->createMock(File::class), 1, 'https://pad.remote.test/p/RemotePad');

		$this->assertSame('remote_export_unavailable', $result['snapshot_warning_code']);
	}
}
