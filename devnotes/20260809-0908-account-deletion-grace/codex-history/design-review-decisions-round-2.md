# 対応マトリクス: design-review Round 2

## [Critical] B0: `addDaysNoOverflow()` は 30 暦日後の表現として不適切

- 判断: **対応する (指摘が正しい。実コードで裏付けを取った)**
- 根拠: `tests/Architecture/CarbonOverflowArithmeticGateTest.php` の禁止語彙を実読した:
  `addmonth(s)` / `submonth(s)` / `addyear(s)` / `subyear(s)` / `addquarter(s)` / `subquarter(s)` の
  **月・年・四半期だけ**であり、**日の加減算は母集団に入っていない**。
  AGENTS.md の実装規約も「月 / 年 / 四半期の加減算は暗黙 overflow メソッドを禁止する」と書いており、
  日は対象外である。さらに Carbon の `NoOverflow` の意味は「上位単位 (月) を越えない」であり、
  日加算に適用すると**月末で丸められて 30 日未満になりうる** — 猶予期間の意味が壊れる。
- 対応内容:
  - `AccountDeletionGrace::purgeAfter()` を **`addDays(self::days())`** に修正した。
  - `AccountDeletionGraceConfigTest` から「`addDays(` 不使用」検査を**削除**した (誤った検査だった)。
  - 代わりに **behavioral** で固定する:
    - `2026-01-31` の 30 日後が `2026-03-02` (月末で丸められない)
    - うるう年の 2 月をまたぐ 30 日後 (`2028-02-10` 起点)
    - **要件は「暦日 30 日」**であることを明記し、アプリのタイムゾーン設定下で
      期待するローカル時刻になることを固定する
  - `BillingRetention::threshold()` の **`subYearsNoOverflow()` は正しい**ので維持する
    (年は gate の母集団に入る)。

## [Critical] B6: `fresh() ?? $notifiable` は削除済み user に送ってしまう

- 判断: **対応する (指摘が正しい。実装契約とコードが逆だった)**
- 根拠: `fresh()` が null (= 執行済みで user 行が無い) のときに、**シリアライズ済みの
  削除前スナップショット**へフォールバックしていた。その状態は予約値と一致するので
  **メールが送られる**。「執行済みなら送らない」という docblock と真逆の実装である。
- 対応内容: フォールバックを削除し、`fresh()` が `User` でなければ **`[]` を返す** (fail-closed) 形に直した。
  テスト **「執行済み (user 削除済み) の queued notification は送られない」**を追加した。

## [Warning] A2: 2 列同時の不変条件をアプリ層だけで守るのは弱い

- 判断: **対応する**
- 根拠: 妥当。監査証跡として扱うなら DB 制約が適切で、将来の別コマンドや直接 UPDATE でも守られる。
- 対応内容: migration に **PostgreSQL の CHECK 制約**を追加した
  (両方 null または両方 non-null)。migration テストで**片側だけの INSERT/UPDATE が拒否される**ことを固定する。

## [Warning] B4: recent-auth 3 route と `A ⊆ U` の両立が不明

- 判断: **対応する (設計文の記述が不正確だった。allowlist は正しい)**
- 根拠: `routes/web.php` を実読した結果、**`recent-auth.confirm` / `recent-auth.status` /
  `recent-auth.password` は `Route::middleware(['auth', 'verified'])->group(...)` の中**にある
  (L186-197 付近)。したがって母集団 `U` に入り、`A ⊆ U` は成立する。
  設計文で「認証回復系は group の外」と一括で書いたのが不正確だった — group の外にあるのは
  **Fortify / Passkeys が登録するログイン・パスワード再設定・メール確認・2FA challenge・
  passkey ログイン**であり、**recent-auth (step-up) は group の中**である。
- **allowlist からは外さない**。理由: `organizations.transfer-ownership` は
  `recent-auth` middleware を持つ**ブロッカー解消経路**であり、step-up 画面へ到達できないと
  移譲ができず**詰む**。取消自体に step-up は不要だが、解消経路には必要である。
- 対応内容: 設計文の記述を「group の外にあるもの / 中にあるもの」で分けて書き直し、
  gate 検査 7 に **`recent-auth.*` 3 本が `U` の中にあること**も加えた
  (group の外へ移されたら fail = allowlist が死に登録になるのを防ぐ)。

## [Warning] B6: 「at-most-once」の記述と再試行許容が矛盾 / inventory 分類が不正確

- 判断: **対応する (両方)**
- 根拠: 妥当。外部メールサービスが受理した後に worker が完了記録前で停止すれば retry で再送されうる。
  また `JobDedupGuarantee` は「job 実行の dedup を保証する」分類であり、
  本設計が保証しているのは「**二重 POST から二重 dispatch しないこと**」であって job 実行の dedup ではない。
- 対応内容:
  - 記述を **「予約操作からの job 生成は最大 1 件。job の実行と外部配送は重複しうる best-effort」**に
    書き直した (「at-most-once」という一語で潰さない)。
  - `JobExecutionDedupInventoryTest` の分類方針を修正した:
    **`JobDedupGuarantee` には登録しない**。実装時に既存 enum を実読し、
    **免除側 (`JobDedupExemption`) + 30 文字以上の根拠**
    (「通知の重複配送は業務不変条件を壊さない。取り消せない外部副作用ではなく、
    予約状態は via() の再確認で守られる」) で登録する。
    既存 case に合うものが無ければ新 case を根拠つきで追加する。

## [Warning] C1b/C1d: 起算列が null の行の「古い」判定が未定義

- 判断: **対応する (指摘が正しい。抽出条件が書けない状態だった)**
- 根拠: 起算列が null なら**その列だけでは「古い」を判定できない**。
  `failClosed` に計上する条件が定義されていなかった。
- 対応内容: `BillingRetentionTarget` に **`anomalyClockColumn(): ?string`** を追加した。
  - **正規の保持起算点** = `clockStartColumn()`
  - **起算点 null を異常として検出し始める補助時計** = `anomalyClockColumn()`
  - `failClosed` の条件は **`{clockStart} IS NULL AND {anomalyClock} <= threshold`**。
    例: 未処理 webhook = `processed_at IS NULL AND created_at <= threshold`。
  - **`Subscription` / `SubscriptionItem` は `anomalyClockColumn()` が `null`**。
    `ends_at IS NULL` は**正常な起算未到来** (継続中の契約) であり異常ではないため、
    異常検出の対象から明示的に除外する。
  - **補助列も schema gate で実在確認**する (`clockStartColumn()` と同じ照合)。
  - **境界テスト**を target ごとに追加する (補助時計が閾値の 1 秒前 / 1 秒後)。

## [Suggestion] `isDue()` は Carbon API の方が意図と型が明確

- 判断: **対応する**
- 対応内容: `$this->purgeAfter->lessThanOrEqualTo($now)` に書き換え、
  `requestedAt !== null` の検査も明示した (PHPStan の narrowing も効く)。

## [Suggestion] `idempotency_key` の `source === null` 表現と日時の正規化

- 判断: **対応する**
- 対応内容: キーの形を固定した:
  `carry_forward:{orgId}:{source ?? 'null'}:{expiresAt?->utc()->format('Y-m-d\TH:i:s\Z') ?? 'null'}:{through->utc()->format('Y-m-d\TH:i:s\Z')}`。
  **`null` は明示トークン `'null'`** で表す (空文字との衝突を避ける)。日時は **UTC 正規化**。
  「同一 group の再実行で同じキーになる」ことをテストで固定する。
