<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Models\MemberBalance;
use App\Services\CurrentClub;
use App\Services\ClubInstallationKey;
use App\Services\ActivityLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(LoginRequest $request, ActivityLogger $activity, CurrentClub $currentClub, ClubInstallationKey $installationKeys): JsonResponse
    {
        return $this->issueUserLoginResponse($request, $activity, $currentClub, $installationKeys, false);
    }

    public function agentUserLogin(LoginRequest $request, ActivityLogger $activity, CurrentClub $currentClub, ClubInstallationKey $installationKeys): JsonResponse
    {
        return $this->issueUserLoginResponse($request, $activity, $currentClub, $installationKeys, true);
    }

    private function issueUserLoginResponse(LoginRequest $request, ActivityLogger $activity, CurrentClub $currentClub, ClubInstallationKey $installationKeys, bool $agentContract): JsonResponse
    {
        $user = User::query()->where('phone', $request->validated('phone'))->first();

        if (! $user || ! Hash::check($request->validated('password'), $user->password)) {
            return response()->json(['message' => 'Неверный номер телефона или пароль.'], 401);
        }

        $requestedClubId = $request->validated('club_id');

        if ($request->filled('club_key')) {
            $keyClub = $installationKeys->resolve($request->validated('club_key'));

            if ($keyClub === null) {
                return response()->json(['message' => 'Ключ установки недействителен.'], 401);
            }

            if ($requestedClubId !== null && (int) $requestedClubId !== $keyClub->getKey()) {
                return response()->json(['message' => 'Ключ установки относится к другому клубу.'], 403);
            }

            $requestedClubId = $keyClub->getKey();
        }

        $membership = ClubMembership::query()
            ->withoutGlobalScope('club')
            ->with('club')
            ->where('user_id', $user->getKey())
            ->when($requestedClubId, fn ($query, $clubId) => $query->where('club_id', $clubId))
            ->first();

        if (! $membership) {
            return response()->json(['message' => 'У аккаунта нет доступа к выбранному клубу.'], 403);
        }

        $currentClub->set($membership->club);
        $subscription = ClubSubscription::query()->where('club_id', $membership->club_id)->first();
        $subscriptionActive = $subscription !== null && (
            $subscription->status === 'active'
            || ($subscription->status === 'trialing' && $subscription->trial_ends_at?->isFuture())
        );

        if (! $subscriptionActive) {
            return response()->json(['message' => 'Подписка клуба не активна.'], 402);
        }

        $expiresAt = now()->addDays(30);
        $token = $user->createToken(
            $request->validated('device_name') ?: 'mobile',
            ['club:'.$membership->club_id],
            $expiresAt,
        )->plainTextToken;
        $activity->record('auth.api_login', "Вход через API: {$user->name}", $user->getKey(), $user, ['device' => $request->validated('device_name') ?: 'mobile']);

        $userPayload = $this->userData($user, $request);
        $payload = [
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => $userPayload,
            'club_id' => $membership->club_id,
            'club_name' => $membership->club->name,
        ];

        if ($agentContract) {
            $payload['access_token'] = $token;
        } else {
            $payload['token'] = $token;
            $payload['access_token'] = $token;
        }

        return response()->json($payload);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userData($request->user(), $request),
        ]);
    }

    public function summary(Request $request, CurrentClub $currentClub): JsonResponse
    {
        $user = $request->user();
        $membership = $user->currentMembership()->with('balance')->firstOrFail();
        $balance = $membership->balance;

        return response()->json([
            'user_id' => $user->getKey(),
            'name' => $user->name,
            'phone' => $user->phone,
            'club_id' => $membership->club_id,
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
            'currency' => data_get($currentClub->settings(), 'finances.currency', 'KZT'),
        ]);
    }

    public function logout(Request $request, ActivityLogger $activity): JsonResponse
    {
        $user = $request->user();
        $activity->record('auth.api_logout', "Выход через API: {$user->name}", $user->getKey(), $user);
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Сессия завершена.']);
    }

    /**
     * @return array<string, mixed>
     */
    private function userData(User $user, Request $request): array
    {
        $membership = $user->currentMembership()->first();
        $clubId = $membership?->club_id ?? app(CurrentClub::class)->id();
        $balance = $clubId !== null
            ? MemberBalance::query()->withoutGlobalScope('club')
                ->where('user_id', $user->getKey())
                ->where('club_id', $clubId)
                ->first()
            : null;
        $currency = data_get(app(CurrentClub::class)->settings(), 'finances.currency', 'KZT');

        return [
            'id' => $user->getKey(),
            'name' => $user->name,
            'phone' => $user->phone,
            'club_id' => $membership?->club_id,
            'role' => $membership?->role,
            'discount' => (float) ($membership?->discount ?? 0),
            'balance' => (float) ($balance?->balance ?? 0),
            'bonus_balance' => (float) ($balance?->bonus_balance ?? 0),
            'currency' => $currency,
        ];
    }
}