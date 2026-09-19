<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Roster Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Roster\Model;

/**
 * WHAT THE AGENDA IS SHOWING — one query, made visible by the filter row.
 *
 * EVERY CHOICE IS A REAL LINK, so a filtered agenda is a url somebody can
 * send and the browser's own back button undoes a choice. That is why this
 * is a value object with `with*()` returning a new one and a `toQuery()`
 * that builds the next link: the row is rendered FROM the filter, never
 * from a parallel list of what the options happen to be.
 *
 * NOTHING HERE IS TRUSTED. Every field arrives from a query string, so each
 * is read as a string or dropped; an unknown post or state filters to
 * nothing and says so, which is a better answer than a 500.
 */
final readonly class AgendaFilter
{
    public function __construct(
        /** A station uuid, or null for every post on the books. */
        public ?string $post = null,
        /** A DayState value, 'due' or 'off', or null for every state. */
        public ?string $state = null,
        /** A shift key, or null for all of them. */
        public ?string $shift = null,
        /** Free text over a person's name and a post's name. */
        public ?string $q = null,
    ) {
    }

    public static function fromQuery(mixed $post, mixed $state, mixed $shift, mixed $q): self
    {
        return new self(self::str($post), self::str($state), self::str($shift), self::str($q));
    }

    private static function str(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return '' === $trimmed ? null : $trimmed;
    }

    public function withPost(?string $post): self
    {
        return new self($post, $this->state, $this->shift, $this->q);
    }

    public function withState(?string $state): self
    {
        return new self($this->post, $state, $this->shift, $this->q);
    }

    public function withShift(?string $shift): self
    {
        return new self($this->post, $this->state, $shift, $this->q);
    }

    /**
     * THE CHOICES AS A QUERY, empties dropped — so "everything" is a bare
     * url rather than three empty parameters, and two people who filtered
     * the same way have the same link.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'post' => $this->post,
            'state' => $this->state,
            'shift' => $this->shift,
            'q' => $this->q,
        ], static fn (?string $value): bool => null !== $value);
    }

    public function isEverything(): bool
    {
        return [] === $this->toQuery();
    }
}
