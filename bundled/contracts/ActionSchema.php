<?php
declare(strict_types=1);
namespace CGF;

/** Bounded JSON Schema subset: one contract for tool discovery and execution. */
final class ActionSchema {
    private const MAX_DEPTH = 12;
    private const MAX_NODES = 1000;
    private const TYPES = ['object','array','string','integer','number','boolean','null'];

    public static function definitions(array $schema): array {
        if (($schema['type'] ?? null) !== 'object') throw new \RuntimeException('Action schema root must be an object');
        $nodes = 0;
        self::checkDefinition($schema, 0, $nodes);
        return self::normalize($schema);
    }

    private static function checkDefinition(array $schema, int $depth, int &$nodes): void {
        if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) throw new \RuntimeException('Action schema complexity limit exceeded');
        $type = $schema['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) throw new \RuntimeException('Unsupported action schema type');
        $supported = ['type','description','properties','required','additionalProperties','items','enum',
            'minLength','maxLength','minimum','maximum','minItems','maxItems','format'];
        foreach ($schema as $key => $value) {
            if (!in_array($key, $supported, true)) throw new \RuntimeException('Unsupported schema keyword: '.$key);
        }
        if (isset($schema['description']) && !is_string($schema['description'])) throw new \RuntimeException('Invalid schema description');
        if (isset($schema['enum']) && (!is_array($schema['enum']) || !$schema['enum'] || !array_is_list($schema['enum']))) {
            throw new \RuntimeException('Invalid enum');
        }
        if ($type === 'object') {
            $props = $schema['properties'] ?? [];
            if (!is_array($props) || ($props !== [] && array_is_list($props))) throw new \RuntimeException('Invalid object properties');
            $required = $schema['required'] ?? [];
            if (!is_array($required) || !array_is_list($required)) throw new \RuntimeException('Invalid required list');
            foreach ($required as $name) {
                if (!is_string($name) || !array_key_exists($name, $props)) throw new \RuntimeException('Required property must be declared');
            }
            foreach ($props as $name => $child) {
                if (!is_string($name) || $name === '' || !is_array($child)) throw new \RuntimeException('Invalid property definition');
                self::checkDefinition($child, $depth + 1, $nodes);
            }
            $additional = $schema['additionalProperties'] ?? false;
            if (!is_bool($additional) && !is_array($additional)) throw new \RuntimeException('Invalid additionalProperties');
            if (is_array($additional)) self::checkDefinition($additional, $depth + 1, $nodes);
        } elseif (isset($schema['properties']) || isset($schema['required']) || isset($schema['additionalProperties'])) {
            throw new \RuntimeException('Object keywords on a non-object');
        }
        if ($type === 'array') {
            if (!isset($schema['items']) || !is_array($schema['items'])) throw new \RuntimeException('Array schema requires items');
            self::checkDefinition($schema['items'], $depth + 1, $nodes);
        } elseif (isset($schema['items'])) throw new \RuntimeException('items is only valid for arrays');
        self::checkLimits($schema, 'minLength', 'maxLength', $type === 'string');
        self::checkLimits($schema, 'minItems', 'maxItems', $type === 'array');
        if (isset($schema['minimum']) || isset($schema['maximum'])) {
            if (!in_array($type, ['integer','number'], true)) throw new \RuntimeException('Numeric bounds on a non-number');
            foreach (['minimum','maximum'] as $key) {
                if (isset($schema[$key]) && !is_int($schema[$key]) && !is_float($schema[$key])) throw new \RuntimeException('Invalid number bound');
            }
            if (isset($schema['minimum'], $schema['maximum']) && $schema['minimum'] > $schema['maximum']) {
                throw new \RuntimeException('Invalid numeric range');
            }
        }
        if (isset($schema['format']) && ($type !== 'string' || $schema['format'] !== 'email')) {
            throw new \RuntimeException('Unsupported string format');
        }
        foreach ($schema['enum'] ?? [] as $v) {
            if (!self::matchesType($v, $type)) throw new \RuntimeException('Enum item has wrong type');
        }
    }

