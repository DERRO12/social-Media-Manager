<?php

use App\Enums\Platform;
use App\Enums\PostStatus;
use Illuminate\Support\Carbon;
use App\Http\Controllers\ChunkedUploadController;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {
    /** The uploaded video (sent in pieces by the browser, see ChunkedUploadController). */
    public ?int $mediaId = null;
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
            'mediaId' => [$needsVideo ? 'required' : 'nullable', Rule::exists('media', 'id')->where('user_id', $user->id)],
            'title' => ['nullable', 'string', 'max:100'],
            'caption' => ['nullable', 'string', 'max:5000', Rule::requiredIf(! $this->mediaId)],
            'when' => ['required', Rule::in(['now', 'schedule', 'draft'])],
            'scheduledAt' => [Rule::requiredIf($this->when === 'schedule'), 'nullable', 'date'],
            'timezone' => ['required', 'timezone:all'],
        ], [
            'accountIds.required' => 'Choose at least one account to post to.',
            'mediaId.required' => 'YouTube, Instagram and TikTok need a video.',
            'mediaId.exists' => 'That video is no longer available. Please upload it again.',
            'caption.required' => 'Write a caption or attach a video.',
        ]);

        $scheduledAt = $this->when === 'schedule'
            ? Carbon::parse($this->scheduledAt, $this->timezone)->utc()
            : null;

        if ($scheduledAt?->isPast()) {
            $this->addError('scheduledAt', 'Pick a time in the future.');

            return;
        }

        if ($this->when !== 'draft' && $user->remainingPosts() === 0) {
            $this->addError('when', 'You have used all the posts your plan includes this month. Save it as a draft, or upgrade your plan.');

            return;
        }

        $user->update(['timezone' => $this->timezone]);

        $media = $this->mediaId ? $user->media()->find($this->mediaId) : null;

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

    /** Called by the browser once every piece of the video has arrived and been joined. */
    public function useMedia(int $id): void
    {
        $this->mediaId = auth()->user()->media()->findOrFail($id)->id;
        $this->resetErrorBag('mediaId');
    }

    public function removeMedia(): void
    {
        // Remove it right away if no post uses it yet; otherwise the daily cleanup handles leftovers.
        auth()->user()->media()->doesntHave('posts')->find($this->mediaId)?->deleteWithFile();
        $this->mediaId = null;
    }

    public function toggleAll(): void
    {
        $all = auth()->user()->socialAccounts()->pluck('id')->map(fn ($id) => (string) $id)->all();

        $this->accountIds = count($this->accountIds) === count($all) ? [] : $all;
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
            'remainingPosts' => auth()->user()->remainingPosts(),
            'maxUploadMb' => config('app.max_upload_mb'),
            'chunkMb' => ChunkedUploadController::CHUNK_MB,
            'media' => $this->mediaId ? auth()->user()->media()->find($this->mediaId) : null,
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
            @if ($remainingPosts !== null)
                <flux:callout :variant="$remainingPosts === 0 ? 'danger' : 'secondary'" icon="information-circle"
                    :heading="$remainingPosts === 0 ? 'You have used all your posts for this month.' : $remainingPosts.' '.Str::plural('post', $remainingPosts).' left this month on your plan.'">
                    @if ($remainingPosts === 0)
                        <x-slot name="actions">
                            <flux:button size="sm" :href="route('billing')" wire:navigate>Upgrade</flux:button>
                        </x-slot>
                    @endif
                </flux:callout>
            @endif

            <flux:field>
                <div class="flex items-center justify-between">
                    <flux:label>Post to</flux:label>
                    <flux:button size="xs" variant="ghost" wire:click="toggleAll">
                        {{ count($accountIds) === $accounts->count() ? 'Clear all' : 'Select all' }}
                    </flux:button>
                </div>
                <div class="grid gap-2 sm:grid-cols-2">
                    @foreach ($accounts as $account)
                        <label wire:key="acc-{{ $account->id }}" class="flex cursor-pointer items-center gap-3 rounded-lg border border-zinc-200 p-3 has-[:checked]:border-zinc-800 has-[:checked]:bg-zinc-50 dark:border-zinc-700 dark:has-[:checked]:border-zinc-300 dark:has-[:checked]:bg-zinc-900">
                            <input type="checkbox" value="{{ $account->id }}" wire:model.live="accountIds" class="rounded" />
                            <x-avatar size="xs" :src="$account->avatar_url" :name="$account->name" />
                            <span class="min-w-0 flex-1 truncate text-sm font-medium">{{ $account->name }}</span>
                            <x-platform-badge :platform="$account->platform" />
                        </label>
                    @endforeach
                </div>
                <flux:error name="accountIds" />
            </flux:field>

            <flux:field>
                <flux:label>Video</flux:label>
                @if ($media)
                    <div class="flex flex-col gap-3 rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                        <video src="{{ $media->url() }}" controls preload="metadata" class="max-h-80 rounded-lg bg-black"></video>
                        <div class="flex items-center justify-between gap-3 text-sm">
                            <span class="truncate"><flux:icon name="check-circle" variant="mini" class="inline text-green-600" /> {{ $media->original_name }} · {{ $media->humanSize() }}</span>
                            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="removeMedia">Remove</flux:button>
                        </div>
                    </div>
                @else
                    {{-- Big videos are sent in pieces (Cloudflare refuses single requests over 100 MB); see ChunkedUploadController. --}}
                    <div x-data="{
                            maxMb: {{ $maxUploadMb }},
                            chunkBytes: {{ $chunkMb }} * 1024 * 1024,
                            progress: null,
                            problem: '',
                            mb(n) { return n >= 1024 ? (n / 1024).toFixed(1) + ' GB' : Math.max(1, Math.round(n)) + ' MB' },
                            send(url, form, onProgress) {
                                return new Promise((resolve, reject) => {
                                    const xhr = new XMLHttpRequest();
                                    xhr.open('POST', url);
                                    xhr.setRequestHeader('X-CSRF-TOKEN', '{{ csrf_token() }}');
                                    xhr.setRequestHeader('Accept', 'application/json');
                                    if (onProgress) xhr.upload.onprogress = (e) => onProgress(e.loaded);
                                    xhr.onload = () => {
                                        let body = {};
                                        try { body = JSON.parse(xhr.responseText) } catch (e) {}
                                        xhr.status >= 200 && xhr.status < 300 ? resolve(body) : reject({ status: xhr.status, message: body.message });
                                    };
                                    xhr.onerror = () => reject({ status: 0 });
                                    xhr.send(form);
                                });
                            },
                            async sendWithRetry(url, form, onProgress) {
                                for (let attempt = 1; ; attempt++) {
                                    try { return await this.send(url, form, onProgress) }
                                    catch (e) {
                                        // Retry network blips and server hiccups, not validation errors.
                                        if (attempt >= 3 || (e.status >= 400 && e.status < 500)) throw e;
                                        await new Promise(r => setTimeout(r, attempt * 2000));
                                    }
                                }
                            },
                            async pick(event) {
                                const file = event.target.files[0];
                                this.problem = '';
                                if (! file) return;
                                const sizeMb = file.size / 1024 / 1024;
                                if (sizeMb > this.maxMb) {
                                    this.problem = `This video is ${this.mb(sizeMb)}. The maximum is ${this.mb(this.maxMb)}. Please compress it or trim it, then try again.`;
                                    event.target.value = '';
                                    return;
                                }

                                const id = crypto.randomUUID();
                                const total = Math.max(1, Math.ceil(file.size / this.chunkBytes));
                                this.progress = 0;

                                try {
                                    for (let i = 0; i < total; i++) {
                                        const form = new FormData();
                                        form.append('upload_id', id);
                                        form.append('index', i);
                                        form.append('total', total);
                                        form.append('chunk', file.slice(i * this.chunkBytes, (i + 1) * this.chunkBytes), 'chunk');
                                        await this.sendWithRetry('{{ route('uploads.chunk') }}', form,
                                            (loaded) => { this.progress = Math.min(99, Math.floor((i * this.chunkBytes + loaded) / file.size * 100)) });
                                    }

                                    const done = new FormData();
                                    done.append('upload_id', id);
                                    done.append('total', total);
                                    done.append('filename', file.name);
                                    done.append('size', file.size);
                                    const result = await this.sendWithRetry('{{ route('uploads.complete') }}', done);
                                    if (! result.id) throw { message: 'The upload could not be saved. Please reload the page and try again.' };

                                    this.progress = 100;
                                    await $wire.useMedia(result.id);
                                } catch (e) {
                                    event.target.value = '';
                                    this.problem = e.message || 'The upload did not finish. Check your internet connection and try again.';
                                } finally {
                                    this.progress = null;
                                }
                            },
                        }">
                        <input type="file" x-on:change="pick($event)" x-bind:disabled="progress !== null" accept="video/mp4,video/quicktime,video/webm"
                            class="block w-full text-sm file:me-4 file:rounded-md file:border-0 file:bg-zinc-800 file:px-4 file:py-2 file:text-white disabled:opacity-50 dark:file:bg-zinc-200 dark:file:text-zinc-900" />

                        <div x-show="progress !== null" x-cloak class="mt-2">
                            <div class="flex justify-between text-xs text-zinc-500">
                                <span>Uploading… keep this page open</span><span x-text="progress + '%'"></span>
                            </div>
                            <div class="mt-1 h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                                <div class="h-full rounded-full bg-zinc-800 transition-all dark:bg-zinc-200" :style="`width: ${progress}%`"></div>
                            </div>
                        </div>
                        <p x-show="problem" x-text="problem" x-cloak class="mt-2 text-sm font-medium text-red-600 dark:text-red-400"></p>
                    </div>
                @endif
                <flux:description>
                    MP4, MOV or WebM, up to {{ $maxUploadMb >= 1024 ? round($maxUploadMb / 1024, 1).' GB' : $maxUploadMb.' MB' }}.
                    Vertical 9:16 works best for TikTok, Reels and Shorts.
                </flux:description>
                <flux:error name="mediaId" />
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
            <flux:error name="when" />

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
