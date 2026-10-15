<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaShopMcp\Site\Audit;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaShopMcp\Site\Support\PiiMasker;
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
                // Маскируем пароли, токены и чувствительные PII (email, телефон, адрес) в аргументах
                $clean = PiiMasker::sanitizeLogArguments($arguments);
                $argsJson = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (strlen((string) $argsJson) > 65000) {
                    $argsJson = substr((string) $argsJson, 0, 65000) . '...[truncated]';
                }
            }

            $created = Factory::getDate()->toSql();
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
                ->bind(':created', $created);

            $this->db->setQuery($query)->execute();
        } catch (\Throwable) {
            // Аудит не должен блокировать основной ответ клиенту
        }
    }
}
