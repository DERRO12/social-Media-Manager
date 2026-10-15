<?php

namespace App\Enums;

enum TargetStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'zinc',
            self::Publishing => 'sky',
            self::Published => 'green',
            self::Failed => 'red',
        };
    }
}
