<?php

/**
 * PHP 8.4 `request_parse_body()` polyfill for environments still on PHP 8.3.
 *
 * Why this exists:
 *
 *   The Symfony http-foundation version installed here declares `php: >=8.4`
 *   and calls the new built-in `request_parse_body()` from
 *   `Request::createFromGlobals()` whenever a request method is one of
 *   PUT / DELETE / PATCH / QUERY. The dfdev container runs PHP 8.3.x, so the
 *   call fails fatally at the very top of `public/index.php`, before any
 *   DreamFactory code or logging fires — every DELETE/PATCH/PUT returns a
 *   500 with no useful trace.
 *
 *   The proper fix is to bump the container's PHP to 8.4, but no
 *   `df-base-img` tag publishes 8.4 yet. This polyfill is the cheap, safe
 *   workaround: it provides a minimum-viable implementation that satisfies
 *   Symfony's call site and lets DELETE-style requests pass through to
 *   Laravel's routing.
 *
 * Loading:
 *
 *   Registered via `autoload.files` in this package's composer.json, so it
 *   runs at the very top of `vendor/autoload.php` — before Symfony's class
 *   loader resolves the `request_parse_body` symbol.
 *
 * Fidelity:
 *
 *   PHP 8.4's built-in parses bodies according to Content-Type for any
 *   request method that carries one. This polyfill only handles
 *   `application/x-www-form-urlencoded` natively; for everything else it
 *   returns empty arrays and leaves Laravel to read the raw body via
 *   `php://input` itself (which is how DreamFactory already reads JSON
 *   payloads everywhere else, so no behavior change for our APIs).
 *
 *   Multipart/form-data on DELETE/PATCH bodies is not supported here. We
 *   don't use it in DreamFactory and PHP's built-in form parser is keyed
 *   on POST anyway.
 *
 *   On PHP 8.4+ the function_exists guard short-circuits, and the real
 *   built-in is used. The polyfill becomes a no-op the day the container
 *   upgrades.
 */

if (!function_exists('request_parse_body')) {

    if (!class_exists('RequestParseBodyException', false)) {
        /**
         * Polyfill of the global `RequestParseBodyException` introduced in
         * PHP 8.4. Symfony catches this via `\RequestParseBodyException`, so
         * it must live in the global namespace.
         */
        class RequestParseBodyException extends \RuntimeException
        {
        }
    }

    /**
     * @param array|null $options  Ignored in this polyfill; accepted for
     *                             signature compatibility with PHP 8.4.
     * @return array{0: array, 1: array}  [post-like parsed body, uploaded files]
     */
    function request_parse_body(?array $options = null): array
    {
        $rawContentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
        $mediaType = strtolower(trim(explode(';', $rawContentType)[0] ?? ''));

        $post  = [];
        $files = [];

        if ($mediaType === 'application/x-www-form-urlencoded') {
            $raw = @file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                parse_str($raw, $post);
            }
        }

        return [$post, $files];
    }
}
