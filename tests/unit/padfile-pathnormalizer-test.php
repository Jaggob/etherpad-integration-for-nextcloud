#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/../../lib/Exception/PadFileFormatException.php';
require_once __DIR__ . '/../../lib/Service/BindingService.php';
require_once __DIR__ . '/../../lib/Service/PadFileService.php';
require_once __DIR__ . '/../../lib/Util/PathNormalizer.php';

use OCA\EtherpadNextcloud\Service\PadFileService;
use OCA\EtherpadNextcloud\Service\BindingService;
use OCA\EtherpadNextcloud\Util\PathNormalizer;

final class TinyTest {
	private int $failures = 0;

	public function assertTrue(bool $condition, string $message): void {
		if (!$condition) {
			$this->failures++;
			echo "FAIL: {$message}\n";
		}
	}

	public function assertSame(mixed $expected, mixed $actual, string $message): void {
		if ($expected !== $actual) {
			$this->failures++;
			echo "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual:   " . var_export($actual, true) . "\n";
		}
	}

	public function assertThrows(callable $fn, string $message): void {
		try {
			$fn();
			$this->failures++;
			echo "FAIL: {$message} (no exception)\n";
		} catch (Throwable) {
			// expected
		}
	}

	public function finish(): int {
		if ($this->failures === 0) {
			echo "All tests passed.\n";
			return 0;
		}
		echo "{$this->failures} test(s) failed.\n";
		return 1;
	}
}

$test = new TinyTest();
$padFileService = new PadFileService();
$pathNormalizer = new PathNormalizer();

$initial = $padFileService->buildInitialDocument(42, 'g.abcdefghijklmnop$demo', BindingService::ACCESS_PROTECTED, 'snapshot');
$parsed = $padFileService->parsePadFile($initial);

$test->assertSame('etherpad-nextcloud/1', $parsed['frontmatter']['format'], 'format must match v1 schema');
$test->assertSame(42, $parsed['frontmatter']['file_id'], 'file_id must roundtrip');
$test->assertSame('g.abcdefghijklmnop$demo', $parsed['frontmatter']['pad_id'], 'pad_id must roundtrip');
$test->assertSame('snapshot', $parsed['body'], 'snapshot body must roundtrip');

$updated = $padFileService->withStateAndSnapshot(
	$initial,
	BindingService::STATE_TRASHED,
	'new snapshot',
	null,
	1700000000,
);
$updatedParsed = $padFileService->parsePadFile($updated);
$test->assertSame(BindingService::STATE_TRASHED, $updatedParsed['frontmatter']['state'], 'state must update');
$test->assertSame('new snapshot', $padFileService->getTextSnapshotForRestore($updated), 'body text snapshot must update');

$withExport = $padFileService->withExportSnapshot(
	$initial,
	"line-a\nline-b",
	'<p>line-a</p><p>line-b</p>',
	7,
);
$withExportParsed = $padFileService->parsePadFile($withExport);
$test->assertSame(7, $withExportParsed['frontmatter']['snapshot_rev'], 'snapshot_rev must be stored');
$test->assertSame("line-a\nline-b", $padFileService->getTextSnapshotForRestore($withExport), 'text snapshot must roundtrip');
$test->assertSame('<p>line-a</p><p>line-b</p>', $padFileService->getHtmlSnapshotForRestore($withExport), 'html snapshot must roundtrip');

// Important product decision: no archive semantics in plugin snapshot.
// If Etherpad content is empty, .pad snapshot must also become empty.
$withEmptyExport = $padFileService->withExportSnapshot(
	$withExport,
	'',
	'',
	8,
);
$test->assertSame('', $padFileService->getTextSnapshotForRestore($withEmptyExport), 'empty text export must overwrite previous snapshot');
$test->assertSame('', $padFileService->getHtmlSnapshotForRestore($withEmptyExport), 'empty html export must overwrite previous snapshot');

