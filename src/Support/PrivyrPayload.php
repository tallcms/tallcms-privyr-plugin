<?php

declare(strict_types=1);

namespace Tallcms\Privyr\Support;

/**
 * Builds the JSON body for Privyr's generic incoming-leads webhook.
 *
 * Privyr renders each top-level key as a labelled line, so we promote the
 * recognised lead attributes (name, email, phone, message, page_url) and add
 * every *remaining* field as its own labelled key — readable in Privyr and
 * lossless. Colliding labels are de-duplicated with a numeric suffix; nothing
 * is dropped or overwritten.
 */
class PrivyrPayload
{
    /**
     * @param  array<int, array<string, mixed>>  $formData  Contact submission form_data
     *                                                       entries: [{name,label,type,value}]
     * @return array<string, mixed>
     */
    public static function fromSubmissionData(
        ?string $name,
        ?string $email,
        array $formData,
        ?string $pageUrl,
    ): array {
        $payload = [];

        if ($name !== null && $name !== '') {
            $payload['name'] = $name;
        }
        if ($email !== null && $email !== '') {
            $payload['email'] = $email;
        }
        if ($pageUrl !== null && $pageUrl !== '') {
            $payload['page_url'] = $pageUrl;
        }

        // Promote phone/message from their form fields and mark them consumed so
        // they aren't repeated as generic labelled lines below.
        $consumed = [];

        $phoneIdx = static::findPhoneIndex($formData);
        if ($phoneIdx !== null) {
            $payload['phone'] = (string) $formData[$phoneIdx]['value'];
            $consumed[$phoneIdx] = true;
        }

        $messageIdx = static::findMessageIndex($formData);
        if ($messageIdx !== null) {
            $payload['message'] = (string) $formData[$messageIdx]['value'];
            $consumed[$messageIdx] = true;
        }

        foreach ($formData as $i => $field) {
            if (! is_array($field) || isset($consumed[$i])) {
                continue;
            }

            // name/email are already promoted to top-level keys.
            if (in_array($field['name'] ?? null, ['name', 'email'], true)) {
                continue;
            }

            $value = $field['value'] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $label = (string) ($field['label'] ?? $field['name'] ?? 'Field');
            $payload[static::uniqueKey($payload, $label)] = is_scalar($value)
                ? (string) $value
                : json_encode($value);
        }

        return $payload;
    }

    /**
     * @param  array<int, array<string, mixed>>  $formData
     */
    private static function findPhoneIndex(array $formData): ?int
    {
        // Prefer an explicit tel field...
        foreach ($formData as $i => $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'tel' && ! empty($field['value'])) {
                return $i;
            }
        }

        // ...then fall back to name/label heuristics.
        foreach ($formData as $i => $field) {
            if (! is_array($field) || empty($field['value'])) {
                continue;
            }
            $haystack = strtolower((string) ($field['name'] ?? '').' '.(string) ($field['label'] ?? ''));
            if (preg_match('/\b(phone|mobile|contact|tel)\b/', $haystack)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $formData
     */
    private static function findMessageIndex(array $formData): ?int
    {
        foreach ($formData as $i => $field) {
            if (! is_array($field) || empty($field['value'])) {
                continue;
            }
            $name = strtolower((string) ($field['name'] ?? ''));
            if ($name === 'message' || ($field['type'] ?? null) === 'textarea') {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function uniqueKey(array $payload, string $label): string
    {
        if (! array_key_exists($label, $payload)) {
            return $label;
        }

        $n = 2;
        while (array_key_exists("{$label} ({$n})", $payload)) {
            $n++;
        }

        return "{$label} ({$n})";
    }
}
