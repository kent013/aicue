# Round 2: Round 1 の指摘への対応

Round 1 の指摘 **7 件すべてに対応した** (反論・見送りは 0 件)。
「実装側の適応」#10 (queued Mailable の暗号化) も対応済みである。

以下は対応マトリクスと、対応した箇所の**差分の全文** (指摘のあった実装 + 追加した回帰テスト) である。
Round 1 で「本文を渡していない」と指摘されたテストのうち、**指摘に対応した 3 ファイル**は本文を添えた。

---

# 対応マトリクス: impl-review Round 1

Codex の全体判定は `CHANGES_REQUESTED`。**指摘 7 件すべてを対応した** (反論・見送りは 0 件)。
「実装側の適応」10 件は 9 件 APPROVE / 1 件 (#10) 要修正で、要修正分も対応済みである。

---

## [Critical] FormRequest の validation failure で認可コード・state・確認トークンがセッションへ残る

- **判断**: 対応する
- **根拠**: **指摘が正しい**。Laravel は validation の失敗時、controller へ到達する**前に**
  `Handler::invalid()` が `withInput(Arr::except($request->input(), $this->dontFlash))` を呼ぶ。
  実装のコメントが言っていた「controller で `withInput()` を呼ばない」は**この経路を塞がない**。
  `dontFlash` には `client_secret` しか入れていないので、`code` / `state` / `token` は素通りだった。
  設計が「経路側で閉じる」と書いていた内容を、実装が**controller 側だけ**で解釈したのが原因である。
- **対応内容**:
  - `App\Support\EnterpriseSso\UniformLoginFailure` を新設し、**企業ログインの失敗の応答を 1 か所へ集約**した
    (文言・行き先・入力の扱いが 1 つの実装にしかない = 片方だけ漏れる形が消える)。
  - `EnterpriseSsoCallbackRequest::failedValidation()` を override し、
    `ValidationException` に**応答を持たせて**既定の組み立て (= flash) を通らないようにした。
  - `ConfirmEmailPromotionRequest::failedValidation()` も同様。行き先と文言は
    「無効なトークン」と**同じ定数**を使う (validation で落ちたか照合で落ちたかを外から区別できない)。
  - `EmailPromotionController` は同じ定数を参照するようにし、文言の二重管理を消した。
- **回帰テスト** (指摘どおり「実際に validation を失敗させてから session を見る」形にした):
  - `EnterpriseSsoLoginTest`「validation の失敗でも code / state が old input に残らない」
    (`code` と `error` の同時 / `state` 欠落 / `code` 欠落 の 3 データセット)
  - `EnterpriseSsoLoginTest`「validation の失敗も他の失敗と同じ応答である」
  - `EmailPromotionTest`「validation の失敗でもトークンが old input に残らない」

---

## [Warning] 未知 `kid` の JWKS 再取得が原子的でない (接続単位のロックが無い)

- **判断**: 対応する
- **根拠**: **指摘が正しい**。設計 B3 は「接続 id 単位のロックを取り、同時要求でも再取得が 1 回になる」
  「ロック基盤の障害時はその試行を拒否する」と明記していたのに、実装が
  `get` → 判定 → `put` だけで済ませていた (**設計の実装漏れ**)。
- **対応内容**: `Cache::lock('enterprise-sso:jwks-refetch:{connectionId}', 15)` を取り、
  **最小間隔の判定をロックの中へ移した**。取れなければ**待たずに拒否**する
  (待つと未知 kid の連打で worker が占有される)。ロック基盤の例外も拒否へ倒す (fail-closed)。
  ロックの寿命 (15 秒) は外向きの時間予算 (接続 3 + 要求 5) より長くしてある
  (取得中に失効すると 2 人目が取り始めて抑止が成立しない)。
  ★受け手を**型宣言された引数**にするため `underRefetchLock(Lock $lock, Closure $callback)` に切り出した
  — 局所変数のままだと G2 の走査器が「受け手の型が解決できない呼び出し」として落とす
  (実際に一度赤くなって気付いた = 走査器が意図どおり効いている)。
- **回帰テスト**: `OidcDiscoveryServiceTest` に 3 本
  (「他者がロックを保持していると拒否され、**外向きの取得を 1 件も行わない**」
   「解放されれば再取得できる (正のコントロール)」「接続が違えば互いを止めない」)。

---

## [Warning] 競合時に token の削除も rollback され、one-time consume が成立しない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。同一トランザクション内で blind index の一意制約違反を例外にすると
  削除まで巻き戻り、同じトークンを期限まで送り直せた。
  さらに pgsql は SQL エラーでトランザクション全体が aborted になるので、
  「捕まえて続きをやる」も同じトランザクションの中では**そもそも動かない**。
- **対応内容**: **消費と適用を 2 段に分けた**。
  第 1 段でトークンの検査と行の削除を確定させ (commit)、第 2 段で `users.email` を適用する。
  適用は**自分の savepoint の中**で書く — 裸で書くと衝突が呼び出し元のトランザクションまで
  巻き込む (テストレーンでは `RefreshDatabase` の外側トランザクションが aborted になる)。
  帰結として衝突したトークンは**消費済みのまま失効する**。これは
  「露出しても 1 回しか効かない」という本機構の狙いと同じ向きなので受け入れる。
- **回帰テスト**: `EmailPromotionTest`「衝突してもトークンは消費済みで、同じトークンを再利用できない」
  (行が 0 件であること + 2 回目が拒否されること + 昇格が起きていないことの 3 点)。

---

## [Warning] メールを既に持つ利用者にも昇格を許しており、既存のメール変更経路を迂回できる

- **判断**: 対応する
- **根拠**: **指摘が正しい**。機能の名前 (「昇格」) が示す対象は「メールを持たない利用者」であり、
  既にある人に開くと**監査と旧アドレスへの通知を持たない第 2 のメール変更経路**になる。
- **対応内容**: `issue()` と `confirm()` の**両方**で、行ロックの下で `email === null` を要求する。
  `issue()` は `bool` を返すようにし、controller が false を「押下時のエラー表示」へ変える
  (ボタンを disabled にしない = 禁止事項 8)。
  ロック付きの読み直しは**インスタンス起点** (`$user->newQuery()->whereKey(...)`) にした
  — 対象が payload 由来の id ではなく常に `Auth::id()` であることを経路の形で示すためである。
- **回帰テスト**: `EmailPromotionTest` に 2 本
  (「既にメールを持つ利用者は発行できない」「発行後に別経路でメールが入ったら確定できない」)。

---

## [Warning] 成功したメール変更の security audit が実装されていない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。設計 E1 の「監査: 変更を記録する (既存の監査基盤へ載せる)」の実装漏れ。
- **対応内容**: 確定時に `SecurityEventRecorder::record(SecurityEventType::EmailChanged, $user, ['source' => 'email_promotion'])`
  を記録する。**トークンも平文のメールも載せない** (利用者と固定の事象種別、および経路の識別だけ)。
- **回帰テスト**: `EmailPromotionTest`「確定を監査に残す (トークンも平文のメールも載せない)」。

---

## [Warning] 生の確認 token が暗号化されない queue payload に保存される

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`ShouldQueue` の Mailable は job payload として直列化されるので、
  private property でも `jobs` 表に平文で残る。暗号化されるのは `ShouldBeEncrypted` を
  実装したものだけである。キューを読める主体が利用者として確定を完了できてしまう。
- **対応内容**: `EmailPromotionMail` に `ShouldBeEncrypted` を実装し、
  **なぜ併記が必須か**を docblock に書いた。
- **回帰テスト**: `EmailPromotionTest`「確認メールはキュー payload を暗号化する」
  (`is_subclass_of(..., ShouldBeEncrypted::class)` を固定)。

---

## [Warning] `key_ops` を部分文字列で判定しているため、検証用途でない鍵が受理される

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`["notverify"]` が `str_contains(..., 'verify')` を通っていた。
  RFC 7517 §4.3 の `key_ops` は大文字小文字を区別する文字列配列で、検証用途は完全一致の `verify` である。
- **対応内容**:
  - `key_ops` は畳んだ後も**トークンの完全一致**で判定する (`in_array('verify', explode(' ', …), true)`)。
    区切り文字を含む用途は**拒否**する (畳んだ後の一致が偽陽性になりうるため)。
  - あわせて「**存在する既知の項目は具体型が違えば拒否**」を足した
    (`{"use": ["sig"]}` が「optional なので欠落可」として素通りしていた — これも指摘どおり)。
- **回帰テスト**: `OidcDiscoveryServiceTest` に 3 本
  (負例: 接頭辞つき / 接尾辞つき / 大文字 / 別用途 の 4 データセット。
   正例: 単独 / 併記 / 重複 の 3 データセット。
   型違反: `use` が配列 / `alg` が数値 / `kty` が配列 / `key_ops` が文字列 / 要素が数値 の 5 データセット)。

---

## [Warning] G2-4 は file 単位の部分文字列検査なので、安全でない `fetch()` を見逃す

- **判断**: 対応する
- **根拠**: **指摘が正しい**。同じファイルに安全な呼び出しが 1 つあれば緑になっていた。
- **対応内容**: 走査器へ `callsMissingNamedArgument()` を足し、**呼び出しごとに**
  括弧の対応を取って `followRedirects:` の有無を見る形にした。
  括弧の対応が取れない形は**違反として返す** ((b) fail-closed)。宣言は呼び出しに数えない。
- **回帰テスト** (指摘どおり「一つは安全・もう一つは既定値」の見本を置いた):
  - 見本 `tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt`
  - 走査器の自己検査に 2 本
    (「同じファイルに安全な呼び出しがあっても既定値の呼び出しを見逃さない」= 1 件だけ落ちる /
     「宣言そのものは呼び出しとみなさない」)。

---

## [Suggestion] メール昇格の発行・再送を操作するフォームが UI に無い

- **判断**: 対応する
- **根拠**: **指摘が正しく、設計の抜けでもある**。設計 E1 は
  「TypeScript 型定義: なし — Svelte のページを 1 枚も足さない」と書いていたが、
  それは**確認画面**の話であって発行の導線ではない。導線が無いと
  メールを持たない利用者は HTTP を手組みしないと機能を開始できず、**行き先のない詰み**になる。
- **対応内容**: `SecurityController` が `canPromoteEmail` (メールが null のときだけ true) を供給し、
  `Settings/Security.svelte` に**メールを持たない利用者だけ**へ出す登録フォームを足した。
  既存の `guardWithRecentAuth` に乗せる (発行は step-up 必須のため)。
  未入力でもボタンを押せる (押下時にエラー表示 = 禁止事項 8)。
- **回帰テスト**: `EmailPromotionTest` に 2 本
  (「メールを持たない利用者の設定画面に導線が出る」「メールを持つ利用者には出ない」)。

---

## 「実装側の適応」への判定について

#1〜#9 は APPROVE。#5 は「APPROVE (限定)」で、
**「B4/C1/C2 全体の実プロセス競合を証明した」とは扱わない**という条件が付いた。
これはテストの docblock が既にそう書いてあり (証明する範囲と証明しない範囲を分けて明記)、
報告でも同じ切り分けを維持する。#10 は本マトリクスの `ShouldBeEncrypted` で解消した。

## Codex がレビューできなかった範囲

振る舞いテスト 29 ファイルは本文を渡しておらず、ツール制限でローカル読み出しもできなかったため
逐行判定されていない。Round 2 では**指摘に対応した箇所のテスト本文**を添えて再確認を求める。

---

## 検証コマンドの結果 (対応後・全 green)

- `composer test`: **7267 tests / 7265 passed / 0 failed** (skipped 2 / risky 5 は既存)
- `composer phpstan`: OK (level 10)
- `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test`: 全 pass / `pnpm test:packages`: 全 pass

---

## 対応した実装の差分 (全文)

```diff
diff --git a/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php b/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php
new file mode 100644
index 00000000..8951377a
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php
@@ -0,0 +1,253 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use JsonException;
+
+/**
+ * IdP の公開鍵集合 (JWKS)。**必要な要素だけ**を具体型で持つ。
+ *
+ * ★`use` と `key_ops` は JWK 仕様で **optional** である。
+ *   **存在するときだけ**検査する — 欠落を理由に有効な鍵を拒否しない。
+ * ★`kid` の**重複は拒否**する。重複したまま「最初に見つかった鍵」で検証すると、
+ *   攻撃者が用意した鍵を先頭へ置くだけで検証を通せる形になりうる。
+ */
+final readonly class OidcJsonWebKeySet
+{
+    /**
+     * `key_ops` をキャッシュ可能な素のスカラーへ畳むときの区切り。
+     *
+     * ★用途の値そのものには現れない文字を選ぶ (RFC 7517 の用途は `sign` / `verify` 等の
+     *   空白を含まない語である)。畳んだあとも**トークンの完全一致**で判定する。
+     */
+    private const string KEY_OPS_SEPARATOR = ' ';
+
+    /**
+     * JWK のうち**文字列であることを要求する**項目。
+     *
+     * 存在するのに型が違う鍵は拒否する (欠落として捨てると malformed が素通りする)。
+     *
+     * @var list<string>
+     */
+    private const array TYPED_STRING_MEMBERS = ['kty', 'kid', 'alg', 'use', 'crv', 'n', 'e', 'x', 'y'];
+
+    /**
+     * @param  array<string, array<string, string>>  $keysByKeyId  kid => JWK の素の要素
+     */
+    private function __construct(public array $keysByKeyId) {}
+
+    /**
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public static function fromResponseBody(string $body): self
+    {
+        try {
+            /** @var mixed $decoded */
+            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
+        } catch (JsonException) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        if (! is_array($decoded)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        $keys = $decoded['keys'] ?? null;
+        if (! is_array($keys)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        /** @var array<string, array<string, string>> $byKeyId */
+        $byKeyId = [];
+        foreach ($keys as $key) {
+            if (! is_array($key)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+            }
+
+            $normalized = self::normalizeKey($key);
+            if ($normalized === null) {
+                // kid を持たない鍵は本アプリの検証に使えない (kid 必須)。集合から静かに落とす。
+                continue;
+            }
+
+            [$keyId, $members] = $normalized;
+
+            if (array_key_exists($keyId, $byKeyId)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksDuplicateKeyId);
+            }
+
+            $byKeyId[$keyId] = $members;
+        }
+
+        if ($byKeyId === []) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        return new self($byKeyId);
+    }
+
+    /**
+     * キャッシュから読み戻す (**素の配列から明示的に組み立て直して検査する**)。
+     *
+     * @param  array<array-key, mixed>  $payload
+     */
+    public static function fromCachePayload(array $payload): ?self
+    {
+        /** @var array<string, array<string, string>> $byKeyId */
+        $byKeyId = [];
+
+        foreach ($payload as $keyId => $members) {
+            if (! is_string($keyId) || $keyId === '' || ! is_array($members)) {
+                return null;
+            }
+
+            /** @var array<string, string> $normalized */
+            $normalized = [];
+            foreach ($members as $name => $value) {
+                if (! is_string($name) || ! is_string($value)) {
+                    return null;
+                }
+                $normalized[$name] = $value;
+            }
+
+            $byKeyId[$keyId] = $normalized;
+        }
+
+        if ($byKeyId === []) {
+            return null;
+        }
+
+        return new self($byKeyId);
+    }
+
+    /**
+     * キャッシュへ入れる形 (**素の配列と文字列だけ**)。
+     *
+     * @return array<string, array<string, string>>
+     */
+    public function toCachePayload(): array
+    {
+        return $this->keysByKeyId;
+    }
+
+    public function has(string $keyId): bool
+    {
+        return array_key_exists($keyId, $this->keysByKeyId);
+    }
+
+    /**
+     * `alg` と整合する鍵を 1 本返す。
+     *
+     * 拒否条件 (deny-by-default):
+     *  - `kid` に一致する鍵が無い
+     *  - `kty` が `alg` と不整合 / EC の `crv` が `alg` と不整合
+     *  - **`use` が存在するのに** `sig` でない
+     *  - **`key_ops` が存在するのに** `verify` を含まない
+     *
+     * @return array<string, string>
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public function keyFor(string $keyId, OidcSigningAlgorithm $algorithm): array
+    {
+        $key = $this->keysByKeyId[$keyId] ?? null;
+        if ($key === null) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksKeyNotFound);
+        }
+
+        if (($key['kty'] ?? null) !== $algorithm->keyType()) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        $curve = $algorithm->curve();
+        if ($curve !== null && ($key['crv'] ?? null) !== $curve) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        // ★optional。**在るときだけ**見る。
+        if (array_key_exists('use', $key) && $key['use'] !== 'sig') {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        // ★**トークンの完全一致**で判定する (部分文字列一致にしない —
+        //   `["notverify"]` が `verify` を含むものとして通ってしまう)。
+        //   RFC 7517 §4.3 の `key_ops` は大文字小文字を区別する文字列の配列であり、
+        //   検証用途は完全一致の `verify` である。
+        if (array_key_exists('key_ops', $key)
+            && ! in_array('verify', explode(self::KEY_OPS_SEPARATOR, $key['key_ops']), true)
+        ) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        return $key;
+    }
+
+    /**
+     * `key_ops` (文字列の配列) を素のスカラーへ畳む。
+     *
+     * ★配列でない / 要素が文字列でない / 区切り文字を含む用途は**拒否する**
+     *   (区切りを含む値を通すと、畳んだ後のトークン一致が偽陽性になりうる)。
+     */
+    private static function normalizeKeyOperations(mixed $value): string
+    {
+        if (! is_array($value)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        $operations = [];
+        foreach ($value as $operation) {
+            if (! is_string($operation) || str_contains($operation, self::KEY_OPS_SEPARATOR)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+            }
+            $operations[] = $operation;
+        }
+
+        return implode(self::KEY_OPS_SEPARATOR, $operations);
+    }
+
+    /**
+     * JWK の要素を「文字列だけの素の配列」へ落とす。
+     *
+     * @param  array<array-key, mixed>  $key
+     * @return array{0: string, 1: array<string, string>}|null
+     */
+    private static function normalizeKey(array $key): ?array
+    {
+        $keyId = $key['kid'] ?? null;
+        if (! is_string($keyId) || $keyId === '') {
+            return null;
+        }
+
+        /** @var array<string, string> $members */
+        $members = [];
+        foreach ($key as $name => $value) {
+            if (! is_string($name)) {
+                continue;
+            }
+
+            if ($name === 'key_ops') {
+                $members['key_ops'] = self::normalizeKeyOperations($value);
+
+                continue;
+            }
+
+            // ★**存在する既知の項目は具体型が違えば拒否する** (欠落として捨てない)。
+            //   捨てると `{"use": ["sig"]}` のような malformed な鍵が
+            //   「optional なので欠落してよい」として素通りする。
+            if (in_array($name, self::TYPED_STRING_MEMBERS, true) && ! is_string($value)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+            }
+
+            if (is_string($value)) {
+                $members[$name] = $value;
+            }
+        }
+
+        return [$keyId, $members];
+    }
+}
diff --git a/app/Http/Controllers/Auth/EmailPromotionController.php b/app/Http/Controllers/Auth/EmailPromotionController.php
new file mode 100644
index 00000000..8a58c4a8
--- /dev/null
+++ b/app/Http/Controllers/Auth/EmailPromotionController.php
@@ -0,0 +1,117 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Auth;
+
+use App\Exceptions\Auth\EmailPromotionConflictException;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Auth\ConfirmEmailPromotionRequest;
+use App\Http\Requests\Auth\StoreEmailPromotionRequest;
+use App\Models\User;
+use App\Services\Auth\EmailPromotionService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Http\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
+ *
+ * ★**認可は「自分の資源」である**。Gate を通さず `Auth::id()` (= `$request->user()`) だけを使う
+ *   (`ControllerAuthorizationGateTest` の exemption へ理由付きで登録する)。
+ * ★確認は **GET の画面 + POST の確定**に割る。署名付き GET のリンクだけだと、
+ *   メールクライアントの先読みやプレビューで**利用者が意図せず確定してしまう**。
+ * ★確認画面は **standalone Blade** である (`Inertia::render` を呼ばない)。
+ *   Inertia は page object を `history.state` へ載せるため、prop へ置いた瞬間に
+ *   **トークンがブラウザの履歴に残る**。
+ * ★失敗しても `withInput()` を使わない (トークンを old input に残さない)。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * リバースプロキシや CDN のアクセスログ、ブラウザの履歴、利用者が URL を他人へ貼ることに
+ * よる露出は防げない。緩和は **60 分の期限**と **一回だけの consume** であり、
+ * 露出しても**使われる窓が短く、1 回しか効かない**ことに寄せている。
+ */
+class EmailPromotionController extends Controller
+{
+    /** 確定・失敗のどちらでも同じ行き先 (存在を漏らさない)。 */
+    private const string SETTINGS_ROUTE = ConfirmEmailPromotionRequest::FAILURE_ROUTE;
+
+    public function __construct(private readonly EmailPromotionService $promotions) {}
+
+    /** 発行 (確認メールを送る)。 */
+    public function store(StoreEmailPromotionRequest $request): RedirectResponse
+    {
+        return $this->issue($request, '確認メールを送信しました。メール内のリンクから登録を完了してください。');
+    }
+
+    /** 再送 (**発行と同じ入口**。旧トークンは失効する)。 */
+    public function resend(StoreEmailPromotionRequest $request): RedirectResponse
+    {
+        return $this->issue($request, '確認メールを再送しました。');
+    }
+
+    /**
+     * 発行・再送の共通の入口。
+     *
+     * ★**既にメールを持つ利用者は対象外**である (押下時にエラーを表示する = 禁止事項 8)。
+     *   既存のメール変更経路 (監査 + 旧アドレスへの通知つき) を迂回させない。
+     */
+    private function issue(StoreEmailPromotionRequest $request, string $success): RedirectResponse
+    {
+        if (! $this->promotions->issue($this->currentUser($request), $request->emailValue())) {
+            return back()->withErrors([
+                ConfirmEmailPromotionRequest::ERROR_KEY => 'この操作はメールアドレスをまだ登録していない場合にのみ使えます。'
+                    .'変更はプロフィール設定から行ってください。',
+            ]);
+        }
+
+        return back()->with('success', $success);
+    }
+
+    /**
+     * 確認画面 (GET)。
+     *
+     * ★**状態を変えない**。トークンを画面へ渡し、利用者が明示のボタンで POST する。
+     * ★**トークンの有効・無効で画面を変えない** (一様。存在の探り当てを作らない)。
+     */
+    public function showConfirm(Request $request): Response
+    {
+        return response()->view('auth.email-promotion.confirm', [
+            'token' => $request->string('token')->value(),
+        ]);
+    }
+
+    /** 確定 (POST のみ)。 */
+    public function confirm(ConfirmEmailPromotionRequest $request): RedirectResponse
+    {
+        try {
+            $confirmed = $this->promotions->confirm($this->currentUser($request), $request->tokenValue());
+        } catch (EmailPromotionConflictException) {
+            // ★衝突の応答は**一様**である (既存利用者の存在を漏らさない)。
+            //   既存利用者は一切変更せず・併合せず・昇格も行わない。
+            return redirect()->route(self::SETTINGS_ROUTE)->withErrors([
+                ConfirmEmailPromotionRequest::ERROR_KEY => ConfirmEmailPromotionRequest::FAILURE_MESSAGE,
+            ]);
+        }
+
+        if (! $confirmed) {
+            // ★validation の失敗と**同じ**応答である (どちらで落ちたかを外から区別できない)。
+            return redirect()->route(self::SETTINGS_ROUTE)->withErrors([
+                ConfirmEmailPromotionRequest::ERROR_KEY => ConfirmEmailPromotionRequest::FAILURE_MESSAGE,
+            ]);
+        }
+
+        return redirect()->route(self::SETTINGS_ROUTE)
+            ->with('success', 'メールアドレスを登録しました。');
+    }
+
+    private function currentUser(Request $request): User
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        return $user;
+    }
+}
diff --git a/app/Http/Controllers/Auth/EnterpriseSsoLoginController.php b/app/Http/Controllers/Auth/EnterpriseSsoLoginController.php
new file mode 100644
index 00000000..23edb616
--- /dev/null
+++ b/app/Http/Controllers/Auth/EnterpriseSsoLoginController.php
@@ -0,0 +1,161 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Auth;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Auth\EnterpriseSsoCallbackRequest;
+use App\Models\OrganizationOidcConnection;
+use App\Services\EnterpriseSso\EnterpriseCallbackAuthenticator;
+use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
+use App\Services\EnterpriseSso\OidcDiscoveryService;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use App\Support\EnterpriseSso\UniformLoginFailure;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Auth;
+use Inertia\Inertia;
+use Inertia\Response as InertiaResponse;
+
+/**
+ * 企業 IdP との OIDC SSO のログイン導線。
+ *
+ * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点で `Auth::login()` で
+ *   ログインを確定させ、画面へ送る。**2 要素認証の入力画面へ転送する分岐を持たない**。
+ *   これは tests/Architecture/SsoTwoFactorInterpositionGateTest が企業・ソーシャルの
+ *   両 controller に対して静的に裏当てし、主たる証明は
+ *   tests/Feature/Auth/EnterpriseSsoLoginTest の実挙動側にある。
+ *   組織の 2 要素義務づけの強制は別関門 (`RequireTwoFactorForEnforcedOrganizations`) が
+ *   **ログイン確定後**にアカウント全体のゲートとして担い、転送先は 2 要素の**設定ページ**である。
+ *
+ * ★`remember: false` である。remember cookie を許すと、接続を無効化した後も
+ *   cookie から新しいセッションを開始できてしまい、
+ *   「次回ログインができなくなる」という効果の主張と整合しない。
+ *
+ * ★失敗の応答は**一様**である (接続や利用者の存在を読み取れない)。
+ *   組み立てるのは {@see UniformLoginFailure} の 1 か所だけで、
+ *   **FormRequest の validation 失敗も同じ応答を通る** (入力を 1 つも flash しない)。
+ *   理由の区別が出るのは**内部のログの理由コードだけ**である。
+ */
+class EnterpriseSsoLoginController extends Controller
+{
+    /**
+     * 企業ログインの入口 (識別名の入力画面)。
+     *
+     * ★外向き通信をしない。DB も変えない。
+     */
+    public function show(): InertiaResponse
+    {
+        return Inertia::render('Auth/EnterpriseLogin');
+    }
+
+    /**
+     * 開始。**行を作ってからリダイレクトする** (逆順だと戻ってきた state が存在しない)。
+     *
+     *  1. 接続を識別名で解決し、**Active であること**を確かめる
+     *  2. CSPRNG で state / nonce / PKCE の検証子 / ブラウザ結合の秘密を各 32 バイト生成する
+     *  3. ブラウザ結合の秘密を**セッションへ置く** (キーは state の指紋ごとに分ける = 複数タブ共存)
+     *  4. 試行の行を作る (state / nonce / 結合の指紋 + 暗号化した検証子 + 期限)
+     *  5. 認可要求の URL を組み立ててリダイレクトする
+     *
+     * ★GET だが **DB に試行の行を作る変更操作**である (OAuth の開始)。
+     *   CSRF トークンの代わりに state・ブラウザ結合・流量制限・no-store が守る。
+     */
+    public function redirect(
+        Request $request,
+        OrganizationOidcConnection $connection,
+        EnterpriseLoginAttemptStore $attempts,
+        OidcDiscoveryService $discovery,
+    ): RedirectResponse {
+        // ★接続の解決と「使えるか」の判定は PublicOidcConnectionBinder が済ませている。
+        //   **不在の識別名と使えない接続は binder の段で同じ 404 に畳まれ**、
+        //   route の missing() が利用者向けの一様な案内へ変える (実在オラクルを作らない)。
+        //   したがって**無効な接続で行が作られることはない**。
+
+        try {
+            $metadata = $discovery->fetchMetadata(OidcIssuerUrl::fromString($connection->issuer));
+        } catch (EnterpriseSsoAttemptRejectedException $e) {
+            return UniformLoginFailure::response($e->reason);
+        }
+
+        $state = AttemptFingerprint::newSecret();
+        $nonce = AttemptFingerprint::newSecret();
+        $codeVerifier = AttemptFingerprint::newSecret();
+        $bindingSecret = AttemptFingerprint::newSecret();
+
+        // ★セッションのキーは state の指紋ごとに分ける (複数タブが共存できる)。
+        $request->session()->put(
+            EnterpriseCallbackAuthenticator::bindingSessionKey(
+                AttemptFingerprint::of(FingerprintPurpose::State, $state),
+            ),
+            $bindingSecret,
+        );
+
+        // ★**行を作ってからリダイレクトする**。
+        $attempts->start($connection, $state, $nonce, $codeVerifier, $bindingSecret);
+
+        return redirect()->away($this->authorizationUrl($metadata->authorizationEndpoint, [
+            'response_type' => 'code',
+            'scope' => 'openid email profile',
+            'client_id' => $connection->client_id,
+            'redirect_uri' => route('enterprise-sso.callback'),
+            'state' => $state,
+            'nonce' => $nonce,
+            'code_challenge' => $this->codeChallenge($codeVerifier),
+            'code_challenge_method' => 'S256',
+        ]));
+    }
+
+    /**
+     * 戻り口。
+     *
+     * ★ここで `redirect()->intended()` を使うのは**ログイン直後フロー**だからである
+     *   (禁止事項 7 の明示的な適用範囲内。既存の `SocialAuthController` と同じ形)。
+     */
+    public function callback(
+        EnterpriseSsoCallbackRequest $request,
+        EnterpriseCallbackAuthenticator $authenticator,
+    ): RedirectResponse {
+        if ($request->providerReturnedError()) {
+            return UniformLoginFailure::response(RejectionReason::ProviderReturnedError);
+        }
+
+        try {
+            $user = $authenticator->authenticate(
+                $request->session(),
+                $request->stateValue(),
+                $request->codeValue(),
+                route('enterprise-sso.callback'),
+            );
+        } catch (EnterpriseSsoAttemptRejectedException $e) {
+            return UniformLoginFailure::response($e->reason);
+        }
+
+        Auth::login($user, remember: false);
+        $request->session()->regenerate();
+
+        return redirect()->intended(route('app.entry'));
+    }
+
+    /**
+     * @param  array<string, string>  $parameters
+     */
+    private function authorizationUrl(string $endpoint, array $parameters): string
+    {
+        // ★endpoint は query を持ちうる (discovery で禁じていない)。既存の query を保つ。
+        $separator = str_contains($endpoint, '?') ? '&' : '?';
+
+        return $endpoint.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
+    }
+
+    /** PKCE (S256) の challenge。 */
+    private function codeChallenge(string $codeVerifier): string
+    {
+        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
+    }
+}
diff --git a/app/Http/Controllers/Settings/SecurityController.php b/app/Http/Controllers/Settings/SecurityController.php
index 85f9e964..54c6796e 100644
--- a/app/Http/Controllers/Settings/SecurityController.php
+++ b/app/Http/Controllers/Settings/SecurityController.php
@@ -20,6 +20,10 @@
  * 2FA / ソーシャル連携 / パスキーの管理面。route closure から抽出したのは
  * passkey 一覧の組み立てで DI (PasskeyLoginPolicy) が必要になり、
  * closure に積み増すと「Controller は薄く」の作法から外れるため。
+ *
+ * ★メールアドレスの昇格 (T253 / E1) の導線もここが供給する。
+ *   **メールを持たない利用者だけ**に出す — 既にある人の変更は
+ *   監査と旧アドレスへの通知を持つプロフィール更新の経路が担う。
  */
 final class SecurityController extends Controller
 {
@@ -40,6 +44,10 @@ public function __invoke(Request $request): InertiaResponse
             // TOTP 有効ユーザーには「ログインには使えないが再認証には使える」旨を出すための判別子。
             // 判定は PasskeyLoginPolicy に集約 (login 認可 / inventory と同一条件)。
             'passkeyLoginAvailable' => $isUser && $this->passkeyLoginPolicy->allowsPasskeyLogin($user),
+            // ★メールアドレスの昇格 (T253 / E1) の導線を出すかどうか。
+            //   企業 SSO でしか入れない利用者は使えるメールを 1 件も持たないので、
+            //   **メールが無いときだけ**この面を出す (既にある人は既存の変更経路を使う)。
+            'canPromoteEmail' => $isUser && $user->email === null,
         ]);
     }
 
diff --git a/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php b/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php
new file mode 100644
index 00000000..00e09d1d
--- /dev/null
+++ b/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php
@@ -0,0 +1,82 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Auth;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Illuminate\Contracts\Validation\Validator;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\ValidationException;
+
+/**
+ * メールアドレスの昇格の確定。
+ *
+ * ★確定は **POST だけ**である (GET の確認画面は状態を変えない)。
+ *   署名付き GET のリンクだけだと、メールクライアントの先読みやプレビューで
+ *   **利用者が意図せず確定してしまう**。
+ * ★**validation の失敗でも入力を 1 つも flash しない**。
+ *   Laravel は validation の失敗時に、controller へ到達する**前に**入力を `_old_input` へ
+ *   退避するので、「controller で `withInput()` を呼ばない」だけでは
+ *   **トークンがセッションに残る**。`failedValidation()` で応答を自分で組み立てて塞ぐ。
+ * ★`token` は一般名なのでグローバルの `dontFlash` へは足さず、**経路側で閉じる**。
+ */
+class ConfirmEmailPromotionRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    /** 失敗の行き先 (照合の失敗と同じ)。 */
+    public const string FAILURE_ROUTE = 'settings.security';
+
+    /** 失敗のエラーキー (照合の失敗と同じ)。 */
+    public const string ERROR_KEY = 'email_promotion';
+
+    /** 失敗の文言 (照合の失敗と**同じ**。区別を外から読み取れないようにする)。 */
+    public const string FAILURE_MESSAGE = 'この確認リンクは無効か、有効期限が切れています。'
+        .'もう一度手続きをやり直してください。';
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * ★**入力を 1 つも flash しない**失敗へ変換する (トークンをセッションに残さない)。
+     *
+     * 行き先と文言は「無効なトークン」と**同じ**である
+     * (validation で落ちたか照合で落ちたかを外から区別できない = 存在を漏らさない)。
+     */
+    protected function failedValidation(Validator $validator): void
+    {
+        throw new ValidationException($validator, redirect()->route(self::FAILURE_ROUTE)->withErrors([
+            self::ERROR_KEY => self::FAILURE_MESSAGE,
+        ]));
+    }
+
+    /**
+     * @return array<string, list<string>>
+     */
+    public function rules(): array
+    {
+        // 長さの上限は指紋の元になる一時値の実長 (base64url 43 文字) に十分な余裕を持たせる。
+        return [
+            'token' => ['required', 'string', 'max:'.(AttemptFingerprint::HEX_LENGTH * 4)],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'token' => '確認トークン',
+        ];
+    }
+
+    public function tokenValue(): string
+    {
+        return $this->string('token')->value();
+    }
+}
diff --git a/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php b/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php
new file mode 100644
index 00000000..9659c1d8
--- /dev/null
+++ b/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Auth;
+
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Support\EnterpriseSso\UniformLoginFailure;
+use Illuminate\Contracts\Validation\Validator;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\ValidationException;
+
+/**
+ * 企業 SSO の戻り口の入力。
+ *
+ * ★**不正な入力では外向き取得を一切開始しない**。ここで弾く。
+ * ★`code` と `error` は**排他**である (両方来た応答は仕様外なので受けない)。
+ * ★**validation の失敗でも入力を 1 つも flash しない**。
+ *   Laravel は validation の失敗時に、controller へ到達する**前に**入力を `_old_input` へ
+ *   退避する (`Handler::invalid()` が `withInput()` を呼ぶ)。したがって
+ *   「controller で `withInput()` を呼ばない」だけでは `code` / `state` がセッションに残る。
+ *   `failedValidation()` で**応答を自分で組み立てて**この経路そのものを塞ぐ。
+ * ★`code` / `state` は一般名なのでグローバルの `dontFlash` へは足さない
+ *   — 他のフォームの入力復元まで黙って変えてしまうため (経路側で閉じる)。
+ */
+class EnterpriseSsoCallbackRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    /** 未認証で到達する経路である (ログインの戻り口)。認可は接続の状態が担う。 */
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * ★**入力を 1 つも flash しない**一様な失敗へ変換する。
+     *
+     * 既定の実装は `_old_input` へ入力を退避してから戻すので、
+     * `code` (認可コード) と `state` がセッションに残る。
+     * `ValidationException` に応答を持たせると `Handler` は既定の組み立てを行わない。
+     */
+    protected function failedValidation(Validator $validator): void
+    {
+        throw new ValidationException(
+            $validator,
+            UniformLoginFailure::response(RejectionReason::ProviderReturnedError),
+        );
+    }
+
+    /**
+     * @return array<string, list<string>>
+     */
+    public function rules(): array
+    {
+        return [
+            'state' => ['required', 'string', 'max:512'],
+            'code' => ['nullable', 'string', 'max:4096', 'required_without:error', 'prohibits:error'],
+            'error' => ['nullable', 'string', 'max:256'],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'state' => '状態値',
+            'code' => '認可コード',
+            'error' => 'エラー',
+        ];
+    }
+
+    /** IdP が error を返したか (一様な失敗として扱う)。 */
+    public function providerReturnedError(): bool
+    {
+        return $this->string('error')->isNotEmpty();
+    }
+
+    public function stateValue(): string
+    {
+        return $this->string('state')->value();
+    }
+
+    public function codeValue(): string
+    {
+        return $this->string('code')->value();
+    }
+}
diff --git a/app/Mail/EmailPromotionMail.php b/app/Mail/EmailPromotionMail.php
new file mode 100644
index 00000000..5cf26741
--- /dev/null
+++ b/app/Mail/EmailPromotionMail.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Mail;
+
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldBeEncrypted;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Mail\Mailable;
+use Illuminate\Mail\Mailables\Content;
+use Illuminate\Mail\Mailables\Envelope;
+use Illuminate\Queue\SerializesModels;
+use SensitiveParameter;
+
+/**
+ * メールアドレスの昇格の確認メール。
+ *
+ * ★既存の送信の作法にそろえて**キューへ載せる** (`ShouldQueue`)。
+ *   投入は昇格の行を作るのと**同一トランザクションの中**で行う (AGENTS.md ドメイン規約 11。
+ *   `afterCommit` に依存しない = 行が巻き戻ればメールも投入されない)。
+ * ★**`ShouldBeEncrypted` を必ず併記する**。キューに載る Mailable は job payload として
+ *   直列化されるので、private property であっても**確認トークンと宛先が平文で `jobs` 表に残る**。
+ *   キューを読める主体がいれば、その者が利用者として確認を完了できてしまう。
+ *   Laravel が payload を暗号化するのは `ShouldBeEncrypted` を実装したものだけである。
+ * ★本文に載せるのは**確認画面の URL だけ**である。宛先のメールも利用者の名前も載せない
+ *   (万一 victim のアドレスが入力されても、攻撃者が任意の文面を送れない形にする)。
+ * ★トークンは `#[SensitiveParameter]` で受ける (スタックトレースに出さない)。
+ */
+class EmailPromotionMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
+{
+    use Queueable;
+    use SerializesModels;
+
+    public function __construct(#[SensitiveParameter] private readonly string $token) {}
+
+    public function envelope(): Envelope
+    {
+        return new Envelope(
+            subject: 'メールアドレスの確認',
+        );
+    }
+
+    public function content(): Content
+    {
+        return new Content(
+            text: 'emails.auth.email-promotion',
+            with: [
+                // ★確認画面 (GET) の URL。**状態を変えない画面**であり、確定は明示の POST である。
+                'confirmUrl' => route('settings.email-promotion.confirm.show', ['token' => $this->token]),
+            ],
+        );
+    }
+}
diff --git a/app/Services/Auth/EmailPromotionService.php b/app/Services/Auth/EmailPromotionService.php
new file mode 100644
index 00000000..e64ec29d
--- /dev/null
+++ b/app/Services/Auth/EmailPromotionService.php
@@ -0,0 +1,240 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth;
+
+use App\Actions\Fortify\UpdateUserProfileInformation;
+use App\DataTransferObjects\Auth\VerifiedEmail;
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Enums\SecurityEventType;
+use App\Exceptions\Auth\EmailPromotionConflictException;
+use App\Mail\EmailPromotionMail;
+use App\Models\EmailPromotion;
+use App\Models\User;
+use App\Services\Security\SecurityEventRecorder;
+use App\Support\EmailNormalizer;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use App\Support\Organization\OrganizationSlugConstraintViolation;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\Config;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Mail;
+use SensitiveParameter;
+
+/**
+ * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
+ *
+ * ## なぜ EnterpriseSso ではなく Auth の名前空間に置くか
+ *
+ * 正典 (laravel-claude-template) の設計判断をそのまま引き継ぐ。
+ * 「メールでの引き当てを禁じる設計検査の走査範囲へ入れないための意図的な配置」である。
+ *
+ * ★**これは検査の回避ではない**。昇格フローも**メールで利用者を引かない** —
+ *   引き当ての鍵は常に `Auth::id()` (自分自身) であり、メール文字列は
+ *   「その利用者に紐づける値」としてしか現れない。走査から外すのは、
+ *   **メール文字列を正当に扱う唯一の場所**を禁止語の走査へ巻き込まないためであって、
+ *   引き当ての禁止を緩めるためではない。この主張は
+ *   tests/Architecture/EmailPromotionIdentityGateTest (G5) が
+ *   「メールから利用者を引く記法を持たない」「既存アカウントとの併合をしない」の
+ *   2 点で固定する。
+ *
+ * ## 適用できる相手 (機能の名前に立ち返る)
+ *
+ * ★**メールを 1 件も持たない利用者だけ**が対象である。
+ *   既にメールを持つ利用者へ開くと、監査と旧アドレスへの通知を持つ既存のメール変更経路
+ *   ({@see UpdateUserProfileInformation}) を**迂回する第 2 の変更経路**に
+ *   なってしまう。発行と確定の**両方**で、行ロックの下で `email === null` を要求する。
+ *
+ * ## トークンの一生
+ *
+ * | 項目 | 形 |
+ * |---|---|
+ * | トークン | **原文を保存せず指紋のみ** (用途ラベル `EmailPromotionToken`) |
+ * | 結合 | `user_id` を持ち、確認時に**認証済みの利用者と一致**すること |
+ * | 期限 | `expires_at` (`config('enterprise-sso.email_promotion.ttl_seconds')`) |
+ * | 一回使用 | **消費 (行の削除) を先に commit する**。下記「なぜ 2 段に分けるか」を参照 |
+ * | 再送 | 新しいトークンを発行したら**旧トークンを失効させる** (発行時の削除 + `user_id` の一意制約) |
+ *
+ * ## なぜ消費と適用を 2 段に分けるか
+ *
+ * 適用 (`users.email` の更新) は**メールの blind index の一意制約違反**になりうる。
+ * 同じトランザクションの中で例外にすると**行の削除まで巻き戻り**、
+ * 同じトークンを期限まで何度でも送れる (= 一回使用が成立しない)。
+ * さらに pgsql は一度 SQL エラーが出るとトランザクション全体が aborted になるため、
+ * 「捕まえて続きをやる」も同じトランザクションの中では**そもそも動かない**。
+ *
+ * したがって **第 1 段で消費を確定させ (commit)、第 2 段で適用する**。
+ * 帰結として、衝突したトークンは**消費済みのまま失効する** (利用者はやり直す)。
+ * これは「露出しても 1 回しか効かない」という本機構の狙いと同じ向きである。
+ */
+final readonly class EmailPromotionService
+{
+    /** メールの blind index の partial unique index 名 (`add_unique_to_blind_indexes_table` が正本)。 */
+    private const string EMAIL_BLIND_INDEX_CONSTRAINT = 'blind_indexes_type_name_value_unique';
+
+    /** PostgreSQL の unique_violation。 */
+    private const string SQLSTATE_UNIQUE_VIOLATION = '23505';
+
+    public function __construct(private SecurityEventRecorder $recorder) {}
+
+    /**
+     * 昇格を始める (確認メールを送る)。
+     *
+     * ★**再送も同じ入口**である。発行のたびに自分の古い行を消すので、旧トークンは失効する。
+     *
+     * @return bool true = 発行した / false = 対象外 (既にメールを持っている)
+     */
+    public function issue(User $user, string $email): bool
+    {
+        $normalized = EmailNormalizer::normalize($email);
+        $token = AttemptFingerprint::newSecret();
+
+        return DB::transaction(function () use ($user, $normalized, $token): bool {
+            // ★行ロックの下で「メールを持たないこと」を確かめる (発行と確定の両方で見る)。
+            //   ★**認証済みの自分自身のインスタンス起点**で引く (`$user->newQuery()`)。
+            //     クラス起点の主キー同一性クエリで書かない — 対象は payload 由来の id ではなく
+            //     常に `Auth::id()` であり、経路そのものを型と起点で固定する。
+            $locked = $this->lockedSelf($user);
+            if (! $locked instanceof User || $locked->email !== null) {
+                return false;
+            }
+
+            // ★自分の未消費の行を消してから作る (利用者ごとに 1 件しか持てない)。
+            $user->emailPromotions()->delete();
+
+            $promotion = new EmailPromotion;
+            $promotion->forceFill([
+                'user_id' => $user->id,
+                'token_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token),
+                'email_encrypted' => $normalized,
+                'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.email_promotion.ttl_seconds')),
+            ])->save();
+
+            // ★**同一トランザクションの中で**キューへ投入する (AGENTS.md ドメイン規約 11)。
+            //   `afterCommit` に依存しない — 行が巻き戻ればメールも投入されない。
+            Mail::to($normalized)->send(new EmailPromotionMail($token));
+
+            return true;
+        });
+    }
+
+    /**
+     * 確認トークンを消費して昇格を確定する。
+     *
+     * ★**確定してよいのは認証済みの本人だけ**である (`user_id` の結合を必ず照合する)。
+     * ★確定では `email_verified_at` を**新しいメールを確認した時刻へ更新する**
+     *   (「以前の値のまま」にしない = timestamp の意味を保つ)。
+     *
+     * @return bool true = 確定した / false = トークンが無効・期限切れ・他人のもの・対象外
+     *
+     * @throws EmailPromotionConflictException 確認済みメールが既存利用者のものと重なった
+     *                                         (★トークンは**消費済み**である)
+     */
+    public function confirm(User $user, #[SensitiveParameter] string $token): bool
+    {
+        $fingerprint = AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token);
+
+        // ── 第 1 段: 消費を**確定させる** (ここで commit される)
+        $email = DB::transaction(function () use ($user, $fingerprint): ?string {
+            // ★行ロックの下で「メールを持たないこと」を再確認する (発行後に別経路で入った場合を弾く)。
+            $locked = $this->lockedSelf($user);
+            if (! $locked instanceof User || $locked->email !== null) {
+                return null;
+            }
+
+            // ★relation 起点で引く (自分の行だけを見る = 他人のトークンでは何も起きない)。
+            $promotion = $user->emailPromotions()
+                ->where('token_fingerprint', $fingerprint)
+                ->lockForUpdate()
+                ->first();
+
+            if ($promotion === null || $promotion->expires_at->isPast()) {
+                return null;
+            }
+
+            $email = $promotion->email_encrypted;
+            if (! is_string($email) || $email === '') {
+                return null;
+            }
+
+            $promotion->delete();
+
+            return $email;
+        });
+
+        if ($email === null) {
+            return false;
+        }
+
+        // ── 第 2 段: 適用 (衝突しても第 1 段の消費は巻き戻らない)
+        $this->applyVerifiedEmail($user, VerifiedEmail::afterConfirmation($email));
+
+        return true;
+    }
+
+    /**
+     * 認証済みの自分自身の行を**ロックして**読み直す。
+     *
+     * ★**インスタンス起点**である (`$user->newQuery()`)。クラス起点の主キー同一性クエリで
+     *   書かないのは、対象が payload 由来の id ではなく常に `Auth::id()` であることを
+     *   経路の形そのもので示すためである (AGENTS.md セキュリティ不変条件 3)。
+     */
+    private function lockedSelf(User $user): ?User
+    {
+        $locked = $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->first();
+
+        return $locked instanceof User ? $locked : null;
+    }
+
+    /**
+     * ★`users.email` を書く**唯一の経路**である (昇格の側)。
+     *
+     * @throws EmailPromotionConflictException
+     */
+    private function applyVerifiedEmail(User $user, VerifiedEmail $email): void
+    {
+        try {
+            // ★**自分のトランザクション (savepoint) の中で**書く。
+            //   pgsql は SQL エラーでトランザクション全体を aborted にするので、裸で書くと
+            //   衝突が**呼び出し元のトランザクションまで巻き込む** (第 1 段の消費が使えなくなる)。
+            //   savepoint の中なら巻き戻るのはこの 1 文だけである。
+            DB::transaction(function () use ($user, $email): void {
+                $user->forceFill([
+                    'email' => $email->value,
+                    // ★**新しいメールを実際に確認した時刻**へ更新する (以前の値のままにしない)。
+                    'email_verified_at' => now(),
+                ])->save();
+            });
+        } catch (QueryException $e) {
+            // ★変換してよいのは**メールの blind index の一意制約違反だけ**である。
+            //   それ以外の一意制約違反と DB の障害は握り潰さず伝播させる。
+            if ($this->isEmailBlindIndexConflict($e)) {
+                throw new EmailPromotionConflictException('email is already taken by another user');
+            }
+
+            throw $e;
+        }
+
+        // ★監査に残すのは**利用者と固定の事象種別だけ**である
+        //   (トークンも平文のメールも載せない。既存の変更経路と同じ event type を使う)。
+        $this->recorder->record(SecurityEventType::EmailChanged, $user, ['source' => 'email_promotion']);
+    }
+
+    /**
+     * メールの blind index の一意制約違反か。
+     *
+     * ★**制約名まで見る** (SQLSTATE だけで判定しない)。他の一意制約違反まで一様な応答へ
+     *   畳むと、壊れていることが「よくある競合」として隠れる。
+     * ★**保証範囲**: PostgreSQL の制約名が例外メッセージに現れることに依存する
+     *   (本アプリは PostgreSQL 固定。準拠実装 {@see OrganizationSlugConstraintViolation})。
+     */
+    private function isEmailBlindIndexConflict(QueryException $e): bool
+    {
+        if (($e->errorInfo[0] ?? null) !== self::SQLSTATE_UNIQUE_VIOLATION) {
+            return false;
+        }
+
+        return str_contains($e->getMessage(), self::EMAIL_BLIND_INDEX_CONSTRAINT);
+    }
+}
diff --git a/app/Services/EnterpriseSso/OidcDiscoveryService.php b/app/Services/EnterpriseSso/OidcDiscoveryService.php
new file mode 100644
index 00000000..b8714db9
--- /dev/null
+++ b/app/Services/EnterpriseSso/OidcDiscoveryService.php
@@ -0,0 +1,302 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\OidcJsonWebKeySet;
+use App\DataTransferObjects\EnterpriseSso\OidcProviderMetadata;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Closure;
+use Illuminate\Contracts\Cache\Lock;
+use Illuminate\Contracts\Cache\Repository as CacheRepository;
+use Illuminate\Support\Facades\Cache;
+use Illuminate\Support\Facades\Config;
+use Kent013\SsrfPin\Dtos\Deadline;
+use Kent013\SsrfPin\Dtos\PinnedFailure;
+use Kent013\SsrfPin\Dtos\PinnedRequest;
+use Kent013\SsrfPin\PinnedHttpClient;
+use Throwable;
+
+/**
+ * 接続先情報 (OIDC Discovery) と公開鍵 (JWKS) の取得。
+ *
+ * ★**外向きは `PinnedHttpClient` だけである**。`Http` ファサード・`HttpFactory` を
+ *   本サービス (および `App\Services\EnterpriseSso` 配下) へ注入しない。
+ *   検査 → 名前解決 → 接続が同じ経路を通るので、検査と接続の間の TOCTOU
+ *   (DNS rebinding) を自分から作り直さない。
+ *   境界の正本は `config/ssrf-pin.php` であり、本機能はそれを変更しない
+ *   (AGENTS.md セキュリティ不変条件 8)。
+ *
+ * ## 防御
+ *
+ *  1. **pin 済み経路** — 検査・名前解決・接続が同じ経路
+ *  2. **リダイレクトを追従しない** (`followRedirects: false`) — 転送先が未検査のまま
+ *     取得されるのを防ぐ。**2xx 以外は一様に拒否する** (3xx を成功として扱わない)
+ *  3. **issuer の完全一致** — 文書の issuer が登録済み issuer と一致すること
+ *  4. **endpoint は https の絶対 URL・userinfo なし・fragment なし** —
+ *     ★同一 origin は**要求しない** (OIDC 標準の要件ではなく、実在の IdP を拒否する)。
+ *     ★**query は禁じない** (禁じる標準上の根拠が無い)
+ *  5. **応答サイズ上限** — 期待と違う応答を DTO に固定しない。
+ *     ★`PinnedRequest` は要求ごとの上限を受け取らない (^0.4) ので、
+ *     transport の上限 (`config/ssrf-pin.php`) の**内側**でアプリが測って拒否する
+ *
+ * ## キャッシュ (セキュリティ不変条件 11)
+ *
+ * 入れるのは**素の配列とスカラーだけ**である。読み戻しは DTO へ明示的に組み立て直して
+ * 検査し、**破損 / 空配列 / 未知の値**のいずれでも `forget` して miss 扱いにする。
+ */
+final readonly class OidcDiscoveryService
+{
+    private const string METADATA_CACHE_PREFIX = 'enterprise-sso:metadata:';
+
+    private const string JWKS_CACHE_PREFIX = 'enterprise-sso:jwks:';
+
+    private const string JWKS_REFETCHED_AT_CACHE_PREFIX = 'enterprise-sso:jwks-refetched-at:';
+
+    /** 再取得の同時実行を抑える接続単位のロック。 */
+    private const string JWKS_REFETCH_LOCK_PREFIX = 'enterprise-sso:jwks-refetch:';
+
+    /**
+     * ロックの寿命 (秒)。
+     *
+     * ★外向き取得の時間予算 (接続 3 + 要求 5) より長くする — 取得中にロックが失効すると
+     *   2 人目が取り始めてしまい、抑止そのものが成立しない。
+     */
+    private const int JWKS_REFETCH_LOCK_SECONDS = 15;
+
+    public function __construct(
+        private PinnedHttpClient $pinned,
+        private CacheRepository $cache,
+    ) {}
+
+    /**
+     * 接続先情報の取得と検証。
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public function fetchMetadata(OidcIssuerUrl $issuer): OidcProviderMetadata
+    {
+        $cached = $this->cachedMetadata($issuer);
+        if ($cached !== null) {
+            return $cached;   // アーリーリターン
+        }
+
+        $body = $this->fetchPinned(
+            $issuer->wellKnownUrl(),
+            Config::integer('enterprise-sso.discovery.max_body_bytes'),
+            RejectionReason::DiscoveryFetchFailed,
+            RejectionReason::DiscoveryBodyTooLarge,
+        );
+
+        $metadata = OidcProviderMetadata::fromResponseBody($body, expectedIssuer: $issuer);
+
+        $this->cache->put(
+            self::METADATA_CACHE_PREFIX.$issuer->cacheDigest(),
+            $metadata->toCachePayload(),
+            Config::integer('enterprise-sso.discovery.cache_ttl_seconds'),
+        );
+
+        return $metadata;
+    }
+
+    /**
+     * 公開鍵集合の取得。
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public function fetchJwks(OidcProviderMetadata $metadata): OidcJsonWebKeySet
+    {
+        $cached = $this->cachedJwks($metadata);
+        if ($cached !== null) {
+            return $cached;   // アーリーリターン
+        }
+
+        return $this->fetchAndCacheJwks($metadata);
+    }
+
+    /**
+     * 未知の `kid` での鍵の再取得。
+     *
+     *  - **接続 id 単位のロック**を取り、同時要求でも再取得が 1 回になる
+     *  - 最終再取得時刻を**スカラー**でキャッシュに持ち、最小間隔の内側では再取得しない
+     *    (未知 kid を連打されたときの増幅を防ぐ)
+     *  - **ロック基盤の障害時はその試行を拒否する** (再取得を無制限に許さない)
+     *  - 再取得は **1 回だけ**である (呼び出し側が再帰しない)
+     *
+     * @throws EnterpriseSsoAttemptRejectedException 最小間隔の内側 (= 再取得しない)
+     */
+    public function refetchJwks(OidcProviderMetadata $metadata, int $connectionId): OidcJsonWebKeySet
+    {
+        $minimumInterval = Config::integer('enterprise-sso.discovery.jwks_refetch_min_interval_seconds');
+
+        // ★**接続 id 単位のロックを取る**。取らずに「読んで → 判定して → 書く」だけだと、
+        //   同じ接続へ未知 kid の callback が同時に来たとき**両方が古い時刻を読んで両方が再取得する**
+        //   (署名不正のトークンを並行投入するだけで IdP への外向き取得を増幅できる)。
+        return $this->underRefetchLock(
+            Cache::lock(self::JWKS_REFETCH_LOCK_PREFIX.$connectionId, self::JWKS_REFETCH_LOCK_SECONDS),
+            function () use ($metadata, $connectionId, $minimumInterval): OidcJsonWebKeySet {
+                $stampKey = self::JWKS_REFETCHED_AT_CACHE_PREFIX.$connectionId;
+
+                // ★最小間隔の判定は**ロックの中で**行う (外で読むと判定そのものが競合する)。
+                /** @var mixed $lastRefetchedAt */
+                $lastRefetchedAt = $this->cache->get($stampKey);
+                if (is_int($lastRefetchedAt) && (time() - $lastRefetchedAt) < $minimumInterval) {
+                    throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
+                }
+
+                $this->cache->put($stampKey, time(), $minimumInterval);
+                $this->cache->forget(self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest());
+
+                return $this->fetchAndCacheJwks($metadata);
+            },
+        );
+    }
+
+    /**
+     * ロックを取れたときだけ `$callback` を走らせる。
+     *
+     * ★**待たない**。待つと未知 kid の連打が worker を占有する。
+     * ★**ロック基盤の障害はその試行を拒否する** (再取得を無制限に許さない = fail-closed)。
+     * ★受け手を**型宣言された引数**にしてあるのは、G2 の走査器が
+     *   「受け手の型が解決できない保護対象語彙の呼び出し」を落とすためである
+     *   (局所変数のままだと未解決として赤くなる = 見逃さない側の設計である)。
+     *
+     * @param  Closure(): OidcJsonWebKeySet  $callback
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function underRefetchLock(Lock $lock, Closure $callback): OidcJsonWebKeySet
+    {
+        try {
+            $acquired = $lock->get();
+        } catch (Throwable) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
+        }
+
+        if ($acquired !== true) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
+        }
+
+        try {
+            return $callback();
+        } finally {
+            $lock->release();
+        }
+    }
+
+    private function fetchAndCacheJwks(OidcProviderMetadata $metadata): OidcJsonWebKeySet
+    {
+        $body = $this->fetchPinned(
+            $metadata->jwksUri,
+            Config::integer('enterprise-sso.discovery.max_body_bytes'),
+            RejectionReason::JwksFetchFailed,
+            RejectionReason::JwksMalformed,
+        );
+
+        $jwks = OidcJsonWebKeySet::fromResponseBody($body);
+
+        $this->cache->put(
+            self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest(),
+            $jwks->toCachePayload(),
+            Config::integer('enterprise-sso.discovery.cache_ttl_seconds'),
+        );
+
+        return $jwks;
+    }
+
+    private function cachedMetadata(OidcIssuerUrl $issuer): ?OidcProviderMetadata
+    {
+        $key = self::METADATA_CACHE_PREFIX.$issuer->cacheDigest();
+
+        /** @var mixed $payload */
+        $payload = $this->cache->get($key);
+        if ($payload === null) {
+            return null;
+        }
+
+        if (! is_array($payload)) {
+            $this->cache->forget($key);
+
+            return null;
+        }
+
+        $metadata = OidcProviderMetadata::fromCachePayload($payload);
+        if ($metadata === null || ! hash_equals($issuer->value, $metadata->issuer->value)) {
+            $this->cache->forget($key);
+
+            return null;
+        }
+
+        return $metadata;
+    }
+
+    private function cachedJwks(OidcProviderMetadata $metadata): ?OidcJsonWebKeySet
+    {
+        $key = self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest();
+
+        /** @var mixed $payload */
+        $payload = $this->cache->get($key);
+        if ($payload === null) {
+            return null;
+        }
+
+        if (! is_array($payload)) {
+            $this->cache->forget($key);
+
+            return null;
+        }
+
+        $jwks = OidcJsonWebKeySet::fromCachePayload($payload);
+        if ($jwks === null) {
+            $this->cache->forget($key);
+
+            return null;
+        }
+
+        return $jwks;
+    }
+
+    /**
+     * pin 済み経路での GET。**2xx かつ上限内の本文だけ**を返す。
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function fetchPinned(
+        string $url,
+        int $maxBodyBytes,
+        RejectionReason $failureReason,
+        RejectionReason $tooLargeReason,
+    ): string {
+        $request = new PinnedRequest(
+            method: 'GET',
+            url: $url,
+            headers: ['Accept' => 'application/json'],
+            connectTimeout: (float) Config::integer('enterprise-sso.discovery.connect_timeout_seconds'),
+        );
+
+        // ★fetch() は PinnedResponse|PinnedFailure を**値で**返す (catch では捕まらない)。
+        $result = $this->pinned->fetch(
+            $request,
+            Deadline::afterSeconds((float) Config::integer('enterprise-sso.discovery.request_timeout_seconds')),
+            followRedirects: false,
+        );
+
+        if ($result instanceof PinnedFailure) {
+            throw EnterpriseSsoAttemptRejectedException::of($failureReason);
+        }
+
+        // ★3xx を成功として扱わない (追従していないので本文は転送元のもの)。
+        if ($result->status < 200 || $result->status >= 300) {
+            throw EnterpriseSsoAttemptRejectedException::of($failureReason);
+        }
+
+        if (strlen($result->body) > $maxBodyBytes) {
+            throw EnterpriseSsoAttemptRejectedException::of($tooLargeReason);
+        }
+
+        return $result->body;
+    }
+}
diff --git a/app/Support/EnterpriseSso/UniformLoginFailure.php b/app/Support/EnterpriseSso/UniformLoginFailure.php
new file mode 100644
index 00000000..2b523d77
--- /dev/null
+++ b/app/Support/EnterpriseSso/UniformLoginFailure.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\RejectionReason;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Support\Facades\Log;
+
+/**
+ * 企業ログインの失敗の**唯一の応答**。
+ *
+ * ★理由によらず**同じ文言・同じ行き先・同じ入力の扱い**である。
+ *   1 か所に閉じ込めているのは、応答を組み立てる場所が 2 つあると
+ *   「片方だけ理由を漏らす」「片方だけ入力を flash する」形が生まれるからである。
+ *
+ * ★**入力を 1 つも flash しない**。Laravel は validation の失敗時に
+ *   `_old_input` へ入力を退避するので、**FormRequest の失敗もここを通す**
+ *   (controller で `withInput()` を呼ばないだけでは `code` / `state` がセッションに残る)。
+ *
+ * ★理由コードは**ログにだけ**出す (利用者に返す応答へ入れない)。
+ */
+final class UniformLoginFailure
+{
+    /** 失敗時に利用者へ見せる**唯一の**文言。 */
+    public const string MESSAGE = '企業アカウントでのログインを完了できませんでした。'
+        .'もう一度お試しいただくか、組織の管理者にお問い合わせください。';
+
+    /** 応答に載せるエラーキー。 */
+    public const string ERROR_KEY = 'enterprise_sso';
+
+    /** インスタンス化しない。 */
+    private function __construct() {}
+
+    public static function response(RejectionReason $reason): RedirectResponse
+    {
+        Log::info('enterprise-sso login rejected', ['reason' => $reason->value]);
+
+        // ★`withInput()` を呼ばない (入力を 1 つも残さない)。
+        return redirect()->route('login')->withErrors([self::ERROR_KEY => self::MESSAGE]);
+    }
+}
diff --git a/resources/js/pages/Settings/Security.svelte b/resources/js/pages/Settings/Security.svelte
index d698e4f7..aea26525 100644
--- a/resources/js/pages/Settings/Security.svelte
+++ b/resources/js/pages/Settings/Security.svelte
@@ -36,6 +36,8 @@
         passkeys?: PasskeyListItem[];
         /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
         passkeyLoginAvailable?: boolean;
