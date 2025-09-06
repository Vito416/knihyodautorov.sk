// File: libs/Templates.php
<?php
declare(strict_types=1);

/**
 * Templates - jednoduchý renderer šablón s automatickým escapovaním.
 *
 * Použitie:
 *   echo Templates::render('emails/reset_password.php', ['name' => 'Ján', 'link' => Templates::raw($url)]);
 *
 * - Šablóny sú PHP súbory umiestnené v adresári VIEWS_DIR (definujte konštantu alebo preconfig $GLOBALS['config']['views_dir']).
 * - Všetky premenné sú predané do šablóny ako premenné, ale hodnoty sú automaticky escapované pomocou htmlspecialchars,
 *   pokiaľ nie sú označené ako Templates::raw(...).
 * - Zabránené sú directory traversal útoky (template názov musí byť relatívny a bez "..").
 */

class Templates
{
    private const DEFAULT_VIEWS_DIR = __DIR__ . '/../www/views';

    /**
     * Označí reťazec ako bezpečný HTML (nebude escapovaný).
     */
    public static function raw(string $html): SafeHtml
    {
        return new SafeHtml($html);
    }

    /**
     * Render template and return HTML as string.
     *
     * @param string $template Relative template path, e.g. 'emails/reset.php' or 'partials/header.php'
     * @param array $data Associative array of data passed to template
     */
    public static function render(string $template, array $data = []): string
    {
        // Security: disallow traversal
        if (strpos($template, '..') !== false) {
            throw new InvalidArgumentException('Invalid template path');
        }

        $viewsDir = $GLOBALS['config']['views_dir'] ?? self::DEFAULT_VIEWS_DIR;
        $path = rtrim($viewsDir, '/\\') . DIRECTORY_SEPARATOR . ltrim($template, '/\\');

        if (!file_exists($path) || !is_file($path)) {
            throw new RuntimeException('Template not found: ' . $path);
        }

        // Prepare escaped data. We convert all scalars to escaped strings except SafeHtml instances.
        $escData = [];
        foreach ($data as $k => $v) {
            if ($v instanceof SafeHtml) {
                $escData[$k] = $v; // keep raw
            } elseif (is_string($v) || is_numeric($v) || is_bool($v)) {
                $escData[$k] = htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            } else {
                // For arrays/objects we leave as-is; template author must handle further
                $escData[$k] = $v;
            }
        }

        // Extract into local scope for template but avoid overwriting internal vars
        extract($escData, EXTR_SKIP);

        ob_start();
        try {
            include $path;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}

/**
 * Wrapper class to indicate the string is safe HTML and mustn't be escaped.
 */
class SafeHtml
{
    private string $html;

    public function __construct(string $html)
    {
        $this->html = $html;
    }

    public function __toString(): string
    {
        return $this->html;
    }
}