<?php

declare(strict_types=1);

use App\Support\Auth\InvitationContinuation;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store as SessionStore;

/*
 * 招待継続 (InvitationContinuation) の単体契約 (正典 v1 i11+i14 / aicue:T263 施策 B)。
 * remember → resolve の round-trip / 型衛生 (不正値は忘れさせて null) / forget の冪等。
 */

/**
 * forget() の呼び出し回数を記録する spy。「鍵が無いときは forget を呼ばない」という契約は
 * 事後状態 (鍵なし) だけでは検証できない (初期状態が既に鍵なしのため、無条件 forget へ
 * 退行しても緑になる) — 呼び出しそのものを観測して固定する。
 */
final class InvitationContinuationForgetSpySession extends SessionStore
{
    public int $forgetCalls = 0;

    /**
     * @param  string|list<string>  $keys
     */
    public function forget($keys): void
    {
        $this->forgetCalls++;
        parent::forget($keys);
    }
}

function freshInvitationContinuationSession(): InvitationContinuationForgetSpySession
{
    return new InvitationContinuationForgetSpySession('test-session', new ArraySessionHandler(60));
}

test('remember → resolve の round-trip で token が返る', function (): void {
    $session = freshInvitationContinuationSession();

    InvitationContinuation::remember($session, 'plain-token-123');

    expect(InvitationContinuation::resolve($session))->toBe('plain-token-123');
    // resolve は非破壊 (後続 POST の受諾に token を残す)
    expect(InvitationContinuation::resolve($session))->toBe('plain-token-123');
});

test('非文字列 (配列) は忘れさせて null を返す (型衛生)', function (): void {
    $session = freshInvitationContinuationSession();
    $session->put('invitation_token', ['tampered']);

    expect(InvitationContinuation::resolve($session))->toBeNull();
    expect($session->has('invitation_token'))->toBeFalse();
});

test('非文字列 (数値) は忘れさせて null を返す (型衛生)', function (): void {
    $session = freshInvitationContinuationSession();
    $session->put('invitation_token', 12345);

    expect(InvitationContinuation::resolve($session))->toBeNull();
    expect($session->has('invitation_token'))->toBeFalse();
});

test('空文字は忘れさせて null を返す', function (): void {
    $session = freshInvitationContinuationSession();
    $session->put('invitation_token', '');

    expect(InvitationContinuation::resolve($session))->toBeNull();
    expect($session->has('invitation_token'))->toBeFalse();
});

test('鍵が無い (null) 場合は forget を呼ばず null を返す', function (): void {
    $session = freshInvitationContinuationSession();

    expect(InvitationContinuation::resolve($session))->toBeNull();
    expect($session->has('invitation_token'))->toBeFalse();
    // 事後状態 (鍵なし) は初期状態と同じで区別がつかないため、呼び出し回数を直接固定する
    // (無条件 forget への退行を赤にする)
    expect($session->forgetCalls)->toBe(0);
});

test('有効 token の resolve は forget を呼ばない (非破壊の直接観測)', function (): void {
    $session = freshInvitationContinuationSession();
    InvitationContinuation::remember($session, 'plain-token-123');

    expect(InvitationContinuation::resolve($session))->toBe('plain-token-123');
    expect($session->forgetCalls)->toBe(0);
});

test('forget は冪等 (2 回呼んでも例外にならず鍵は消えたまま)', function (): void {
    $session = freshInvitationContinuationSession();
    InvitationContinuation::remember($session, 'plain-token-123');

    InvitationContinuation::forget($session);
    InvitationContinuation::forget($session);

    expect($session->has('invitation_token'))->toBeFalse();
    expect(InvitationContinuation::resolve($session))->toBeNull();
});
