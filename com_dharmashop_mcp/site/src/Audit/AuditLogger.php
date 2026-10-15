<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Audit;

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

final class AuditLogger
{
    public function __construct(
        private readonly DatabaseInterface $db
    ) {}

    public function log(
        ?int $tokenId,
        ?int $userId,
        string $method,
        ?string $toolName,
        ?array $arguments,
        string $status,
        int $durationMs,
        ?string $errorMessage = null,
        ?string $ip = null,
        ?string $userAgent = null
    ): void {
        try {
            $argsJson = null;
            if (!empty($arguments)) {
                // Маскируем возможные пароли и токены в аргументах
                $clean = $arguments;
                unset($clean['password'], $clean['token'], $clean['secret']);
                $argsJson = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen((string) $argsJson) > 65000) {
                    $argsJson = substr((string) $argsJson, 0, 65000) . '...[truncated]';
                }
            }

            $query = $this->db->createQuery()
                ->insert($this->db->quoteName('#__dharmashop_mcp_logs'))
                ->columns([
                    'token_id', 'user_id', 'method', 'tool_name',
                    'arguments_json', 'status', 'duration_ms', 'error_message',
                    'ip', 'user_agent', 'created'
                ])
                ->values(
                    ':token_id, :user_id, :method, :tool_name, :args, :status, :duration, :error, :ip, :ua, :created'
                )
                ->bind(':token_id', $tokenId)
                ->bind(':user_id', $userId)
                ->bind(':method', $method)
                ->bind(':tool_name', $toolName)
                ->bind(':args', $argsJson)
                ->bind(':status', $status)
                ->bind(':duration', $durationMs)
                ->bind(':error', $errorMessage)
                ->bind(':ip', $ip)
                ->bind(':ua', $userAgent)
                $created = Factory::getDate()->toSql();
            $query->bind(':created', $created);

            $this->db->setQuery($query)->execute();
        } catch (\Throwable) {
            // Аудит не должен блокировать основной ответ клиенту
        }
    }
}
