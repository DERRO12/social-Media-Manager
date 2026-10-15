<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

new class extends Component {
    use WithFileUploads;

    public $video;
    public string $title = '';
    public string $caption = '';
    /** @var int[] */
    public array $accountIds = [];
    public string $when = 'now';
    public string $scheduledAt = '';
    public string $timezone = 'UTC';
    public string $youtubePrivacy = 'public';
    public string $tiktokPrivacy = 'SELF_ONLY';

    /** Platforms that cannot post without a video. */
    private const VIDEO_ONLY = [Platform::YouTube, Platform::Instagram, Platform::TikTok];

    public function mount(): void
    {
        $this->timezone = auth()->user()->timezone ?: 'UTC';
        $this->tiktokPrivacy = config('services.tiktok.default_privacy');
    }

    public function save(): void
    {
        $user = auth()->user();
        $selected = $user->socialAccounts()->whereIn('id', $this->accountIds)->get();
        $needsVideo = $selected->contains(fn ($a) => in_array($a->platform, self::VIDEO_ONLY, true));

        $this->validate([
            'accountIds' => ['required', 'array', 'min:1'],
            'accountIds.*' => [Rule::exists('social_accounts', 'id')->where('user_id', $user->id)],
            'video' => [$needsVideo ? 'required' : 'nullable', 'file', 'mimetypes:video/mp4,video/quicktime,video/webm', 'max:1048576'],
            'title' => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:5000', Rule::requiredIf(! $this->video)],
            'when' => ['required', Rule::in(['now', 'schedule', 'draft'])],
            'scheduledAt' => [Rule::requiredIf($this->when === 'schedule'), 'nullable', 'date'],
            'timezone' => ['required', 'timezone:all'],
        ], [
            'accountIds.required' => 'Choose at least one account to post to.',
            'video.required' => 'YouTube, Instagram and TikTok need a video.',
            'caption.required' => 'Write a caption or attach a video.',
        ]);

        $scheduledAt = $this->when === 'schedule'
            ? Carbon::parse($this->scheduledAt, $this->timezone)->utc()
            : null;

        if ($scheduledAt?->isPast()) {
            $this->addError('scheduledAt', 'Pick a time in the future.');

            return;
        }

        $user->update(['timezone' => $this->timezone]);

        $media = null;

        if ($this->video) {
            $media = $user->media()->create([
                'disk' => 'public',
                'path' => $this->video->store("media/{$user->id}", 'public'),
                'original_name' => $this->video->getClientOriginalName(),
                'mime_type' => $this->video->getMimeType(),
                'size' => $this->video->getSize(),
            ]);
        }

        $post = $user->posts()->create([
            'media_id' => $media?->id,
            'title' => $this->title ?: null,
            'caption' => $this->caption,
            'status' => match ($this->when) {
                'draft' => PostStatus::Draft,
                default => PostStatus::Scheduled,
            },
            'scheduled_at' => $scheduledAt ?? ($this->when === 'now' ? now() : null),
        ]);

        foreach ($selected as $account) {
            $post->targets()->create([
                'social_account_id' => $account->id,
                'options' => match ($account->platform) {
                    Platform::YouTube => ['privacy' => $this->youtubePrivacy],
                    Platform::TikTok => ['privacy' => $this->tiktokPrivacy],
                    default => [],
                },
            ]);
        }

        if ($this->when === 'now') {
            $post->dispatchPublishing();
        }

        session()->flash('status', match ($this->when) {
            'now' => 'Publishing started. Check the status below.',
            'schedule' => 'Post scheduled for '.$user->localTime($scheduledAt).'.',
            'draft' => 'Draft saved.',
        });

        $this->redirectRoute('posts', navigate: true);
    }

    public function with(): array
    {
        $accounts = auth()->user()->socialAccounts()->orderBy('platform')->get();
        $selectedPlatforms = $accounts->whereIn('id', $this->accountIds)->pluck('platform')->unique();

        return [
            'accounts' => $accounts,
            'hasYouTube' => $selectedPlatforms->contains(Platform::YouTube),
            'hasTikTok' => $selectedPlatforms->contains(Platform::TikTok),
            'hasX' => $selectedPlatforms->contains(Platform::X),
        ];
    }
}; ?>