$legacy = "[InternetShortcut]\nURL=https://pad.portal.fzs.de/p/ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca\n";
$legacyParsed = $padFileService->parseLegacyOwnpadShortcut($legacy);
$test->assertTrue(is_array($legacyParsed), 'legacy ownpad shortcut must be detected');
$test->assertSame('https://pad.portal.fzs.de/p/ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca', (string)$legacyParsed['url'], 'legacy ownpad URL must roundtrip');
$test->assertSame('ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca', (string)$legacyParsed['pad_id'], 'legacy ownpad pad_id must be extracted');
$test->assertSame(
	BindingService::ACCESS_PUBLIC,
	$padFileService->inferAccessModeFromPadId('ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca'),
	'non-group pad ids must default to public access mode'
);
$test->assertSame(
	BindingService::ACCESS_PROTECTED,
	$padFileService->inferAccessModeFromPadId('g.TmDeyA334sIq2LQh$new-pad-8-uliir60u4h'),
	'group pad ids must map to protected access mode'
);
$legacyProtected = "[InternetShortcut]\nURL=https://pad.portal.fzs.de/p/g.TmDeyA334sIq2LQh\$new-pad-8-uliir60u4h\n";
$legacyProtectedParsed = $padFileService->parseLegacyOwnpadShortcut($legacyProtected);
$test->assertTrue(is_array($legacyProtectedParsed), 'legacy protected ownpad shortcut must be parsed for later policy checks');

$withPadUrl = $padFileService->buildInitialDocument(
	50,
	'ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca',
	BindingService::ACCESS_PUBLIC,
	'',
	'https://pad.portal.fzs.de/p/ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca',
);
$withPadUrlParsed = $padFileService->parsePadFile($withPadUrl);
$test->assertSame(
	'https://pad.portal.fzs.de/p/ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca',
	(string)$withPadUrlParsed['frontmatter']['pad_url'],
	'pad_url must be stored in frontmatter'
);
$quotedPadUrl = 'https://pad.example.org/p/say-"hello"-path\\with\\slashes';
$quoted = $padFileService->buildInitialDocument(
	51,
	'ncckmrb1konwdtj6ywfnnr2f2udv8rgr87puljd6zg7wca',
	BindingService::ACCESS_PUBLIC,
	'',
	$quotedPadUrl,
);
$quotedParsed = $padFileService->parsePadFile($quoted);
$test->assertSame(
	$quotedPadUrl,
	(string)$quotedParsed['frontmatter']['pad_url'],
	'quoted and backslash characters must roundtrip in string scalar frontmatter'
);

$test->assertThrows(
	fn () => $padFileService->parsePadFile("not-a-frontmatter"),
	'parsePadFile must reject invalid content',
);
$test->assertThrows(
	fn () => $padFileService->parsePadFile("---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id:\n  child: x\naccess_mode: \"protected\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n"),
	'parsePadFile must reject malformed nested YAML for scalar keys',
);
$test->assertThrows(
	fn () => $padFileService->parsePadFile("---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"protected\"\nstate: \"active\"\n- broken\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\n---\n"),
	'parsePadFile must reject invalid YAML lines',
);
$test->assertThrows(
	fn () => $padFileService->parsePadFile("---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"protected\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: -2\n---\n"),
	'parsePadFile must reject invalid snapshot_rev below -1',
);
$test->assertThrows(
	fn () => $padFileService->parsePadFile("---\nformat: \"etherpad-nextcloud/1\"\nfile_id: 1\npad_id: \"demo\"\naccess_mode: \"protected\"\nstate: \"active\"\ndeleted_at: null\ncreated_at: \"2026-03-06T00:00:00+00:00\"\nupdated_at: \"2026-03-06T00:00:00+00:00\"\nsnapshot_rev: 0\npad_url: \"ftp://example.org/p/demo\"\n---\n"),
	'parsePadFile must reject non-http(s) pad_url values',
);

$normalizedViewer = $pathNormalizer->normalizeViewerFilePath('https://cloud.example/remote.php/dav/files/jacob/Apps/Test/demo.pad');
$test->assertSame('/Apps/Test/demo.pad', $normalizedViewer, 'viewer path must normalize DAV URL');

$test->assertThrows(
	fn () => $pathNormalizer->normalizeViewerFilePath('/Apps/../secret.pad'),
	'viewer path must reject traversal',
);

$normalizedPublic = $pathNormalizer->normalizePublicShareFilePath(
	'https://cloud.example/public.php/dav/files/token123/folder/demo.pad',
	'token123',
);
$test->assertSame('folder/demo.pad', $normalizedPublic, 'public share path must normalize token URL');

$test->assertThrows(
	fn () => $pathNormalizer->normalizePublicShareFilePath('https://cloud.example/public.php/dav/files/token999/folder/demo.pad', 'token123'),
	'public share path must reject token mismatch',
);

exit($test->finish());
