<?php

/**
 * Dharma MCP.
 *
 * @package    com_dharma_mcp
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Oauth;

\defined('_JEXEC') or die;

/**
 * Ошибка OAuth в терминах RFC 6749: код (invalid_request, invalid_grant...) и описание.
 * $redirectable = false, если ошибке нельзя «возвращаться» клиенту (неизвестный клиент или адрес возврата).
 */
final class OauthException extends \RuntimeException
{
    public function __construct(
        public readonly string $error,
        string $description,
        public readonly int $httpCode = 400,
        public readonly bool $redirectable = true
    ) {
        parent::__construct($description);
    }
}
