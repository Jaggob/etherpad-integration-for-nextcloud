<?php

declare(strict_types=1);

namespace OCP\AppFramework\Http\Attribute;

if (!class_exists(PublicPage::class)) {
	#[\Attribute(\Attribute::TARGET_METHOD)]
	class PublicPage {
	}
}
