<?php

/**
 * Dharma MCP — набор «Клиника».
 *
 * @package    plg_dharmamcp_clinic
 * @copyright  (C) 2026 Dharma. Все права защищены.
 * @license    GNU General Public License версии 2 или новее; см. LICENSE.txt
 */

declare(strict_types=1);

namespace Dharma\Plugin\DharmaMcp\Clinic\Tool;

\defined('_JEXEC') or die;

use Dharma\Component\DharmaMcp\Site\Tool\Access;
use Dharma\Component\DharmaMcp\Site\Tool\ToolContext;
use Dharma\Component\DharmaMcp\Site\Tool\ToolException;
use Joomla\CMS\Uri\Uri;

/** Проверка страницы сайта «как её видит поисковик»: код ответа, title, description, H1, canonical, robots, разметка. Только свой сайт, только чтение. */
final class PageCheckTool extends AbstractClinicTool
{
    public function getName(): string
    {
        return 'clinic.page_check';
    }

    public function getTitle(): string
    {
        return 'Check how a site page looks to search engines';
    }

    public function getDescription(): string
    {
        return 'Fetches one page of THIS site by its path (for example "/vrachi" or "/priem-vrachey/allergologiya-i-immunologiya") and reports the HTTP status, title and its length, description and its length, H1 count and text, canonical, robots, Open Graph URL, '
            . 'the JSON-LD types and a list of problems (missing or too long title/description, several H1, canonical pointing elsewhere, noindex). Use it after changing a record to verify the public page. Read-only; other sites are refused.';
    }

    public function getRequiredScope(): string
    {
        return 'clinic:read';
    }

    public function getAccess(): Access
    {
        return Access::Read;
    }

    public function getInputSchema(): array
    {
        return self::schema(['path' => self::str('Site-relative path starting with "/", with an optional query string.')], ['path']);
    }

    public function execute(array $arguments, ToolContext $context): array
    {
        $this->boot($context);
        $path = self::requireString($arguments, 'path');

        if ($path[0] !== '/' || str_starts_with($path, '//') || preg_match('~[\s\\@]|\.\./~', $path) === 1 || strlen($path) > 300) {
            throw ToolException::validation('Pass a path of this site starting with a single "/" (no host, no spaces).');
        }

        $base = rtrim(Uri::root(), '/');
        $host = (string) parse_url($base, PHP_URL_HOST);
        $local = $host === 'localhost' || str_ends_with($host, '.test') || str_ends_with($host, '.localhost') || $host === '127.0.0.1';
        $context_ = stream_context_create(['http' => ['method' => 'GET', 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0, 'header' => "User-Agent: DharmaMcpPageCheck/1\r\nAccept: text/html\r\n"],
            'ssl' => ['verify_peer' => !$local, 'verify_peer_name' => !$local]]);
        $html = @file_get_contents($base . $path, false, $context_);

        if ($html === false) {
            throw ToolException::validation('The page could not be fetched from the server itself.');
        }

        $status = 0;
        $location = '';

        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
                $status = (int) $m[1];
            } elseif (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }
        }

        $text = static fn (string $value): string => trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m) ? $text($m[1]) : '';
        $description = preg_match('~<meta\s+name="description"\s+content="([^"]*)"~i', $html, $m) ? $text($m[1]) : '';
        $robots = preg_match('~<meta\s+name="robots"\s+content="([^"]*)"~i', $html, $m) ? $m[1] : '';
        $canonical = preg_match('~<link\s+href="([^"]*)"\s+rel="canonical"|<link\s+rel="canonical"\s+href="([^"]*)"~i', $html, $m) ? ($m[1] !== '' ? $m[1] : ($m[2] ?? '')) : '';
        $ogUrl = preg_match('~property="og:url"\s+content="([^"]*)"|content="([^"]*)"\s+property="og:url"~i', $html, $m) ? ($m[1] !== '' ? $m[1] : ($m[2] ?? '')) : '';
        preg_match_all('~<h1[^>]*>(.*?)</h1>~is', $html, $h1);
        preg_match_all('~<script type="application/ld\+json">(.*?)</script>~is', $html, $ld);
        $types = [];

        foreach ($ld[1] ?? [] as $block) {
            $data = json_decode($block, true);

            foreach (\is_array($data) ? ($data['@graph'] ?? [$data]) : [] as $node) {
                foreach ((array) ($node['@type'] ?? []) as $type) {
                    $types[$type] = true;
                }
            }
        }

        $problems = [];
        $length = static fn (string $v): int => mb_strlen($v);

        if ($status >= 300 && $status < 400) {
            $problems[] = "The page redirects ($status) to $location.";
        } elseif ($status !== 200) {
            $problems[] = "HTTP status is $status.";
        }
        if ($title === '') {
            $problems[] = 'No title.';
        } elseif ($length($title) > 65) {
            $problems[] = 'Title is longer than 65 characters.';
        }
        if ($description === '') {
            $problems[] = 'No description.';
        } elseif ($length($description) > 160) {
            $problems[] = 'Description is longer than 160 characters.';
        }
        if (\count($h1[1] ?? []) !== 1) {
            $problems[] = 'The page has ' . \count($h1[1] ?? []) . ' H1 headings (expected 1).';
        }
        if (stripos($robots, 'noindex') !== false) {
            $problems[] = 'The page is closed from indexing (noindex).';
        }
        if ($canonical !== '' && rtrim($canonical, '/') !== rtrim($base . strtok($path, '?'), '/')) {
            $problems[] = 'Canonical points to another address: ' . $canonical;
        }

        return [
            'path' => $path,
            'status' => $status,
            'title' => $title,
            'title_length' => $length($title),
            'description' => $description,
            'description_length' => $length($description),
            'h1' => array_map($text, $h1[1] ?? []),
            'canonical' => $canonical,
            'og_url' => $ogUrl,
            'robots' => $robots,
            'json_ld_types' => array_keys($types),
            'problems' => $problems,
        ];
    }
}
