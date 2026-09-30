<?php

declare(strict_types=1);

namespace App\Services\Games;

/**
 * Чтение и запись игровых конфигов через панель.
 *
 * Поддерживаемые форматы описаны в games.config_files[].format:
 *   properties  — server.properties, server.cfg (SAMP/MTA — свои секции)
 *   json        — config.json (CRMP, RAGE.MP, ALTV)
 *   yaml        — pocketmine.yml
 *   cfg         — Source/Valve (autoexec.cfg)
 *   ini         — GameUserSettings.ini (ARK)
 *   samp_cfg    — server.cfg SAMP (ключ-значение с кавычками)
 *   mta_cfg     — server.cfg MTA (двойные двоеточия)
 *   rust_cfg    — serverconfig.cfg (CFG-формат с кавычками и // комментариями)
 *   unturned_dat— Commands.dat (JSON-массив)
 *   js          — altv.config.js (только чтение, правка — полным файлом)
 *
 * Каждый парсер умеет и прочитать значения по описанным полям, и записать их обратно,
 * не трогая комментарии и неизвестные ключи.
 */
class ConfigFileService
{
    // ── Чтение ──────────────────────────────────────────────────────────

    /**
     * @return array{format: string, values: array<string, mixed>, raw: string}
     */
    public function read(string $raw, string $format): array
    {
        return match ($format) {
            'json' => ['format' => $format, 'values' => json_decode($raw, true) ?: [], 'raw' => $raw],
            'yaml' => ['format' => $format, 'values' => $this->parseYaml($raw), 'raw' => $raw],
            'unturned_dat' => ['format' => $format, 'values' => $this->parseDat($raw), 'raw' => $raw],
            'mta_cfg' => ['format' => $format, 'values' => $this->parseKeyValue($raw, ':', ['#', ';']), 'raw' => $raw],
            'samp_cfg' => ['format' => $format, 'values' => $this->parseKeyValue($raw, '=', ['//', ';']), 'raw' => $raw],
            'rust_cfg' => ['format' => $format, 'values' => $this->parseKeyValue($raw, '=', ['//', '#']), 'raw' => $raw],
            'cfg' => ['format' => $format, 'values' => $this->parseKeyValue($raw, ' ', ['//']), 'raw' => $raw],
            'ini' => ['format' => $format, 'values' => $this->parseIni($raw), 'raw' => $raw],
            default => ['format' => 'properties', 'values' => $this->parseProperties($raw), 'raw' => $raw],
        };
    }

    /** Достаёт из файла только те поля, что описаны в схеме игры. */
    public function extractFields(string $raw, array $fields, string $format): array
    {
        $values = $this->read($raw, $format)['values'];
        $out = [];

        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            if ($key === null) {
                continue;
            }

            $out[$key] = [
                'label' => $field['label'] ?? $key,
                'type' => $field['type'] ?? 'text',
                'value' => data_get($values, $key, $field['default'] ?? null),
                'options' => $field['options'] ?? null,
                'hint' => $field['hint'] ?? null,
                'read_only' => (bool) ($field['read_only'] ?? false),
                'min' => $field['min'] ?? null,
                'max' => $field['max'] ?? null,
                'maxlength' => $field['maxlength'] ?? null,
            ];
        }

