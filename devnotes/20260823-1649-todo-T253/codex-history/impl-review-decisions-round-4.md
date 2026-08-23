# 対応マトリクス: impl-review Round 4

Codex の全体判定は `CHANGES_REQUESTED`。**承認を阻む 2 件**と、
**阻まない項目 (Suggestion) 4 件**に分けて返ってきた。

**承認を阻む 2 件は対応した**。Suggestion は 4 件中 3 件を対応し、1 件を見送った
(見送りの理由は下記)。反論は 0 件である。

---

## [承認を阻む] 二段の公開により、`confirm()` の token 検証と本人結合を迂回できる

- **判断**: 対応する
- **根拠**: **指摘が正しい**。Round 3 の対応で `consumeToken()` / `applyConfirmedEmail()` を
  `public` にしたが、これは**テスト容易性のために本番の操作面を広げた**ものだった。
  `applyConfirmedEmail($user, VerifiedEmail::afterConfirmation('attacker@example.com'))` は
  promotion 行も指紋照合も期限も本人結合も要らずに通る。
  `VerifiedEmail` は「トークンを正当に消費した結果」であることを表せない値なので、
  capability としても機能しない。指摘のとおり
  **「2 段構成がサービス内部の契約であること」と「各段を誰でも個別に呼べること」は別**である。
- **対応内容**: Codex が挙げた選択肢のうち**「継ぎ目だけを協力者として外に出す」**を採った。
  - `App\Contracts\Auth\EmailPromotionStageBoundary` を新設。メソッドは
    `afterConsume(User $user): void` の 1 本だけである。
  - 本番実装 `App\Services\Auth\InertEmailPromotionStageBoundary` は**何もしない**
    (`Inert` = 不活性、という名前で「処理が足されたらレビューで目に入る」形にした)。
    `AppServiceProvider::register()` で bind する。
  - **`consumeToken()` / `applyConfirmedEmail()` を `private` に戻した**。
    公開の入口は `confirm()` 1 本だけである = 操作面は Round 2 以前と同一に戻った。
  - `confirm()` は第 1 段が戻った直後に `$this->stageBoundary->afterConsume($user)` を呼ぶ。
    継ぎ目が**できるのは「その時点で任意のコードが走る」ことだけ**であり、
    メールを書くこともトークンを消費することもできない。
  - 検査は `Tests\Support\Auth\InterferingEmailPromotionStageBoundary` を container へ
    差し込み、**入口は `confirm()` のまま**割り込みを起こす。
- **回帰テスト**:
  - `EmailPromotionTest`「本番の継ぎ目は何もしない (公開入口は confirm() 1 本のまま)」 —
    container が解決するのが `Inert…` であることと、**2 段が `private` であること**を
    reflection で固定した (公開へ戻したら赤くなる)。
  - 既存の割り込みテストと正のコントロールを `confirm()` 経由へ書き直した。

---

## [承認を阻む] 走査器の深さ管理が `T_ATTRIBUTE` / 文字列内挿の開始 token を扱わない

- **判断**: 対応する
- **根拠**: **指摘が正しい**。`php -r` で token 列を実測して確認した:
  - attribute の開きは `T_ATTRIBUTE` (text は `#[`) であり、素の `[` ではない。
    開きとして数えないのに閉じの `]` だけ数えるので、**そこから深さが 1 つずれる**。
  - `"${x}"` の開きは `T_DOLLAR_OPEN_CURLY_BRACES` (text は `${`)。同じくずれる。
  - `"{$x}"` の `T_CURLY_OPEN` は **text が `{`** なので旧実装でも偶然合っていた。
    ただし**偶然に依存しない**ので id でも明示した。
  旧実装 (Round 4 修正前) をそのまま再現して走らせ、
  **attribute 形も `${}` 形も違反 0 件 = 見逃し**になることを実測した (下記「実測」)。
- **対応内容**: 整数のカウンタを**区切りの stack** へ替えた。
  - 開きの判定を `closerForOpener()` に集約し、`T_ATTRIBUTE` → `]`、
    `T_DOLLAR_OPEN_CURLY_BRACES` / `T_CURLY_OPEN` → `}`、`(` `[` `{` → 対応する閉じ、とした。
  - 閉じでは stack を pop し、**期待と食い違えば「読み切れない」として落とす**
    ((b) fail-closed)。整数カウンタでは `([)]` のような壊れた対応を検出できない。
  - 引数を読み切っても開きが残っている形も「読み切れない」にした。
  - docblock に**保証しないもの**を書いた — first-class callable `fetch(...)` は
    引数を確定できないので**違反側**に落ちること、可変メソッド名は走査対象に入らないこと。
