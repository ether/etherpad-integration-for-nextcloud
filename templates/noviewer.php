<?php
/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 * Copyright (c) 2026 Jacob Bühler
 */
/** @var array $_ */
/** @var \OCP\IL10N $l */
?>
<div class="section">
	<h2><?php p((string)($_['title'] ?? $l->t('Could not open pad'))); ?></h2>
	<p><?php p((string)($_['error'] ?? $l->t('Unknown error.'))); ?></p>
</div>
