<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Localization;

use InvalidArgumentException;
use Normalizer;

/**
 * A culturally significant string kept exactly as its source wrote it.
 *
 * The original is never trimmed, case-folded or Unicode-normalised: combining
 * marks, their order, saltillos and zero-width characters all survive. Only
 * searchKey() normalises, and only for matching; it is never displayed or
 * stored in place of the original.
 */
final readonly class Term
{
    /**
     * @param  string  $original  The authoritative spelling, byte for byte.
     * @param  string|null  $language  BCP 47 tag of the original (null when not established).
     * @param  list<string>  $alternates  Other attested spellings of the same term.
     * @param  array<string, string>  $translations  Locale => rendering in that locale.
     * @param  string|null  $pronunciation  Free-form pronunciation guide (for example IPA).
     * @param  string|null  $audio  URL or storage path of a recording.
     */
    public function __construct(
        public string $original,
        public ?string $language = null,
        public TextDirection $direction = TextDirection::LeftToRight,
        public array $alternates = [],
        public array $translations = [],
        public ?string $pronunciation = null,
        public ?string $audio = null,
    ) {
        self::assertText($original, 'original');

        foreach ($alternates as $alternate) {
            self::assertText($alternate, 'alternate spelling');
        }

        foreach ($translations as $locale => $text) {
            self::assertLocale((string) $locale);
            self::assertText($text, "translation [{$locale}]");
        }

        if ($language !== null) {
            self::assertLocale($language);
        }
    }

    public static function of(string $original, ?string $language = null): self
    {
        return new self($original, $language);
    }

    /**
     * The rendering for a locale: an exact translation, then the language
     * part of the locale (fr_CM → fr), then the original.
     */
    public function label(?string $locale = null): string
    {
        if ($locale === null || $locale === '') {
            return $this->original;
        }

        $normalised = str_replace('_', '-', $locale);

        $candidates = [$locale, $normalised, explode('-', $normalised)[0]];

        foreach ($candidates as $candidate) {
            if (isset($this->translations[$candidate])) {
                return $this->translations[$candidate];
            }
        }

        return $this->original;
    }

    /**
     * A key for search and indexing only: NFC and case-folded when ext-intl
     * is available, else the original unchanged.
     */
    public function searchKey(): string
    {
        return self::keyFor($this->original);
    }

    /** Whether a query names this term, by original or any alternate spelling. */
    public function matches(string $query): bool
    {
        $key = self::keyFor($query);

        foreach ([$this->original, ...$this->alternates] as $spelling) {
            if (self::keyFor($spelling) === $key) {
                return true;
            }
        }

        return false;
    }

    public function withTranslation(string $locale, string $text): self
    {
        return new self($this->original, $this->language, $this->direction, $this->alternates, [...$this->translations, $locale => $text], $this->pronunciation, $this->audio);
    }

    /**
     * A looser key for free-text search: decomposed, combining marks
     * removed, case-folded ("Kèlu̧" → "kelu"). Used only to find candidates;
     * never for identity, never stored, never shown. Without ext-intl it
     * falls back to keyFor().
     */
    public static function foldFor(string $text): string
    {
        if (! class_exists(Normalizer::class)) {
            return self::keyFor($text);
        }

        $nfd = Normalizer::normalize($text, Normalizer::FORM_D);

        return mb_strtolower((string) preg_replace('/\p{Mn}+/u', '', $nfd === false ? $text : $nfd), 'UTF-8');
    }

    public static function keyFor(string $text): string
    {
        if (class_exists(Normalizer::class)) {
            $nfc = Normalizer::normalize($text, Normalizer::FORM_C);

            return mb_strtolower($nfc === false ? $text : $nfc, 'UTF-8');
        }

        return $text;
    }

    /**
     * Accept either a bare string (the original) or the array form.
     *
     * @param  string|array<string, mixed>  $value
     */
    public static function fromValue(string|array $value): self
    {
        if (is_string($value)) {
            return new self($value);
        }

        $original = $value['original'] ?? null;

        if (! is_string($original)) {
            throw new InvalidArgumentException('A term needs an [original] string.');
        }

        $direction = $value['direction'] ?? 'ltr';

        return new self(
            original: $original,
            language: self::optionalString($value, 'language'),
            direction: TextDirection::tryFrom(is_string($direction) ? $direction : '') ?? throw new InvalidArgumentException('Term direction must be ltr or rtl.'),
            alternates: self::stringList($value['alternates'] ?? [], 'alternates'),
            translations: self::stringMap($value['translations'] ?? [], 'translations'),
            pronunciation: self::optionalString($value, 'pronunciation'),
            audio: self::optionalString($value, 'audio'),
        );
    }

    /** @return array{original: string, language: ?string, direction: string, alternates: list<string>, translations: array<string, string>, pronunciation: ?string, audio: ?string} */
    public function toArray(): array
    {
        $translations = $this->translations;
        ksort($translations);

        return [
            'original' => $this->original,
            'language' => $this->language,
            'direction' => $this->direction->value,
            'alternates' => $this->alternates,
            'translations' => $translations,
            'pronunciation' => $this->pronunciation,
            'audio' => $this->audio,
        ];
    }

    private static function assertText(string $text, string $what): void
    {
        if ($text === '' || trim($text) === '') {
            throw new InvalidArgumentException("A term's {$what} cannot be empty.");
        }

        if (! mb_check_encoding($text, 'UTF-8')) {
            throw new InvalidArgumentException("A term's {$what} is not valid UTF-8.");
        }
    }

    private static function assertLocale(string $locale): void
    {
        if (! preg_match('/^[A-Za-z]{2,8}(?:[-_][A-Za-z0-9]{1,8})*$/', $locale)) {
            throw new InvalidArgumentException("[{$locale}] is not a language tag.");
        }
    }

    /** @param array<string, mixed> $value */
    private static function optionalString(array $value, string $key): ?string
    {
        $item = $value[$key] ?? null;

        if ($item !== null && ! is_string($item)) {
            throw new InvalidArgumentException("Term [{$key}] must be a string.");
        }

        return $item;
    }

    /** @return list<string> */
    private static function stringList(mixed $value, string $key): array
    {
        if (! is_array($value) || ! array_is_list($value) || array_filter($value, 'is_string') !== $value) {
            throw new InvalidArgumentException("Term [{$key}] must be a list of strings.");
        }

        return $value;
    }

    /** @return array<string, string> */
    private static function stringMap(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Term [{$key}] must map locales to strings.");
        }

        $map = [];

        foreach ($value as $locale => $text) {
            if (! is_string($text)) {
                throw new InvalidArgumentException("Term [{$key}.{$locale}] must be a string.");
            }

            $map[(string) $locale] = $text;
        }

        return $map;
    }
}
