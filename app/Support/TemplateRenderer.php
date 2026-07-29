<?php

namespace App\Support;

/**
 * Substitutes `{{PLACEHOLDER}}` tokens in a document-template body. Ported
 * verbatim from the frontend's `renderTemplate`
 * (frontend/dashboard/src/features/documents/renderTemplate.ts) so the
 * server-rendered body can never drift from the client-side wizard preview.
 * Unknown or empty keys render as `«—»` — the raw `{{TOKEN}}` must never
 * leak into a rendered document.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, string>  $values
     */
    public static function render(string $bodyTemplate, array $values): string
    {
        return preg_replace_callback(
            '/\{\{\s*([A-Z0-9_]+)\s*\}\}/',
            function (array $matches) use ($values) {
                $value = $values[$matches[1]] ?? null;

                return $value !== null && trim((string) $value) !== '' ? $value : '«—»';
            },
            $bodyTemplate,
        );
    }
}
