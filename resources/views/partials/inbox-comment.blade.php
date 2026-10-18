{{-- One comment or reply in an inbox conversation. Expects $comment, $canReply, $isReply, and the component's $replyingTo. --}}
<div wire:key="c-{{ $comment->id }}">
    <div class="flex gap-3">
        <x-avatar :size="$isReply ? 'xs' : 'sm'" :src="$comment->author_avatar_url" :name="$comment->author_name ?? '?'" />
        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <span class="font-medium">{{ $comment->is_own ? 'You' : ($comment->author_name ?? 'Unknown') }}</span>
                @if (! $comment->is_own && $comment->author_handle && $comment->author_handle !== $comment->author_name)
                    <span class="text-zinc-500">{{ $comment->author_handle }}</span>
                @endif
                <span class="text-zinc-500">· {{ $comment->commented_at?->diffForHumans() }}</span>
                @if (! $comment->read_at && ! $comment->is_own)
                    <span class="size-2 rounded-full bg-blue-500" title="Unread"></span>
                @endif
            </div>
            <p class="mt-1 whitespace-pre-line break-words text-sm">{{ $comment->body }}</p>
            <div class="mt-1.5 flex items-center gap-4 text-xs text-zinc-500">
                <span class="flex items-center gap-1" title="Likes"><flux:icon name="heart" variant="micro" /> {{ $comment->likes }}</span>
                @if ($canReply && $replyingTo !== $comment->id)
                    <button type="button" class="font-medium hover:text-zinc-900 hover:underline dark:hover:text-white" wire:click="startReply({{ $comment->id }})">Reply</button>
                @endif
            </div>

            @if ($replyingTo === $comment->id)
                <form wire:submit="sendReply" class="mt-3 flex flex-col gap-2">
                    <flux:textarea wire:model="reply" rows="2" :placeholder="'Reply to '.($comment->is_own ? 'this' : ($comment->author_name ?? 'this comment')).'…'" autofocus />
                    <flux:error name="reply" />
                    <div class="flex gap-2">
                        <flux:button size="sm" type="submit" variant="primary" wire:loading.attr="disabled" wire:target="sendReply">Send reply</flux:button>
                        @if ($aiEnabled)
                            <flux:button size="sm" icon="sparkles" wire:click="suggestReply" wire:loading.attr="disabled" wire:target="suggestReply">Suggest a reply</flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" wire:click="$set('replyingTo', null)">Cancel</flux:button>
                    </div>
                </form>
            @endif
        </div>
    </div>
</div>
