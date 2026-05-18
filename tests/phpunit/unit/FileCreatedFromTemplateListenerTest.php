<?php

declare(strict_types=1);

namespace OCA\EtherpadNextcloud\Tests\Unit;

use OCA\EtherpadNextcloud\Listeners\FileCreatedFromTemplateListener;
use OCA\EtherpadNextcloud\Service\PadCreationService;
use OCP\EventDispatcher\Event;
use OCP\Files\File;
use OCP\Files\Template\FileCreatedFromTemplateEvent;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The listener is now a thin wrapper that delegates the template
 * materialization pipeline to `PadCreationService::materializeTemplateInto`.
 * Detailed behavior of that pipeline (external template handling, binding
 * cleanup, etc.) is exercised in `PadCreationServiceTest`. The cases here
 * focus on the listener-specific responsibilities: filtering irrelevant
 * events and resetting the NC byte-copy to empty when materialization fails.
 */
class FileCreatedFromTemplateListenerTest extends TestCase {
	public function testIgnoresUnrelatedEvent(): void {
		$service = $this->createMock(PadCreationService::class);
		$service->expects($this->never())->method('materializeTemplateInto');

		$this->buildListener($service)->handle(new class extends Event {});
	}

	public function testIgnoresNonPadTarget(): void {
		$service = $this->createMock(PadCreationService::class);
		$service->expects($this->never())->method('materializeTemplateInto');

		$this->buildListener($service)->handle(new FileCreatedFromTemplateEvent(
			$this->file('Template.pad'),
			$this->file('Notes.txt'),
		));
	}

	public function testIgnoresMissingTemplate(): void {
		$service = $this->createMock(PadCreationService::class);
		$service->expects($this->never())->method('materializeTemplateInto');

		$this->buildListener($service)->handle(new FileCreatedFromTemplateEvent(
			null,
			$this->file('New.pad'),
		));
	}

	public function testDelegatesToService(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->never())->method('putContent');

		$service = $this->createMock(PadCreationService::class);
		$service->expects($this->once())
			->method('materializeTemplateInto')
			->with($target, $template, $this->isInstanceOf(IUser::class));

		$this->buildListener($service)->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	public function testResetsTargetWhenServiceThrows(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->once())->method('putContent')->with('');

		$service = $this->createMock(PadCreationService::class);
		$service->method('materializeTemplateInto')
			->willThrowException(new \RuntimeException('boom'));

		$this->buildListener($service)->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	public function testResetsTargetWhenNoUserInSession(): void {
		$template = $this->file('Tpl.pad');
		$target = $this->file('New.pad');
		$target->expects($this->once())->method('putContent')->with('');

		$service = $this->createMock(PadCreationService::class);
		$service->expects($this->never())->method('materializeTemplateInto');

		$listener = $this->buildListener($service, withUser: false);
		$listener->handle(new FileCreatedFromTemplateEvent($template, $target));
	}

	private function buildListener(
		PadCreationService $service,
		bool $withUser = true,
	): FileCreatedFromTemplateListener {
		$userSession = $this->createMock(IUserSession::class);
		if ($withUser) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn('alice');
			$user->method('getDisplayName')->willReturn('Alice');
			$userSession->method('getUser')->willReturn($user);
		} else {
			$userSession->method('getUser')->willReturn(null);
		}
		return new FileCreatedFromTemplateListener(
			$service,
			$userSession,
			$this->createMock(LoggerInterface::class),
		);
	}

	private function file(string $name): File {
		$file = $this->createMock(File::class);
		$file->method('getName')->willReturn($name);
		$file->method('getId')->willReturn(42);
		return $file;
	}
}
