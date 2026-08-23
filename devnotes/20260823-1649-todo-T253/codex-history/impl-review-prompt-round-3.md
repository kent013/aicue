# Round 3: Round 2 の指摘への対応

Round 2 の指摘 (Warning 3 / 空振り 1 / Suggestion 3) に**すべて対応した**。
反論・見送りは 0 件だが、Suggestion 1 件 (`$lock->release()` の例外の扱い) だけは
**別の倒し方**を採ったので、その理由を対応マトリクスに書いた。

---

# 対応マトリクス: impl-review Round 2

Codex の全体判定は `CHANGES_REQUESTED`。Round 1 の指摘はすべて APPROVE され、
**新たに 4 件 (Warning 3 / 空振り 1) と Suggestion 3 件**が出た。
**すべて対応した** (反論・見送りは 0 件)。

---

## [Warning] 消費の commit とメール適用の間に、別経路のメール更新を上書きする

- **判断**: 対応する
- **根拠**: **指摘が正しい**。第 1 段でロックを手放してから第 2 段で書くので、
  その隙に別経路 (プロフィール更新) がメールを入れると、第 2 段が**黙って上書きする**。
  Round 1 で「2 段に分ける」ことだけを直し、**分けたことで開いた窓**を閉じていなかった。
  既存の「発行後に別経路でメールが入ったら確定できない」テストは第 1 段より**前**の割り込みしか
  測っておらず、この窓を固定していなかった (これも指摘どおり)。
- **対応内容**: 第 2 段でも**利用者の行をロックして読み直し**、`email === null` のときだけ適用する。
  弾いた場合も**トークンは消費済みのまま**にする (一回使用を保ったまま、他経路の結果を壊さない)。
  書き込みは**読み直した新しいインスタンス**に対して行う
  (Suggestion「失敗時に未保存値が残る」も同時に解消する)。
  `applyVerifiedEmail()` は `bool` を返すようにし、`confirm()` の戻り値へ素直に流す。
- **回帰テスト**: `EmailPromotionTest`「消費の確定と適用の間に別経路がメールを入れたら、
  その更新を上書きしない」。指摘された 4 つの成功条件をすべて固定した —
  別経路の値が残る / promotion 行は消費済み / **昇格の監査は作られない** / 同じトークンは再利用できない。

---

## [Warning] G2-4 は `followRedirects:` の**存在**しか見ておらず `true` を見逃す

- **判断**: 対応する
- **根拠**: **指摘が正しい**。gate の名前と失敗メッセージは「追従を明示的に切る」と主張しているのに、
  実際に保証していたのは「名前付き引数がある」だけだった (**主張と保証の食い違い**)。
- **対応内容**: 走査器の API を `callsMissingNamedArgument()` から
  `callsWithoutNamedLiteral($sources, $method, $argument, $literal)` へ変え、
  **値がリテラルちょうど 1 トークンであること**まで見るようにした。
  指摘のとおり `$configuredValue` / `! true` のような**静的に確定できない式は通さない**
  (値の次のトークンが `,` か `)` でなければ式とみなして落とす = fail-closed)。
- **回帰テスト** (指摘された 3 方向をすべて置いた):
  見本 `RedirectFollowingSample.php.txt` に **5 つの呼び出し**を置き、
  安全な 1 件 (リテラル `false`) だけを通し、
  「引数が無い」「`followRedirects: true`」「動的な値」「否定の式」の 4 件を落とすことを固定した。
  正例は「リテラルの false ちょうど」だけである。

---

## [Warning] 「トークンが長すぎる」データセットが validation failure を起こしていない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`super-secret-token` は 18 文字で、上限は
  `AttemptFingerprint::HEX_LENGTH * 4` = 256 文字。**規則を通ってしまい**、
  `failedValidation()` の回帰になっていなかった (controller が `withInput()` を使わないことしか測れない)。
