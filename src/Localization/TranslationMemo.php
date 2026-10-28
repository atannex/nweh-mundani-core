<?php

declare(strict_types=1);

namespace Atannex\NwehMundani\Core\Localization;

/**
 * Per-request memo of translation maps, bound with $app->scoped(): Laravel
 * discards it at the end of every Octane request and queued job, so a
 * long-running worker never keeps translations from an earlier unit of work.
 * Within one request it saves a lookup per label.
 */
final class TranslationMemo
{
    /** @var array<string, array<string, array<string, string>>> */
    public array $maps = [];

    public function forget(string $prefix): void
    {
        foreach (array_keys($this->maps) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->maps[$key]);
            }
        }
    }
}