- **回帰テスト** (指摘された 3 方向 + 正例を揃えた):
  - 見本に 4 形を追加 (attribute の後に入れ子 = 違反 / `${}` の後に入れ子 = 違反 /
    attribute つきで外側 false = 正例 / 内挿 2 形つきで外側 false = 正例)。
    一括検査を **11 呼び出し / 違反 7 件 / 正例 4 件**へ更新した。
  - 自己検査に 6 本追加 (attribute 負例 / 内挿負例 / attribute + 内挿の正例 /
    閉じの種類が食い違う形 / 閉じ切らない形 / first-class callable)。

---

## [Suggestion] 割り込みテストの docblock は「commit 済み」ではなく「level が戻った」と書くべき

- **判断**: 対応する
- **根拠**: 指摘が正しい。`RefreshDatabase` の外側のトランザクションがあるので、
  第 1 段が閉じるのは**実際には savepoint** である。
  「本番の独立トランザクションの commit と同じ可視性を証明した」とは言えない。
- **対応内容**: 文言を「第 1 段が開いた層をすべて閉じ、呼び出し前の level へ戻った」に直し、
  さらに Suggestion のとおり**継ぎ目で `DB::transactionLevel()` を実測**して
  呼び出し前の値と一致することを固定した (「段を抜けた」を主張ではなく測定にした)。

---

## [Suggestion] `flushEventListeners()` は将来 trait / observer が足された瞬間に汚染へ変わる

- **判断**: 対応する
- **根拠**: 指摘が正しい。現状のモデル構成では実害が無いことは確認済みだが、
  **その安全性はモデル定義に依存していて、依存していることがコードから見えない**。
- **対応内容**: 後始末を `SecurityAuditEvent::flushEventListeners()` (そのモデルの
  **全 event** を静的に削除する) から **`Event::forget('eloquent.created: '.SecurityAuditEvent::class)`**
  へ替えた。**張った 1 つの event 名だけ**を忘れさせるので、
  モデルに trait / observer が足されても他の購読を巻き込まない。

---

## [Suggestion] first-class callable `fetch(...)` の扱いを docblock に明記する

- **判断**: 対応する
- **対応内容**: 走査器の docblock「保証しないもの」に 1 行書き、
  **自己検査でも固定した** (書いただけにしない)。

---

## [Suggestion] `recordOrFail()` の使用者集合を Architecture gate で exact-fit に固定する

- **判断**: **見送る** (このラウンドでは作らない)
- **根拠**: Codex 自身が「今回の T253 実装の正確性は実挙動テストで既に固定されているため、
  **これは承認阻害とはしない**」と明示している。そのうえで見送る理由は 2 つある。
  1. **本リポジトリで gate を新設する費用が小さくない**。AGENTS.md
     「走査器・gate を新設・変更するときに同じ PR で揃える 4 点」は、負例と正例・
     解決できない形を落とす分岐・母集団の空振り検査・docblock の 4 点を要求する。
     可変メソッド名 (`$r->{$m}()`) を保証範囲から外すなら、利用側 gate は
     (b) に従って**検出力の主張をその構文を除く形へ狭める**必要もある。
  2. **合議の残りが 1 ラウンドしかない**。この状況で新しい走査器を足すと、
     **その走査器自体が未レビューのまま残る**。Round 2・Round 3・Round 4 は
     いずれも「新しく足した走査器」に指摘が出ており、同じことを繰り返す確率が高い。
- **代わりに置いたもの**: `recordOrFail()` の docblock に**許される 2 つの呼び出し元を名指し**し、
  書き分けの軸 (「確定した変更を同じトランザクションで記録する」か「試行を観測するだけ」か) を
  正本として書いた (Round 4 で `APPROVE` を得ている)。
  gate 化は**別 TODO の候補**として残す。

---

## Round 4 で APPROVE / 承認阻害でないとされた点 (変更しない)

- メール更新と監査の原子性 (`recordOrFail()` を第 2 段のトランザクションの中で呼ぶ)
- 通常の括弧・配列・波括弧に対する深さ判定と、その負例・正例の対
- 監査ロールバックのテストの壊し方 (`created` で挿入後に壊し、行の存在を確認してから例外)
- `SecurityEventRecorder` の docblock の書き分けの軸
- `OidcDiscoveryService::release()` の best-effort + 固定文言の warning