    private static function checkLimits(array $schema, string $min, string $max, bool $allowed): void {
        if (!$allowed && (isset($schema[$min]) || isset($schema[$max]))) throw new \RuntimeException('Invalid schema bounds');
        foreach ([$min, $max] as $key) {
            if (isset($schema[$key]) && (!is_int($schema[$key]) || $schema[$key] < 0 || $schema[$key] > 1000000)) {
                throw new \RuntimeException('Invalid schema bound');
            }
        }
        if (isset($schema[$min], $schema[$max]) && $schema[$min] > $schema[$max]) throw new \RuntimeException('Invalid schema range');
    }

    private static function normalize(array $schema): array {
        if ($schema['type'] === 'object') {
            $props = $schema['properties'] ?? [];
            foreach ($props as $name => $child) $props[$name] = self::normalize($child);
            $schema['properties'] = $props === [] ? new \stdClass() : $props;
            if (is_array($schema['additionalProperties'] ?? null)) {
                $schema['additionalProperties'] = self::normalize($schema['additionalProperties']);
            }
        }
        if ($schema['type'] === 'array') $schema['items'] = self::normalize($schema['items']);
        return $schema;
    }

    public static function assertValue(mixed $value, array $schema, string $path = '$', int $depth = 0): void {
        if ($depth > self::MAX_DEPTH) throw new HttpError(422, 'Données trop imbriquées : '.$path);
        $type = $schema['type'];
        if (!self::matchesType($value, $type)) throw new HttpError(422, 'Type invalide : '.$path);
        if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) throw new HttpError(422, 'Valeur invalide : '.$path);
        if ($type === 'string') {
            $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : preg_match_all('/./us', $value);
            if ($length === false) throw new HttpError(422, 'Chaîne UTF-8 invalide : '.$path);
            if ($length < ($schema['minLength'] ?? 0) || $length > ($schema['maxLength'] ?? 5000)) {
                throw new HttpError(422, 'Longueur invalide : '.$path);
            }
            if (($schema['format'] ?? null) === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                throw new HttpError(422, 'Email invalide : '.$path);
            }
        } elseif ($type === 'integer' || $type === 'number') {
            if ((isset($schema['minimum']) && $value < $schema['minimum']) ||
                (isset($schema['maximum']) && $value > $schema['maximum'])) {
                throw new HttpError(422, 'Nombre hors limites : '.$path);
            }
        } elseif ($type === 'array') {
            $size = count($value);
            if ($size < ($schema['minItems'] ?? 0) || $size > ($schema['maxItems'] ?? 1000)) {
                throw new HttpError(422, 'Taille de liste invalide : '.$path);
            }
            foreach ($value as $i => $item) self::assertValue($item, $schema['items'], $path.'['.$i.']', $depth + 1);
        } elseif ($type === 'object') {
            $properties = $schema['properties'] ?? [];
            $extra = $schema['additionalProperties'] ?? false;
            if (count($value) > 1000) throw new HttpError(422, 'Objet trop volumineux : '.$path);
            foreach ($schema['required'] ?? [] as $key) {
                if (!array_key_exists($key, $value)) throw new HttpError(422, 'Champ requis : '.$path.'.'.$key);
            }
            foreach ($value as $key => $item) {
                if (array_key_exists($key, $properties)) {
                    self::assertValue($item, $properties[$key], $path.'.'.$key, $depth + 1);
                } elseif ($extra === false) {
                    throw new HttpError(422, 'Champ interdit : '.$path.'.'.$key);
                } elseif (is_array($extra)) {
                    self::assertValue($item, $extra, $path.'.'.$key, $depth + 1);
                }
            }
        }
    }

    private static function matchesType(mixed $value, string $type): bool {
        return match ($type) {
            'string' => is_string($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'boolean' => is_bool($value),
            'null' => $value === null,
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ($value === [] || !array_is_list($value)),
            default => false,
        };
    }
}
