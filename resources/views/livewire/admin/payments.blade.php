<?php

use App\Models\PaymentClaim;
use App\Notifications\PaymentClaimReviewed;
use App\Services\Billing\SubscriptionManager;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'pending';

    public ?int $rejectingId = null;
    public string $rejectReason = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function approve(int $id, SubscriptionManager $manager): void
    {
        $claim = PaymentClaim::pending()->findOrFail($id);
        $sub = $manager->approvePayment($claim, auth()->user());
        rescue(fn () => $claim->user->notify(new PaymentClaimReviewed($claim->fresh())));

        session()->flash('status', "Approved. {$claim->user->name}'s {$claim->plan->name} plan is active until {$sub->ends_at->toFormattedDateString()}.");
        $this->redirectRoute('admin.payments', ['status' => $this->status], navigate: true);
    }

    public function startReject(int $id): void
    {
        $this->rejectingId = $id;
        $this->rejectReason = '';
        $this->modal('reject-form')->show();
    }

    public function reject(SubscriptionManager $manager): void
    {
        $this->validate(['rejectReason' => ['nullable', 'string', 'max:500']]);

        $claim = PaymentClaim::pending()->findOrFail($this->rejectingId);
        $manager->rejectPayment($claim, auth()->user(), $this->rejectReason ?: null);
        rescue(fn () => $claim->user->notify(new PaymentClaimReviewed($claim->fresh())));

        $this->modal('reject-form')->close();
        session()->flash('status', 'Payment rejected. The client has been told by email.');
        $this->redirectRoute('admin.payments', ['status' => $this->status], navigate: true);
    }

    public function with(): array
    {
        return [
            'claims' => PaymentClaim::with('user.subscription.plan', 'plan', 'reviewer')
                ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
                ->latest()
                ->paginate(25),
            'pendingCount' => PaymentClaim::pending()->count(),
        ];
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <flux:heading size="xl">Payments</flux:heading>
            <flux:text>Clients who paid with a PayPal or Yo! Payments link. Check the reference in your account, then approve to activate their plan.</flux:text>
        </div>
        <flux:radio.group wire:model.live="status" variant="segmented">
            <flux:radio value="pending" :label="'To check ('.$pendingCount.')'" />
            <flux:radio value="approved" label="Approved" />
            <flux:radio value="rejected" label="Rejected" />
            <flux:radio value="all" label="All" />
        </flux:radio.group>
    </div>

    <div class="flex flex-col gap-3">
        @forelse ($claims as $claim)
            <div wire:key="claim-{{ $claim->id }}" class="flex flex-wrap items-start gap-4 rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <flux:badge size="sm" :color="$claim->statusColor()">{{ ucfirst($claim->status) }}</flux:badge>
                        <a href="{{ route('admin.client', $claim->user) }}" wire:navigate class="font-medium hover:underline">{{ $claim->user->name }}</a>
                        <span class="text-sm text-zinc-500">{{ $claim->user->email }}</span>
                    </div>
                    <div class="mt-2 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                        <div><span class="text-zinc-500">Plan:</span> {{ $claim->plan?->name ?? '—' }} ({{ $claim->currency }} {{ $claim->amount }})</div>
                        <div><span class="text-zinc-500">Method:</span> {{ $claim->methodLabel() }}</div>
                        <div><span class="text-zinc-500">Reference:</span> <span class="select-all font-mono">{{ $claim->reference }}</span></div>
                        <div><span class="text-zinc-500">Sent:</span> {{ $claim->created_at->format('M j, Y g:i A') }}</div>
                        @if ($claim->note)
                            <div class="sm:col-span-2"><span class="text-zinc-500">Note:</span> {{ $claim->note }}</div>
                        @endif
                        @if ($claim->user->subscription)
                            <div class="sm:col-span-2"><span class="text-zinc-500">Current status:</span> {{ $claim->user->subscription->effectiveStatus()->label() }}{{ $claim->user->subscription->plan ? ' · '.$claim->user->subscription->plan->name : '' }}</div>
                        @endif
                        @if ($claim->reviewed_at)
                            <div class="sm:col-span-2 text-zinc-500">{{ ucfirst($claim->status) }} {{ $claim->reviewed_at->diffForHumans() }} by {{ $claim->reviewer?->name }}{{ $claim->admin_note ? ': '.$claim->admin_note : '' }}</div>
                        @endif
                    </div>
                </div>
                @if ($claim->status === 'pending')
                    <div class="flex gap-2">
                        <flux:button variant="primary" icon="check" wire:click="approve({{ $claim->id }})"
                            wire:confirm="Approve this payment and activate {{ $claim->plan?->name }} for {{ $claim->plan?->interval === 'year' ? 'a year' : 'a month' }}?">Approve</flux:button>
                        <flux:button variant="danger" icon="x-mark" wire:click="startReject({{ $claim->id }})">Reject</flux:button>
                    </div>
                @endif
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                <flux:text>{{ $status === 'pending' ? 'No payments waiting. 🎉' : 'Nothing here.' }}</flux:text>
            </div>
        @endforelse
    </div>

    {{ $claims->links() }}

    <flux:modal name="reject-form" class="w-full max-w-md">
        <form wire:submit="reject" class="flex flex-col gap-4">
            <flux:heading size="lg">Reject payment</flux:heading>
            <flux:textarea wire:model="rejectReason" label="Reason (sent to the client)" rows="3" placeholder="e.g. We couldn't find this reference. Please check the transaction ID." />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="danger">Reject</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
