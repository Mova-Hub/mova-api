<?php

namespace App\Domain\Calendar;

use DateTimeInterface;

/**
 * Builds an RFC 5545 iCalendar document.
 *
 * Hand rolled rather than pulled from a package, because the whole format we
 * need is about eighty lines and the rules that actually bite are few and
 * specific. They are all implemented here and each is commented, which is worth
 * more than a dependency whose failure mode is a calendar app silently
 * refusing to subscribe with no error anywhere.
 *
 * The three that break real feeds:
 *
 *  1. **CRLF line endings, always.** RFC 5545 requires them. Google tolerates
 *     bare LF; Apple Calendar does not, and fails by showing an empty
 *     subscription rather than an error.
 *  2. **Lines fold at 75 octets**, counted in bytes and not characters, with
 *     the continuation starting with a single space. A long French
 *     destination with accents overruns this quickly, and a multi-byte
 *     character split across the fold corrupts the file.
 *  3. **Text values escape backslash, semicolon, comma and newline.** An
 *     unescaped comma in a SUMMARY silently truncates it at that comma,
 *     because comma is the value separator.
 */
class IcsCalendar
{
    /** @var array<int, string> */
    private array $lines = [];

    public function __construct(
        private string $name,
        private string $description = '',
        private string $timezone = 'Africa/Brazzaville',
    ) {}

    /**
     * Adds one event.
     *
     * `$uid` must be stable for the life of the booking. A calendar client
     * matches on it to decide "update the entry I already have" versus "add a
     * new one", so a UID derived from anything that changes, a date or a
     * status, produces a duplicate entry every time the trip moves rather than
     * the update the client wanted.
     */
    public function event(
        string $uid,
        string $summary,
        DateTimeInterface $start,
        DateTimeInterface $end,
        string $location = '',
        string $description = '',
        bool $allDay = false,
        string $status = 'CONFIRMED',
        ?int $reminderMinutes = null,
    ): self {
        $this->lines[] = 'BEGIN:VEVENT';
        $this->lines[] = 'UID:'.$uid;
        $this->lines[] = 'DTSTAMP:'.$this->utc(new \DateTimeImmutable('now'));

        if ($allDay) {
            /*
             * An all-day event is a DATE, not a DATETIME, and its DTEND is
             * EXCLUSIVE: a one day event ending on the same day it starts
             * renders as zero length and disappears from the grid. The end
             * therefore moves to the following day.
             */
            $this->lines[] = 'DTSTART;VALUE=DATE:'.$start->format('Ymd');
            $this->lines[] = 'DTEND;VALUE=DATE:'.
                (new \DateTimeImmutable($end->format('Y-m-d')))->modify('+1 day')->format('Ymd');
        } else {
            $this->lines[] = 'DTSTART:'.$this->utc($start);
            $this->lines[] = 'DTEND:'.$this->utc($end);
        }

        $this->lines[] = 'SUMMARY:'.$this->escape($summary);

        if ($location !== '') {
            $this->lines[] = 'LOCATION:'.$this->escape($location);
        }

        if ($description !== '') {
            $this->lines[] = 'DESCRIPTION:'.$this->escape($description);
        }

        $this->lines[] = 'STATUS:'.$status;
        // Free/busy: a charter occupies the client for its duration, so the
        // slot should show as busy rather than as a note in the margin.
        $this->lines[] = 'TRANSP:OPAQUE';

        if ($reminderMinutes !== null) {
            /*
             * A VALARM the SERVER sets, so the reminder exists even for a
             * client who has push notifications turned off. It duplicates
             * `trips:remind` on purpose: they fail independently, and the
             * cost of two nudges is far lower than the cost of a missed coach.
             */
            $this->lines[] = 'BEGIN:VALARM';
            $this->lines[] = 'ACTION:DISPLAY';
            $this->lines[] = 'DESCRIPTION:'.$this->escape($summary);
            $this->lines[] = 'TRIGGER:-PT'.$reminderMinutes.'M';
            $this->lines[] = 'END:VALARM';
        }

        $this->lines[] = 'END:VEVENT';

        return $this;
    }

    public function render(): string
    {
        $head = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Mova Mobility//Charter//FR',
            'CALSCALE:GREGORIAN',
            // PUBLISH, not REQUEST. REQUEST is a meeting invitation and makes
            // some clients offer accept and decline buttons for a coach.
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.$this->escape($this->name),
            'X-WR-TIMEZONE:'.$this->timezone,
        ];

        if ($this->description !== '') {
            $head[] = 'X-WR-CALDESC:'.$this->escape($this->description);
        }

        $all = array_merge($head, $this->lines, ['END:VCALENDAR']);

        return implode("\r\n", array_map($this->fold(...), $all))."\r\n";
    }

    /** Times go out in UTC. Congo has no DST, so there is nothing to get wrong. */
    private function utc(DateTimeInterface $date): string
    {
        return (new \DateTimeImmutable($date->format('Y-m-d H:i:s'), $date->getTimezone()))
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Ymd\THis\Z');
    }

    /**
     * Escapes a TEXT value.
     *
     * Order matters: the backslash must be doubled FIRST, or the backslashes
     * introduced by the later replacements get doubled themselves and the
     * output is corrupt.
     */
    private function escape(string $value): string
    {
        return str_replace(
            ['\\', "\r\n", "\n", "\r", ';', ','],
            ['\\\\', '\\n', '\\n', '\\n', '\\;', '\\,'],
            $value,
        );
    }

    /**
     * Folds a line at 75 octets, never mid-character.
     *
     * `mb_strcut` rather than `substr`, because the limit is in BYTES while a
     * French destination is not: cutting "Pointe-Noire" is safe, cutting an
     * accented character in half is not, and the result is a file Apple
     * Calendar rejects without saying why.
     */
    private function fold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $folded = mb_strcut($line, 0, 75);
        $rest = substr($line, strlen($folded));

        // Continuations are 74 octets plus the leading space that marks them.
        while ($rest !== '') {
            $chunk = mb_strcut($rest, 0, 74);
            $folded .= "\r\n ".$chunk;
            $rest = substr($rest, strlen($chunk));
        }

        return $folded;
    }
}