<div class="mx-auto flex w-full max-w-3xl flex-col gap-6" x-data x-init="$wire.timezone = Intl.DateTimeFormat().resolvedOptions().timeZone">
    <div>
        <flux:heading size="xl">Create post</flux:heading>
        <flux:text>Upload once, publish everywhere.</flux:text>
    </div>

    @if ($accounts->isEmpty())
        <div class="rounded-xl border border-dashed border-zinc-300 p-8 text-center dark:border-zinc-600">
            <flux:text>Connect an account first.</flux:text>
            <flux:button class="mt-3" variant="primary" :href="route('accounts')" wire:navigate>Connect accounts</flux:button>
        </div>
    @else
        <form wire:submit="save" class="flex flex-col gap-6">
            <flux:field>
                <flux:label>Post to</flux:label>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($accounts as $account)
                        <label wire:key="acc-{{ $account->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-3 has-[:checked]:border-zinc-800 has-[:checked]:bg-zinc-50 dark:border-zinc-700 dark:has-[:checked]:border-zinc-300 dark:has-[:checked]:bg-zinc-900">
                            <input type="checkbox" value="{{ $account->id }}" wire:model.live="accountIds" class="rounded" />
                            <flux:avatar size="xs" :src="$account->avatar_url" :name="$account->name" />
                            <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $account->name }}</span>
                            <x-platform-badge :platform="$account->platform" />
                        </label>
                    @endforeach
                </div>
                <flux:error name="accountIds" />
            </flux:field>

            <flux:field>
                <flux:label>Video</flux:label>
                <input type="file" wire:model="video" accept="video/mp4,video/quicktime,video/webm"
                    class="block w-full text-sm file:me-4 file:rounded-md file:border-0 file:bg-zinc-800 file:px-4 file:py-2 file:text-white dark:file:bg-zinc-200 dark:file:text-zinc-900" />
                <div wire:loading wire:target="video" class="text-sm text-zinc-500">Uploading…</div>
                @if ($video && ! $errors->has('video'))
                    <video src="{{ $video->temporaryUrl() }}" controls class="mt-2 max-h-80 rounded-lg"></video>
                @endif
                <flux:description>MP4, MOV or WebM, up to 1 GB. Vertical 9:16 works best for TikTok, Reels and Shorts.</flux:description>
                <flux:error name="video" />
            </flux:field>

            <flux:input wire:model="title" label="Title" description="Used for YouTube and LinkedIn video titles." maxlength="100" />

            <flux:field>
                <flux:label>Caption</flux:label>
                <flux:textarea wire:model.live.debounce.300ms="caption" rows="5" placeholder="Write your caption, #hashtags and @mentions…" />
                <flux:description>
                    {{ mb_strlen($caption) }} characters
                    @if ($hasX && mb_strlen($caption) > 280)
                        <span class="text-amber-600">· X will cut this to 280 characters.</span>
                    @endif
                </flux:description>
                <flux:error name="caption" />
            </flux:field>

            @if ($hasYouTube || $hasTikTok)
                <div class="grid gap-4 sm:grid-cols-2">
                    @if ($hasYouTube)
                        <flux:select wire:model="youtubePrivacy" label="YouTube visibility">
                            <option value="public">Public</option>
                            <option value="unlisted">Unlisted</option>
                            <option value="private">Private</option>
                        </flux:select>
                    @endif
                    @if ($hasTikTok)
                        <flux:select wire:model="tiktokPrivacy" label="TikTok visibility" description="Unaudited TikTok apps can only post as 'Only me'.">
                            <option value="PUBLIC_TO_EVERYONE">Everyone</option>
                            <option value="MUTUAL_FOLLOW_FRIENDS">Friends</option>
                            <option value="FOLLOWER_OF_CREATOR">Followers</option>
                            <option value="SELF_ONLY">Only me</option>
                        </flux:select>
                    @endif
                </div>
            @endif

            <flux:radio.group wire:model.live="when" label="When" variant="segmented">
                <flux:radio value="now" label="Post now" />
                <flux:radio value="schedule" label="Schedule" />
                <flux:radio value="draft" label="Save draft" />
            </flux:radio.group>

            @if ($when === 'schedule')
                <flux:input type="datetime-local" wire:model="scheduledAt" label="Publish at" :description="'Your timezone: '.$timezone" />
            @endif

            <div class="flex justify-end">
                <flux:button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="save,video">
                    {{ ['now' => 'Publish now', 'schedule' => 'Schedule post', 'draft' => 'Save draft'][$when] }}
                </flux:button>
            </div>
        </form>
    @endif
</div>
