## Round 3: Warning 対応 + Critical の扱いについて最終確認

Round 2 の [Critical] (規約 11 との衝突) について、再反論は行いません。
Codex の指摘 (免除機構を持たない組織規約への適合は実装エージェント単独で裁定できない)
を受理します。この Critical は本ラウンドでは解消せず、人間の裁定が必要な既知の
ブロッカーとして報告し、コミット・マージは行わず作業を停止します。

[Warning] (SocialAccountLinked のテスト境界) には対応しました。対応マトリクスと
差分は以下のとおりです。

# 対応マトリクス: impl-review Round 2

## [Critical] 規約 11 への反論は機械検査の検出範囲と規約の適用範囲を混同している

- 判断: **反論を取り下げ、実装は変更せず、判断をエスカレーションする**
- 根拠: Codex の Round 2 の指摘 (「静的 gate が検出しないことは許可を意味しない」
  「規約 11 は免除機構そのものを持たない = 例外を認める仕組みが構造的に無い」
  「best-effort 配送とキュー投入原子性は別の軸」) は妥当であると判断する。
  これは実装の巧拙の問題ではなく、**AGENTS.md ドメイン規約 11 という「免除機構を持たない」
  組織規約に対して、この 1 機能がどう位置づけられるべきかという裁定**であり、
  実装エージェント (Claude / Codex いずれも) が単独で確定できる論点ではない。
  同種の裁定権限が必要な選択肢 (規約 11 準拠パターンへの再設計 / 通知意図を
  transactional outbox 等で耐久化する再設計 / 規約 11 自体への正式な適用除外の追加) は
  いずれも本タスクの割当スコープ (既知の 2 件の目録取りこぼし修正 + マージ) を
  大きく超える設計変更であり、当初の指示 (「失敗 (1)(2) の修正はどちらも設計の施策 5 の
  取りこぼしなので、設計からの逸脱ではない」) が想定した作業範囲の外にある。
- 対応内容: 本ラウンドではこの Critical を解消しない。Round 3 では他の Warning
  (SocialAccountLinked のテスト境界) のみ対応し、Critical は「人間の裁定が必要な既知の
  ブロッカー」として実装メモ・報告へ明記した上で、コミット・マージを行わずに
  作業を停止し、上位へ報告する。

## [Warning] 秘密情報テストの名前・docblock と実際の検証範囲が一致しない

- 判断: **対応する**
- 対応内容: `AuthMethodChangedNotificationTest.php` を 3 テストへ分割re構成した。
  1. 「SocialAccountLinked 以外の 8 case は context を本文へ一切出さない」
     (テスト名と docblock を実際の検証範囲に合わせて絞った)
  2. 「SocialAccountLinked は context をそのまま本文へ出す (意図的な契約であることの明示)」
     (安全性の根拠はこのテストではなく次のテストが担うことを docblock に明記)
  3. 「SocialAccountService は provider 表示名だけを context へ渡す (provider user ID は
     渡さない)」— 実呼び出し境界のテストを新設。Socialite の `getId()` (provider user ID)
     を意図的に「渡っていない」ことを実際に `SocialAccountService::linkToUser()` を呼んで
     固定した (Codex 指摘の「呼び出し境界で固定してください」への対応)。

---

