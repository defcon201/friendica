<?php

namespace Friendica\Module;

use Friendica\BaseModule;
use Friendica\Core\Hook;
use Friendica\Core\L10n;
use Friendica\Core\Renderer;

/**
 * Home module - Landing page of the current node
 */
class Home extends BaseModule
{
	public static function content()
	{
		$app = self::getApp();
		$config = $app->getConfig();

		// currently no returned data is used
		$ret = [];

		Hook::callAll('home_init', $ret);

		if (local_user() && ($app->user['nickname'])) {
			$app->internalRedirect('network');
		}

		if (strlen($config->get('system', 'singleuser'))) {
			$app->internalRedirect('/profile/' . $config->get('system', 'singleuser'));
		}

		$customHome = '';
		$defaultHeader = ($config->get('config', 'sitename') ? L10n::t('Welcome to %s', $config->get('config', 'sitename')) : '');

		$homeFilePath = $app->getBasePath() . '/home.html';
		$cssFilePath = $app->getBasePath() . '/home.css';

		if (file_exists($homeFilePath)) {
			$customHome = $homeFilePath;

			if (file_exists($cssFilePath)) {
				$app->page['htmlhead'] .= '<link rel="stylesheet" type="text/css" href="' . $app->getBaseURL() . '/home.css' . '" media="all" />';
			}
		}

		$login = Login::form($app->query_string, $config->get('config', 'register_policy') === Register::CLOSED ? 0 : 1);

		$content = '';
		Hook::callAll('home_content', $content);

		$tpl = Renderer::getMarkupTemplate('home.tpl');
		return Renderer::replaceMacros($tpl, [
			'$defaultheader' => $defaultHeader,
			'$customhome'    => $customHome,
			'$login'         => $login,
			'$content'       => $content,
		]);
	}
}