- **対応内容**:
  - データセットの値を**上限から生成**するようにした (`str_repeat(...)` で確実に超える長さ)。
  - 「トークンが配列」のデータセットも足した (型でも落ちることを測る)。
  - さらに**空振りしていないことを直接固定する**テストを足した —
    `ConfirmEmailPromotionRequest::rules()` に対して
    「上限ちょうどは通る / 上限 + 1 は落ちる / 配列は落ちる / 空は落ちる」を検査する。
    ★Codex は「service を mock して未呼び出しを確認する」案も挙げたが、
    `EmailPromotionService` は `final readonly` なので差し替えられない。
    代わりに**規則そのものを直接撃つ**形にした (「送っている値が確実に不正である」ことの証明としては同値である)。

---

## [Suggestion] 重複した `["verify", "verify"]` を正例にしている

- **判断**: 対応する
- **根拠**: 指摘のとおり重複に意味は無く malformed 寄りである。deny-by-default を優先する。
- **対応内容**: `normalizeKeyOperations()` が重複を**拒否**するようにし、
  テストでも正例から外して**負例へ移した**。

---

## [Suggestion] ロックの寿命と時間予算の大小関係を設定検査で固定する

- **判断**: 対応する
- **根拠**: 指摘のとおり。設定を変えて予算がロックの寿命を超えると、
  「取得中に失効して 2 人目が取り始める」形が黙って復活する。
- **対応内容**: `JWKS_REFETCH_LOCK_SECONDS` を public にし、
  `EnterpriseSsoConfigTest` に「ロックの寿命 > 接続 + 要求の予算」を足した。

---

## [Suggestion] `$lock->release()` の例外がそのまま伝播する

- **判断**: 対応する (ただし**指摘とは別の倒し方**を採る)
- **根拠**: Codex は「ロック基盤の障害は一様な拒否」という契約を release にも適用する案を挙げたが、
  **release は後片付けである**。取得に成功した後の解放の失敗で**正しく取れた JWKS を捨てる**のは、
  可用性を下げるだけで安全側ではない (取りこぼしてもロックは寿命で自然に切れるので、
  「二度と再取得できない」形にもならない)。
- **対応内容**: `release()` を **best-effort** にし、**なぜ拒否へ倒さないか**を docblock に書いた。
  取得 (`get()`) の失敗は従来どおり fail-closed のままである。

---

## Round 2 で APPROVE された点 (変更しない)

`EnterpriseSsoCallbackRequest` / `ConfirmEmailPromotionRequest` / `UniformLoginFailure` /
`EmailPromotionMail` / `OidcJsonWebKeySet` / `OidcDiscoveryService` (ロック) /
`OidcDiscoveryServiceTest` / `SecurityController` / `Security.svelte` はいずれも APPROVE。
とくに「`ValidationException` に response を持たせれば `withInput()` を迂回できる」という
Round 1 Critical の塞ぎ方は十分と確認された。

---

## 検証コマンドの結果 (対応後・全 green)

- `composer test`: **7275 tests / 7273 passed / 0 failed** (skipped 2 / risky 5 は既存)
- `composer phpstan`: OK (level 10) / `vendor/bin/pint --test`: passed

---

## 対応の差分 (全文)

