<?php
declare(strict_types=1);

namespace Dharma\Component\DharmaMcp\Site\Audit;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Support\PiiMasker;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

final class AuditLogger
{
    /** Сколько знаков параметров сохраняется в одной записи журнала. */
    private const MAX_ARGS_CHARS = 4000;

    /** Длиннее этого значения параметров (файлы, картинки в base64, тексты) в журнале сокращаются. */
    private const MAX_VALUE_CHARS = 300;

    public function __construct(
        private readonly DatabaseInterface $db
    ) {}

    /**
     * Длинные значения (содержимое файла, картинка в base64, большой текст) в журнал целиком не кладутся:
     * остаётся начало и число знаков. Иначе журнал разрастался бы на мегабайты за день.
     *
     * @param   array<mixed>  $data
     *
     * @return  array<mixed>
     */
    private static function shorten(array $data): array
    {
        foreach ($data as $key => $value) {
            if (\is_array($value)) {
                $data[$key] = self::shorten($value);
            } elseif (\is_string($value) && mb_strlen($value) > self::MAX_VALUE_CHARS) {
                $data[$key] = mb_substr($value, 0, 200) . '…[' . mb_strlen($value) . ' знаков]';
            }
        }

        return $data;
    }

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
                $clean = self::shorten(PiiMasker::sanitizeLogArguments($arguments));
                $argsJson = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
                if (mb_strlen((string) $argsJson) > self::MAX_ARGS_CHARS) {
                    $argsJson = mb_substr((string) $argsJson, 0, self::MAX_ARGS_CHARS) . '...[truncated]';
                }
            }

            $created = Factory::getDate()->toSql();
            $query = $this->db->createQuery()
                ->insert($this->db->quoteName('#__dharma_mcp_logs'))
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

    /** Удаляет записи журнала старше срока хранения (вызывается изредка, в ходе обычных запросов). */
    public function purge(int $retentionDays, int $maxRows = 0): void
    {
        try {
            $before = Factory::getDate('-' . max(1, $retentionDays) . ' days')->toSql();
            $query = $this->db->createQuery()
                ->delete($this->db->quoteName('#__dharma_mcp_logs'))
                ->where($this->db->quoteName('created') . ' < :before')
                ->bind(':before', $before);
            $this->db->setQuery($query)->execute();

            // Предел числа записей: самые старые сверх предела удаляются, как бы много вызовов ни было за срок хранения.
            if ($maxRows > 0) {
                $limit = (int) $this->db->setQuery(
                    $this->db->createQuery()->select($this->db->quoteName('id'))->from($this->db->quoteName('#__dharma_mcp_logs'))
                        ->order($this->db->quoteName('id') . ' DESC'),
                    $maxRows,
                    1
                )->loadResult();

                if ($limit > 0) {
                    $this->db->setQuery(
                        $this->db->createQuery()->delete($this->db->quoteName('#__dharma_mcp_logs'))
                            ->where($this->db->quoteName('id') . ' <= :limit')->bind(':limit', $limit, \Joomla\Database\ParameterType::INTEGER)
                    )->execute();
                }
            }
        } catch (\Throwable) {
            // Чистка журнала не должна влиять на ответ.
        }
    }
}
