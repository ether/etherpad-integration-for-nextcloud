<?php

declare(strict_types=1);

return ['routes' => [
	['name' => 'viewer#showPad', 'url' => '/', 'verb' => 'GET'],
	['name' => 'viewer#showPadById', 'url' => '/by-id/{fileId}', 'verb' => 'GET'],
	['name' => 'embed#showById', 'url' => '/embed/by-id/{fileId}', 'verb' => 'GET'],
	['name' => 'embed#createByParent', 'url' => '/embed/create-by-parent/{parentFolderId}', 'verb' => 'GET'],
	['name' => 'publicViewer#showPad', 'url' => '/public/{token}', 'verb' => 'GET'],
	['name' => 'publicViewer#openPadData', 'url' => '/api/v1/public/open/{token}', 'verb' => 'GET'],
	['name' => 'pad#create', 'url' => '/api/v1/pads', 'verb' => 'POST'],
	['name' => 'pad#createByParent', 'url' => '/api/v1/pads/create-by-parent', 'verb' => 'POST'],
	['name' => 'pad#createFromUrl', 'url' => '/api/v1/pads/from-url', 'verb' => 'POST'],
	['name' => 'pad#createFromTemplate', 'url' => '/api/v1/pads/from-template', 'verb' => 'POST'],
	['name' => 'pad#metaById', 'url' => '/api/v1/pads/meta-by-id/{fileId}', 'verb' => 'GET'],
	['name' => 'pad#resolveById', 'url' => '/api/v1/pads/resolve', 'verb' => 'GET'],
	['name' => 'pad#open', 'url' => '/api/v1/pads/open', 'verb' => 'POST'],
	['name' => 'pad#openById', 'url' => '/api/v1/pads/open-by-id', 'verb' => 'POST'],
	['name' => 'pad#initialize', 'url' => '/api/v1/pads/initialize', 'verb' => 'POST'],
	['name' => 'pad#initializeById', 'url' => '/api/v1/pads/initialize-by-id/{fileId}', 'verb' => 'POST'],
	['name' => 'pad#syncStatusById', 'url' => '/api/v1/pads/sync-status/{fileId}', 'verb' => 'GET'],
	['name' => 'pad#syncById', 'url' => '/api/v1/pads/sync/{fileId}', 'verb' => 'POST'],
	['name' => 'pad#trash', 'url' => '/api/v1/pads/trash', 'verb' => 'POST'],
	['name' => 'pad#restore', 'url' => '/api/v1/pads/restore', 'verb' => 'POST'],
	['name' => 'pad#recoverByFileId', 'url' => '/api/v1/pads/recover-from-snapshot/{fileId}', 'verb' => 'POST'],
	['name' => 'pad#findOriginalByFileId', 'url' => '/api/v1/pads/find-original/{fileId}', 'verb' => 'GET'],
	['name' => 'admin#saveSettings', 'url' => '/api/v1/admin/settings', 'verb' => 'POST'],
	['name' => 'admin#healthCheck', 'url' => '/api/v1/admin/health', 'verb' => 'POST'],
	['name' => 'admin#consistencyCheck', 'url' => '/api/v1/admin/consistency-check', 'verb' => 'POST'],
	['name' => 'admin#retryPendingDeletes', 'url' => '/api/v1/admin/retry-pending-deletes', 'verb' => 'POST'],
	['name' => 'admin#setTestFault', 'url' => '/api/v1/admin/test-fault', 'verb' => 'POST'],
]];
