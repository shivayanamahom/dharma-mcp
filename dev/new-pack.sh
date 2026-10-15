#!/usr/bin/env bash
# Dev-only: создаёт каркас плагина-набора инструментов (группа dharmamcp).
# Использование: dev/new-pack.sh <element> "<описание RU>" "<описание EN>"
# Пример:        dev/new-pack.sh shop "Набор «Магазин»" "Shop tool pack"
# Инструменты добавляются файлами src/Tool/*Tool.php — Extension находит и регистрирует их сам.
set -euo pipefail

EL="${1:?element}"
RU="${2:?описание RU}"
EN="${3:?описание EN}"
CLASS="$(tr '[:lower:]' '[:upper:]' <<< "${EL:0:1}")${EL:1}"
UP="$(tr '[:lower:]' '[:upper:]' <<< "$EL")"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
D="$ROOT/plg_dharmamcp_$EL"

mkdir -p "$D/services" "$D/src/Extension" "$D/src/Tool" "$D/language/ru-RU" "$D/language/en-GB"

cat > "$D/$EL.xml" <<XML
<?xml version="1.0" encoding="utf-8"?>
<extension type="plugin" group="dharmamcp" method="upgrade">
	<name>PLG_DHARMAMCP_${UP}</name>
	<creationDate>2026-10</creationDate>
	<author>Dharma</author>
	<copyright>(C) 2026 Dharma. Все права защищены.</copyright>
	<license>GNU General Public License версии 2 или новее; см. LICENSE.txt</license>
	<version>0.2.0</version>
	<description>PLG_DHARMAMCP_${UP}_XML_DESCRIPTION</description>
	<namespace path="src">Dharma\\Plugin\\DharmaMcp\\${CLASS}</namespace>
	<files>
		<folder>language</folder>
		<folder plugin="$EL">services</folder>
		<folder>src</folder>
	</files>
</extension>
XML

cat > "$D/services/provider.php" <<PHP
<?php

/**
 * Dharma MCP — набор «$EL».
 *
 * @package    plg_dharmamcp_$EL
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

\\defined('_JEXEC') or die;

use Dharma\\Plugin\\DharmaMcp\\${CLASS}\\Extension\\${CLASS};
use Joomla\\CMS\\Extension\\PluginInterface;
use Joomla\\CMS\\Factory;
use Joomla\\CMS\\Plugin\\PluginHelper;
use Joomla\\DI\\Container;
use Joomla\\DI\\ServiceProviderInterface;

return new class () implements ServiceProviderInterface {
    public function register(Container \$container): void
    {
        \$container->set(
            PluginInterface::class,
            static function (Container \$container): ${CLASS} {
                \$plugin = new ${CLASS}((array) PluginHelper::getPlugin('dharmamcp', '$EL'));
                \$plugin->setApplication(Factory::getApplication());

                return \$plugin;
            }
        );
    }
};
PHP

cat > "$D/src/Extension/$CLASS.php" <<PHP
<?php

/**
 * Dharma MCP — набор «$EL».
 *
 * @package    plg_dharmamcp_$EL
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\\Plugin\\DharmaMcp\\${CLASS}\\Extension;

\\defined('_JEXEC') or die;

use Dharma\\Component\\DharmaMcp\\Site\\Event\\RegisterToolsEvent;
use Dharma\\Component\\DharmaMcp\\Site\\Tool\\ToolInterface;
use Joomla\\CMS\\Plugin\\CMSPlugin;
use Joomla\\Event\\SubscriberInterface;

/**
 * Регистрирует в Dharma MCP все инструменты из src/Tool/*Tool.php.
 * Отключение плагина в менеджере плагинов полностью убирает набор.
 */
final class ${CLASS} extends CMSPlugin implements SubscriberInterface
{
    protected \$autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return ['onDharmaMcpRegisterTools' => 'onRegisterTools'];
    }

    public function onRegisterTools(RegisterToolsEvent \$event): void
    {
        foreach (glob(\\dirname(__DIR__) . '/Tool/*Tool.php') ?: [] as \$file) {
            \$class = 'Dharma\\\\Plugin\\\\DharmaMcp\\\\${CLASS}\\\\Tool\\\\' . basename(\$file, '.php');
            if (!class_exists(\$class) || !is_subclass_of(\$class, ToolInterface::class) || (new \\ReflectionClass(\$class))->isAbstract()) {
                continue;
            }
            \$event->register(new \$class());
        }
    }
}
PHP

printf 'PLG_DHARMAMCP_%s="Dharma MCP: %s"\nPLG_DHARMAMCP_%s_XML_DESCRIPTION="%s"\n' "$UP" "$RU" "$UP" "$RU" | tee "$D/language/ru-RU/plg_dharmamcp_$EL.sys.ini" > "$D/language/ru-RU/plg_dharmamcp_$EL.ini"
printf 'PLG_DHARMAMCP_%s="Dharma MCP: %s"\nPLG_DHARMAMCP_%s_XML_DESCRIPTION="%s"\n' "$UP" "$EN" "$UP" "$EN" | tee "$D/language/en-GB/plg_dharmamcp_$EL.sys.ini" > "$D/language/en-GB/plg_dharmamcp_$EL.ini"

echo "Каркас создан: $D"
