<?php

declare(strict_types=1);

namespace App\Helpers;

use InvalidArgumentException;

/**
 * View – minimal template renderer.
 *
 * Renders a PHP template from the /templates directory, optionally wrapping
 * it inside the shared layout.
 *
 * Usage (from a controller / page script):
 *   View::render('home', ['title' => 'Home', 'teams' => $teams]);
 *
 * Inside a template the variables are available directly:
 *   <?= htmlspecialchars($title) ?>
 */
class View
{
    private static string $templateDir = '';

    /** Render a template, wrapping it in the site layout. */
    public static function render(string $template, array $vars = []): void
    {
        $content = self::capture($template, $vars);

        // Expose $content plus all caller-supplied vars to the layout.
        $vars['content'] = $content;
        self::output('layout', $vars);
    }

    /** Render a template without the layout (e.g. for AJAX partials). */
    public static function partial(string $template, array $vars = []): void
    {
        self::output($template, $vars);
    }

    // -------------------------------------------------------------------------

    /** Capture a template's output as a string. */
    private static function capture(string $template, array $vars): string
    {
        ob_start();
        self::output($template, $vars);
        return (string) ob_get_clean();
    }

    /** Include a template file with extracted variables. */
    private static function output(string $template, array $vars): void
    {
        $file = self::templateDir() . '/' . $template . '.php';

        if (!file_exists($file)) {
            throw new InvalidArgumentException("Template not found: {$template}");
        }

        // extract() puts array keys into local scope for the template.
        extract($vars, EXTR_SKIP);
        require $file;
    }

    private static function templateDir(): string
    {
        if (self::$templateDir === '') {
            self::$templateDir = dirname(__DIR__, 2) . '/templates';
        }

        return self::$templateDir;
    }
}
