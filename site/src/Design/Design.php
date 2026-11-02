<?php

declare(strict_types=1);

namespace Cms\Site\Design;

use InvalidArgumentException;
use Cms\Support\Files;

/**
 * The site's design system, kept with its content so it is edited in the CMS,
 * versioned in git and readable by agents:
 *
 *   content/design/tokens.json   tokens, fonts, element styles, prose, buttons, page transition (DesignJson)
 *   content/design/custom.css    extra CSS (appended last, unlayered — it wins)
 *   content/design/custom.js     extra JavaScript (an ES module; people with site.code only)
 *   content/design/fonts/        self-hosted .woff2 files
 *
 * Compiled to public/_cms/site/design-<hash>.css (and custom-<hash>.js);
 * fonts are copied to public/_cms/fonts/.
 */
final class Design
{
    private ?DesignJson $json = null;

    public function __construct(private readonly DesignCompiler $compiler) {}

    public function path(string $relative = ''): string
    {
        return content_path('design'.($relative !== '' ? '/'.ltrim($relative, '/') : ''));
    }

    public function json(): DesignJson
    {
        return $this->json ??= DesignJson::load($this->path('tokens.json'), dirname(__DIR__, 2).'/resources/design/tokens.json');
    }

    public function forget(): void
    {
        $this->json = null;
    }

    public function customCss(): string
    {
        return is_file($file = $this->path('custom.css')) ? (string) file_get_contents($file) : '';
    }

    public function customJs(): string
    {
        return is_file($file = $this->path('custom.js')) ? (string) file_get_contents($file) : '';
    }

    public function saveCode(string $kind, string $code): void
    {
        if (! in_array($kind, ['css', 'js'], true)) {
            throw new InvalidArgumentException(__('Code is css or js.'));
        }

        $file = $this->path("custom.{$kind}");

        trim($code) === '' ? @unlink($file) : Files::put($file, rtrim($code)."\n");
    }

    /** The compiled stylesheet URL (written only when it changed). */
    public function stylesheet(): string
    {
        return $this->publish('design', 'css', $this->compile($this->json(), $this->customCss()));
    }

    /** The stylesheet of a design and custom CSS — the saved one, or a draft for the editor's live preview. */
    public function compile(DesignJson $json, string $custom): string
    {
        $css = $this->compiler->css($json, fn (string $src) => $this->fontUrl($src));

        if (trim($custom) !== '') {
            $css .= "/* custom.css */\n".$custom;
        }

        return $css;
    }

    public function script(): ?string
    {
        $js = $this->customJs();

        return trim($js) === '' ? null : $this->publish('custom', 'js', $js);
    }

    /** @return list<string> font URLs to preload */
    public function preloads(): array
    {
        $out = [];

        foreach ($this->json()->fonts() as $font) {
            if (! empty($font['preload']) && isset($font['src']) && ($url = $this->fontUrl((string) $font['src'])) !== null) {
                $out[] = $url;
            }
        }

        return array_values(array_unique($out));
    }

    /** Fonts in content/design/fonts are served from public/_cms/fonts. */
    public function fontUrl(string $src): ?string
    {
        if (preg_match('#^(https://|/)#', $src)) {
            return $src;
        }

        $name = basename($src);

        if (! preg_match('/^[\w.-]+\.woff2$/', $name) || ! is_file($file = $this->path('fonts/'.$name))) {
            return null;
        }

        $target = public_path('_cms/fonts/'.$name);

        if (! is_file($target) || filemtime($target) < filemtime($file)) {
            Files::put($target, (string) file_get_contents($file));
        }

        return '/_cms/fonts/'.$name;
    }

    /** @return list<string> file names in content/design/fonts */
    public function fontFiles(): array
    {
        return array_map('basename', glob($this->path('fonts/*.woff2')) ?: []);
    }

    private function publish(string $name, string $ext, string $contents): string
    {
        $hash = substr(hash('xxh128', $contents), 0, 10);
        $relative = "_cms/site/{$name}-{$hash}.{$ext}";

        if (! is_file(public_path($relative))) {
            foreach (glob(public_path("_cms/site/{$name}-*.{$ext}")) ?: [] as $old) {
                @unlink($old);
            }

            Files::put(public_path($relative), $contents);
        }

        return '/'.$relative;
    }
}