        return $out;
    }

    // ── Запись ──────────────────────────────────────────────────────────

    /**
     * Применяет изменения и возвращает новое содержимое файла.
     *
     * @param  array<string, mixed>  $values  ключи из схемы (в т.ч. с точками)
     */
    public function write(string $raw, array $values, string $format): string
    {
        if ($format === 'json') {
            $data = json_decode($raw, true) ?: [];

            foreach ($values as $key => $value) {
                data_set($data, $key, $value);
            }

            return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if ($format === 'unturned_dat') {
            $data = json_decode(trim($raw), true) ?: [];

            foreach ($values as $key => $value) {
                $found = false;
                foreach ($data as $i => $row) {
                    if (($row['Key'] ?? null) === $key) {
                        $data[$i]['Value'] = $value;
                        $found = true;
                        break;
                    }
                }

                if (! $found) {
                    $data[] = ['Key' => $key, 'Value' => $value];
                }
            }

            return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $format = match ($format) {
            'mta_cfg' => ['delim' => ':', 'comments' => ['#', ';']],
            'samp_cfg' => ['delim' => '=', 'comments' => ['//', ';']],
            'rust_cfg' => ['delim' => '=', 'comments' => ['//', '#']],
            'cfg' => ['delim' => ' ', 'comments' => ['//']],
            default => ['delim' => '=', 'comments' => ['#', ';']],
        };

        $lines = preg_split("/\r\n|\n|\r/", $raw) ?: [];
        $applied = [];
        $result = [];

        foreach ($lines as $line) {
            $trimmed = ltrim($line);
            $isComment = false;

            foreach ($format['comments'] as $prefix) {
                if (str_starts_with($trimmed, $prefix)) {
                    $isComment = true;
                    break;
                }
            }

            if (! $isComment && $trimmed !== '' && str_contains($line, $format['delim'])) {
                $key = trim(strtok($line, $format['delim']));

                if (array_key_exists($key, $values) && ! isset($applied[$key])) {
                    $result[] = $this->formatLine($key, $values[$key], $format['delim'], $format);
                    $applied[$key] = true;

                    continue;
                }
            }

            $result[] = $line;
        }

        // Новые ключи дописываем в конец
        foreach ($values as $key => $value) {
            if (! isset($applied[$key])) {
                $result[] = $this->formatLine($key, $value, $format['delim'], $format);
            }
        }

        return implode("\n", $result);
    }

    private function formatLine(string $key, mixed $value, string $delim, array $format): string
    {
        $rendered = match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            is_array($value) => implode(',', $value),
            default => (string) $value,
        };

        if ($format['comments'][0] === '//') {
            $rendered = '"'.str_replace('"', '\"', $rendered).'"';
        }

        return $key.$delim.$rendered;
    }

    // ── Парсеры ─────────────────────────────────────────────────────────

    /** server.properties, spigot.yml-подобные плоские файлы. */
    public function parseProperties(string $raw): array
    {
        $out = [];

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '!')) {
                continue;
            }

            if (! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $out[trim($key)] = trim($value);
        }

        return $out;
    }

    /** Универсальный key=value парсер с разделителем и комментариями. */
    public function parseKeyValue(string $raw, string $delim, array $comments = ['#']): array
    {
        $out = [];

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '') {
                continue;
            }

            $isComment = false;
            foreach ($comments as $prefix) {
                if (str_starts_with($trimmed, $prefix)) {
                    $isComment = true;
                    break;
                }
            }

            if ($isComment) {
                continue;
            }

            if (! str_contains($line, $delim)) {
                continue;
            }

            [$key, $value] = explode($delim, $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, " \t\"'");

            if ($key === '') {
                continue;
            }

            $out[$key] = $this->castValue($value);
        }

        return $out;
    }

    /** INI с секциями [Section] — GameUserSettings.ini. */
    public function parseIni(string $raw): array
    {
        $out = [];
        $section = null;

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, ';') || str_starts_with($trimmed, '#')) {
                continue;
            }

            if (preg_match('/^\[(.+)\]$/', $trimmed, $m)) {
                $section = trim($m[1]);

                continue;
            }

            if (! str_contains($trimmed, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $trimmed, 2);
            $key = trim($key);
            $value = trim(trim($value), " \t\"'");
            $value = $this->castValue($value);

            $out[$section !== null ? $section.'.'.$key : $key] = $value;
        }

        return $out;
    }

    /** Минимальный YAML: вложенные отступы, скаляры, списки через запятую. */
    public function parseYaml(string $raw): array
    {
        $out = [];
        $stack = [['indent' => -1, 'node' => &$out]];

        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            if (trim($line) === '' || str_starts_with(trim($line), '#')) {
                continue;
            }

            $indent = strlen($line) - strlen(ltrim($line));
            $content = trim($line);

            while (count($stack) > 1 && $indent <= $stack[count($stack) - 1]['indent']) {
                array_pop($stack);
            }

            $parent = &$stack[count($stack) - 1]['node'];

            if (preg_match('/^([^:]+):\s*(.*)$/', $content, $m)) {
                $key = trim($m[1], " \t\"'");
                $value = trim($m[2]);

                if ($value === '') {
                    $child = [];
                    $parent[$key] = &$child;
                    $stack[] = ['indent' => $indent, 'node' => &$child];

                    continue;
                }

                $parent[$key] = $this->parseScalar($value);
            }
        }

        return $out;
    }

    /** pocketmine.yml устроен как YAML — сериализуем обратно. */
    public function dumpYaml(array $data, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[] = $pad.$key.':';
                $out[] = $this->dumpYaml($value, $indent + 1);
            } else {
                $out[] = $pad.$key.': '.$this->dumpScalar($value);
            }
        }

        return implode("\n", $out);
    }

    /** Commands.dat — JSON-массив объектов {Key, Value}. */
    public function parseDat(string $raw): array
    {
        $decoded = json_decode(trim($raw), true);

        if (is_array($decoded)) {
            $out = [];
            foreach ($decoded as $row) {
                if (isset($row['Key'])) {
                    $out[$row['Key']] = $row['Value'];
                }
            }

            return $out;
        }

        // Формат «Key = Value» построчно (старые версии)
        return $this->parseKeyValue($raw, '=', ['//', '#']);
    }

    // ── Внутреннее ──────────────────────────────────────────────────────

    private function parseScalar(string $value): mixed
    {
        $value = trim($value);
        $value = trim($value, " \t\"'");

        return $this->castValue($value);
    }

    private function dumpScalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_numeric($value)) {
            return (string) $value;
        }

        return (string) $value;
    }

    private function castValue(string $value): mixed
    {
        $lower = strtolower($value);

        if ($lower === 'true' || $lower === 'yes' || $lower === 'on') {
            return true;
        }

        if ($lower === 'false' || $lower === 'no' || $lower === 'off') {
            return false;
        }

        if (is_numeric($value) && ! str_starts_with($value, '0') || preg_match('/^-?\d+$/', $value) === 1) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    /**
     * Валидация значения по описанию поля схемы.
     */
    public function validateValue(string $key, mixed $value, array $field): ?string
    {
        $type = $field['type'] ?? 'text';

        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'number', 'port' => $this->validateNumber($key, $value, $field),
            'bool' => is_bool($value) || in_array($value, ['true', 'false', '0', '1', 0, 1], true)
                ? null
                : __('games.errors.invalid_bool'),
            'select' => isset($field['options']) && ! in_array((string) $value, array_map('strval', (array) $field['options']), true)
                ? __('games.errors.invalid_option')
                : null,
            'text' => isset($field['maxlength']) && mb_strlen((string) $value) > (int) $field['maxlength']
                ? __('games.errors.too_long', ['max' => $field['maxlength']])
                : null,
            default => null,
        };
    }

    private function validateNumber(string $key, mixed $value, array $field): ?string
    {
        if (! is_numeric($value)) {
            return __('games.errors.invalid_number');
        }

        $num = $value + 0;

        if (isset($field['min']) && $num < $field['min']) {
            return __('games.errors.too_small', ['min' => $field['min']]);
        }

        if (isset($field['max']) && $num > $field['max']) {
            return __('games.errors.too_large', ['max' => $field['max']]);
        }

        if (($field['type'] ?? '') === 'port' && ($num < 1 || $num > 65535)) {
            return __('games.errors.invalid_port');
        }

        return null;
    }
}
