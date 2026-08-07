# 対応マトリクス: design-review Round 5

> ⚠ 本ラウンドは app-design SKILL.md の**上限 5 ラウンド目**である。
> 以下の対応は設計へ反映済みだが、**Codex による再レビューは行っていない**
> (final_verdict は Round 5 の実判定どおり CHANGES_REQUESTED を報告する)。

## S4 [Critical] Stripe の preflight 1 / 2 の「配置」を赤化できるテストシームが無い

- 判断: **対応する (設計変更)**
- 根拠: 完全に妥当。検証した結果:
  - `duringCreateInvoice` は `createAutoRechargeInvoice()` の**内側**で発火するため、
    **preflight 1 より後**である。preflight 1 を削除しても結果 (invoice 作成 → attach 0 行 → 終端)
    は変わらず、M16 相当が赤にならない。
  - preflight 2 を通すには attach 成功後に terminal 化する必要があるが、
    `duringCreateInvoice` は attach より前に発火するため attach が 0 行になり preflight 2 へ到達しない。
    既存 invoice 経路 (`stripe_invoice_id !== null`) では冒頭の Pending guard と preflight 2 の間に
    注入点が 1 つも無い。
  - Manual 側は事情が違い、**behavioral に赤化できる**:
    解析は `onAttempt` (LLM 呼び出し n 回目で terminal 化 → n+1 回目が抑止される)、
    レンダは `duringCompose` (compose 中に terminal 化 → upload が抑止される)。
    したがって不足しているのは **Billing だけ**である。
- 対応内容: Codex 提案の 1 番目 (「ownership verifier を小さな注入可能 collaborator にする」) を採る。
  **`app/Support/JobExecution/AttemptOwnershipPreflight`** を新設し
  (`stillPending(TicketAutoRechargeAttempt $attempt, ExternalCallKind $call): bool`)、
  `AutoRechargeService` のコンストラクタで受け取る。
  - **非 final クラス**にする。これは `App\Services\Render\RenderObjectStorage` と同じ作法で、
    「fake が override して差し替える前提」であることを docblock に書く
    (interface を新設しない = AGENTS.md 思考原則 2)。
  - テスト側は `Tests\Support\FakeAttemptOwnershipPreflight` (`$denyKinds` / `$calls` を持つ) を
    `app()->instance()` で差し込む。
  - これで **本番コードにテスト専用 closure を足さずに** 次が成立する:
    - `denyKinds = [StripeInvoiceCreate]` → `createdInvoices === []` を期待。
      **create 直前の `stillPending()` を削除すると invoice が作られてしまい赤化する** (M16)。
    - `denyKinds = [StripeInvoicePay]` → `payCalls === []` かつ invoice が終端される、を期待。
      **pay 直前の `stillPending()` を削除すると pay が走り赤化する** (M17)。
  - `AutoRechargeService::stillPending()` は廃止し (後方互換の並走を残さない。AGENTS.md 思考原則 3)、
    所有権喪失ログもこの collaborator が出す (Billing 側ログ schema の所在が 1 箇所になる)。
  - S6 の目録の `PreflightCheckpoint` は
    `AttemptOwnershipPreflight::stillPending` / `ReturnsBoolean` を指す
    (`verifierClass` を持つ型モデルなので変更なしで表現できる)。
  - Manual 側は**変更しない** — 既に behavioral に赤化できるため、
    collaborator を足すのは利益のない churn になる (AGENTS.md 思考原則 2)。
    この非対称性の理由を設計に明記する。

## S4 [Warning] 新設メソッドが変更箇所に列挙されていない

- 判断: **対応する**
- 根拠: 事実。実装漏れ防止。
- 対応内容: 変更箇所に `terminateUnattachedInvoice()` / `terminateInvoiceBestEffort()` /
  コンストラクタへの `AttemptOwnershipPreflight` 追加を列挙する。

## S7 [Warning] Stripe については「Feature テストが配置を保証する」と言えない

- 判断: **対応する**
- 根拠: 上記のとおり。collaborator 導入後は成立する。
- 対応内容: mutation 表に **M16 / M17** を追加し、対応表の分担記述を
  「Billing は注入可能な preflight collaborator の fake が配置を赤化する」まで具体化する。

## S6 [Suggestion] 期待集合側にも同一 `ExternalCallKind` の重複検査を入れる

- 判断: **対応する (安価)**
- 根拠: 妥当。期待値と checkpoint の両方を重複登録した場合に読みやすく失敗する。
- 対応内容: `jobDedupRequiredExternalCalls()` の各リストに重複が無いことを検査するケースを追加。
