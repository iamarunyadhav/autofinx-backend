<?php

namespace App\Support;

use RuntimeException;

class EnvFileEditor
{
    public function read(array $keys): array
    {
        $values = [];
        $content = $this->content();

        foreach ($keys as $key) {
            $values[$key] = $this->parseValue($content, $key);
        }

        return $values;
    }

    public function write(array $values): void
    {
        $path = base_path('.env');
        $content = $this->content();

        foreach ($values as $key => $value) {
            $line = $key . '=' . $this->formatValue((string) $value);
            if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $content)) {
                $content = preg_replace('/^' . preg_quote($key, '/') . '=.*/m', $line, $content, 1);
            } else {
                $content = rtrim($content) . PHP_EOL . $line . PHP_EOL;
            }

            putenv($key . '=' . (string) $value);
            $_ENV[$key] = (string) $value;
            $_SERVER[$key] = (string) $value;
        }

        if (file_put_contents($path, $content, LOCK_EX) === false) {
            throw new RuntimeException('Unable to write .env file.');
        }
    }

    private function content(): string
    {
        $path = base_path('.env');
        if (! is_file($path)) {
            throw new RuntimeException('.env file not found.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new RuntimeException('Unable to read .env file.');
        }

        return $content;
    }

    private function parseValue(string $content, string $key): string
    {
        if (! preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $content, $match)) {
            return '';
        }

        $value = trim((string) $match[1]);
        if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function formatValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|#|"|\'/', $value)) {
            return '"' . addcslashes($value, "\\\"") . '"';
        }

        return $value;
    }
}
