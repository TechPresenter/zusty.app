<?php

declare(strict_types=1);

namespace Zuste\Core;

use Zuste\Helpers\Csrf;
use Zuste\Helpers\Str;

/**
 * Plain-PHP view renderer for the admin panel.
 *
 * Templates are ordinary .php files under app/Views. Data is extracted into
 * local variables, and `$this` inside a template is this renderer, so helpers
 * like `$this->e()` and `$this->icon()` are available everywhere.
 */
final class View
{
    private static string $viewPath = '';

    /** @var array<string,mixed> Shared with every template. */
    private static array $shared = [];

    /** @var array<string,string> Captured named sections. */
    private array $sections = [];

    private ?string $currentSection = null;

    private ?string $layout = null;

    public static function setPath(string $path): void
    {
        self::$viewPath = rtrim($path, '/\\');
    }

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /**
     * Render a template to a string.
     *
     * @param array<string,mixed> $data
     */
    public static function render(string $template, array $data = []): string
    {
        return (new self())->renderTemplate($template, $data);
    }

    /** Render and send as an HTML response. */
    public static function display(string $template, array $data = [], int $status = 200): never
    {
        Response::html(self::render($template, $data), $status);
    }

    /** @param array<string,mixed> $data */
    private function renderTemplate(string $template, array $data): string
    {
        $file = self::$viewPath . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $template) . '.php';

        if (!is_file($file)) {
            throw new \RuntimeException('View not found: ' . $template);
        }

        $variables = array_merge(self::$shared, $data);

        ob_start();

        // Templates read these as plain locals; `$this` is the renderer.
        extract($variables, EXTR_SKIP);
        require $file;

        $content = (string) ob_get_clean();

        // If the template declared a layout, wrap the output in it.
        if ($this->layout !== null) {
            $layout       = $this->layout;
            $this->layout = null;

            // A template may either capture its body into a named `content`
            // section with start()/stop(), or simply echo it. Only fall back
            // to the raw buffer when no section was captured — otherwise the
            // section would be clobbered by the (empty) outer buffer.
            if (($this->sections['content'] ?? '') === '') {
                $this->sections['content'] = $content;
            }

            return $this->renderTemplate($layout, $variables);
        }

