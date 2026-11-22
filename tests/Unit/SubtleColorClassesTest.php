<?php

declare(strict_types=1);

namespace GlpiPlugin\Grcmanager\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1026 : GLPI and Tabler only ship the Bootstrap 5.3 `bg-X-subtle`, `text-X-emphasis` and
 * `border-X-subtle` classes for the theme colors (primary, secondary, success...). The named Tabler
 * colors (orange, blue, green...) used by GRC badges are defined by public/css/grcmanager.css; a
 * badge whose class is defined nowhere is rendered without a background.
 *
 * This test fails when the code uses such a class that neither the plugin stylesheet nor the
 * known Tabler list below defines, whether it is written literally (`bg-orange-subtle`) or built
 * from a color name (`bg-{{ info.color }}-subtle` with `'color' => 'orange'` on the PHP side).
 */
final class SubtleColorClassesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /** Theme colors for which GLPI 11/12 (Tabler 1.x, Bootstrap 5.3) define the classes. */
    private const THEME_COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'danger', 'light', 'dark'];

    /** Named Tabler colors: the plugin stylesheet must cover all of them for dynamic classes. */
    private const TABLER_NAMED_COLORS = [
        'blue', 'azure', 'indigo', 'purple', 'pink', 'red', 'orange', 'yellow', 'lime', 'green', 'teal', 'cyan',
    ];

    /** @return list<string> */
    private static function knownTablerClasses(): array
    {
        $classes = ['text-body-emphasis'];
        foreach (self::THEME_COLORS as $color) {
            $classes[] = 'bg-' . $color . '-subtle';
            $classes[] = 'text-' . $color . '-emphasis';
            $classes[] = 'border-' . $color . '-subtle';
        }

        return $classes;
    }

    /** @return list<string> */
    private static function pluginStylesheetClasses(): array
    {
        $css = file_get_contents(self::ROOT . '/public/css/grcmanager.css');
        self::assertIsString($css);
        preg_match_all('/^\.((?:bg|text|border)-[a-z]+-(?:subtle|emphasis))\s*\{/m', $css, $matches);

        return $matches[1];
    }

    /** @return array<string, string> path => contents of every Twig, PHP and JS source file */
    private static function sourceFiles(): array
    {
        $files = [];
        foreach (['templates', 'src', 'front', 'inc', 'ajax', 'js', 'public'] as $dir) {
            $path = self::ROOT . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if ($file instanceof \SplFileInfo && preg_match('/\.(twig|php|js)$/', $file->getFilename()) === 1) {
                    $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
                }
            }
        }
        foreach (['setup.php', 'hook.php'] as $file) {
            $files[$file] = (string) file_get_contents(self::ROOT . '/' . $file);
        }

        return $files;
    }

    private static function isDefined(string $class): bool
    {
        return in_array($class, self::knownTablerClasses(), true)
            || in_array($class, self::pluginStylesheetClasses(), true);
    }

    public function testStylesheetIsLoadedByTheAddCssHook(): void
    {
        $setup = (string) file_get_contents(self::ROOT . '/setup.php');
        // Issue #663 : la feuille d'accessibilité est chargée par le même crochet.
        foreach (['grcmanager', 'grcmanager-a11y'] as $sheet) {
            self::assertMatchesRegularExpression(
                '/ADD_CSS\]\[.grcmanager.\] = \[[^\]]*.css\/' . preg_quote($sheet, '/') . '\.css./',
                $setup
            );
        }
        self::assertFileExists(self::ROOT . '/public/css/grcmanager.css');
        self::assertFileExists(self::ROOT . '/public/css/grcmanager-a11y.css');
    }

    public function testStylesheetCoversEveryNamedTablerColorInLightAndDarkThemes(): void
    {
        $css = (string) file_get_contents(self::ROOT . '/public/css/grcmanager.css');
        // The rule itself (start of a line, followed by "{"), not a mention in the header comment.
        $darkStart = strpos($css, "\n[data-bs-theme=\"dark\"] {");
        self::assertNotFalse($darkStart, 'The stylesheet must define a dark theme variant.');
        $light = substr($css, 0, $darkStart);
        $dark = substr($css, $darkStart);
        foreach (self::TABLER_NAMED_COLORS as $color) {
            $classes = ['bg-' . $color . '-subtle', 'text-' . $color . '-emphasis', 'border-' . $color . '-subtle'];
            foreach ($classes as $class) {
                self::assertContains(
                    $class,
                    self::pluginStylesheetClasses(),
                    $class . ' is missing from public/css/grcmanager.css.'
                );
            }
            foreach (['bg-subtle', 'text-emphasis', 'border-subtle'] as $suffix) {
                $variable = '--grcmanager-' . $color . '-' . $suffix . ':';
                self::assertStringContainsString($variable, $light, $variable . ' (light theme)');
                self::assertStringContainsString($variable, $dark, $variable . ' (dark theme)');
            }
        }
    }

    public function testEveryLiteralSubtleOrEmphasisClassIsDefined(): void
    {
        $missing = [];
        foreach (self::sourceFiles() as $path => $contents) {
            preg_match_all('/(?<![\w-])((?:bg|text|border)-[a-z]+-(?:subtle|emphasis))(?![\w-])/', $contents, $matches);
            foreach (array_unique($matches[1]) as $class) {
                if (!self::isDefined($class)) {
                    $missing[] = $class . ' (' . basename($path) . ')';
                }
            }
        }

        self::assertSame(
            [],
            $missing,
            'Classes used by the code but defined neither by Tabler nor by public/css/grcmanager.css.'
        );
    }

    public function testEveryColorNameFeedingADynamicClassIsDefined(): void
    {
        $colors = [];
        foreach (self::sourceFiles() as $path => $contents) {
            // Twig: bg-{{ expr }}-subtle / text-{{ expr }}-emphasis -> quoted literals inside expr.
            $dynamicClass = '/(?:bg|text|border)-\{\{((?:(?!\}\}|\{\{).)*)\}\}-(?:subtle|emphasis)/s';
            preg_match_all($dynamicClass, $contents, $matches);
            foreach ($matches[1] as $expression) {
                // Operands of a comparison (line.decision == 'remove') are not colors.
                $comparison = '/(?:==|!=|\bin)\s*\'[a-z]+\'|\'[a-z]+\'\s*(?:==|!=)/';
                $expression = (string) preg_replace($comparison, '', $expression);
                preg_match_all("/'([a-z]+)'/", $expression, $literals);
                foreach ($literals[1] as $literal) {
                    $colors[$literal][] = basename($path);
                }
            }
            // PHP: 'color' => 'orange', 'tone' => 'red', 'avg_color' => ...
            preg_match_all("/'[a-z_]*(?:color|tone)' *=> *'([a-z]+)'/", $contents, $matches);
            foreach ($matches[1] as $literal) {
                $colors[$literal][] = basename($path);
            }
        }

        $missing = [];
        foreach ($colors as $color => $paths) {
            if (!self::isDefined('bg-' . $color . '-subtle') || !self::isDefined('text-' . $color . '-emphasis')) {
                $missing[] = $color . ' (' . implode(', ', array_unique($paths)) . ')';
            }
        }

        self::assertNotSame([], $colors, 'The scan must find the color names used by the badges.');
        self::assertSame(
            [],
            $missing,
            'Color names used in bg-X-subtle / text-X-emphasis classes without a definition.'
        );
    }
}
