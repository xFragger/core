<?php
$l = OC_L10N::get('files_sharing');

OC::$CLASSPATH['OC_Share_Backend_File'] = 'files_sharing/lib/share/file.php';
OC::$CLASSPATH['OC_Share_Backend_Folder'] = 'files_sharing/lib/share/folder.php';
OC::$CLASSPATH['OC\Files\Storage\Shared'] = 'files_sharing/lib/sharedstorage.php';
OC::$CLASSPATH['OC\Files\Cache\Shared_Cache'] = 'files_sharing/lib/cache.php';
OC::$CLASSPATH['OC\Files\Cache\Shared_Permissions'] = 'files_sharing/lib/permissions.php';
OC::$CLASSPATH['OC\Files\Cache\Shared_Updater'] = 'files_sharing/lib/updater.php';
OC::$CLASSPATH['OC\Files\Cache\Shared_Watcher'] = 'files_sharing/lib/watcher.php';
OC::$CLASSPATH['OCA\Files\Share\Api'] = 'files_sharing/lib/api.php';
OC::$CLASSPATH['OCA\Files\Share\Maintainer'] = 'files_sharing/lib/maintainer.php';
OC::$CLASSPATH['OCA\Files\Share\Proxy'] = 'files_sharing/lib/proxy.php';

\OCP\App::registerAdmin('files_sharing', 'settings-admin');

OCP\Util::connectHook('OC_Filesystem', 'setup', '\OC\Files\Storage\Shared', 'setup');
OCP\Util::connectHook('OC_Filesystem', 'setup', '\OCA\Files_Sharing\External\Manager', 'setup');
OCP\Share::registerBackend('file', 'OC_Share_Backend_File');
OCP\Share::registerBackend('folder', 'OC_Share_Backend_Folder', 'file');

OCP\Util::addScript('files_sharing', 'share');
OCP\Util::addScript('files_sharing', 'external');

\OC_Hook::connect('OC_Filesystem', 'post_write', '\OC\Files\Cache\Shared_Updater', 'writeHook');
\OC_Hook::connect('OC_Filesystem', 'post_delete', '\OC\Files\Cache\Shared_Updater', 'postDeleteHook');
\OC_Hook::connect('OC_Filesystem', 'delete', '\OC\Files\Cache\Shared_Updater', 'deleteHook');
\OC_Hook::connect('OC_Filesystem', 'post_rename', '\OC\Files\Cache\Shared_Updater', 'renameHook');
\OC_Hook::connect('OC_Appconfig', 'post_set_value', '\OCA\Files\Share\Maintainer', 'configChangeHook');

\OCP\Util::connectHook('OCP\Share', 'post_shared', '\OC\Files\Cache\Shared_Updater', 'postShareHook');
\OCP\Util::connectHook('OCP\Share', 'post_unshare', '\OC\Files\Cache\Shared_Updater', 'postUnshareHook');

OC_FileProxy::register(new OCA\Files\Share\Proxy());

\OCA\Files\App::getNavigationManager()->add(
	array(
		"id" => 'sharingin',
		"appname" => 'files_sharing',
		"script" => 'list.php',
		"order" => 10,
		"name" => $l->t('Shared with you')
	)
);
\OCA\Files\App::getNavigationManager()->add(
	array(
		"id" => 'sharingout',
		"appname" => 'files_sharing',
		"script" => 'list.php',
		"order" => 15,
		"name" => $l->t('Shared with others')
	)
);
\OCA\Files\App::getNavigationManager()->add(
	array(
		"id" => 'sharinglinks',
		"appname" => 'files_sharing',
		"script" => 'list.php',
		"order" => 20,
		"name" => $l->t('Shared by link')
	)
);
