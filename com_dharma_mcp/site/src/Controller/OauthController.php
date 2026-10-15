<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Controller;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Oauth\OauthException;
use Dharma\Component\DharmaMcp\Site\Oauth\OauthServer;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseInterface;

/**
 * OAuth 2.1 для MCP-клиентов: метаданные, регистрация клиентов, токены и обработка согласия.
 * Задачи доступны как index.php?option=com_dharma_mcp&task=oauth.<задача>; системный плагин
 * dharmamcp дополнительно открывает их по «красивым» адресам (/oauth/token, /.well-known/...).
 */
class OauthController extends BaseController
{
    /** Joomla угадывает имя компонента по пространству имён (com_dharmamcp), поэтому оно задано явно. */
    protected $option = 'com_dharma_mcp';

    /** Метаданные защищённого ресурса (RFC 9728). */
    public function resource(): void
    {
        $this->guard();
        $this->json(200, OauthServer::resourceMetadata((string) $this->input->getString('r', '')));
    }

    /** Метаданные сервера авторизации (RFC 8414). */
    public function server(): void
    {
        $this->guard();
        $this->json(200, OauthServer::serverMetadata());
    }

    /** Динамическая регистрация клиента (RFC 7591). */
    public function register(): void
    {
        $this->guard();
        if ($this->method() !== 'POST') {
            $this->oauthError(new OauthException('invalid_request', 'Use POST.', 405));
        }
        if ((int) ComponentHelper::getParams('com_dharma_mcp')->get('oauth_registration', 1) !== 1) {
            $this->oauthError(new OauthException('access_denied', 'Client registration is disabled on this site.', 403));
        }

        $meta = json_decode((string) file_get_contents('php://input'), true);
        try {
            $this->json(201, $this->oauthServer()->register(\is_array($meta) ? $meta : []));
        } catch (OauthException $e) {
            $this->oauthError($e);
        }
    }

    /** Эндпоинт токенов: authorization_code и refresh_token. */
    public function token(): void
    {
        $this->guard();
        if ($this->method() !== 'POST') {
            $this->oauthError(new OauthException('invalid_request', 'Use POST.', 405));
        }

        $body = (string) file_get_contents('php://input');
        $params = $_POST;
        if ($params === [] && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            $decoded = json_decode($body, true);
            $params = \is_array($decoded) ? $decoded : [];
        }

        try {
            $this->json(200, $this->oauthServer()->token($params));
        } catch (OauthException $e) {
            $this->oauthError($e);
        }
    }

    /** Обработка формы согласия (кнопки «Разрешить» и «Отклонить»). */
    public function consent(): void
    {
        $app = $this->app;
        $user = $app->getIdentity();

        if ($user->guest || !$user->authorise('core.manage', 'com_dharma_mcp')) {
            $app->enqueueMessage(Text::_('JERROR_ALERTNOAUTHOR'), 'error');
            $app->redirect(Uri::root());
        }
        if (!Session::checkToken('post')) {
            $app->enqueueMessage(Text::_('JINVALID_TOKEN'), 'error');
            $app->redirect(Uri::root());
        }

        $input = $app->getInput();
        $request = [];
        foreach (['client_id', 'redirect_uri', 'response_type', 'scope', 'state', 'code_challenge', 'code_challenge_method'] as $key) {
            $request[$key] = $input->post->getString($key, '');
        }

        $server = $this->oauthServer();
        try {
            $validated = $server->validateAuthorizeRequest($request);
        } catch (OauthException $e) {
            // Адрес возврата ненадёжен: ошибку показываем здесь, клиенту не возвращаем.
            $app->enqueueMessage(Text::_('COM_DHARMA_MCP_OAUTH_ERROR_REQUEST') . ' ' . $e->getMessage(), 'error');
            $app->redirect(Uri::root());
        }

        if ($input->post->getCmd('decision') !== 'allow') {
            $this->redirectToClient($validated['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'The user denied the request.'], $validated['state']);
        }

        $params = ComponentHelper::getParams('com_dharma_mcp');
        $allowed = OauthServer::grantableScopes($user, (int) $params->get('pii_enabled', 0) === 1);
        $chosen = array_values(array_intersect((array) $input->post->get('scopes', [], 'array'), $validated['scopes'], $allowed));
        if ($chosen === []) {
            $this->redirectToClient($validated['redirect_uri'], ['error' => 'access_denied', 'error_description' => 'No permissions were granted.'], $validated['state']);
        }

        $code = $server->issueCode((string) $validated['client']->client_id, (int) $user->id, $chosen, $validated['redirect_uri'], $validated['code_challenge']);
        $this->redirectToClient($validated['redirect_uri'], ['code' => $code, 'iss' => OauthServer::issuer()], $validated['state']);
    }

    // ---- Внутреннее ------------------------------------------------------------------------------------------------

    private function oauthServer(): OauthServer
    {
        return new OauthServer(Factory::getContainer()->get(DatabaseInterface::class));
    }

    private function method(): string
    {
        return strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    /** OAuth включён? Обрабатывает предзапросы CORS. */
    private function guard(): void
    {
        $this->cors();
        if ($this->method() === 'OPTIONS') {
            $this->finish(204, '');
        }
        if ((int) ComponentHelper::getParams('com_dharma_mcp')->get('oauth_enabled', 1) !== 1) {
            $this->finish(404, '');
        }
    }

    private function cors(): void
    {
        // Публичные клиенты без cookie: открытый CORS нужен браузерным клиентам MCP.
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, MCP-Protocol-Version');
        header('Access-Control-Max-Age: 600');
    }

    private function json(int $code, array $data): void
    {
        $this->finish($code, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', 'application/json');
    }

    private function oauthError(OauthException $e): void
    {
        if ($e->httpCode === 401) {
            header('WWW-Authenticate: Basic realm="Dharma MCP"');
        }
        $this->json($e->httpCode, ['error' => $e->error, 'error_description' => $e->getMessage()]);
    }

    private function finish(int $code, string $body, string $type = ''): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        http_response_code($code);
        header('Cache-Control: no-store');
        header('Pragma: no-cache');
        if ($type !== '') {
            header('Content-Type: ' . $type . '; charset=utf-8');
        }
        echo $body;
        $this->app->close();
        exit;
    }

    /** Возврат клиенту с параметрами в строке запроса. */
    private function redirectToClient(string $redirectUri, array $params, ?string $state): never
    {
        if ($state !== null && $state !== '') {
            $params['state'] = $state;
        }
        $url = $redirectUri . (str_contains($redirectUri, '?') ? '&' : '?') . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        // Заголовки через приложение Joomla: так они одинаково отправляются и проверяются.
        $this->app->setHeader('Cache-Control', 'no-store', true);
        $this->app->setHeader('Location', $url, true);
        $this->app->setHeader('Status', '303', true);
        $this->app->sendHeaders();
        $this->app->close();
        exit;
    }

    /** Адрес страницы входа Joomla с возвратом на текущую страницу согласия. */
    public static function loginUrl(): string
    {
        // Адрес собирается вручную: Route::_() в этом контексте может вернуть пустое значение.
        return Uri::root() . 'index.php?option=com_users&view=login&return=' . base64_encode(Uri::getInstance()->toString());
    }
}
