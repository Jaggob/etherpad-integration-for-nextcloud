<?php

declare(strict_types=1);

namespace OCP\EventDispatcher;

if (!interface_exists(IEventListener::class)) {
	interface IEventListener {
		public function handle(Event $event): void;
	}
}
