<?php

namespace App\Enums;

enum PostStatus: string
{
    case Draft = 'draft';
    case Scheduled = 'scheduled';
    case Publishing = 'publishing';
    case Published = 'published';
    case PartiallyFailed = 'partially_failed';
    case Failed = 'failed';

    public function label(): string
    {
        return str($this->value)->replace('_', ' ')->title();
    }

    public function color(): string
    {
        return match ($this) {
            self::Draft => 'zinc',
            self::Scheduled => 'amber',
            self::Publishing => 'sky',
            self::Published => 'green',
            self::PartiallyFailed => 'orange',
            self::Failed => 'red',
        };
    }
}
