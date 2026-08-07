# 対応マトリクス: design-review Round 3

## S1 [Warning] `LOG_EVENT` の「キー集合固定」と Billing の 8 キー (attempt_ulid) が矛盾

- 判断: **対応する**
- 根拠: 妥当。docblock が「キー集合固定」と書きながらテスト計画は追加キーを許容しており、自己矛盾。
- 対応内容: 契約を Codex 提案の後者へ統一 —
  **「必須 7 キーを共通 schema とし、PII-free なドメイン固有キーの追加は可」**。
  docblock・テスト計画・S7 対応表の文言を「**必須**キー集合固定」へ揃える。

## S4 / S6 [Critical] auto-recharge の外部呼び出しは 2 つあるのに目録は pay しか登録していない

- 判断: **対応する**
- 根拠: 完全に妥当。`StripeInvoiceCreate` の preflight を消しても gate が green のままで、
  「読み手が目録からすべての外部呼び出しを辿れる」という説明も成立していなかった。
- 対応内容: `GuaranteeEntry::$preflight` を
  **`$preflights: non-empty-list<PreflightRequirement>`** へ変更する。
  - auto-recharge は `StripeInvoiceCreate` / `StripeInvoicePay` の **2 件**を登録
  - 外部呼び出しを持たないジョブは `[new NoExternalCall(…)]` の 1 件
  - gate に「**同じ `ExternalCallKind` を重複登録していない**」検査を追加
  - gate に「`NoExternalCall` と `PreflightCheckpoint` を**混在させない**」検査を追加
    (「外部呼び出しなし」と「外部呼び出しあり」は同時に主張できない)

## S4 [Warning] 「Failed なら terminateAndFail が終端済み」の前提を behavioral に固定すべき

- 判断: **対応する**
- 根拠: 妥当。実装を確認すると `terminateAndFail()` は
  `if (! $this->tryTerminateInvoice($attempt)) { return; }` の後にのみ
  `transitionToTerminal(Failed)` を呼ぶため前提は成立するが、
  **その順序が変わったら `terminateInvoiceAfterOwnershipLost()` の Canceled 限定が壊れる**。
  前提はテストで固定すべきである。
- 対応内容: S4 のテスト計画に 2 件追加 —
  「`terminateInvoice` が失敗したら attempt は **Pending のまま** (Failed へ遷移しない)」
  「Failed へ遷移した attempt は invoice 終端済みである」。

## S5 [Critical] `ExecuteAutoRechargeAttemptJob` の import が無い

- 判断: **対応する**
- 根拠: 事実。掲載コードは「変更後コード」として扱われるため、そのままでは解決に失敗する。
- 対応内容: `use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;` を追記。

## S6 [Critical] `DatabaseUniqueConstraint` の適用条件が台帳の冪等キーと一致しない

- 判断: **対応する**
- 根拠: 完全に妥当。現 case は「partial unique index が **2 回目の起票**を拒否する」であり、
  `recharge:{invoiceId}` は「同じ冪等キーによる **2 回目の効果確定 (台帳計上)**」を拒否するもので、
  対象が違う。複数 mechanism 化しても case 自体の適用条件が不一致のままだった。
- 対応内容: Codex が「概念を混ぜない原則からは明確」とした後者を採る —
  **`JobDedupGuarantee::IdempotentLedgerKeyUniqueConstraint` を別 case として追加**する
  (AGENTS.md 思考原則 4「別物の概念を似ているからで統合しない」)。
  `DatabaseUniqueConstraint` の適用条件は「起票の拒否」のまま狭く保つ。

## S6 [Critical] gate の掲載コードに import が不足

- 判断: **対応する**
- 根拠: 事実。
- 対応内容: gate の掲載コードの import 節に
  `ExecuteAutoRechargeAttemptJob` / `AutoRechargeService` / `PreflightControlFlow` /
  `JobDedupGuarantee` を追加し、「要点のみ掲載だが import は実装時にこの一覧を満たすこと」と明記。

## S6 [Warning] 固定 event literal の検査がシングルクォートしか見ていない

- 判断: **対応する**
- 根拠: 妥当。`"job_ownership_lost"` で gate を回避できる。
- 対応内容: single / double quote の **4 パターン**を検査する。
  mutation 表に **double quote 版 (M8b)** を追加する。

## S6 [Warning] `mechanisms` の重複検査が順序依存で失敗メッセージが読みにくい

- 判断: **対応する (承認条件外だが安価)**
- 根拠: 妥当。重複値そのものを出せば原因が即わかる。
- 対応内容: `array_diff_assoc($values, array_unique($values))` で重複値を算出して表示する形に変更。

## S7 [Warning] 対応表が単一 checkpoint 前提。「Stripe を呼ばず invoice を終端する」は不正確

- 判断: **対応する**
- 根拠: 妥当。invoice 終端自体が Stripe 呼び出しである。
- 対応内容: 対応表の当該行を
  「所有権喪失時に **invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する**」へ改め、
  preflight 行を「登録された**すべての** checkpoint が実在し、制御方式に一致する戻り型を持つ」へ更新。
