<?php

declare(strict_types=1);
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */

namespace OCA\EtherpadNextcloud\Exception;

/**
 * Thrown by the legacy Ownpad migration path when the source URL's pad-id
 * is already bound to a different NC file in this instance and the
 * requesting user cannot read the file that owns the existing binding.
 *
 * The migration is refused rather than letting the requesting user claim
 * a pad they don't already have access to. Surfaces as HTTP 409 with code
 * `legacy_collision_no_access`.
 */
final class LegacyPadCollisionException extends \RuntimeException {
}
