<?php

declare(strict_types=1);

use Atannex\NwehMundani\Core\Localization\Term;
use Atannex\NwehMundani\Core\Localization\TextDirection;

it('keeps the original byte for byte, combining marks and all', function () {
    $original = "Agha\u{0300}\u{0327}"; // decomposed and in non-canonical mark order
    $term = new Term($original);

    expect($term->original)->toBe($original)
        ->and(bin2hex($term->label('fr')))->toBe(bin2hex($original))
        ->and(bin2hex($term->toArray()['original']))->toBe(bin2hex($original));
});

it('preserves zero-width characters, saltillos and surrounding whitespace', function () {
    $original = " Fa\u{A78C}\u{200B}a\u{0300} ";

    expect((new Term($original))->original)->toBe($original);
});

it('matches equivalent spellings by search key without changing the original', function () {
    $precomposed = new Term("Ng\u{0229}\u{0300}");               // ȩ + grave
    $decomposed = "Nge\u{0327}\u{0300}";                         // e + cedilla + grave

    expect($precomposed->matches($decomposed))->toBeTrue()
        ->and($precomposed->original)->toBe("Ng\u{0229}\u{0300}")
        ->and($precomposed->matches('Nge'))->toBeFalse();
})->skip(! class_exists(Normalizer::class), 'needs ext-intl');

it('finds alternate spellings', function () {
    $term = new Term('Mmock', alternates: ['Mmok']);

    expect($term->matches('Mmok'))->toBeTrue()
        ->and($term->matches('Mmouck'))->toBeFalse();
});

it('falls back from locale to language to original', function () {
    $term = new Term('Ankoah', 'nwe', translations: ['fr' => 'jour un', 'en-GB' => 'day one']);

    expect($term->label('fr_CM'))->toBe('jour un')
        ->and($term->label('en-GB'))->toBe('day one')
        ->and($term->label('de'))->toBe('Ankoah')
        ->and($term->label())->toBe('Ankoah');
});

it('carries direction, pronunciation and audio', function () {
    $term = Term::fromValue(['original' => 'نص', 'direction' => 'rtl', 'pronunciation' => '/nasˤ/', 'audio' => 'https://example.test/a.mp3']);

    expect($term->direction)->toBe(TextDirection::RightToLeft)
        ->and($term->pronunciation)->toBe('/nasˤ/')
        ->and(Term::fromValue($term->toArray()))->toEqual($term);
});

it('rejects empty and invalid UTF-8 strings', function (string $bad) {
    expect(fn () => new Term($bad))->toThrow(InvalidArgumentException::class);
})->with(['', '   ', "\xC3\x28"]);
