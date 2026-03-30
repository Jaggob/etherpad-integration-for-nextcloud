<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
?>
<link rel="stylesheet" href="<?php p(link_to('etherpad_nextcloud', 'css/embed.css')); ?>">
<script nonce="<?php p((string)$_['cspNonce']); ?>" defer src="<?php p(link_to('etherpad_nextcloud', 'js/embed-create-main.js')); ?>"></script>
<div id="etherpad-nextcloud-embed-create"
	class="epnc-embed"
	data-parent-folder-id="<?php p((string)$_['parent_folder_id']); ?>"
	data-name="<?php p((string)$_['name']); ?>"
	data-access-mode="<?php p((string)$_['access_mode']); ?>"
	data-create-by-parent-url="<?php p((string)$_['create_by_parent_url']); ?>"
	data-request-token="<?php p((string)($_['requesttoken'] ?? '')); ?>"
	data-l10n-loading="<?php p((string)$_['l10n']['loading']); ?>"
	data-l10n-error-title="<?php p((string)$_['l10n']['error_title']); ?>">
	<div class="epnc-embed__loading" data-epnc-embed-create-loading>
		<?php p((string)$_['l10n']['loading']); ?>
	</div>
	<div class="epnc-embed__error" data-epnc-embed-create-error hidden>
		<h2 class="epnc-embed__error-title"><?php p((string)$_['l10n']['error_title']); ?></h2>
		<p class="epnc-embed__error-message" data-epnc-embed-create-error-message></p>
	</div>
</div>
