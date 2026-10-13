<?php

use App\Models\User;
use App\Models\MemberBalance;
use App\Models\Club;
use App\Models\ClubMembership;
use App\Models\ClubSubscription;
use App\Services\CurrentClub;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

test('mobile users can login, inspect their profile, and revoke their token', function () {
    $user = User::factory()->create([
        'name' => 'Мобильный пользователь',
        'phone' => '+79991234567',
        'password' => Hash::make('secret-password'),
    ]);
    $user->currentMembership()->firstOrFail()->update(['role' => 'Гость', 'discount' => 5]);
    MemberBalance::query()->where('user_id', $user->id)->update(['balance' => 1200, 'bonus_balance' => 30]);

    $loginResponse = $this->postJson('/api/auth/login', [
        'phone' => '+79991234567',
        'password' => 'secret-password',
        'device_name' => 'ios-app',
    ]);

    $loginResponse
        ->assertSuccessful()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.phone', '+79991234567')
        ->assertJsonPath('user.role', 'Гость')
        ->assertJsonPath('user.balance', 1200);

    $token = $loginResponse->json('token');

    $this->withToken($token)
        ->getJson('/api/auth/me')
        ->assertSuccessful()
        ->assertJsonPath('user.name', 'Мобильный пользователь');

    $this->withToken($token)->postJson('/api/auth/logout')->assertSuccessful();
    $this->assertDatabaseMissing('personal_access_tokens', [
        'id' => (int) explode('|', $token, 2)[0],
    ]);

    $this->app['auth']->forgetGuards();
    $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
});

test('mobile login rejects invalid credentials', function () {
    User::factory()->create([
        'phone' => '+79991234567',
        'password' => Hash::make('secret-password'),
    ]);

    $this->postJson('/api/auth/login', [
        'phone' => '+79991234567',
        'password' => 'wrong-password',
    ])->assertUnauthorized();
});

test('mobile profile requires a bearer token', function () {
    $this->getJson('/api/auth/me')->assertUnauthorized();
});

test('api me returns only the authenticated users data for the token club', function () {
    $currentClub = app(CurrentClub::class);
    $clubA = $currentClub->get();
    $clubA->update(['settings' => ['finances' => ['currency' => 'KZT']]]);
    $user = User::factory()->create([
        'name' => 'Иван',
        'phone' => '+77001234567',
    ]);
    MemberBalance::query()->withoutGlobalScope('club')
        ->firstOrCreate(['club_id' => $clubA->id, 'user_id' => $user->id], ['balance' => 0, 'bonus_balance' => 0])
        ->update([
            'balance' => 150.5,
            'bonus_balance' => 20,
        ]);

    $clubB = Club::query()->create(['name' => 'Другой клуб', 'slug' => 'api-me-other-club']);
    $currentClub->set($clubB);
    $user->memberships()->create(['role' => 'manager']);
    MemberBalance::query()->withoutGlobalScope('club')->create([
        'club_id' => $clubB->id,
        'user_id' => $user->id,
        'balance' => 9999,
        'bonus_balance' => 888,
    ]);

    $token = $user->createToken('api-me', ['club:'.$clubA->id])->plainTextToken;
    $currentClub->clear();

    $this->getJson('/api/me')->assertUnauthorized();

    $this->withToken($token)->getJson('/api/me')
        ->assertSuccessful()
        ->assertExactJson([
            'user_id' => $user->id,
            'name' => 'Иван',
            'phone' => '+77001234567',
            'club_id' => $clubA->id,
            'balance' => 150.5,
            'bonus_balance' => 20.0,
            'currency' => 'KZT',
        ])
        ->assertJsonMissingPath('token')
        ->assertJsonMissingPath('password');

    $this->withToken($token)
        ->withHeader('X-Club-Id', (string) $clubB->id)
        ->getJson('/api/me')
        ->assertForbidden();
});

test('api me returns payment required when the token clubs subscription is inactive', function () {
    $user = User::factory()->create();
    $clubId = $user->currentMembership()->value('club_id');
    $subscription = ClubSubscription::query()->where('club_id', $clubId)->firstOrFail();
    $subscription->update(['status' => 'trialing', 'trial_ends_at' => now()->subSecond()]);
    $token = $user->createToken('api-me', ['club:'.$clubId])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($token)->getJson('/api/me')->assertStatus(402);
});

test('agent user login returns access token and user balance for the selected club', function () {
    $club = Club::query()->create(['name' => 'Agent Club', 'slug' => 'agent-club']);
    app(CurrentClub::class)->set($club);
    $club->update(['settings' => ['finances' => ['currency' => 'KZT']]]);

    $plan = App\Models\SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $club->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => now()->subDay(),
        'trial_ends_at' => null,
    ]);

    $user = User::factory()->create([
        'name' => 'Пользователь клуба',
        'phone' => '+79990000001',
        'password' => Hash::make('secret-password'),
    ]);
    $user->memberships()->withoutGlobalScope('club')->where('club_id', $club->id)->delete();
    $user->memberships()->create(['club_id' => $club->id, 'role' => 'cashier', 'discount' => 0]);
    MemberBalance::query()->withoutGlobalScope('club')
        ->firstOrCreate(['club_id' => $club->id, 'user_id' => $user->getKey()], ['balance' => 0, 'bonus_balance' => 0])
        ->update([
            'balance' => 250.50,
            'bonus_balance' => 30.25,
        ]);

    $this->postJson('/api/agent/user/login', [
        'phone' => '+79990000001',
        'password' => 'secret-password',
        'club_id' => $club->id,
    ])
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.name', 'Пользователь клуба')
        ->assertJsonPath('user.phone', '+79990000001')
        ->assertJsonPath('user.club_id', $club->id)
        ->assertJsonPath('user.balance', 250.5)
        ->assertJsonPath('user.bonus_balance', 30.25)
        ->assertJsonPath('user.currency', 'KZT')
        ->assertJsonPath('user.role', 'cashier')
        ->assertJsonStructure(['access_token', 'token_type', 'expires_at', 'user']);
});

