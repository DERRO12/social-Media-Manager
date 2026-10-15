@props(['platform'])

<flux:badge size="sm" :color="$platform->color()" {{ $attributes }}>{{ $platform->label() }}</flux:badge>
