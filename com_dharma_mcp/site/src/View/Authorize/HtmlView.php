<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\View\Authorize;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Controller\OauthController;
use Dharma\Component\DharmaMcp\Site\Oauth\OauthException;
use Dharma\Component\DharmaMcp\Site\Oauth\OauthServer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\Database\DatabaseInterface;

/**
 * Страница согласия: показывается вошедшему пользователю Joomla, когда MCP-клиент просит доступ.
 */
class HtmlView extends BaseHtmlView
{
    protected $option = 'com_dharma_mcp';

    public ?string $error = null;

    public ?array $request = null;

    /** @var string[] */
    public array $grantable = [];

    /** @var array<string, string> */
    public array $raw = [];

    public function display($tpl = null): void
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        $app->getLanguage()->load('com_dharma_mcp', JPATH_ADMINISTRATOR);

        // Страницу согласия нельзя вставлять в чужой фрейм: иначе посторонний сайт прикроет кнопку «Разрешить» невидимой
        // и выманит у администратора доступ для программы, зарегистрированной злоумышленником.
        $app->setHeader('X-Frame-Options', 'DENY', true);
        $app->setHeader('Content-Security-Policy', "frame-ancestors 'none'");
        $app->setHeader('Cache-Control', 'no-store', true);

        $params = ComponentHelper::getParams('com_dharma_mcp');
        if ((int) $params->get('oauth_enabled', 1) !== 1) {
            $this->error = 'COM_DHARMA_MCP_OAUTH_DISABLED';
            parent::display($tpl);

            return;
        }

        // Гость сначала входит штатной формой Joomla и возвращается сюда.
        if ($user->guest) {
            $app->redirect(OauthController::loginUrl());
        }

        $input = $app->getInput();
        foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method'] as $key) {
            $this->raw[$key] = $input->get->getString($key, '');
        }

        if (!$user->authorise('core.manage', 'com_dharma_mcp')) {
            $this->error = 'COM_DHARMA_MCP_OAUTH_NO_RIGHTS';
        } else {
            try {
                $this->request = (new OauthServer(Factory::getContainer()->get(DatabaseInterface::class)))->validateAuthorizeRequest($this->raw);
                $this->grantable = OauthServer::grantableScopes($user, (int) $params->get('pii_enabled', 0) === 1);
            } catch (OauthException $e) {
                $this->error = 'COM_DHARMA_MCP_OAUTH_ERROR_REQUEST';
                $this->raw = [];
                $this->errorDetail = $e->getMessage();
            }
        }

        parent::display($tpl);
    }

    public string $errorDetail = '';
}