test('agent user login rejects invalid credentials', function () {
    $club = Club::query()->create(['name' => 'Agent Club 2', 'slug' => 'agent-club-2']);
    app(CurrentClub::class)->set($club);
    $club->subscription()->create([
        'saas_plan_id' => App\Models\SaasPlan::query()->firstOrCreate(
            ['slug' => 'trial'],
            ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
        )->id,
        'status' => 'active',
        'started_at' => now()->subDay(),
        'trial_ends_at' => null,
    ]);

    User::factory()->create([
        'phone' => '+79990000002',
        'password' => Hash::make('secret-password'),
    ]);
    $this->postJson('/api/agent/user/login', [
        'phone' => '+79990000002',
        'password' => 'wrong-password',
        'club_id' => $club->id,
    ])->assertUnauthorized();
});

test('agent user login requires membership for the requested club', function () {
    $club = Club::query()->create(['name' => 'Wrong Club', 'slug' => 'wrong-club']);
    app(CurrentClub::class)->set($club);
    $club->subscription()->create([
        'saas_plan_id' => App\Models\SaasPlan::query()->firstOrCreate(
            ['slug' => 'trial'],
            ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
        )->id,
        'status' => 'active',
        'started_at' => now()->subDay(),
        'trial_ends_at' => null,
    ]);

    $user = User::query()->create([
        'name' => 'No Club Member',
        'phone' => '+79990000003',
        'password' => Hash::make('secret-password'),
    ]);
    $foreignClub = Club::query()->create(['name' => 'Another Club', 'slug' => 'another-club']);
    ClubMembership::query()->withoutGlobalScope('club')->forceCreate([
        'club_id' => $foreignClub->id,
        'user_id' => $user->id,
        'role' => 'manager',
    ]);

    $this->postJson('/api/agent/user/login', [
        'phone' => '+79990000003',
        'password' => 'secret-password',
        'club_id' => $club->id,
    ])->assertForbidden();
});

test('agent user login requires active subscription for the selected club', function () {
    $club = Club::query()->create(['name' => 'Inactive Club', 'slug' => 'inactive-club']);
    app(CurrentClub::class)->set($club);
    $plan = App\Models\SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $club->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'trialing',
        'started_at' => now()->subDays(15),
        'trial_ends_at' => now()->subSecond(),
    ]);

    $user = User::query()->create([
        'name' => 'Inactive Subscription User',
        'phone' => '+79990000004',
        'password' => Hash::make('secret-password'),
    ]);
    $user->memberships()->create(['club_id' => $club->id, 'role' => 'admin']);

    $this->postJson('/api/agent/user/login', [
        'phone' => '+79990000004',
        'password' => 'secret-password',
        'club_id' => $club->id,
    ])->assertStatus(402)->assertJsonPath('message', 'Подписка клуба не активна.');
});

test('get api me returns the authenticated users balance and profile from the bearer token', function () {
    $club = Club::query()->create(['name' => 'Token Club', 'slug' => 'token-club']);
    app(CurrentClub::class)->set($club);
    $club->update(['settings' => ['finances' => ['currency' => 'KZT']]]);
    $plan = App\Models\SaasPlan::query()->firstOrCreate(
        ['slug' => 'trial'],
        ['name' => 'Пробный период', 'trial_days' => 14, 'is_active' => true],
    );
    $club->subscription()->create([
        'saas_plan_id' => $plan->id,
        'status' => 'active',
        'started_at' => now()->subDay(),
        'trial_ends_at' => null,
    ]);

    $user = User::query()->create([
        'name' => 'Токен Пользователь',
        'phone' => '+79990000005',
        'password' => Hash::make('secret-password'),
    ]);
    $user->memberships()->create(['club_id' => $club->id, 'role' => 'operator']);
    MemberBalance::query()->withoutGlobalScope('club')
        ->firstOrCreate(['club_id' => $club->id, 'user_id' => $user->id], ['balance' => 0, 'bonus_balance' => 0])
        ->update([
            'balance' => 321.25,
            'bonus_balance' => 12.5,
        ]);

    $token = $user->createToken('agent-user', ['club:'.$club->id])->plainTextToken;
    app(CurrentClub::class)->clear();

    $this->withToken($token)
        ->getJson('/api/me')
        ->assertOk()
        ->assertJsonPath('user_id', $user->id)
        ->assertJsonPath('name', 'Токен Пользователь')
        ->assertJsonPath('phone', '+79990000005')
        ->assertJsonPath('club_id', $club->id)
        ->assertJsonPath('balance', 321.25)
        ->assertJsonPath('bonus_balance', 12.5)
        ->assertJsonPath('currency', 'KZT');
});