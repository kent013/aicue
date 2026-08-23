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
