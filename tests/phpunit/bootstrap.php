<?php

declare(strict_types=1);

$stubFiles = [
	__DIR__ . '/stubs/OCA/EtherpadNextcloud/AppInfo/Application.php',
	__DIR__ . '/stubs/OCP/AppFramework/Controller.php',
	__DIR__ . '/stubs/OCP/ISession.php',
	__DIR__ . '/stubs/OCP/AppFramework/PublicShareController.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/ContentSecurityPolicy.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/DataResponse.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/RedirectResponse.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/TemplateResponse.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/Attribute/NoAdminRequired.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/Attribute/NoCSRFRequired.php',
	__DIR__ . '/stubs/OCP/AppFramework/Http/Attribute/PublicPage.php',
	__DIR__ . '/stubs/OCP/AppFramework/Utility/ITimeFactory.php',
	__DIR__ . '/stubs/OCP/BackgroundJob/TimedJob.php',
	__DIR__ . '/stubs/OC/Security/CSRF/CsrfToken.php',
	__DIR__ . '/stubs/OC/Security/CSRF/CsrfTokenManager.php',
	__DIR__ . '/stubs/OCP/DB/QueryBuilder/IQueryBuilder.php',
	__DIR__ . '/stubs/OCP/EventDispatcher/Event.php',
	__DIR__ . '/stubs/OCP/EventDispatcher/IEventListener.php',
	__DIR__ . '/stubs/OCP/Constants.php',
	__DIR__ . '/stubs/OCP/Files/File.php',
	__DIR__ . '/stubs/OCP/Files/Folder.php',
	__DIR__ . '/stubs/OCP/Files/IRootFolder.php',
	__DIR__ . '/stubs/OCP/Files/NotFoundException.php',
	__DIR__ . '/stubs/OCP/Files/Template/FileCreatedFromTemplateEvent.php',
	__DIR__ . '/stubs/OCP/IRequest.php',
	__DIR__ . '/stubs/OCP/Security/ISecureRandom.php',
	__DIR__ . '/stubs/OCP/IConfig.php',
	__DIR__ . '/stubs/OCP/IAppConfig.php',
	__DIR__ . '/stubs/OCP/IDBConnection.php',
	__DIR__ . '/stubs/OCP/IL10N.php',
	__DIR__ . '/stubs/OCP/IGroupManager.php',
	__DIR__ . '/stubs/OCP/IURLGenerator.php',
	__DIR__ . '/stubs/OCP/IUser.php',
	__DIR__ . '/stubs/OCP/IUserSession.php',
	__DIR__ . '/stubs/OCP/Lock/LockedException.php',
	__DIR__ . '/stubs/OCP/Security/CSP/AddContentSecurityPolicyEvent.php',
	__DIR__ . '/stubs/OCP/Share/Exceptions/ShareNotFound.php',
	__DIR__ . '/stubs/OCP/Share/IManager.php',
	__DIR__ . '/stubs/OCP/Share/IShare.php',
	__DIR__ . '/stubs/Psr/Log/LoggerInterface.php',
];

foreach ($stubFiles as $stubFile) {
	if (is_file($stubFile)) {
		require_once $stubFile;
	}
}

spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\EtherpadNextcloud\\';
	if (!str_starts_with($class, $prefix)) {
		return;
	}
	$relative = substr($class, strlen($prefix));
	$path = __DIR__ . '/../../lib/' . str_replace('\\', '/', $relative) . '.php';
	if (is_file($path)) {
		require_once $path;
	}
});
