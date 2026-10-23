<?php
/** Dharma MCP: единая группировка областей для токенов и согласия OAuth. */
declare(strict_types=1);
namespace Dharma\Component\DharmaMcp\Site\Support;
\defined('_JEXEC') or die;
final class ScopeCatalog
{
    public static function groups(array $scopes): array
    {
        $definitions = [
            'shop' => ['shop:read', 'shop:write'],
            'radicalmart' => ['radicalmart:read', 'radicalmart:write'],
            'content' => ['content:read', 'content:write'],
            'template' => ['template:read', 'template:write'],
            'system' => ['system:read', 'system:write'],
            'personal' => ['customers:pii'],
        ];
        $groups = [];
        foreach ($definitions as $key => $values) {
            $values = array_values(array_intersect($values, $scopes));
            if ($values !== []) {
                $groups[] = ['key' => $key, 'title' => 'COM_DHARMA_MCP_SCOPE_GROUP_' . strtoupper($key),
                    'description' => 'COM_DHARMA_MCP_SCOPE_GROUP_' . strtoupper($key) . '_DESC', 'scopes' => $values];
            }
        }
        return $groups;
    }
}
