<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
?>
<div id="etherpad-nextcloud-embed"
	class="epnc-embed"
	data-file-id="<?php p((string)$_['file_id']); ?>"
	data-open-by-id-url="<?php p((string)$_['open_by_id_url']); ?>"
	data-initialize-by-id-url-template="<?php p((string)$_['initialize_by_id_url_template']); ?>"
	data-request-token="<?php p((string)($_['requesttoken'] ?? '')); ?>"
	data-l10n-loading="<?php p((string)$_['l10n']['loading']); ?>"
	data-l10n-error-title="<?php p((string)$_['l10n']['error_title']); ?>">
	<div class="epnc-embed__loading" data-epnc-embed-loading>
		<?php p((string)$_['l10n']['loading']); ?>
	</div>
	<div class="epnc-embed__error" data-epnc-embed-error hidden>
		<h2 class="epnc-embed__error-title"><?php p((string)$_['l10n']['error_title']); ?></h2>
		<p class="epnc-embed__error-message" data-epnc-embed-error-message></p>
	</div>
	<iframe class="epnc-embed__iframe" data-epnc-embed-iframe hidden></iframe>
</div>
