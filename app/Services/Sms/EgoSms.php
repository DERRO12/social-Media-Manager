<?php

namespace App\Services\Sms;

use App\Models\EmailCampaignSend;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Text messages through EgoSMS (Uganda), https://egosms.co. One JSON endpoint:
 * POST {"method": "SendSms", "userdata": {username, password}, "msgdata": [{number, message, senderid, priority}]}
 * → {"Status": "OK", "Cost": …, "MsgFollowUpUniqueCode": …} or {"Status": "Failed", "Message": …}.
 *
 * Clients use their own EgoSMS account if they connect one; otherwise the platform's account from
 * Admin → Settings → SMS, within a monthly allowance per client.
 */
class EgoSms
{
    public const URL = 'https://comms.egosms.co/api/v1/json/';

    /** The platform's shared account, if the admin set one up. */
    public static function platformAccount(): ?array
    {
        $username = Setting::get('sms.username');
        $password = Setting::get('sms.password');

        return $username && $password
            ? ['username' => $username, 'password' => $password, 'sender_id' => (string) Setting::get('sms.sender_id', 'Gokatale'), 'shared' => true]
            : null;
    }

    /** Which account a client's SMS go out from: their own, else the platform's. */
    public static function accountFor(User $user): ?array
    {
        if ($own = $user->smsAccount) {
            return ['username' => $own->username, 'password' => $own->password, 'sender_id' => $own->sender_id, 'shared' => false];
        }

        return self::platformAccount();
    }

    public static function monthlyAllowance(): int
    {
        return (int) Setting::get('sms.monthly_limit', 200);
    }

    /** SMS a client sent this month through the platform's account. */
    public static function usedThisMonth(User $user): int
    {
        return EmailCampaignSend::query()
            ->whereHas('campaign', fn ($q) => $q->where('user_id', $user->id)->where('channel', 'sms'))
            ->where('status', 'sent')
            ->where('sent_at', '>=', now()->startOfMonth())
            ->count();
    }

    /** How many more SMS a client may send now (unlimited on their own account). */
    public static function remainingFor(User $user): int
    {
        $account = self::accountFor($user);

        return match (true) {
            ! $account => 0,
            ! $account['shared'] => PHP_INT_MAX,
            default => max(0, self::monthlyAllowance() - self::usedThisMonth($user)),
        };
    }

    /** @return array{ok: bool, cost: ?float, error: ?string} */
    public function send(array $account, string $number, string $message): array
    {
        try {
            $response = Http::timeout(20)->acceptJson()->post(self::URL, [
                'method' => 'SendSms',
                'userdata' => ['username' => $account['username'], 'password' => $account['password']],
                'msgdata' => [[
                    'number' => $number,
                    'message' => $message,
                    'senderid' => $account['sender_id'],
                    'priority' => '0',
                ]],
            ]);
        } catch (Throwable) {
            return ['ok' => false, 'cost' => null, 'error' => 'Could not reach EgoSMS. Please try again.'];
        }

        $data = $response->json() ?? [];

        if (strtoupper((string) ($data['Status'] ?? '')) === 'OK') {
            return ['ok' => true, 'cost' => is_numeric($data['Cost'] ?? null) ? (float) $data['Cost'] : null, 'error' => null];
        }

        return ['ok' => false, 'cost' => null, 'error' => self::explain((string) ($data['Message'] ?? $response->body()))];
    }

    /** Account balance in UGX, or null if EgoSMS didn't say. */
    public function balance(array $account): ?string
    {
        try {
            $data = Http::timeout(15)->acceptJson()->post(self::URL, [
                'method' => 'Balance',
                'userdata' => ['username' => $account['username'], 'password' => $account['password']],
            ])->json() ?? [];
        } catch (Throwable) {
            return null;
        }

        return isset($data['Balance']) ? (string) $data['Balance'] : null;
    }

    public static function explain(string $message): string
    {
        $lower = strtolower($message);

        return match (true) {
            str_contains($lower, 'credential') || str_contains($lower, 'username') || str_contains($lower, 'password') || str_contains($lower, 'login')
                => 'EgoSMS rejected the username or password.',
            str_contains($lower, 'balance') || str_contains($lower, 'credit') || str_contains($lower, 'insufficient')
                => 'The EgoSMS account has run out of credit. Top it up at egosms.co.',
            str_contains($lower, 'sender') => 'EgoSMS rejected the sender ID. Use the one approved on your EgoSMS account.',
            default => 'EgoSMS: '.mb_substr($message ?: 'unknown error', 0, 200),
        };
    }

    /** Whether an error means every message will fail (so stop instead of trying the rest). */
    public static function isFatal(string $error): bool
    {
        return str_contains($error, 'rejected the username') || str_contains($error, 'run out of credit') || str_contains($error, 'sender ID');
    }
}