```diff
diff --git a/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php b/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php
new file mode 100644
index 00000000..cf9ae584
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php
@@ -0,0 +1,258 @@
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
+     * ★配列でない / 要素が文字列でない / 区切り文字を含む / **重複した**用途は**拒否する**
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
+            // ★**重複も拒否する**。同じ用途を 2 回書くことに意味は無く、malformed 寄りである。
+            //   deny-by-default を優先し、意味の無い形を通さない。
+            if (in_array($operation, $operations, true)) {
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
diff --git a/app/Services/Auth/EmailPromotionService.php b/app/Services/Auth/EmailPromotionService.php
new file mode 100644
index 00000000..271bad90
--- /dev/null
+++ b/app/Services/Auth/EmailPromotionService.php
@@ -0,0 +1,270 @@
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
+ *
+ * ★**第 2 段でも行ロックの下で `email === null` を再確認する**。
+ *   第 1 段は commit してロックを手放しているので、2 段の間に別経路 (プロフィール更新など) が
+ *   メールを入れていることがありうる。再確認しないと**その更新を黙って上書きする**。
+ *   再確認で弾いた場合もトークンは消費済みのままにする (一回使用を保つ)。
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
+     * @return bool true = 確定した / false = トークンが無効・期限切れ・他人のもの・対象外・
+     *              **第 1 段の後に別経路でメールが入った** (その場合も**トークンは消費済み**である)
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
+        return $this->applyVerifiedEmail($user, VerifiedEmail::afterConfirmation($email));
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
+     * ★**ここでも行ロックの下で `email === null` を再確認する**。
+     *   第 1 段 (消費) は commit してロックを手放しているので、その隙に別経路が
+     *   メールを入れていることがありうる。再確認しないと**その更新を黙って上書きする**。
+     *   上書きしないときはトークンを**消費済みのまま**にして false を返す
+     *   (一回使用は保ったまま、他経路の結果を壊さない)。
+     *
+     * ★書き込みは**第 1 段で読み直した新しいインスタンス**に対して行う。
+     *   呼び出し側が持っているインスタンスへ `forceFill()` すると、失敗したときに
+     *   未保存の値がそのまま残る。
+     *
+     * @return bool true = 適用した / false = 第 1 段の後にメールが入ったので適用しない
+     *
+     * @throws EmailPromotionConflictException
+     */
+    private function applyVerifiedEmail(User $user, VerifiedEmail $email): bool
+    {
+        try {
+            // ★**自分のトランザクション (savepoint) の中で**書く。
+            //   pgsql は SQL エラーでトランザクション全体を aborted にするので、裸で書くと
+            //   衝突が**呼び出し元のトランザクションまで巻き込む** (第 1 段の消費が使えなくなる)。
+            //   savepoint の中なら巻き戻るのはこの 1 文だけである。
+            $applied = DB::transaction(function () use ($user, $email): bool {
+                $locked = $this->lockedSelf($user);
+
+                if (! $locked instanceof User || $locked->email !== null) {
+                    return false;
+                }
+
+                $locked->forceFill([
+                    'email' => $email->value,
+                    // ★**新しいメールを実際に確認した時刻**へ更新する (以前の値のままにしない)。
+                    'email_verified_at' => now(),
+                ])->save();
+
+                return true;
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
+        if (! $applied) {
+            return false;
+        }
+
+        // ★監査に残すのは**利用者と固定の事象種別だけ**である
+        //   (トークンも平文のメールも載せない。既存の変更経路と同じ event type を使う)。
+        $this->recorder->record(SecurityEventType::EmailChanged, $user, ['source' => 'email_promotion']);
+
+        return true;
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
index 00000000..c5cbe30e
--- /dev/null
+++ b/app/Services/EnterpriseSso/OidcDiscoveryService.php
@@ -0,0 +1,309 @@
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
+    public const int JWKS_REFETCH_LOCK_SECONDS = 15;
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
+            // ★解放の失敗で**成功した取得結果を捨てない** (解放は後片付けである)。
+            //   取りこぼしてもロックは寿命 (JWKS_REFETCH_LOCK_SECONDS) で自然に切れるので、
+            //   「二度と再取得できない」形にはならない。
+            try {
+                $lock->release();
+            } catch (Throwable) {
+                // 後片付けの失敗は本体の結果に影響させない
+            }
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
diff --git a/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php b/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php
new file mode 100644
index 00000000..1f3ead17
--- /dev/null
+++ b/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php
@@ -0,0 +1,86 @@
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
+    // ★`fetch()` の第 3 引数は**既定が true** なので、**リテラルの false** を渡していることを
+    //   **呼び出しごとに**要求する。
+    //   - ファイル単位の部分文字列一致だと、同じファイルへ既定値の呼び出しを 1 行足すだけで見逃す
+    //   - 名前付き引数の**存在だけ**を見ると `followRedirects: true` が素通りする
+    //   - 静的に確定できない値 (`$configured` / `! false` / `false || true`) も通さない (fail-closed)
+    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
+        ->toBe([], 'pin 済み経路の fetch() は followRedirects: false を明示すること');
+});
+
+test('G2-5: 走査が空振りしていない (母集団が空でない)', function (): void {
+    expect(EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots()))->not->toBe([]);
+});
diff --git a/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
new file mode 100644
index 00000000..49fc433c
--- /dev/null
+++ b/tests/Architecture/fixtures/enterprise-sso/RedirectFollowingSample.php.txt
@@ -0,0 +1,47 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use Kent013\SsrfPin\PinnedHttpClient;
+
+/**
+ * ★負例の見本。**安全な呼び出しと危険な呼び出しが同じファイルに同居する**形を置く。
+ *   ファイル単位の部分文字列一致だと安全な方を見て緑になってしまう (それを赤にする)。
+ *
+ * 危険な形は 3 方向ある:
+ *   1. 引数そのものが無い (既定の true が効く)
+ *   2. `followRedirects: true` (明示的に追従する)
+ *   3. 値が静的に確定できない (実行時に true になりうる)
+ */
+final class RedirectFollowingSample
+{
+    public function __construct(private PinnedHttpClient $pinned) {}
+
+    /** ★唯一の正例。リテラルの false ちょうど。 */
+    public function safe(): void
+    {
+        $this->pinned->fetch($request, $deadline, followRedirects: false);
+    }
+
+    public function missingArgument(): void
+    {
+        $this->pinned->fetch($request, $deadline);
+    }
+
+    public function explicitlyTrue(): void
+    {
+        $this->pinned->fetch($request, $deadline, followRedirects: true);
+    }
+
+    public function dynamicValue(): void
+    {
+        $this->pinned->fetch($request, $deadline, followRedirects: $configured);
+    }
+
+    public function negatedExpression(): void
+    {
+        $this->pinned->fetch($request, $deadline, followRedirects: ! true);
+    }
+}
diff --git a/tests/Feature/Auth/EmailPromotionTest.php b/tests/Feature/Auth/EmailPromotionTest.php
new file mode 100644
index 00000000..8a618d01
--- /dev/null
+++ b/tests/Feature/Auth/EmailPromotionTest.php
@@ -0,0 +1,520 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\SecurityEventType;
+use App\Http\Requests\Auth\ConfirmEmailPromotionRequest;
+use App\Mail\EmailPromotionMail;
+use App\Models\EmailPromotion;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Illuminate\Contracts\Queue\ShouldBeEncrypted;
+use Illuminate\Database\QueryException;
+use Illuminate\Log\Events\MessageLogged;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use Illuminate\Support\Facades\Mail;
+use Illuminate\Support\Facades\Validator;
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
+    // ★**規則上たしかに不正になる値**を使う (上限から生成する)。
+    //   短い値だと validation を通ってしまい、`failedValidation()` の回帰にならない
+    //   (controller が withInput() を使わないことしか測れない)。
+    'トークンが長すぎる' => [['token' => 'super-secret-token'.str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4)]],
+    'トークンが無い' => [[]],
+    'トークンが配列' => [['token' => ['super-secret-token']]],
+]);
+
+test('確認の入力規則が、テストが送る値を実際に不正と判定する (空振りしていないことの証明)', function (mixed $token, bool $shouldFail): void {
+    // ★データセットの値が**規則上たしかに不正**であることを直接固定する。
+    //   短い値のまま「old input に無い」だけを見ると、validation を通っていても緑になり、
+    //   `failedValidation()` の回帰にならない (Round 1 の Critical が再発しても気付けない)。
+    $rules = (new ConfirmEmailPromotionRequest)->rules();
+    $validator = Validator::make(['token' => $token], $rules);
+
+    expect($validator->fails())->toBe($shouldFail);
+})->with([
+    '上限ちょうどは通る' => [str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4), false],
+    '上限 + 1 は落ちる' => [str_repeat('x', AttemptFingerprint::HEX_LENGTH * 4 + 1), true],
+    '配列は落ちる' => [['x'], true],
+    '空は落ちる' => ['', true],
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
+
+test('消費の確定と適用の間に別経路がメールを入れたら、その更新を上書きしない', function (): void {
+    $user = promotionUser();
+    $token = issuePromotion($user);
+
+    // ★第 1 段 (消費の commit) の**後**・第 2 段 (適用) の**前**に割り込む。
+    //   監査の記録点を使って「適用の直前」を捕まえるのではなく、
+    //   利用者の保存を観測して割り込む (適用は保存そのものなので、その 1 つ前で入る)。
+    $interrupted = false;
+    EmailPromotion::deleted(function () use ($user, &$interrupted): void {
+        if ($interrupted) {
+            return;
+        }
+        $interrupted = true;
+
+        // 別経路がメールを入れる (プロフィール更新など)
+        $user->newQuery()->whereKey($user->getKey())->update([
+            'email' => encryptedEmailFor('other@corp.example'),
+        ]);
+    });
+
+    try {
+        $this->actingAs($user)
+            ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+            ->assertSessionHasErrors('email_promotion');
+    } finally {
+        EmailPromotion::flushEventListeners();
+    }
+
+    // ★別経路の更新が残る (昇格が黙って上書きしない)
+    expect($user->fresh()?->email)->toBe('other@corp.example');
+    // ★トークンは消費済みである (一回使用は保たれる)
+    expect(EmailPromotion::query()->where('user_id', $user->id)->count())->toBe(0);
+    // ★昇格の監査は作られない (適用していないため)
+    expect(SecurityAuditEvent::query()
+        ->where('user_id', $user->id)
+        ->where('event_type', SecurityEventType::EmailChanged->value)
+        ->count())->toBe(0);
+    // ★同じトークンは再利用できない
+    $this->actingAs($user)
+        ->post(route('settings.email-promotion.confirm'), ['token' => $token])
+        ->assertSessionHasErrors('email_promotion');
+});
+
+/** CipherSweet で暗号化した email 値 (別経路の更新を模すための補助)。 */
+function encryptedEmailFor(string $email): string
+{
+    $row = User::getCipherSweetEncryptedRow();
+
+    /** @var array<string, mixed> $encrypted */
+    $encrypted = $row->encryptRow(['email' => $email, 'name' => 'x']);
+
+    /** @var string $value */
+    $value = $encrypted['email'];
+
+    return $value;
+}
diff --git a/tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php b/tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php
new file mode 100644
index 00000000..7ff824cf
--- /dev/null
+++ b/tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php
@@ -0,0 +1,73 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\EnterpriseSso\OidcDiscoveryService;
+use Illuminate\Support\Facades\Config;
+
+/*
+ * 企業 OIDC SSO の設定値の値域 (A1)。
+ *
+ * ★上限を置くのは「顧客の入力では広げられない」ことの担保ではなく、
+ *   **設定の書き換えで安全側の前提が黙って崩れないため**である
+ *   (例: 時刻ずれを 1 日に広げれば期限切れトークンが通る)。
+ */
+
+test('全整数の設定が正数である (0 と負数を弾く)', function (string $key): void {
+    expect(Config::integer($key))->toBeGreaterThan(0);
+})->with([
+    'enterprise-sso.discovery.connect_timeout_seconds',
+    'enterprise-sso.discovery.request_timeout_seconds',
+    'enterprise-sso.discovery.cache_ttl_seconds',
+    'enterprise-sso.discovery.jwks_refetch_min_interval_seconds',
+    'enterprise-sso.discovery.max_body_bytes',
+    'enterprise-sso.token.connect_timeout_seconds',
+    'enterprise-sso.token.request_timeout_seconds',
+    'enterprise-sso.token.max_body_bytes',
+    'enterprise-sso.id_token.leeway_seconds',
+    'enterprise-sso.id_token.max_subject_length',
+    'enterprise-sso.login_attempt.ttl_seconds',
+    'enterprise-sso.login_attempt.prune_chunk',
+    'enterprise-sso.email_promotion.ttl_seconds',
+]);
+
+test('接続の待ち時間は要求全体の待ち時間を超えない', function (string $group): void {
+    expect(Config::integer("enterprise-sso.{$group}.connect_timeout_seconds"))
+        ->toBeLessThanOrEqual(Config::integer("enterprise-sso.{$group}.request_timeout_seconds"));
+})->with(['discovery', 'token']);
+
+test('時刻ずれの許容は 300 秒を超えない (期限切れトークンを通さない)', function (): void {
+    expect(Config::integer('enterprise-sso.id_token.leeway_seconds'))->toBeLessThanOrEqual(300);
+});
+
+test('ログイン試行は 1800 秒より長生きしない', function (): void {
+    expect(Config::integer('enterprise-sso.login_attempt.ttl_seconds'))->toBeLessThanOrEqual(1800);
+});
+
+test('メール昇格の確認は 1 日より長生きしない', function (): void {
+    expect(Config::integer('enterprise-sso.email_promotion.ttl_seconds'))->toBeLessThanOrEqual(86400);
+});
+
+test('応答の大きさの上限が過大でない', function (): void {
+    expect(Config::integer('enterprise-sso.discovery.max_body_bytes'))->toBeLessThanOrEqual(1048576);
+    expect(Config::integer('enterprise-sso.token.max_body_bytes'))->toBeLessThanOrEqual(262144);
+});
+
+test('鍵の再取得の最小間隔が 1 秒以上ある (増幅を防ぐ)', function (): void {
+    expect(Config::integer('enterprise-sso.discovery.jwks_refetch_min_interval_seconds'))
+        ->toBeGreaterThanOrEqual(1);
+});
+
+test('subject の長さの上限が DB の列と一致する', function (): void {
+    // A2 の `enterprise_identities.subject` は varchar(255) + octet_length の CHECK である。
+    expect(Config::integer('enterprise-sso.id_token.max_subject_length'))->toBe(255);
+});
+
+test('鍵の再取得のロックの寿命が外向きの時間予算より長い', function (): void {
+    // ★取得の途中でロックが失効すると 2 人目が取り始めてしまい、抑止そのものが成立しない。
+    //   設定を変えて予算がロックの寿命を超えたら**この検査が先に赤くなる**。
+    $budget = Config::integer('enterprise-sso.discovery.connect_timeout_seconds')
+        + Config::integer('enterprise-sso.discovery.request_timeout_seconds');
+
+    expect(OidcDiscoveryService::JWKS_REFETCH_LOCK_SECONDS)->toBeGreaterThan($budget);
+});
diff --git a/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php b/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php
new file mode 100644
index 00000000..06adc011
--- /dev/null
+++ b/tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php
@@ -0,0 +1,341 @@
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
+test('重複した key_ops を拒否する (意味が無く malformed 寄りなので通さない)', function (): void {
+    $key = [...FakeIdentityProvider::publicJwk(), 'key_ops' => ['verify', 'verify']];
+    unset($key['use']);
+    $idp = (new FakeIdentityProvider)->withKeys([$key])->install();
+    $metadata = discoveryService()->fetchMetadata(issuerOf($idp));
+
+    expect(fn () => discoveryService()->fetchJwks($metadata))
+        ->toThrow(EnterpriseSsoAttemptRejectedException::class);
+});
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
diff --git a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
new file mode 100644
index 00000000..91fbf6f5
--- /dev/null
+++ b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
@@ -0,0 +1,549 @@
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
+     * 指定のメソッドの**呼び出しごと**に、名前付き引数が**特定のリテラルで**渡されているかを見る。
+     *
+     * ★ファイル単位の部分文字列一致にしない。「同じファイルに安全な呼び出しが 1 つあれば
+     *   緑になる」形だと、**同じファイルへ既定値の呼び出しを 1 行足すだけで見逃す**。
+     * ★**値まで見る**。名前付き引数の存在だけを見ると `followRedirects: true` が素通りする
+     *   (gate の名前が主張していることと、実際に保証していることが食い違う)。
+     * ★**静的に確定できない値は違反として返す** ((b) fail-closed)。
+     *   `followRedirects: $configured` / `! false` / `false || true` はどれも通さない —
+     *   通してよいのは**リテラルちょうど 1 トークン**の場合だけである。
+     *
+     * @param  array<string, string>  $sources
+     * @param  string  $literal  許すリテラル (例: `false`)
+     * @return list<string> 引数が無い / 値が違う / 値を確定できない呼び出しの記述子
+     */
+    public static function callsWithoutNamedLiteral(
+        array $sources,
+        string $method,
+        string $argument,
+        string $literal,
+    ): array {
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
+                $valuePosition = null;
+                for ($k = $i + 2; $k < $end; $k++) {
+                    if ($tokens[$k]['id'] === T_STRING
+                        && $tokens[$k]['text'] === $argument
+                        && ($tokens[$k + 1]['text'] ?? '') === ':'
+                        // ★`?:` (三項) や `::` と取り違えない
+                        && ($tokens[$k + 2]['text'] ?? '') !== ':'
+                    ) {
+                        $valuePosition = $k + 2;
+
+                        break;
+                    }
+                }
+
+                if ($valuePosition === null) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() に {$argument}: が無い";
+
+                    continue;
+                }
+
+                // ★値は**リテラルちょうど 1 トークン**であること。
+                //   次のトークンが `,` か `)` でなければ式であり、静的に確定できない。
+                $value = $tokens[$valuePosition]['text'] ?? '';
+                $after = $tokens[$valuePosition + 1]['text'] ?? '';
+
+                if (strtolower($value) !== strtolower($literal) || ($after !== ',' && $after !== ')')) {
+                    $violations[] = "{$path}:{$tokens[$i]['line']}: {$method}() の {$argument}: が {$literal} でない";
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
index 00000000..650f22fd
--- /dev/null
+++ b/tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php
@@ -0,0 +1,157 @@
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
+test('危険な fetch() の 4 形をすべて落とし、リテラルの false だけを通す (負例)', function (): void {
+    $violations = EnterpriseSsoSourceScanner::callsWithoutNamedLiteral(
+        scannerFixture('RedirectFollowingSample'),
+        'fetch',
+        'followRedirects',
+        'false',
+    );
+
+    // 見本の 5 呼び出しのうち、安全な 1 件だけを通す
+    expect($violations)->toHaveCount(4);
+
+    $combined = implode("\n", $violations);
+    // 引数が無い形
+    expect($combined)->toContain('followRedirects: が無い');
+    // 値が false でない形 (明示 true / 動的 / 式)
+    expect(substr_count($combined, 'followRedirects: が false でない'))->toBe(3);
+});
+
+test('リテラルの false ちょうどの呼び出しは通す (正例)', function (): void {
+    $sources = ['sample.php' => <<<'PHP'
+        <?php
+        final class Sample
+        {
+            public function __construct(private Client $pinned) {}
+
+            public function run(): void
+            {
+                $this->pinned->fetch($request, $deadline, followRedirects: false);
+            }
+        }
+        PHP];
+
+    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
+        ->toBe([]);
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
+    expect(EnterpriseSsoSourceScanner::callsWithoutNamedLiteral($sources, 'fetch', 'followRedirects', 'false'))
+        ->toBe([]);
+});
```

---

## 再確認をお願いしたい点

1. **第 2 段の再ロック**が、指摘された競合窓 (第 1 段の commit 〜 適用の間) を実際に閉じているか。
   また「弾いた場合もトークンは消費済みのまま」という帰結が妥当か。
2. **G2-4 の値検査**が、指摘された 3 方向 (引数なし / `true` / 静的に確定できない値) を
   すべて落としているか。`,` か `)` が続かなければ式とみなす判定で十分か。
3. `$lock->release()` を **best-effort にした判断**への意見
   (取得の失敗は fail-closed のまま。解放の失敗で正しく取れた結果を捨てるのは
   可用性を下げるだけで安全側ではない、という理由である)。
4. その他、対応によって**新しく生まれた欠陥**が無いか。

全体判定を `APPROVED` または `CHANGES_REQUESTED` で明示してほしい。
