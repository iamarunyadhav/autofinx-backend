<?php

namespace App\Domain\Ml\Shadow;

/**
 * Converts a review document/transcript into small, independently reviewable
 * claims. It is deliberately deterministic: no claim is silently invented,
 * and unlabelled text remains in the caller's selected category.
 */
final class V5KnowledgeCategorizer
{
    private const CATEGORIES = ['CANDLE', 'TREND', 'SUPPORT_RESISTANCE', 'RISK'];

    /** @return list<array{category:string,title:string,content:string}> */
    public function split(string $title, string $content, string $fallbackCategory = 'OTHER'): array
    {
        $content = trim(preg_replace('/\r\n?/', "\n", $content) ?? $content);
        if ($content === '') {
            return [];
        }
        $sections = [];
        $category = $this->normalize($fallbackCategory);
        $buffer = [];
        foreach (preg_split('/\n/', $content) ?: [] as $line) {
            $heading = $this->headingCategory($line);
            if ($heading !== null) {
                if ($buffer !== []) {
                    $sections[] = [$category, trim(implode("\n", $buffer))];
                    $buffer = [];
                }
                $category = $heading;
                continue;
            }
            $buffer[] = $line;
        }
        if ($buffer !== []) {
            $sections[] = [$category, trim(implode("\n", $buffer))];
        }

        return array_values(array_filter(array_map(function (array $section) use ($title): ?array {
            [$category, $body] = $section;
            if ($body === '') return null;
            return ['category' => $category, 'title' => $title.' · '.$this->label($category), 'content' => $body];
        }, $sections)));
    }

    private function headingCategory(string $line): ?string
    {
        $candidate = strtoupper(trim(preg_replace('/^[#\s\d.\-:)]+/', '', $line) ?? $line));
        foreach (self::CATEGORIES as $category) {
            $label = str_replace('_', '[ _-]*', $category);
            if (preg_match('/^'.str_replace(' ', '[ _-]*', $label).'\s*:?$/i', $candidate)) return $category;
        }
        return null;
    }

    private function normalize(string $category): string
    {
        return in_array($category, self::CATEGORIES, true) ? $category : 'OTHER';
    }

    private function label(string $category): string
    {
        return ucwords(strtolower(str_replace('_', ' / ', $category)));
    }
}