        return $content;
    }

    // -----------------------------------------------------------------
    // Template API
    // -----------------------------------------------------------------

    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    public function start(string $section): void
    {
        $this->currentSection = $section;
        ob_start();
    }

    public function stop(): void
    {
        if ($this->currentSection === null) {
            return;
        }

        $this->sections[$this->currentSection] = (string) ob_get_clean();
        $this->currentSection                  = null;
    }

    public function section(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    /** Include a partial with its own scope. */
    public function partial(string $template, array $data = []): void
    {
        echo self::render($template, $data);
    }

    // -----------------------------------------------------------------
    // Escaping and formatting helpers
    // -----------------------------------------------------------------

    /** Escape for HTML. Use this for every dynamic value. */
    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Escape for use inside a JavaScript string or data-* attribute. */
    public function json(mixed $value): string
    {
        return htmlspecialchars(
            (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT),
            ENT_QUOTES,
            'UTF-8'
        );
    }

    /** Render trusted CMS HTML that has already been sanitised. */
    public function html(?string $value): string
    {
        return Str::sanitizeHtml((string) $value);
    }

    public function csrf(): string
    {
        return Csrf::field();
    }

    public function csrfToken(): string
    {
        return Csrf::token();
    }

    public function old(string $key, mixed $default = ''): string
    {
        return $this->e(Session::oldInput($key, $default));
    }

    /** First validation error for a field, or ''. */
    public function error(string $field): string
    {
        $errors = Session::errors();

        return isset($errors[$field][0]) ? $this->e($errors[$field][0]) : '';
    }

    public function hasError(string $field): bool
    {
        return isset(Session::errors()[$field]);
    }

    public function date(?string $value, string $format = 'd M Y'): string
    {
        if ($value === null || $value === '' || $value === '0000-00-00 00:00:00') {
            return '—';
        }

        $timestamp = strtotime($value);

        return $timestamp === false ? '—' : date($format, $timestamp);
    }

    public function dateTime(?string $value): string
    {
        return $this->date($value, 'd M Y, g:i A');
    }

    public function timeAgo(?string $value): string
    {
        return Str::timeAgo($value) ?: '—';
    }

    public function money(mixed $amount, ?string $currency = null): string
    {
        return \Zuste\Helpers\Money::format((float) $amount, $currency);
    }

    public function moneyShort(mixed $amount, ?string $currency = null): string
    {
        return \Zuste\Helpers\Money::formatShort((float) $amount, $currency);
    }

    public function number(mixed $value): string
    {
        return number_format((float) $value);
    }

    public function compact(mixed $value): string
    {
        return Str::compactNumber((float) $value);
    }

    public function initials(string $name): string
    {
        return Str::initials($name);
    }

    /** Build a URL preserving the current query string with overrides. */
    public function queryUrl(array $overrides = [], ?string $path = null): string
    {
        $query = array_merge($_GET, $overrides);
        $query = array_filter($query, static fn ($v): bool => $v !== '' && $v !== null);

        $path ??= strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

        return $this->e($path . ($query === [] ? '' : '?' . http_build_query($query)));
    }

    public function isActive(string $prefix): bool
    {
        $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

        return $path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/');
    }

    /** Does the signed-in admin hold this permission? */
    public function can(string $permission): bool
    {
        $permissions = self::$shared['permissions'] ?? [];

        return $permission === '' || (is_array($permissions) && in_array($permission, $permissions, true));
    }

    /**
     * Inline SVG icon (Lucide-style, 24×24, 1.75 stroke) — matches the
     * "linear icons with rounded terminals" rule in the design system.
     */
    public function icon(string $name, string $classes = 'ic'): string
    {
        $paths = self::iconPaths();
        $path  = $paths[$name] ?? $paths['circle'];

        return sprintf(
            '<svg class="%s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" '
            . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%s</svg>',
            $this->e($classes),
            $path
        );
    }

    /** @return array<string,string> */
    private static function iconPaths(): array
    {
        static $paths = null;

        return $paths ??= [
            'dashboard'   => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
            'users'       => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
            'star'        => '<path d="m12 2 3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14l-5-4.87 6.91-1.01L12 2z"/>',
            'grid'        => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/>',
            'calendar'    => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
            // Used by the command palette's "Recent" group. Drawn on the same
            // 24-grid and stroke weight as the rest so it sits level with them.
            'clock'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
            'wallet'      => '<path d="M19 7V5a2 2 0 0 0-2-2H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/><path d="M18 12a2 2 0 0 0 0 4h4v-4z"/>',
            'card'        => '<rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/>',
            'chart'       => '<path d="M3 3v18h18"/><path d="m19 9-5 5-4-4-3 3"/>',
            'file'        => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6"/>',
            'image'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-3.09-3.09a2 2 0 0 0-2.82 0L6 21"/>',
            'bell'        => '<path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.94 1.94 0 0 0 3.4 0"/>',
            'chat'        => '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z"/>',
            'headset'     => '<path d="M3 14v-3a9 9 0 0 1 18 0v3"/><path d="M21 16a2 2 0 0 1-2 2h-1a2 2 0 0 1-2-2v-2a2 2 0 0 1 2-2h3zM3 16a2 2 0 0 0 2 2h1a2 2 0 0 0 2-2v-2a2 2 0 0 0-2-2H3z"/>',
            'shield'      => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
            // The verification-badge catalogue and the profile-lock screen name
            // these. icon() renders nothing for a name it does not hold, so
            // without them eight badges and every lock row drew a blank space.
            'lock'        => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
            'unlock'      => '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
            'award'       => '<circle cx="12" cy="8" r="6"/><path d="m8.21 13.89-1.2 7.11L12 18.5l4.99 2.5-1.2-7.11"/>',
            'briefcase'   => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
            'link'        => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
            'mail'        => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 7-10 6L2 7"/>',
            'verified'    => '<path d="m12 2 2.4 2.4 3.4-.6.6 3.4L22 12l-2.4 2.4.6 3.4-3.4.6L12 22l-2.4-2.4-3.4.6-.6-3.4L2 12l2.4-2.4-.6-3.4 3.4-.6z"/><path d="m9 12 2 2 4-4"/>',
            'rotate-cw'   => '<path d="M21 2v6h-6"/><path d="M21 12a9 9 0 1 1-3-6.7L21 8"/>',
            // Named by the engagement_event and profile_view_source taxonomies
            // (migration 039). icon() falls back to a plain circle for an
            // unknown name, so without these the share, QR and acquisition-path
            // rows would all draw the same meaningless dot.
            'share'       => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 13.5 6.8 4M15.4 6.5l-6.8 4"/>',
            'qr-code'     => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM20 14h1M14 20h3M20 17v4"/>',
            'home'        => '<path d="m3 10 9-7 9 7v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 21v-7h6v7"/>',
            'compass'     => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5.5-5.5 2 2-5.5z"/>',
            'globe'       => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a15 15 0 0 1 0 18 15 15 0 0 1 0-18z"/>',
            'settings'    => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.6h.09A1.65 1.65 0 0 0 10 3.09V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
            'logout'      => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
            'search'      => '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/>',
            'plus'        => '<path d="M12 5v14M5 12h14"/>',
            // An unknown name silently renders a plain circle, so a typo
            // ships as a meaningless dot. These four were being referenced
            // by KYC and taxonomy views without ever having been defined.
            'arrow-left'  => '<path d="M19 12H5"/><path d="m12 19-7-7 7-7"/>',
            'arrow-right' => '<path d="M5 12h14"/><path d="m12 5 7 7-7 7"/>',
            'help-circle' => '<circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3"/><path d="M12 17h.01"/>',
            'smartphone'  => '<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M12 18h.01"/>',
            'alert-triangle' => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
            'edit'        => '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
            'trash'       => '<path d="M3 6h18M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2m3 0v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><path d="M10 11v6M14 11v6"/>',
            'eye'         => '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>',
            'check'       => '<path d="M20 6 9 17l-5-5"/>',
            'check-circle'=> '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
            'x'           => '<path d="M18 6 6 18M6 6l12 12"/>',
            'alert'       => '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><path d="M12 9v4M12 17h.01"/>',
            'info'        => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4M12 8h.01"/>',
            'download'    => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/>',
            'upload'      => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/>',
            'filter'      => '<path d="M22 3H2l8 9.46V19l4 2v-8.54z"/>',
            'play'        => '<polygon points="6 3 20 12 6 21 6 3"/>',
            'refresh'     => '<path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/>',
            'menu'        => '<path d="M3 12h18M3 6h18M3 18h18"/>',
            'chevron-left'=> '<path d="m15 18-6-6 6-6"/>',
            'chevron-right'=> '<path d="m9 18 6-6-6-6"/>',
            'chevron-down'=> '<path d="m6 9 6 6 6-6"/>',
            'arrow-up'    => '<path d="M12 19V5M5 12l7-7 7 7"/>',
            'arrow-down'  => '<path d="M12 5v14M19 12l-7 7-7-7"/>',
            'trending-up' => '<path d="m22 7-8.5 8.5-5-5L2 17"/><path d="M16 7h6v6"/>',
            'moon'        => '<path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>',
            'sun'         => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M6.34 17.66l-1.41 1.41M19.07 4.93l-1.41 1.41"/>',
            'database'    => '<ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/>',
            'flag'        => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/><path d="M4 22v-7"/>',
            'key'         => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
            'user-check'  => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/>',
            'external'    => '<path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/><path d="M15 3h6v6M10 14 21 3"/>',
            'copy'        => '<rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>',
            'circle'      => '<circle cx="12" cy="12" r="10"/>',
            'inbox'       => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/>',
            'megaphone'   => '<path d="m3 11 18-5v12L3 14v-3z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/>',
        ];
    }
}