+        /** メールアドレスの昇格の導線を出すか (メールを持たない利用者だけ true。T253) */
+        canPromoteEmail?: boolean;
     }
 
     let {
@@ -43,6 +45,7 @@
         linkedProviders = [],
         passkeys = [],
         passkeyLoginAvailable = false,
+        canPromoteEmail = false,
     }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
@@ -103,6 +106,21 @@
         });
     }
 
+    /* ---- メールアドレスの昇格 (T253 / E1) ---- */
+    // ★企業 SSO でしか入れない利用者が、自分で使えるメールを持つための救済導線。
+    //   ★未入力でもボタンを押せる (押下時にサーバがエラーを返す = 禁止事項 8)。
+    const emailPromotionForm = useForm({ email: "" });
+
+    function submitEmailPromotion(event: SubmitEvent): void {
+        event.preventDefault();
+        void guardWithRecentAuth(() => {
+            emailPromotionForm.post("/settings/email-promotion", {
+                preserveScroll: true,
+                onSuccess: () => emailPromotionForm.reset(),
+            });
+        });
+    }
+
     function resumePendingAction(): void {
         const action = pendingAction;
         pendingAction = null;
@@ -506,6 +524,54 @@
         </nav>
 
         <div class="mt-6 flex flex-col gap-10">
+            {#if canPromoteEmail}
+                <!-- ★メールを持たない利用者 (企業 SSO でしか入れない利用者) だけに出す救済導線。
+                     既にメールがある人の変更は、監査と旧アドレスへの通知を持つ
+                     プロフィール更新の経路が担う (T253 / E1) -->
+                <Card padding="lg">
+                    <h2 class="text-h3">メールアドレスの登録</h2>
+                    <p class="mt-1 text-caption text-text-secondary">
+                        いまは勤務先の ID プロバイダ経由でのみログインできます。
+                        メールアドレスを登録すると、パスワード再設定などの連絡を受け取れるようになります。
+                    </p>
+
+                    <form
+                        novalidate
+                        onsubmit={submitEmailPromotion}
+                        class="mt-4 flex flex-col gap-4"
+                    >
+                        <FormField
+                            label="メールアドレス"
+                            id="email-promotion-email"
+                            error={emailPromotionForm.errors.email ??
+                                emailPromotionForm.errors.email_promotion}
+                            help="入力したアドレスに確認メールを送ります。リンクを開いて確定するまで登録されません。"
+                        >
+                            {#snippet children({ id, describedBy, invalid })}
+                                <Input
+                                    {id}
+                                    type="email"
+                                    bind:value={emailPromotionForm.email}
+                                    error={invalid}
+                                    aria-describedby={describedBy}
+                                    autocomplete="email"
+                                    testId="email-promotion-email"
+                                />
+                            {/snippet}
+                        </FormField>
+                        <div class="flex justify-end">
+                            <Button
+                                type="submit"
+                                loading={emailPromotionForm.processing}
+                                testId="email-promotion-submit"
+                            >
+                                確認メールを送る
+                            </Button>
+                        </div>
+                    </form>
+                </Card>
+            {/if}
+
             <Card padding="lg">
                 <div class="flex items-center justify-between gap-4">
                     <h2 class="text-h3">2要素認証</h2>
diff --git a/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php b/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php
new file mode 100644
index 00000000..a03fef13
--- /dev/null
+++ b/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php
@@ -0,0 +1,84 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Http\Client\Factory as HttpFactory;
+use Illuminate\Support\Facades\Http;
+use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;
+
+/*
+ * G2: 企業 SSO の外向き取得は pin 済み経路だけを通る。
+ *
+ * ## 本 gate が主張する範囲 (これ以上を主張しない)
+ *
+ * 次の 3 つの積だけである:
+ *  1. 走査根の中に**既知の禁止型・ファサードの参照**が無い
+ *  2. 走査根の中に**動的な呼び出しの形**が無い
+ *  3. 走査根の中に**受け手の型が解決できない保護対象語彙の呼び出し**が無い
+ *
+ * ★**「外向きは PinnedHttpClient だけである」という主張の主証明は静的側に置かない。**
+ *   主証明は次の 2 本である:
+ *     - **DI の結線テスト** (`tests/Feature/EnterpriseSso/EnterpriseSsoHttpWiringTest.php`) —
+ *       企業 SSO の 3 サービスへ注入される HTTP の担い手が `PinnedHttpClient` だけであること
+ *     - **実挙動テスト** (`tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php` ほか) —
+ *       実装が pin 済み経路を実際に通ること (通らなければ偽 IdP に 1 件も要求が届かない)
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - 文字列で解決する container 経由 (`app('…')`) は見ない
+ * - vendor の内部から出る通信は見ない
+ * - 走査根の外 (controller / Job など) は母集団に入らない
+ *
+ * 走査器そのものの検出力は `tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php` が
+ * 負例と正例の**両方向**で固定する。
+ */
+
+/** 走査根 (存在しなければ fail-fast する)。 */
+function enterpriseSsoOutboundRoots(): array
+{
+    return ['app/Services/EnterpriseSso'];
+}
+
+/** 保護対象の語彙 (受け手の型を解決できないまま書けてはいけない呼び出し)。 */
+function enterpriseSsoProtectedVocabulary(): array
+{
+    return ['fetch', 'get', 'post', 'send', 'request', 'put', 'patch', 'delete', 'head'];
+}
+
+test('G2-1: 走査根に禁止型・ファサードの参照が無い (許可一覧を持たない)', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());
+
+    expect(EnterpriseSsoSourceScanner::forbiddenClassReferences($sources, [
+        Http::class,
+        HttpFactory::class,
+        'GuzzleHttp\Client',
+        'Symfony\Component\HttpClient\HttpClient',
+    ]))->toBe([], '企業 SSO の外向き取得は PinnedHttpClient だけを通ること');
+});
+
+test('G2-2: 走査根に動的な呼び出しの形が無い (未解決を無言で候補から外さない)', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());
+
+    expect(EnterpriseSsoSourceScanner::dynamicCallForms($sources))->toBe([]);
+});
+
+test('G2-3: 受け手の型が解決できない保護対象語彙の呼び出しが無い', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());
+
+    expect(EnterpriseSsoSourceScanner::unresolvedProtectedCalls($sources, enterpriseSsoProtectedVocabulary()))
+        ->toBe([]);
+});
+
+test('G2-4: すべての fetch() が追従を明示的に切っている (呼び出し単位で見る)', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());
+
+    // ★`fetch()` の第 3 引数は**既定が true** なので、明示的に false を渡していることを
+    //   **呼び出しごとに**要求する。ファイル単位の部分文字列一致だと、同じファイルへ
+    //   既定値の呼び出しを 1 行足すだけで見逃す。
+    expect(EnterpriseSsoSourceScanner::callsMissingNamedArgument($sources, 'fetch', 'followRedirects'))
+        ->toBe([], 'pin 済み経路の fetch() は followRedirects: false を明示すること');
+});
+
+test('G2-5: 走査が空振りしていない (母集団が空でない)', function (): void {
+    expect(EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots()))->not->toBe([]);
+});
diff --git a/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
new file mode 100644
index 00000000..6f0f6467
--- /dev/null
+++ b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use Kent013\SsrfPin\PinnedHttpClient;
+
+/**
+ * ★負例の見本。**同じファイルに安全な呼び出しと既定値の呼び出しが同居する**形を置く。
+ *   ファイル単位の部分文字列一致だと安全な方を見て緑になってしまう (それを赤にする)。
+ */
+final class RedirectFollowingSample
+{
+    public function __construct(private PinnedHttpClient $pinned) {}
+
+    public function safe(): void
+    {
+        $this->pinned->fetch($request, $deadline, followRedirects: false);
+    }
+
+    public function unsafe(): void
+    {
+        $this->pinned->fetch($request, $deadline);
+    }
+}
diff --git a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
new file mode 100644
index 00000000..70dd46ff
--- /dev/null
+++ b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
@@ -0,0 +1,526 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\EnterpriseSso;
+
+use RuntimeException;
+use Tests\Support\PhpReferenceScanner;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\ReferenceKind;
+use Tests\Support\ReferenceSite;
+
+/**
+ * 企業 SSO の 5 本の gate (G1〜G5) が共有する走査器。
+ *
+ * ## 走査対象
+ *
+ * 呼び出し側が渡した**走査根の配下の `*.php` 全数**である
+ * (根そのものが存在しなければ fail-fast する = 改名・移動で黙って空振りしない)。
+ *
+ * ## 名前の解決 (AGENTS.md 走査器共通規約 (a))
+ *
+ * クラス参照は `Tests\Support\PhpReferenceScanner` が解いた**完全修飾名**で突き合わせる
+ * (短名一致は別名つき取り込み 1 つで黙る)。本走査器は解決の実装を自分で持たない。
+ *
+ * ## 解決できない形の扱い ((b) fail-closed)
+ *
+ * 走査根が**自分たちが書く小さな領域**であることを使い、次の 2 つを**違反として返す**
+ * (未解決を無言で候補から外さない):
+ *
+ *  1. **動的な呼び出しの形** — `$obj->$name()` / `new $cls` / `$cls::method()` /
+ *     `call_user_func` 系。走査根の中でこれらを使う正当な理由が無いので、禁じても実装が困らない
+ *  2. **受け手の型が解決できない保護対象語彙の呼び出し** — 呼び出し側が
+ *     {@see self::unresolvedProtectedCalls()} へ語彙を渡す。動的構文でなくても
+ *     解決範囲の外に落ちうるため、そこも失敗させる
+ *
+ * ## 語彙一致 ((e))
+ *
+ * 語彙の一致は**トークンの完全一致**で判定する (素の部分文字列一致に頼らない)。
+ * 区切りは PHP の字句そのものであり、`hasSecretIn` のような**接頭辞・接尾辞つきの識別子**は
+ * 別のトークンなので一致しない。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - 文字列リテラルの中身に書かれたクラス名 (`app('App\\Services\\…')`) は見ない
+ * - **受け手の型は「構築子の promoted プロパティの型」からしか解決しない**。
+ *   局所変数・factory の戻り値・プロパティ以外の代入は解決しない
+ *   (だからこそ、それらは 2 の**違反**として返る = 見逃さない)
+ * - `app/` の外 (vendor が呼ぶ経路) は母集団に入らない
+ */
+final class EnterpriseSsoSourceScanner
+{
+    /** 動的呼び出しとみなす vendor / 標準の関数名 (可変 callable)。 */
+    private const array DYNAMIC_CALLABLE_FUNCTIONS = [
+        'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array',
+    ];
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /**
+     * 走査根の配下の PHP ファイル (相対パス => ソース)。
+     *
+     * @param  list<string>  $roots  リポジトリ相対の走査根
+     * @return array<string, string>
+     */
+    public static function sources(array $roots): array
+    {
+        $base = dirname(__DIR__, 3);
+
+        /** @var array<string, string> $sources */
+        $sources = [];
+        foreach ($roots as $root) {
+            $absolute = $base.'/'.$root;
+
+            // ★存在しない根は fail-fast (改名・移動で黙って空振りしない = (b) の 3 つ目)。
+            if (! is_dir($absolute) && ! is_file($absolute)) {
+                throw new RuntimeException("走査根が存在しません: {$root}");
+            }
+
+            if (is_file($absolute)) {
+                $sources[$root] = (string) file_get_contents($absolute);
+
+                continue;
+            }
+
+            foreach (PhpReferenceScanner::phpFiles($absolute, $root) as $relative => $source) {
+                $sources[$relative] = $source;
+            }
+        }
+
+        return $sources;
+    }
+
+    /**
+     * 指定した完全修飾名への参照 (取り込みも site も両方見る)。
+     *
+     * @param  array<string, string>  $sources
+     * @param  list<string>  $forbidden  完全修飾名
+     * @return list<string> 人が読める記述子
+     */
+    public static function forbiddenClassReferences(array $sources, array $forbidden): array
+    {
+        $lowered = array_map(strtolower(...), $forbidden);
+
+        $violations = [];
+        foreach ($sources as $path => $source) {
+            $result = PhpReferenceScanner::references($path, $source);
+
+            foreach ($result->imports as $fqcn) {
+                if (in_array(strtolower($fqcn), $lowered, true)) {
+                    $violations[] = "{$path}: {$fqcn} を取り込んでいる";
+                }
+            }
+
+            foreach ($result->sites as $site) {
+                if (self::siteReferences($site, $lowered)) {
+                    $violations[] = "{$path}:{$site->line}: {$site->name} を参照している";
+                }
+            }
+        }
+
+        return array_values(array_unique($violations));
+    }
+
+    /**
+     * 動的な呼び出しの形 ((b) fail-closed の 1 つ目)。
+     *
+     * @param  array<string, string>  $sources
+     * @return list<string>
+     */
+    public static function dynamicCallForms(array $sources): array
+    {
+        $violations = [];
+
+        foreach ($sources as $path => $source) {
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+
+            for ($i = 0; $i < $count; $i++) {
+                $text = $tokens[$i]['text'];
+                $next = $tokens[$i + 1]['text'] ?? '';
+
+                // `$obj->$name()` / `$obj::$name()` — 矢印 / 二重コロンの直後が変数で、**呼び出している**もの。
+                // ★`Foo::$property` (静的プロパティへの参照) は動的な**呼び出し**ではないので拾わない
+                //   (拾うと `JWT::$leeway = …` のような正当な代入まで違反になる)。
+                if (($text === '->' || $text === '?->' || $text === '::')
+                    && str_starts_with($next, '$')
+                    && ($tokens[$i + 2]['text'] ?? '') === '('
+                ) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: 動的なメンバー名";
+
+                    continue;
+                }
+
+                // `new $cls`
+                if ($tokens[$i]['id'] === T_NEW && str_starts_with($next, '$')) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: 可変クラス名の生成";
+
+                    continue;
+                }
+
+                // `call_user_func(...)` 系
+                if ($tokens[$i]['id'] === T_STRING
+                    && in_array(strtolower($text), self::DYNAMIC_CALLABLE_FUNCTIONS, true)
+                    && $next === '('
+                    && ! in_array($tokens[$i - 1]['text'] ?? '', ['->', '?->', '::'], true)
+                ) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: 可変 callable ({$text})";
+                }
+            }
+        }
+
+        return array_values(array_unique($violations));
+    }
+
+    /**
+     * **受け手の型が解決できない**保護対象語彙の呼び出し ((b) fail-closed の 2 つ目)。
+     *
+     * 受け手の型は「構築子の promoted プロパティの型」からだけ解決する。
+     * それ以外 (局所変数・factory の戻り値) は解決できないので**違反として返す**。
+     *
+     * @param  array<string, string>  $sources
+     * @param  list<string>  $vocabulary  保護対象のメソッド名 (小文字)
+     * @return list<string>
+     */
+    public static function unresolvedProtectedCalls(array $sources, array $vocabulary): array
+    {
+        $violations = [];
+
+        foreach ($sources as $path => $source) {
+            $properties = self::declaredPropertyTypes($source);
+            $variables = self::declaredParameterTypes($source);
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+
+            for ($i = 0; $i < $count; $i++) {
+                if ($tokens[$i]['id'] !== T_STRING || ($tokens[$i + 1]['text'] ?? '') !== '(') {
+                    continue;
+                }
+                if (! in_array(strtolower($tokens[$i]['text']), $vocabulary, true)) {
+                    continue;
+                }
+
+                $arrow = $tokens[$i - 1]['text'] ?? '';
+                if ($arrow !== '->' && $arrow !== '?->') {
+                    // 静的呼び出し / 素の関数呼び出しは受け手の型の話ではない
+                    continue;
+                }
+
+                // 解決済みとみなすのは 2 形だけである:
+                //   (1) `$this-><宣言された型のプロパティ>->method()`
+                //   (2) `$<宣言された型の引数>->method()`
+                // どちらも**型が静的に書かれている**受け手であり、字句だけで型が確定する。
+                $property = $tokens[$i - 2]['text'] ?? '';
+                $receiverArrow = $tokens[$i - 3]['text'] ?? '';
+                $receiver = $tokens[$i - 4]['text'] ?? '';
+
+                $viaProperty = $receiver === '$this'
+                    && ($receiverArrow === '->' || $receiverArrow === '?->')
+                    && array_key_exists($property, $properties);
+
+                $viaParameter = str_starts_with($property, '$')
+                    && array_key_exists(substr($property, 1), $variables);
+
+                if (! $viaProperty && ! $viaParameter) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: 受け手の型が解決できない {$tokens[$i]['text']}()";
+                }
+            }
+        }
+
+        return array_values(array_unique($violations));
+    }
+
+    /**
+     * 語彙のトークン完全一致 ((e))。
+     *
+     * @param  array<string, string>  $sources
+     * @param  list<string>  $vocabulary
+     * @return list<string>
+     */
+    public static function forbiddenTokens(array $sources, array $vocabulary): array
+    {
+        $lowered = array_map(strtolower(...), $vocabulary);
+
+        $violations = [];
+        foreach ($sources as $path => $source) {
+            foreach (PhpTokenScan::normalize($source) as $token) {
+                if ($token['id'] !== T_STRING) {
+                    continue;
+                }
+                if (in_array(strtolower($token['text']), $lowered, true)) {
+                    $violations[] = "{$path}:{$token['line']}: {$token['text']}";
+                }
+            }
+        }
+
+        return array_values(array_unique($violations));
+    }
+
+    /**
+     * 指定のメソッドを**呼んでいる**ファイル (呼び出し元の exact-fit の pin 用)。
+     *
+     * ★**宣言 (`function foo()`) は呼び出しではない**ので数えない
+     *   (数えると定義しているファイル自身が必ず呼び出し元として現れ、pin が意味を失う)。
+     *
+     * @param  array<string, string>  $sources
+     * @return list<string>
+     */
+    public static function filesCalling(array $sources, string $method): array
+    {
+        $lowered = strtolower($method);
+
+        $files = [];
+        foreach ($sources as $path => $source) {
+            $tokens = PhpTokenScan::normalize($source);
+
+            foreach ($tokens as $index => $token) {
+                if ($token['id'] !== T_STRING || strtolower($token['text']) !== $lowered) {
+                    continue;
+                }
+                if (($tokens[$index + 1]['text'] ?? '') !== '(') {
+                    continue;
+                }
+                // 宣言はスキップする
+                if (($tokens[$index - 1]['id'] ?? null) === T_FUNCTION) {
+                    continue;
+                }
+
+                $files[] = $path;
+
+                break;
+            }
+        }
+
+        return array_values(array_unique($files));
+    }
+
+    /**
+     * 指定のメソッドの**呼び出しごと**に、必要な名前付き引数が渡されているかを見る。
+     *
+     * ★ファイル単位の部分文字列一致にしない。「同じファイルに安全な呼び出しが 1 つあれば
+     *   緑になる」形だと、**同じファイルへ既定値の呼び出しを 1 行足すだけで見逃す**。
+     *
+     * @param  array<string, string>  $sources
+     * @return list<string> 引数が無い呼び出しの記述子
+     */
+    public static function callsMissingNamedArgument(array $sources, string $method, string $argument): array
+    {
+        $loweredMethod = strtolower($method);
+
+        $violations = [];
+        foreach ($sources as $path => $source) {
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+
+            for ($i = 0; $i < $count; $i++) {
+                if ($tokens[$i]['id'] !== T_STRING || strtolower($tokens[$i]['text']) !== $loweredMethod) {
+                    continue;
+                }
+                if (($tokens[$i + 1]['text'] ?? '') !== '(') {
+                    continue;
+                }
+                // 宣言は呼び出しではない
+                if (($tokens[$i - 1]['id'] ?? null) === T_FUNCTION) {
+                    continue;
+                }
+
+                $end = self::matchingParenthesis($tokens, $i + 1);
+                if ($end === null) {
+                    // 括弧の対応が取れない = 解決できない形なので**落とす** ((b) fail-closed)
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の引数を読み切れない";
+
+                    continue;
+                }
+
+                $found = false;
+                for ($k = $i + 2; $k < $end; $k++) {
+                    if ($tokens[$k]['id'] === T_STRING
+                        && $tokens[$k]['text'] === $argument
+                        && ($tokens[$k + 1]['text'] ?? '') === ':'
+                    ) {
+                        $found = true;
+
+                        break;
+                    }
+                }
+
+                if (! $found) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() に {$argument}: が無い";
+                }
+            }
+        }
+
+        return array_values(array_unique($violations));
+    }
+
+    /**
+     * `(` の位置から対応する `)` の位置を返す (見つからなければ null)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    private static function matchingParenthesis(array $tokens, int $open): ?int
+    {
+        $depth = 0;
+        $count = count($tokens);
+
+        for ($i = $open; $i < $count; $i++) {
+            if ($tokens[$i]['text'] === '(') {
+                $depth++;
+
+                continue;
+            }
+            if ($tokens[$i]['text'] === ')') {
+                $depth--;
+                if ($depth === 0) {
+                    return $i;
+                }
+            }
+        }
+
+        return null;
+    }
+
+    /**
+     * 型を宣言されたプロパティ (プロパティ名 => 型の短名)。
+     *
+     * 構築子の promoted プロパティと、通常のプロパティ宣言の両方を拾う。
+     *
+     * @return array<string, string>
+     */
+    private static function declaredPropertyTypes(string $source): array
+    {
+        $tokens = PhpTokenScan::normalize($source);
+        $count = count($tokens);
+
+        /** @var array<string, string> $properties */
+        $properties = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            // 変数の直前に型が並ぶ形 (`private readonly Foo $bar` / `private Foo $bar`)
+            if (! str_starts_with($tokens[$i]['text'], '$')) {
+                continue;
+            }
+
+            $type = null;
+            $sawModifier = false;
+            for ($k = $i - 1; $k >= 0 && $k >= $i - 5; $k--) {
+                $text = $tokens[$k]['text'];
+                $id = $tokens[$k]['id'];
+
+                if ($id === T_STRING && $type === null) {
+                    $type = $text;
+
+                    continue;
+                }
+                if (in_array($id, [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY], true)) {
+                    $sawModifier = true;
+
+                    break;
+                }
+                if ($text === '?' || $id === T_WHITESPACE) {
+                    continue;
+                }
+                break;
+            }
+
+            if ($sawModifier && $type !== null) {
+                $properties[substr($tokens[$i]['text'], 1)] = $type;
+            }
+        }
+
+        // `$this->pinned` のように**プロパティ名で引ける**表にする
+        return $properties;
+    }
+
+    /**
+     * 型を宣言された関数・メソッドの引数 (変数名 => 型の短名)。
+     *
+     * ★**ファイル全体で 1 つの表に畳む**。同名の引数が別のメソッドで別の型を持つ場合、
+     *   後の宣言が勝つ。これは「型が書かれているか」だけを見る用途なので問題にならない
+     *   (**どの型か**の判定には使っていない)。
+     * ★型を書いていない引数 (`function f($x)`) は表に載らないので、
+     *   その受け手の保護対象語彙の呼び出しは**未解決として落ちる**。
+     *
+     * @return array<string, string>
+     */
+    private static function declaredParameterTypes(string $source): array
+    {
+        $tokens = PhpTokenScan::normalize($source);
+        $count = count($tokens);
+
+        /** @var array<string, string> $variables */
+        $variables = [];
+
+        for ($i = 0; $i < $count; $i++) {
+            if ($tokens[$i]['id'] !== T_FUNCTION && $tokens[$i]['id'] !== T_FN) {
+                continue;
+            }
+
+            // 引数リストの括弧を探す
+            $open = null;
+            for ($k = $i + 1; $k < $count && $k <= $i + 4; $k++) {
+                if ($tokens[$k]['text'] === '(') {
+                    $open = $k;
+
+                    break;
+                }
+            }
+            if ($open === null) {
+                continue;
+            }
+
+            $depth = 0;
+            for ($k = $open; $k < $count; $k++) {
+                $text = $tokens[$k]['text'];
+                if ($text === '(') {
+                    $depth++;
+
+                    continue;
+                }
+                if ($text === ')') {
+                    $depth--;
+                    if ($depth === 0) {
+                        break;
+                    }
+
+                    continue;
+                }
+
+                if ($depth !== 1 || ! str_starts_with($text, '$')) {
+                    continue;
+                }
+
+                // 直前に型 (T_STRING) が並んでいれば「型が書かれている」とみなす
+                for ($t = $k - 1; $t >= $open && $t >= $k - 3; $t--) {
+                    if ($tokens[$t]['text'] === '?' || $tokens[$t]['text'] === '|') {
+                        continue;
+                    }
+                    if ($tokens[$t]['id'] === T_STRING || $tokens[$t]['id'] === T_ARRAY) {
+                        $variables[substr($text, 1)] = $tokens[$t]['text'];
+                    }
+                    break;
+                }
+            }
+        }
+
+        return $variables;
+    }
+
+    /**
+     * @param  list<string>  $lowered
+     */
+    private static function siteReferences(ReferenceSite $site, array $lowered): bool
+    {
+        if (in_array($site->kind, [ReferenceKind::NameReference, ReferenceKind::Construction], true)) {
+            return in_array(strtolower($site->name), $lowered, true);
+        }
+
+        if ($site->kind === ReferenceKind::StaticCall && $site->receiver->isResolved()) {
+            return in_array(strtolower($site->receiver->fqcn()), $lowered, true);
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
new file mode 100644
index 00000000..418b5c19
--- /dev/null
+++ b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
@@ -0,0 +1,133 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Facades\Http;
+use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;
+
+/*
+ * 走査器そのものの検出力の裏取り (AGENTS.md 走査器共通規約 (c) = 両方向)。
+ *
+ * ★見本は `tests/Architecture/fixtures/enterprise-sso/*.php.txt` に置く
+ *   (拡張子が `.php` でないので autoload も走査根への混入も起きない)。
+ */
+
+/** @return array<string, string> */
+function scannerFixture(string $name): array
+{
+    $path = dirname(__DIR__, 2).'/tests/Architecture/fixtures/enterprise-sso/'.$name.'.php.txt';
+    $path = str_replace('/tests/tests/', '/tests/', $path);
+
+    expect(is_file($path))->toBeTrue("見本が存在すること: {$path}");
+
+    return ["fixtures/{$name}.php" => (string) file_get_contents($path)];
+}
+
+test('動的な呼び出しの 3 形をすべて検出する (負例)', function (): void {
+    $violations = EnterpriseSsoSourceScanner::dynamicCallForms(scannerFixture('DynamicCallSample'));
+
+    expect($violations)->toHaveCount(3);
+    expect(implode("\n", $violations))->toContain('動的なメンバー名');
+    expect(implode("\n", $violations))->toContain('可変クラス名の生成');
+    expect(implode("\n", $violations))->toContain('可変 callable');
+});
+
+test('規定どおりの固定の呼び出しを動的とみなさない (正例)', function (): void {
+    expect(EnterpriseSsoSourceScanner::dynamicCallForms(scannerFixture('CleanSample')))->toBe([]);
+});
+
+test('受け手の型が解決できない保護対象語彙の呼び出しを検出する (負例)', function (): void {
+    $violations = EnterpriseSsoSourceScanner::unresolvedProtectedCalls(
+        scannerFixture('UnresolvedReceiverSample'),
+        ['fetch'],
+    );
+
+    // 解決できる 1 件は通し、解決できない 1 件だけを落とす
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('受け手の型が解決できない fetch()');
+});
+
+test('構築子の promoted プロパティ経由の呼び出しを誤検出しない (正例)', function (): void {
+    expect(EnterpriseSsoSourceScanner::unresolvedProtectedCalls(scannerFixture('CleanSample'), ['fetch']))
+        ->toBe([]);
+});
+
+test('禁止クラスの参照を完全修飾名で検出する (負例)', function (): void {
+    $violations = EnterpriseSsoSourceScanner::forbiddenClassReferences(
+        scannerFixture('ForbiddenHttpSample'),
+        [Http::class],
+    );
+
+    expect($violations)->not->toBe([]);
+});
+
+test('禁止クラスを参照しないファイルを誤検出しない (正例)', function (): void {
+    expect(EnterpriseSsoSourceScanner::forbiddenClassReferences(scannerFixture('CleanSample'), [Http::class]))
+        ->toBe([]);
+});
+
+test('語彙一致はトークン完全一致である (接頭辞・打ち消し・接尾辞の 3 形を拾わない)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->preReveal();
+                $this->notReveal();
+                $this->revealLater();
+            }
+        }
+        PHP];
+
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['reveal']))->toBe([]);
+});
+
+test('語彙一致は同名のトークンをちょうど拾う (負例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function run(): void
+            {
+                $this->reveal();
+            }
+        }
+        PHP];
+
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['reveal']))->toHaveCount(1);
+});
+
+test('存在しない走査根は fail-fast する (空振りを緑にしない)', function (): void {
+    expect(fn () => EnterpriseSsoSourceScanner::sources(['app/NotThere']))
+        ->toThrow(RuntimeException::class);
+});
+
+test('走査根から 1 件以上のファイルを列挙できる (母集団が空でない)', function (): void {
+    expect(EnterpriseSsoSourceScanner::sources(['app/Services/EnterpriseSso']))->not->toBe([]);
+});
+
+test('同じファイルに安全な呼び出しがあっても、既定値の呼び出しを見逃さない (負例)', function (): void {
+    $violations = EnterpriseSsoSourceScanner::callsMissingNamedArgument(
+        scannerFixture('RedirectFollowingSample'),
+        'fetch',
+        'followRedirects',
+    );
+
+    // 安全な 1 件は通し、既定値の 1 件だけを落とす
+    expect($violations)->toHaveCount(1);
+    expect($violations[0])->toContain('followRedirects: が無い');
+});
+
+test('宣言そのものは呼び出しとみなさない (正例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function fetch(): void {}
+        }
+        PHP];
+
+    expect(EnterpriseSsoSourceScanner::callsMissingNamedArgument($sources, 'fetch', 'followRedirects'))
+        ->toBe([]);
+});
```

## 追加した回帰テストの差分 (全文)

```diff
diff --git a/tests/Feature/Auth/EmailPromotionTest.php b/tests/Feature/Auth/EmailPromotionTest.php
new file mode 100644
index 00000000..7d6405f6
--- /dev/null
+++ b/tests/Feature/Auth/EmailPromotionTest.php
@@ -0,0 +1,441 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\SecurityEventType;
+use App\Mail\EmailPromotionMail;
+use App\Models\EmailPromotion;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use Illuminate\Contracts\Queue\ShouldBeEncrypted;
+use Illuminate\Database\QueryException;
+use Illuminate\Log\Events\MessageLogged;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Mail;
+
+/*
+ * メールアドレスの昇格 (E1)。
+ *
+ * ★昇格フローも**メールで利用者を引かない**。引き当ての鍵は常に自分自身であり、
+ *   メール文字列は「その利用者に紐づける値」としてしか現れない。
+ */
+
+function promotionUser(): User
+{
+    $user = User::factory()->create();
+    $user->forceFill(['email' => null, 'email_verified_at' => now()])->save();
+
+    return $user->fresh() ?? $user;
+}
+
+/** 発行して平文トークンを取り出す (メールの本文から)。 */
+function issuePromotion(User $user, string $email = 'new@corp.example'): string
+{
+    Mail::fake();
+
+    test()->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->post(route('settings.email-promotion.store'), ['email' => $email])
+        ->assertSessionHas('success');
+
+    $token = null;
+    // ★Mailable は ShouldQueue なのでキューへ載る (assertSent ではなく assertQueued)。
+    Mail::assertQueued(EmailPromotionMail::class, function (EmailPromotionMail $mail) use (&$token): bool {
+        $rendered = $mail->render();
+        preg_match('/token=([A-Za-z0-9_-]+)/', $rendered, $matches);
+        $token = $matches[1] ?? null;
+
+        return true;
+    });
+
+    expect($token)->toBeString();
+
+    return (string) $token;
+}
+
+test('トークンを踏むまで昇格しない', function (): void {
+    $user = promotionUser();
+    issuePromotion($user);
+
+    expect($user->fresh()?->email)->toBeNull();
+    expect(EmailPromotion::query()->count())->toBe(1);
+});
+
+test('確認画面 (GET) は状態を変えない', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    $this->actingAs($user)
+        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]))
+        ->assertSuccessful();
+
+    expect($user->fresh()?->email)->toBeNull();
+    expect(EmailPromotion::query()->count())->toBe(1);
+});
+
+test('確認画面は Inertia ではない (トークンが履歴へ載る経路が存在しない)', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    $response = $this->actingAs($user)
+        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]));
+
+    $response->assertHeaderMissing('X-Inertia');
+    expect($response->getContent())->not->toContain('data-page');
+});
+
+test('確認画面がトークンを hidden 項目として描き、no-referrer と no-store を持つ', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    $response = $this->actingAs($user)
+        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]));
+
+    $body = (string) $response->getContent();
+    expect($body)->toContain('name="token"');
+    expect($body)->toContain('type="hidden"');
+    expect($body)->toContain('<meta name="referrer" content="no-referrer">');
+    $response->assertHeader('Cache-Control', 'no-store, private');
+});
+
+test('確認画面が外部リソースを 1 つも読み込まない', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    $body = (string) $this->actingAs($user)
+        ->get(route('settings.email-promotion.confirm.show', ['token' => $token]))
+        ->getContent();
+
+    expect($body)->not->toContain('<link');
+    expect($body)->not->toContain('<script');
+    expect($body)->not->toContain('<img');
+
+    // ★本文に現れる絶対 URL は**自分自身の host だけ**である
+    //   (form の action は同一オリジンなので許される。外部 host が 1 つでもあれば Referer の経路ができる)。
+    preg_match_all('#https?://[^"\'\s>]+#', $body, $matches);
+    $ownHost = (string) parse_url((string) config('app.url'), PHP_URL_HOST);
+    foreach ($matches[0] as $url) {
+        expect(parse_url($url, PHP_URL_HOST))->toBe($ownHost);
+    }
+});
+
+test('確認画面はトークンの有効・無効で変わらない (存在の探り当てを作らない)', function (): void {
+    $user = promotionUser();
+    $valid = issuePromotion($user);
+
+    $withValid = $this->actingAs($user)
+        ->get(route('settings.email-promotion.confirm.show', ['token' => $valid]));
+    $withInvalid = $this->actingAs($user)
+        ->get(route('settings.email-promotion.confirm.show', ['token' => 'never-issued']));
+
+    expect($withValid->getStatusCode())->toBe($withInvalid->getStatusCode());
+    // トークンの値だけが違う (画面の構造は同じ)
+    expect(str_replace($valid, 'X', (string) $withValid->getContent()))
+        ->toBe(str_replace('never-issued', 'X', (string) $withInvalid->getContent()));
+});
+
+test('POST で確定すると email と email_verified_at が更新される', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+    $before = $user->fresh()?->email_verified_at;
+
+    $this->travelTo(now()->addMinute());
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertRedirect(route('settings.security'))
+        ->assertSessionHas('success');
+
+    $fresh = $user->fresh();
+    expect($fresh?->email)->toBe('new@corp.example');
+    // ★「以前の値のまま」にせず、新しいメールを確認した時刻へ更新する
+    expect($fresh?->email_verified_at?->greaterThan($before))->toBeTrue();
+    expect(EmailPromotion::query()->count())->toBe(0);
+});
+
+test('確定後はパスワード再設定が使えるようになる (昇格前は宛先が無い)', function (): void {
+    $user = promotionUser();
+    expect($user->routeNotificationFor('mail'))->toBeNull();
+
+    $token = issuePromotion($user);
+    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);
+
+    expect($user->fresh()?->routeNotificationFor('mail'))->toBe('new@corp.example');
+});
+
+test('同じトークンは 2 回使えない', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+});
+
+test('他人のトークンでは昇格しない (user_id の結合が認可そのものである)', function (): void {
+    $owner = promotionUser();
+    $token = issuePromotion($owner);
+    $attacker = promotionUser();
+
+    $this->actingAs($attacker)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+
+    expect($attacker->fresh()?->email)->toBeNull();
+    // ★他人の行を消してもいない
+    expect(EmailPromotion::query()->where('user_id', $owner->id)->count())->toBe(1);
+});
+
+test('期限切れのトークンは拒否される', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    EmailPromotion::query()->update(['expires_at' => now()->subMinute()]);
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+
+    expect($user->fresh()?->email)->toBeNull();
+});
+
+test('再送で旧トークンが失効する', function (): void {
+    $user = promotionUser();
+    $first = issuePromotion($user);
+
+    Mail::fake();
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->post(route('settings.email-promotion.resend'), ['email' => 'second@corp.example'])
+        ->assertSessionHas('success');
+
+    // 利用者ごとに未消費は 1 件だけ
+    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(1);
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $first])
+        ->assertSessionHasErrors('email_promotion');
+});
+
+test('確認済みメールが既存利用者と重なったとき、既存を一切変更せず一様に断る', function (): void {
+    $existing = User::factory()->create(['email' => 'taken@corp.example']);
+    $existingName = $existing->name;
+
+    $user = promotionUser();
+    $token = issuePromotion($user, 'taken@corp.example');
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertRedirect(route('settings.security'))
+        ->assertSessionHasErrors('email_promotion');
+
+    // ★既存利用者は 1 バイトも変わっていない (併合もしない)
+    $freshExisting = $existing->fresh();
+    expect($freshExisting?->email)->toBe('taken@corp.example');
+    expect($freshExisting?->name)->toBe($existingName);
+    // ★昇格も起きていない
+    expect($user->fresh()?->email)->toBeNull();
+});
+
+test('衝突の応答が「無効なトークン」と見分けられない (存在を漏らさない)', function (): void {
+    User::factory()->create(['email' => 'taken@corp.example']);
+    $user = promotionUser();
+    $conflicting = issuePromotion($user, 'taken@corp.example');
+
+    $conflict = $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $conflicting]);
+    $invalid = $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => 'never-issued']);
+
+    expect($conflict->getStatusCode())->toBe($invalid->getStatusCode());
+    expect($conflict->headers->get('Location'))->toBe($invalid->headers->get('Location'));
+});
+
+test('blind index 以外の一意制約違反は握り潰さない (負のコントロール)', function (): void {
+    $user = promotionUser();
+    issuePromotion($user);
+
+    // 同じ利用者の 2 件目を直接作ると `email_promotions_user_unique` に当たる。
+    // ★これは blind index の違反ではないので、一様な応答へ畳まず**そのまま伝播する**。
+    expect(fn () => DB::table('email_promotions')->insert([
+        'user_id' => $user->id,
+        'token_fingerprint' => str_repeat('a', 64),
+        'email_encrypted' => 'x',
+        'expires_at' => now()->addHour(),
+        'created_at' => now(),
+        'updated_at' => now(),
+    ]))->toThrow(QueryException::class);
+});
+
+test('トークンは原文で保存されない (指紋だけ)', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    /** @var object{token_fingerprint: string} $raw */
+    $raw = DB::table('email_promotions')->where('user_id', $user->id)->first();
+
+    expect($raw->token_fingerprint)->not->toContain($token);
+    expect(json_encode((array) $raw, JSON_THROW_ON_ERROR))->not->toContain($token);
+});
+
+test('ログにトークンが出ない', function (): void {
+    $records = [];
+    Log::listen(function (MessageLogged $event) use (&$records): void {
+        $records[] = $event->message.json_encode($event->context);
+    });
+
+    $user = promotionUser();
+    $token = issuePromotion($user);
+    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);
+
+    expect(implode("\n", $records))->not->toContain($token);
+});
+
+test('確定に失敗してもトークンが old input に残らない', function (): void {
+    $user = promotionUser();
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => 'never-issued']);
+
+    /** @var array<string, mixed> $old */
+    $old = session()->get('_old_input', []);
+    expect($old)->not->toHaveKey('token');
+});
+
+test('validation の失敗でもトークンが old input に残らない', function (array $payload): void {
+    $user = promotionUser();
+
+    // ★Laravel は validation の失敗時、controller へ到達する**前に**入力を退避する。
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), $payload)
+        ->assertRedirect(route('settings.security'));
+
+    /** @var array<string, mixed> $old */
+    $old = session()->get('_old_input', []);
+    expect($old)->not->toHaveKey('token');
+    expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain('super-secret-token');
+})->with([
+    'トークンが長すぎる' => [['token' => 'super-secret-token']],
+    'トークンが無い' => [[]],
+]);
+
+test('衝突してもトークンは消費済みで、同じトークンを再利用できない', function (): void {
+    User::factory()->create(['email' => 'taken@corp.example']);
+    $user = promotionUser();
+    $token = issuePromotion($user, 'taken@corp.example');
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+
+    // ★消費 (行の削除) は commit 済みである (同じトランザクションで巻き戻さない)
+    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
+
+    // ★同じトークンの 2 回目は無効である
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+
+    expect($user->fresh()?->email)->toBeNull();
+});
+
+test('既にメールを持つ利用者は発行できない (既存の変更経路を迂回させない)', function (): void {
+    Mail::fake();
+    $user = User::factory()->create(['email' => 'existing@corp.example']);
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->post(route('settings.email-promotion.store'), ['email' => 'new@corp.example'])
+        ->assertSessionHasErrors('email_promotion');
+
+    Mail::assertNothingQueued();
+    expect(EmailPromotion::query()->count())->toBe(0);
+    expect($user->fresh()?->email)->toBe('existing@corp.example');
+});
+
+test('発行後に別経路でメールが入ったら確定できない', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    // 別経路でメールが入る
+    $user->forceFill(['email' => 'other@corp.example'])->save();
+
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+
+    expect($user->fresh()?->email)->toBe('other@corp.example');
+});
+
+test('確定を監査に残す (トークンも平文のメールも載せない)', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    $this->actingAs($user)->post(route('settings.email-promotion.confirm'), ['token' => $token]);
+
+    $event = SecurityAuditEvent::query()
+        ->where('user_id', $user->id)
+        ->where('event_type', SecurityEventType::EmailChanged->value)
+        ->firstOrFail();
+
+    $encoded = json_encode($event->getAttributes(), JSON_THROW_ON_ERROR);
+    expect($encoded)->not->toContain($token);
+    expect($encoded)->not->toContain('new@corp.example');
+});
+
+test('確認メールはキュー payload を暗号化する (jobs 表からトークンを読めない)', function (): void {
+    // ★private property でも job payload には直列化される。ShouldBeEncrypted が無いと
+    //   キューを読める主体がトークンと宛先を取り出して利用者として確定できてしまう。
+    expect(is_subclass_of(EmailPromotionMail::class, ShouldBeEncrypted::class))->toBeTrue();
+});
+
+test('4 route とも未認証では到達できない', function (string $method, string $name): void {
+    $this->call($method, route($name))->assertRedirect(route('login'));
+})->with([
+    ['POST', 'settings.email-promotion.store'],
+    ['POST', 'settings.email-promotion.resend'],
+    ['GET', 'settings.email-promotion.confirm.show'],
+    ['POST', 'settings.email-promotion.confirm'],
+]);
+
+test('発行と再送は再認証なしで弾かれる', function (string $name): void {
+    $user = promotionUser();
+
+    $this->actingAs($user)
+        ->post(route($name), ['email' => 'new@corp.example'])
+        ->assertRedirect(route('recent-auth.confirm'));
+})->with([
+    'settings.email-promotion.store',
+    'settings.email-promotion.resend',
+]);
+
+test('確認には再認証を課さない (救済経路に関門を足すと詰む)', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    // ★recent-auth のセッションを持たないまま確定できる
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHas('success');
+
+    expect($user->fresh()?->email)->toBe('new@corp.example');
+});
+
+test('メールを持たない利用者の設定画面に登録の導線が出る', function (): void {
+    $user = promotionUser();
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertSuccessful()
+        ->assertInertia(fn ($page) => $page->where('canPromoteEmail', true));
+});
+
+test('メールを持つ利用者には導線が出ない (既存の変更経路を使う)', function (): void {
+    $user = User::factory()->create(['email' => 'existing@corp.example']);
+
+    $this->actingAs($user)->get(route('settings.security'))
+        ->assertSuccessful()
+        ->assertInertia(fn ($page) => $page->where('canPromoteEmail', false));
+});
diff --git a/tests/Feature/Auth/EnterpriseSsoLoginTest.php b/tests/Feature/Auth/EnterpriseSsoLoginTest.php
new file mode 100644
index 00000000..6d3cbe7f
--- /dev/null
+++ b/tests/Feature/Auth/EnterpriseSsoLoginTest.php
@@ -0,0 +1,332 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Models\EnterpriseSsoLoginAttempt;
+use App\Models\Organization;
+use App\Models\OrganizationOidcConnection;
+use App\Models\User;
+use App\Services\EnterpriseSso\EnterpriseCallbackAuthenticator;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Testing\TestResponse;
+use Tests\Support\EnterpriseSso\FakeIdentityProvider;
+
+/*
+ * 企業 SSO のログイン導線 (C2)。
+ *
+ * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点でログインを確定させ、
+ *   2 要素認証の入力画面へ転送する分岐を持たない。**主たる証明はここ (実挙動) にある**。
+ */
+
+function activeConnection(FakeIdentityProvider $idp, ?Organization $organization = null): OrganizationOidcConnection
+{
+    return OrganizationOidcConnection::factory()->active()->create([
+        'organization_id' => ($organization ?? Organization::factory()->create())->id,
+        'login_slug' => 'acme-idp',
+        'issuer' => $idp->issuer,
+        'client_id' => 'client-1234',
+        'client_secret_encrypted' => ConnectionSecret::fromPlaintext('very-secret-value'),
+    ]);
+}
+
+/** 開始 → IdP の応答 → 戻り口までを 1 往復させる。 */
+function completeEnterpriseLogin(FakeIdentityProvider $idp, string $subject = 'sub-abc'): TestResponse
+{
+    $start = test()->get('/enterprise/acme-idp/redirect');
+    $start->assertRedirect();
+
+    /** @var string $location */
+    $location = $start->headers->get('Location');
+    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
+
+    $idp->withClaims(['nonce' => $query['nonce'], 'sub' => $subject]);
+
+    return test()->get(route('enterprise-sso.callback', [
+        'state' => $query['state'],
+        'code' => 'authorization-code',
+    ]));
+}
+
+test('開始で行が作られてからリダイレクトする (順序)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $response = $this->get('/enterprise/acme-idp/redirect');
+
+    $response->assertRedirect();
+    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(1);
+});
+
+test('認可要求に必須の引数が載る', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $response = $this->get('/enterprise/acme-idp/redirect');
+    parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
+
+    expect($query['response_type'])->toBe('code');
+    expect($query['scope'])->toContain('openid');
+    expect($query['client_id'])->toBe('client-1234');
+    expect($query['code_challenge_method'])->toBe('S256');
+    expect($query['state'])->not->toBe('');
+    expect($query['nonce'])->not->toBe('');
+    expect($query['code_challenge'])->not->toBe('');
+    expect($query['redirect_uri'])->toBe(route('enterprise-sso.callback'));
+});
+
+test('開始の応答が no-store である', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $this->get('/enterprise/acme-idp/redirect')
+        ->assertHeader('Cache-Control', 'no-store, private');
+});
+
+test('無効な接続では行を作らず、実在しない識別名と同じ応答になる (実在オラクルを作らない)', function (OidcConnectionStatus $status): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $connection = activeConnection($idp);
+    $connection->forceFill(['status' => $status])->save();
+
+    $disabled = $this->get('/enterprise/acme-idp/redirect');
+    $missing = $this->get('/enterprise/never-registered/redirect');
+
+    expect($disabled->getStatusCode())->toBe($missing->getStatusCode());
+    expect($disabled->headers->get('Location'))->toBe($missing->headers->get('Location'));
+    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(0);
+})->with([
+    OidcConnectionStatus::Draft,
+    OidcConnectionStatus::Verified,
+    OidcConnectionStatus::Disabled,
+]);
+
+test('往復でログインが確定し、利用者・身元・所属が作られる', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $connection = activeConnection($idp);
+
+    completeEnterpriseLogin($idp)->assertRedirect();
+
+    expect(Auth::check())->toBeTrue();
+    expect($connection->identities()->count())->toBe(1);
+
+    /** @var User $user */
+    $user = Auth::user();
+    expect($user->email)->toBeNull();
+    expect($user->hasVerifiedEmail())->toBeTrue();
+});
+
+test('2 要素認証が有効な利用者もそのままログインが確定する (AG-200 の主証明 その 1)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $connection = activeConnection($idp);
+
+    // 1 回目のログインで利用者を作り、2 要素を有効にする
+    completeEnterpriseLogin($idp);
+    /** @var User $user */
+    $user = Auth::user();
+    $user->forceFill([
+        'two_factor_secret' => encrypt('secret'),
+        'two_factor_confirmed_at' => now(),
+    ])->save();
+    Auth::logout();
+
+    // 2 回目: **2 要素の入力画面へ送られない**
+    $response = completeEnterpriseLogin($idp);
+
+    expect(Auth::check())->toBeTrue();
+    expect(Auth::id())->toBe($user->id);
+    $response->assertRedirect();
+    expect($response->headers->get('Location'))->not->toContain('two-factor');
+});
+
+test('組織が 2 要素を義務づけていても、確定したうえで設定ページへ導かれる (AG-200 の主証明 その 2)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $organization = Organization::factory()->create(['two_factor_required' => true]);
+    activeConnection($idp, $organization);
+
+    completeEnterpriseLogin($idp);
+
+    // ★ログインは確定している (待機ログインを作らない)
+    expect(Auth::check())->toBeTrue();
+
+    // 義務づけの強制は**ログイン確定後**のアカウント全体のゲートであり、行き先は設定ページである
+    $this->get(route('settings.security'))->assertSuccessful();
+});
+
+test('remember cookie を発行しない', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $response = completeEnterpriseLogin($idp);
+
+    foreach ($response->headers->getCookies() as $cookie) {
+        expect($cookie->getName())->not->toStartWith('remember_web');
+    }
+});
+
+test('確定でセッション ID が変わる (セッション固定化を塞ぐ)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $this->get('/enterprise/acme-idp/redirect');
+    $before = session()->getId();
+
+    completeEnterpriseLogin($idp);
+
+    expect(session()->getId())->not->toBe($before);
+});
+
+test('不正な入力では外向き取得を一切開始しない', function (array $query): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $this->get(route('enterprise-sso.callback', $query));
+
+    expect($idp->requests)->toBe([]);
+    expect(Auth::check())->toBeFalse();
+})->with([
+    'state が無い' => [['code' => 'c']],
+    'code も error も無い' => [['state' => 's']],
+    'code と error の同時' => [['state' => 's', 'code' => 'c', 'error' => 'access_denied']],
+    'state が配列' => [['state' => ['a'], 'code' => 'c']],
+    'code が長すぎる' => [['state' => 's', 'code' => str_repeat('c', 5000)]],
+]);
+
+test('IdP の error 応答は一様な失敗になる', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $this->get(route('enterprise-sso.callback', ['state' => 'anything', 'error' => 'access_denied']))
+        ->assertRedirect(route('login'));
+
+    expect(Auth::check())->toBeFalse();
+});
+
+test('別のブラウザで戻り口を開いてもログインできない (login CSRF)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $start = $this->get('/enterprise/acme-idp/redirect');
+    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
+    $idp->withClaims(['nonce' => $query['nonce']]);
+
+    // 攻撃者のセッション (結合の秘密を持たない) から戻り口を開く
+    $this->flushSession();
+
+    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']))
+        ->assertRedirect(route('login'));
+
+    expect(Auth::check())->toBeFalse();
+    // ★行は残る (攻撃者が被害者の試行を消せない)
+    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(1);
+});
+
+test('開始後に接続を無効化するとログインできない', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $connection = activeConnection($idp);
+
+    $start = $this->get('/enterprise/acme-idp/redirect');
+    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
+    $idp->withClaims(['nonce' => $query['nonce']]);
+
+    $connection->forceFill(['status' => OidcConnectionStatus::Disabled])->save();
+
+    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']))
+        ->assertRedirect(route('login'));
+
+    expect(Auth::check())->toBeFalse();
+    // ★JIT も起きていない (副作用が 1 バイトも残らない)
+    expect($connection->identities()->count())->toBe(0);
+});
+
+test('失敗の応答が一様である (接続や利用者の存在を読み取れない)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $unknownState = $this->get(route('enterprise-sso.callback', ['state' => 'never-issued', 'code' => 'c']));
+    $providerError = $this->get(route('enterprise-sso.callback', ['state' => 'never-issued', 'error' => 'x']));
+
+    expect($unknownState->getStatusCode())->toBe($providerError->getStatusCode());
+    expect($unknownState->headers->get('Location'))->toBe($providerError->headers->get('Location'));
+});
+
+test('使用済みの state では 2 回目にログインできない', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $start = $this->get('/enterprise/acme-idp/redirect');
+    parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
+    $idp->withClaims(['nonce' => $query['nonce']]);
+
+    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']));
+    expect(Auth::check())->toBeTrue();
+    Auth::logout();
+
+    $this->get(route('enterprise-sso.callback', ['state' => $query['state'], 'code' => 'c']))
+        ->assertRedirect(route('login'));
+    expect(Auth::check())->toBeFalse();
+});
+
+test('結合の秘密は state の指紋ごとに分かれる (複数タブが共存できる)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $first = $this->get('/enterprise/acme-idp/redirect');
+    parse_str((string) parse_url((string) $first->headers->get('Location'), PHP_URL_QUERY), $firstQuery);
+
+    $second = $this->get('/enterprise/acme-idp/redirect');
+    parse_str((string) parse_url((string) $second->headers->get('Location'), PHP_URL_QUERY), $secondQuery);
+
+    foreach ([$firstQuery['state'], $secondQuery['state']] as $state) {
+        $key = EnterpriseCallbackAuthenticator::bindingSessionKey(
+            AttemptFingerprint::of(FingerprintPurpose::State, $state),
+        );
+        expect(session()->get($key))->toBeString();
+    }
+});
+
+test('入口の画面は外向き通信をせず DB も変えない', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+
+    $this->get(route('enterprise-sso.login'))->assertSuccessful();
+
+    expect($idp->requests)->toBe([]);
+    expect(EnterpriseSsoLoginAttempt::query()->count())->toBe(0);
+});
+
+test('validation の失敗でも code / state が old input に残らない', function (array $query): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    // ★Laravel は validation の失敗時、controller へ到達する**前に**入力を `_old_input` へ
+    //   退避する。controller で withInput() を呼ばないだけでは塞げない経路である。
+    $this->get(route('enterprise-sso.callback', $query))->assertRedirect(route('login'));
+
+    /** @var array<string, mixed> $old */
+    $old = session()->get('_old_input', []);
+
+    expect($old)->not->toHaveKey('code');
+    expect($old)->not->toHaveKey('state');
+    expect(json_encode($old, JSON_THROW_ON_ERROR))->not->toContain('super-secret-code');
+})->with([
+    'code と error の同時' => [[
+        'state' => 'super-secret-state',
+        'code' => 'super-secret-code',
+        'error' => 'access_denied',
+    ]],
+    'state が無い' => [['code' => 'super-secret-code']],
+    'code も error も無い' => [['state' => 'super-secret-state']],
+]);
+
+test('validation の失敗も他の失敗と同じ応答である (どこで落ちたか読み取れない)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    activeConnection($idp);
+
+    $invalidInput = $this->get(route('enterprise-sso.callback', ['state' => 's', 'code' => 'c', 'error' => 'x']));
+    $unknownState = $this->get(route('enterprise-sso.callback', ['state' => 'never-issued', 'code' => 'c']));
+
+    expect($invalidInput->getStatusCode())->toBe($unknownState->getStatusCode());
+    expect($invalidInput->headers->get('Location'))->toBe($unknownState->headers->get('Location'));
+});
diff --git a/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php b/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php
new file mode 100644
index 00000000..e56dcc9e
--- /dev/null
+++ b/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php
@@ -0,0 +1,332 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Services\EnterpriseSso\OidcDiscoveryService;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Illuminate\Support\Facades\Cache;
+use Kent013\SsrfPin\Dtos\PinnedFailure;
+use Kent013\SsrfPin\Enums\SsrfDenyReason;
+use Tests\Support\EnterpriseSso\FakeIdentityProvider;
+
+/*
+ * 接続先情報と公開鍵の取得 (B1)。
+ *
+ * ★偽の IdP は **ssrf-pin が出荷している transport の seam** を差し替えるだけである。
+ *   `UrlSafetyInspector` は本物が動くので、実装が pin 済み経路を通らなければ
+ *   本 fake には 1 件も要求が届かない (= 到達の検証を兼ねる)。
+ */
+
+function discoveryService(): OidcDiscoveryService
+{
+    return app(OidcDiscoveryService::class);
+}
+
+function issuerOf(FakeIdentityProvider $idp): OidcIssuerUrl
+{
+    return OidcIssuerUrl::fromString($idp->issuer);
+}
+
+test('実装が PinnedHttpClient を通る (偽の transport に要求が届く)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+
+    discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect($idp->requests)->toHaveCount(1);
+    expect($idp->requests[0]->url)->toBe($idp->issuer.'/.well-known/openid-configuration');
+});
+
+test('issuer が一致しない文書を拒否する', function (): void {
+    $idp = (new FakeIdentityProvider)->withMetadata(['issuer' => 'https://evil.example.test'])->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
+
+test('endpoint が別 origin でも受理する (実在の IdP を拒否しない)', function (): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([
+        'jwks_uri' => 'https://keys.example.test/jwks',
+    ])->install();
+
+    expect(discoveryService()->fetchMetadata(issuerOf($idp))->jwksUri)
+        ->toBe('https://keys.example.test/jwks');
+});
+
+test('endpoint に query が付いていても受理する (禁じる標準上の根拠が無い)', function (): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([
+        'token_endpoint' => 'https://idp.example.test/token?tenant=a',
+    ])->install();
+
+    expect(discoveryService()->fetchMetadata(issuerOf($idp))->tokenEndpoint)
+        ->toBe('https://idp.example.test/token?tenant=a');
+});
+
+test('endpoint が規則に合わない文書を拒否する', function (string $key, string $value): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([$key => $value])->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+})->with([
+    'http の token endpoint' => ['token_endpoint', 'http://idp.example.test/token'],
+    'userinfo つき' => ['token_endpoint', 'https://u:p@idp.example.test/token'],
+    'fragment つき' => ['token_endpoint', 'https://idp.example.test/token#a'],
+    '相対 URL' => ['jwks_uri', '/jwks'],
+]);
+
+test('パス付きの issuer で well-known の URL が正しく組み立つ', function (): void {
+    $idp = (new FakeIdentityProvider('https://idp.example.test/tenant'))->install();
+
+    discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect($idp->requests[0]->url)
+        ->toBe('https://idp.example.test/tenant/.well-known/openid-configuration');
+});
+
+test('client 認証方式の欠落は client_secret_basic として受理する (仕様の既定)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $metadata = $idp->metadata();
+    unset($metadata['token_endpoint_auth_methods_supported']);
+    $idp->withBody(json_encode($metadata, JSON_THROW_ON_ERROR));
+
+    expect(discoveryService()->fetchMetadata(issuerOf($idp))->tokenEndpointAuthMethods)
+        ->toHaveCount(1);
+});
+
+test('client 認証方式が明示されていて対応が無い IdP は拒否する', function (mixed $methods): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([
+        'token_endpoint_auth_methods_supported' => $methods,
+    ])->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+})->with([
+    '空配列' => [[]],
+    '未知値だけ' => [['private_key_jwt']],
+]);
+
+test('basic と post の混在では basic が先に来る (body 漏洩面が小さい方を優先)', function (): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([
+        'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
+    ])->install();
+
+    expect(discoveryService()->fetchMetadata(issuerOf($idp))->tokenEndpointAuthMethods[0]->value)
+        ->toBe('client_secret_basic');
+});
+
+test('署名方式の欠落・空・交わらない集合を拒否する (必須項目である)', function (mixed $algorithms): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([
+        'id_token_signing_alg_values_supported' => $algorithms,
+    ])->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+})->with([
+    '空配列' => [[]],
+    'none だけ' => [['none']],
+    'HMAC だけ' => [['HS256']],
+]);
+
+test('3xx を成功として扱わない', function (): void {
+    $idp = (new FakeIdentityProvider)->withStatus(302)->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
+
+test('大きすぎる応答を拒否する', function (): void {
+    $idp = (new FakeIdentityProvider)->withBody(str_repeat('x', 300000))->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
+
+test('JSON でない / オブジェクトでない応答を拒否する', function (string $body): void {
+    $idp = (new FakeIdentityProvider)->withBody($body)->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+})->with(['not json', '"a string"', '12']);
+
+test('取得そのものの失敗は値で返り、固定の理由コードの例外になる', function (): void {
+    $idp = (new FakeIdentityProvider)
+        ->withTransportFailure(new PinnedFailure(SsrfDenyReason::InvalidHost, 'https://idp.example.test', 0))
+        ->install();
+
+    expect(fn () => discoveryService()->fetchMetadata(issuerOf($idp)))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
+
+test('2 回目の取得はキャッシュから返る (外向きの取得が増えない)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+
+    discoveryService()->fetchMetadata(issuerOf($idp));
+    discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect($idp->requests)->toHaveCount(1);
+});
+
+test('キャッシュ hit でも広告された署名方式が残る (B3 の共通部分が成立する)', function (): void {
+    $idp = (new FakeIdentityProvider)->withMetadata([
+        'id_token_signing_alg_values_supported' => ['RS256', 'ES256'],
+    ])->install();
+
+    discoveryService()->fetchMetadata(issuerOf($idp));
+    $cached = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect($cached->idTokenSigningAlgorithms)->toHaveCount(2);
+    expect($cached->advertises(OidcSigningAlgorithm::Es256))->toBeTrue();
+});
+
+test('壊れたキャッシュは forget して取り直す', function (mixed $payload): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $issuer = issuerOf($idp);
+
+    Cache::put('enterprise-sso:metadata:'.$issuer->cacheDigest(), $payload, 300);
+
+    $metadata = discoveryService()->fetchMetadata($issuer);
+
+    expect($metadata->issuer->value)->toBe($idp->issuer);
+    expect($idp->requests)->toHaveCount(1);
+})->with([
+    '空配列' => [[]],
+    '要素が足りない' => [['issuer' => 'https://idp.example.test']],
+    '未知の署名方式' => [[
+        'issuer' => 'https://idp.example.test',
+        'authorization_endpoint' => 'https://idp.example.test/authorize',
+        'token_endpoint' => 'https://idp.example.test/token',
+        'jwks_uri' => 'https://idp.example.test/jwks',
+        'auth_methods' => ['client_secret_basic'],
+        'id_token_signing_algorithms' => ['HS256'],
+    ]],
+]);
+
+test('公開鍵の取得もキャッシュされ、鍵は素の配列で保存される', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    discoveryService()->fetchJwks($metadata);
+    discoveryService()->fetchJwks($metadata);
+
+    // discovery 1 回 + JWKS 1 回 (2 回目はキャッシュ)
+    expect($idp->requests)->toHaveCount(2);
+
+    /** @var array<string, array<string, string>> $cached */
+    $cached = Cache::get('enterprise-sso:jwks:'.$metadata->issuer->cacheDigest());
+    expect($cached)->toBeArray();
+    expect($cached[FakeIdentityProvider::KEY_ID]['kty'])->toBe('RSA');
+});
+
+test('kid が重複する JWKS を拒否する', function (): void {
+    $key = FakeIdentityProvider::publicJwk();
+    $idp = (new FakeIdentityProvider)->withKeys([$key, $key])->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect(fn () => discoveryService()->fetchJwks($metadata))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
+
+test('鍵の再取得は最小間隔の内側では起きない (増幅を防ぐ)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    discoveryService()->refetchJwks($metadata, connectionId: 1);
+
+    expect(fn () => discoveryService()->refetchJwks($metadata, connectionId: 1))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
+
+test('鍵の再取得は接続単位のロックで直列化される (同時要求でも 1 回)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+    $requestsBefore = count($idp->requests);
+
+    // ★ロックを**先に他者が保持している**状態を作る。待たずに拒否されることが要点である
+    //   (待つと未知 kid の連打で worker が占有される)。
+    $holder = Cache::lock('enterprise-sso:jwks-refetch:1', 15);
+    expect($holder->get())->toBeTrue();
+
+    try {
+        expect(fn () => discoveryService()->refetchJwks($metadata, connectionId: 1))
+            ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+    } finally {
+        $holder->release();
+    }
+
+    // ★拒否された側は外向きの取得を 1 件も行わない (増幅しない)
+    expect(count($idp->requests))->toBe($requestsBefore);
+});
+
+test('ロックが解放されれば再取得できる (正のコントロール)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    $holder = Cache::lock('enterprise-sso:jwks-refetch:1', 15);
+    $holder->get();
+    $holder->release();
+
+    expect(discoveryService()->refetchJwks($metadata, connectionId: 1)->has(FakeIdentityProvider::KEY_ID))
+        ->toBeTrue();
+});
+
+test('接続が違えば互いの再取得を止めない (ロックは接続単位である)', function (): void {
+    $idp = (new FakeIdentityProvider)->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    $holder = Cache::lock('enterprise-sso:jwks-refetch:1', 15);
+    expect($holder->get())->toBeTrue();
+
+    try {
+        expect(discoveryService()->refetchJwks($metadata, connectionId: 2)->has(FakeIdentityProvider::KEY_ID))
+            ->toBeTrue();
+    } finally {
+        $holder->release();
+    }
+});
+
+test('key_ops は完全一致で判定する (notverify を verify とみなさない)', function (array $keyOps): void {
+    $key = [...FakeIdentityProvider::publicJwk(), 'key_ops' => $keyOps];
+    unset($key['use']);
+    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+    $jwks = discoveryService()->fetchJwks($metadata);
+
+    expect(fn () => $jwks->keyFor(FakeIdentityProvider::KEY_ID, OidcSigningAlgorithm::Rs256))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+})->with([
+    '接頭辞つき' => [['notverify']],
+    '接尾辞つき' => [['verifying']],
+    '大文字' => [['VERIFY']],
+    '別の用途だけ' => [['sign']],
+]);
+
+test('key_ops に verify があれば受理する (正のコントロール)', function (array $keyOps): void {
+    $key = [...FakeIdentityProvider::publicJwk(), 'key_ops' => $keyOps];
+    unset($key['use']);
+    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect(discoveryService()->fetchJwks($metadata)
+        ->keyFor(FakeIdentityProvider::KEY_ID, OidcSigningAlgorithm::Rs256))
+        ->toHaveKey('kty');
+})->with([
+    '単独' => [['verify']],
+    '他と併記' => [['verify', 'wrapKey']],
+    '重複' => [['verify', 'verify']],
+]);
+
+test('存在する既知の項目の型が違う鍵を拒否する (欠落として捨てない)', function (array $overrides): void {
+    $key = [...FakeIdentityProvider::publicJwk(), ...$overrides];
+    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect(fn () => discoveryService()->fetchJwks($metadata))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+})->with([
+    'use が配列' => [['use' => ['sig']]],
+    'alg が数値' => [['alg' => 256]],
+    'kty が配列' => [['kty' => ['RSA']]],
+    'key_ops が文字列' => [['key_ops' => 'verify']],
+    'key_ops の要素が数値' => [['key_ops' => [1]]],
+]);
```

---

## 再確認をお願いしたい点

1. **Critical の塞ぎ方**が十分か — `failedValidation()` で `ValidationException` に応答を持たせる形で、
   `Handler::invalid()` の `withInput()` を通らないと判断してよいか。
2. **メール昇格の 2 段構成**が「一回使用」を本当に成立させているか
   (第 2 段を savepoint に入れたのは、衝突が呼び出し元のトランザクションを aborted にするのを避けるためである)。
3. **JWKS 再取得のロック**の寿命・非待機・fail-closed の判断が妥当か。
4. その他、対応によって**新しく生まれた欠陥**が無いか。

全体判定を `APPROVED` または `CHANGES_REQUESTED` で明示してほしい。
