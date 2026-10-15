<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Auth\AuthResult;
use Dharma\Component\DharmaMcp\Site\Support\PiiMasker;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Всё, что нужно инструменту во время вызова: пользователь токена, настройки, БД, приложение.
 * Права проверяются от имени пользователя токена — ACL Joomla, а не «сервисный» доступ.
 */
final class ToolContext
{
    public function __construct(
        public readonly AuthResult $auth,
        public readonly Registry $params,
        public readonly DatabaseInterface $db,
        public readonly CMSApplication $app,
        public readonly string $serverMode
    ) {}

    public function user(): User
    {
        return $this->auth->user;
    }

    /** Право пользователя токена (ACL Joomla). */
    public function can(string $action, string $asset = 'com_dharma_mcp'): bool
    {
        return (bool) $this->user()->authorise($action, $asset);
    }

    public function requireCan(string $action, string $asset, string $what = ''): void
    {
        if (!$this->can($action, $asset)) {
            throw ToolException::forbidden(
                'The Joomla user of this token is not allowed to do that' . ($what !== '' ? ' (' . $what . ')' : '') . '.'
            );
        }
    }

    /** Только пользователи с правом «Суперпользователь» (для инструментов системного уровня). */
    public function requireSuperUser(): void
    {
        if (!$this->user()->authorise('core.admin')) {
            throw ToolException::forbidden('This tool requires a Joomla Super User.');
        }
    }

    /** Разрешены ли персональные данные: настройка сервера и область токена одновременно. */
    public function piiAllowed(): bool
    {
        return (bool) (int) $this->params->get('pii_enabled', 0) && $this->auth->hasScope('customers:pii');
    }

    public function maskPii(): bool
    {
        return (bool) (int) $this->params->get('mask_pii', 1);
    }

    /** Значение поля покупателя с учётом настройки: скрыто, замаскировано или как есть. */
    public function customerField(string $key, mixed $value): mixed
    {
        return PiiMasker::maskValue($key, $value, $this->piiAllowed(), $this->maskPii());
    }

    /**
     * Текст, введённый посетителями или поставщиками (комментарии, отзывы, названия из импорта),
     * возвращается в обёртке: это данные, а не инструкции для ассистента.
     */
    public function userContent(?string $text): ?array
    {
        return $text === null || $text === '' ? null : ['user_content' => $text];
    }

    /**
     * Постраничная выдача: [limit, offset] с потолком.
     *
     * @return array{0: int, 1: int}
     */
    public function page(array $arguments, int $default = 20, int $max = 100): array
    {
        $limit = (int) ($arguments['limit'] ?? $default);
        $offset = (int) ($arguments['offset'] ?? 0);

        return [max(1, min($max, $limit)), max(0, $offset)];
    }

    /** Модель компонента (по умолчанию административная) через MVCFactory. */
    public function model(string $component, string $name, string $client = 'Administrator'): object
    {
        $model = $this->app->bootComponent($component)->getMVCFactory()->createModel($name, $client, ['ignore_request' => true]);
        if (!$model) {
            throw ToolException::notFound("Model $name of $component is not available.");
        }

        return $model;
    }

    public function table(string $component, string $name, string $client = 'Administrator'): object
    {
        $table = $this->app->bootComponent($component)->getMVCFactory()->createTable($name, $client);
        if (!$table) {
            throw ToolException::notFound("Table $name of $component is not available.");
        }

        return $table;
    }

    public function componentEnabled(string $component): bool
    {
        return \Joomla\CMS\Component\ComponentHelper::isEnabled($component);
    }
}