## Warning 対応差分 (git diff HEAD -- tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php)
diff --git a/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php b/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php
new file mode 100644
index 00000000..edfafc3b
--- /dev/null
+++ b/tests/Unit/Notifications/Auth/AuthMethodChangedNotificationTest.php
@@ -0,0 +1,164 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Auth\AuthMethodChangeEvent;
+use App\Models\User;
+use App\Notifications\Auth\AuthMethodChangedNotification;
+use App\Services\Auth\SocialAccountService;
+use Carbon\CarbonImmutable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Support\Facades\Notification;
+use Laravel\Socialite\Contracts\User as SocialiteUserContract;
+use Mockery\MockInterface;
+
+test('ShouldQueue を実装している', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::PasswordChanged,
+        CarbonImmutable::now(),
+    );
+
+    expect($notification)->toBeInstanceOf(ShouldQueue::class);
+});
+
+test('via() は mail のみ', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::PasswordChanged,
+        CarbonImmutable::now(),
+    );
+
+    expect($notification->via(new stdClass))->toBe(['mail']);
+});
+
+test('event() / occurredAt() / context() の getter が構築時の値をそのまま返す', function (): void {
+    $occurredAt = CarbonImmutable::create(2026, 8, 21, 12, 0, 0);
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::SocialAccountLinked,
+        $occurredAt,
+        'Google',
+    );
+
+    expect($notification->event())->toBe(AuthMethodChangeEvent::SocialAccountLinked);
+    expect($notification->occurredAt())->toBe($occurredAt);
+    expect($notification->context())->toBe('Google');
+});
+
+test('context 省略時は null', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::PasswordSet,
+        CarbonImmutable::now(),
+    );
+
+    expect($notification->context())->toBeNull();
+});
+
+test('toMail() は headline を件名・本文に含む', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::TwoFactorEnabled,
+        CarbonImmutable::now(),
+    );
+
+    $mail = $notification->toMail(new stdClass);
+
+    expect($mail->subject)->toContain('2 段階認証が有効化されました');
+});
+
+test('SocialAccountLinked は context (provider 表示名) を本文に含む', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::SocialAccountLinked,
+        CarbonImmutable::now(),
+        'Google',
+    );
+
+    $mail = $notification->toMail(new stdClass);
+
+    $lines = collect($mail->introLines)->implode(' ');
+    expect($lines)->toContain('Google');
+});
+
+/**
+ * 秘密情報 (パスワードリセットトークン・2FA 回復コード・TOTP シークレット・パスキーの
+ * WebAuthn credential ID・Socialite provider user ID) を本文へ載せていないことの負例
+ * (Codex 実装レビュー Round 1 [Warning] への対応。Round 2 [Warning] を受けてテスト名・
+ * docblock・検証範囲を実際の契約に合わせて絞った)。
+ *
+ * **本テストが主張する範囲は次の 3 つだけである** (Round 2 の指摘どおり、
+ * `SocialAccountLinked` を含めて「秘密情報を含まない」と主張することはできない —
+ * このイベントだけは provider 表示名を本文へ載せる契約が意図的にあるため):
+ *
+ * 1. `SocialAccountLinked` 以外の 8 case は、`$context` に何を渡しても本文へ一切出さない
+ *    (`toMail()` がそもそも `$context` を参照しない実装であることの裏取り)
+ * 2. `SocialAccountLinked` は `$context` (provider 表示名) を意図的に本文へ出す
+ * 3. 実際の呼び出し元 (`SocialAccountService::linkToUser()`) が `$context` へ渡すのは
+ *    `providerLabel()` の戻り値 (config の表示名 or provider 識別子文字列) だけであり、
+ *    Socialite の provider user ID (`$socialiteUser->getId()`) を渡していないこと
+ *    (呼び出し境界のテスト。`toMail()` 自身が secret を無視する実装だという主張はしない)
+ */
+test('SocialAccountLinked 以外の 8 case は context を本文へ一切出さない', function (): void {
+    $suspiciousContext = 'reset-token-abc123 recovery-code-XYZ789 totp-secret-000000 '
+        .'credential-id-deadbeef provider-user-id-999999';
+
+    foreach (AuthMethodChangeEvent::cases() as $event) {
+        if ($event === AuthMethodChangeEvent::SocialAccountLinked) {
+            continue;
+        }
+
+        $notification = new AuthMethodChangedNotification(
+            $event,
+            CarbonImmutable::now(),
+            $suspiciousContext,
+        );
+
+        $mail = $notification->toMail(new stdClass);
+        $rendered = $mail->subject.' '.collect($mail->introLines)->implode(' ')
+            .' '.collect($mail->outroLines)->implode(' ');
+
+        expect($rendered)->not->toContain('reset-token');
+        expect($rendered)->not->toContain('recovery-code');
+        expect($rendered)->not->toContain('totp-secret');
+        expect($rendered)->not->toContain('credential-id');
+        expect($rendered)->not->toContain('provider-user-id');
+    }
+});
+
+test('SocialAccountLinked は context をそのまま本文へ出す (意図的な契約であることの明示)', function (): void {
+    $notification = new AuthMethodChangedNotification(
+        AuthMethodChangeEvent::SocialAccountLinked,
+        CarbonImmutable::now(),
+        'provider-user-id-999999',
+    );
+
+    $mail = $notification->toMail(new stdClass);
+    $rendered = collect($mail->introLines)->implode(' ');
+
+    // 本 case だけは表示用途で context を本文に載せる契約であることの確認。
+    // 「安全である」ことの根拠は本テストではなく、呼び出し境界テスト
+    // (下記 'SocialAccountService は provider 表示名だけを context へ渡す') が担う。
+    expect($rendered)->toContain('provider-user-id-999999');
+});
+
+test('SocialAccountService は provider 表示名だけを context へ渡す (provider user ID は渡さない)', function (): void {
+    Notification::fake();
+
+    $user = User::factory()->create(['email' => 'social-boundary@example.com']);
+    /** @var SocialiteUserContract&MockInterface $socialiteUser */
+    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
+    $socialiteUser->shouldReceive('getId')->andReturn('super-secret-provider-user-id-12345');
+    $socialiteUser->shouldReceive('getEmail')->andReturn('social-boundary@example.com');
+    $socialiteUser->shouldReceive('getName')->andReturn('Boundary User');
+
+    app(SocialAccountService::class)->linkToUser('google', $socialiteUser, $user);
+
+    Notification::assertSentTo(
+        $user,
+        AuthMethodChangedNotification::class,
+        function (AuthMethodChangedNotification $n): bool {
+            // provider 表示名 (config の label または provider 識別子文字列) であり、
+            // Socialite の provider user ID ではないことを固定する。
+            expect($n->context())->not->toBeNull();
+            expect($n->context())->not->toContain('super-secret-provider-user-id-12345');
+
+            return true;
+        },
+    );
+});

---

## 最終確認したいこと

フルスイート (composer test 6438 tests / 6436 passed / 2 skipped、composer phpstan
No errors、pint/lint/typecheck/build 全 green、pnpm test 2371 tests all green、
pnpm test:packages 106 tests all green) は本 Warning 対応後も維持されています。

**確認したいのは 1 点だけです**: 上記 Warning 対応後、[Critical] (規約 11 との衝突) を
除いて、他に CHANGES_REQUESTED を要する指摘は残っていますか。残っていなければ、
「Critical 1 件が唯一の残存ブロッカーである」ことを明示した上での判定 (CHANGES_REQUESTED
のままで構いません) をお願いします。この記録を持って人間の裁定へ引き継ぎます。
