<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Days worth posting about: holidays and awareness days (with a focus on Uganda and East Africa) plus
 * shopping days, each with a content idea. Shown on the content calendar.
 * Islamic holidays follow the moon and aren't listed; add them yourself when dates are announced.
 */
class MarketingDates
{
    /** 'MM-DD' => [name, idea] */
    private const FIXED = [
        '01-01' => ['New Year’s Day', 'Share your goals for the year or thank your customers for last year.'],
        '01-26' => ['Liberation Day (Uganda)', 'A respectful post about community and progress.'],
        '02-14' => ['Valentine’s Day', 'Gift ideas, couples’ offers, or “love our customers” shout-outs.'],
        '02-16' => ['Archbishop Janani Luwum Day (Uganda)', 'Public holiday: share your opening hours.'],
        '03-08' => ['International Women’s Day', 'Celebrate the women on your team or among your customers.'],
        '04-07' => ['World Health Day', 'Share a health or wellbeing tip linked to what you do.'],
        '04-22' => ['Earth Day', 'Show what you do to reduce waste or support nature.'],
        '05-01' => ['Labour Day', 'Thank your team with a behind-the-scenes post.'],
        '06-03' => ['Uganda Martyrs Day', 'Public holiday: share hours and safe travel wishes for Namugongo pilgrims.'],
        '06-05' => ['World Environment Day', 'A green tip or your eco-friendly products.'],
        '06-09' => ['Heroes Day (Uganda)', 'Celebrate the everyday heroes among your customers.'],
        '06-21' => ['World Music Day', 'Share the music your team works to, or a fun Reel with a trending sound.'],
        '08-12' => ['International Youth Day', 'Feature young customers, interns or tips for young entrepreneurs.'],
        '09-27' => ['World Tourism Day', 'Show your town or recommend places to visit near you.'],
        '10-01' => ['International Coffee Day', 'Coffee-break content, a team photo, or a coffee offer.'],
        '10-09' => ['Independence Day (Uganda)', 'Celebrate Uganda: local products, colours and pride.'],
        '10-16' => ['World Food Day', 'Food businesses: your best dish. Others: the team’s favourite local food.'],
        '11-19' => ['International Men’s Day', 'Celebrate the men on your team or among your customers.'],
        '12-01' => ['World AIDS Day', 'A respectful awareness post supporting the cause.'],
        '12-09' => ['Independence Day (Tanzania)', 'Greet your Tanzanian customers.'],
        '12-12' => ['Jamhuri Day (Kenya)', 'Greet your Kenyan customers.'],
        '12-24' => ['Christmas Eve', 'Last-minute gift ideas and holiday opening hours.'],
        '12-25' => ['Christmas Day', 'Season’s greetings from your team.'],
        '12-26' => ['Boxing Day', 'After-Christmas offers.'],
        '12-31' => ['New Year’s Eve', 'Your year in review: best moments, thank you, what’s next.'],
    ];

    /** @return array<string, array<int, array{name: string, idea: string}>> 'Y-m-d' => days in that month */
    public static function forMonth(int $year, int $month): array
    {
        $days = [];

        foreach (self::FIXED as $md => [$name, $idea]) {
            if ((int) substr($md, 0, 2) === $month) {
                $days[sprintf('%04d-%s', $year, $md)][] = compact('name', 'idea');
            }
        }

        foreach (self::moving($year) as $date => [$name, $idea]) {
            if ((int) substr($date, 5, 2) === $month) {
                $days[$date][] = compact('name', 'idea');
            }
        }

        ksort($days);

        return $days;
    }

    /** Dates that change every year. */
    private static function moving(int $year): array
    {
        $ordinals = [1 => 'first', 2 => 'second', 3 => 'third', 4 => 'fourth'];
        $nth = fn (int $n, string $weekday, int $month) => CarbonImmutable::create($year, $month, 1)->modify("{$ordinals[$n]} {$weekday} of this month")->toDateString();
        $easter = self::easter($year);
        $thanksgiving = CarbonImmutable::parse($nth(4, 'thursday', 11));

        return [
            $easter->subDays(2)->toDateString() => ['Good Friday', 'Public holiday: share your Easter opening hours.'],
            $easter->toDateString() => ['Easter Sunday', 'Easter greetings and family-friendly offers.'],
            $easter->addDay()->toDateString() => ['Easter Monday', 'Public holiday: a relaxed, fun post.'],
            $nth(2, 'sunday', 5) => ['Mother’s Day', 'Gifts for mum, or a tribute to the mothers on your team.'],
            $nth(3, 'sunday', 6) => ['Father’s Day', 'Gifts for dad, or a tribute to fathers.'],
            $thanksgiving->addDay()->toDateString() => ['Black Friday', 'Your biggest offer of the year. Announce it a week before.'],
            $thanksgiving->addDays(4)->toDateString() => ['Cyber Monday', 'Online-only offers and free delivery.'],
        ];
    }

    /** Western Easter Sunday (anonymous Gregorian algorithm; no calendar extension needed). */
    private static function easter(int $y): CarbonImmutable
    {
        $a = $y % 19;
        $b = intdiv($y, 100);
        $c = $y % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($y, $month, $day);
    }
}
