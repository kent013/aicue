# 対応マトリクス: conceptual-review Round 1

Codex 判定: CHANGES_REQUESTED ([Critical] 0 / [Warning] 5 / [Suggestion] 7)

## [Warning] 1. T090-b: `/billing` だけでは「気づいたら止まっていた」は解けない

- 判断: **対応する (部分的に。範囲を限定して受け入れる)**
- 根拠: 指摘は正しい。実際に止まる地点は `ProjectService:34` (プロジェクト作成) と
  `TakeUploadService:61` (アップロード) の 2 箇所で、いずれも
  `QuotaExceededException` → `back()->with('error', ...)` に落ちる
  (`bootstrap/app.php` の `$exceptions->render(QuotaExceededException ...)`)。
  現在の文言は
  「現在のプランの上限 (X: N) に達しています。プランのアップグレードをご検討ください。」で、
  **回復先の画面名を含んでいない**。
- 対応内容: `QuotaExceededException::forLimit()` の文言に**回復先を明示**する
  (「現在のご利用状況と上限は「お支払い」画面で確認できます。」)。
  URL 文字列や新しい構造化 flash 機構は作らない (flash は素の文字列であり、
  リンク化には新機構が要る = 今必要ではない)。文言は Feature テストで固定する。
  `/billing` は課金ゲートの構造的 allowlist 内なので未契約組織からも到達できる (裏取り済み)。

## [Warning] 2. AGENTS.md へ判断理由を持ち込むな

- 判断: **一部反論する / 一部対応する**
- 根拠: 「理由書きを AGENTS.md に積むな」は同意する。ただし AGENTS.md ドメイン固有規約 #3 は
  **経路 C の担当実装を名指しする正本**であり、現状は
  「`App\Http\Responses\Fortify\LogoutResponse` の `Inertia::clearHistory()`」だけを発行契機として
  書いている。施策 1 で発行契機が「認証失敗 (`AuthenticationException`)」にも広がるため、
  **更新しないと正本が事実と食い違う**。規約の正確性 > 編集回避。
- 対応内容: AGENTS.md #3 の変更は**発行契機の 1 句追記のみ**に限定する
  (理由・却下した代案・再検討条件は書かない)。理由の恒久化先は
  `docs/supported-browsers.md` / `bfcache-guard.ts` docblock / `LogoutResponse` docblock に限定する。

## [Warning] 3. `AuthenticationException` フックの適用範囲が粗い

- 判断: **対応する**
- 根拠: 指摘のとおり設計文が条件を書いていなかった。ソースで裏取りした結果、
  guards 配列による面の判別は**信頼できない**:
  web の `auth` middleware は `[null]` (`Authenticate::authenticate` の空配列 → `[null]` 変換)、
  `AuthenticateSession::logout()` は `['web']`、Filament の `Authenticate` は override で
  `[null]` 変換を通らず `[]` になる。Filament 実装詳細に依存する判別は将来壊れる。
- 対応内容: 条件を**明文化**し、判別を 2 つに絞る:
  1. `$request->expectsJson()` が真なら積まない (API / MCP。Inertia 応答が来ずフラグが宙に浮く)
  2. `$request->hasSession()` が偽なら積まない (stateless 経路では積めない)
  Filament (`/admin`) の認証失敗では積まれる。これは**安全側の偽陽性として明示的に許容**する
  (影響は「Inertia 面の履歴が 1 回再キーされ、戻るがサーバ再取得になる」だけで、
  ログアウト経路で既に受け入れている UX コストと同種)。
  負のコントロールとして **JSON (`expectsJson`) の 401 ではフラグが積まれない**テストを必須にする。

## [Warning] 4. T089-b の期待効果を「認識後に限定」と前面化せよ

- 判断: **対応する**
- 根拠: 文言の問題であり指摘は妥当。「履歴復元を塞ぐ」と読めると保証を過大に書くことになる。
- 対応内容: 期待効果と docs 側要約を
  「**認証失敗を契機に、以後の戻るによる復元を無効化する**」へ言い換え、
  「一度もサーバと話さないまま戻る場合は塞がらない」を対で書く。

## [Warning] 5. business / enterprise の quota 定義欠落を open question のまま流すな

- 判断: **対応する (ただし TODO 登録ではなく機械的不変条件で追跡する)**
- 根拠: `docs/TODO.md` は本フェーズの責務外 (登録は後続フェーズ)。かつ TODO は腐る。
  実害は「quota 定義の無い plan_code が organizations.plan_code に入ると無制限扱いになる」
  (`QuotaService::limits` の `?? []`) であり、**その条件が成立した瞬間に落ちるテスト**が
  最も強い追跡手段になる。
- 対応内容: 既存の `tests/Architecture/QuotaKeyConfigInvariantTest` に
  「**`PlanSeeder` が投入する plan code は必ず `config/quota.php` の plans に entry を持つ**」を追加する
  (現状 personal/starter/standard で green、business/enterprise を seed した瞬間に red)。
  `PlanCode` enum 全 case との一致は要求しない (enterprise は問い合わせ営業で
  Plan 行も plan_prices も持たず、plan_code が付く経路が無いため)。
  open question としても残す (製品判断が要るため)。

## [Suggestion] 群

- T089-a 許容 / T089-b popstate 却下 / T090-a 現状維持 / T090-c コード変更なし /
  T090-d Plan Factory 不作成: いずれも妥当との評価。**変更しない**。
- 「共有端末の運用上の補完策も一文入れると判断が強くなる」: 対応する
  (`docs/supported-browsers.md` の受容記述に「共有端末ではブラウザを閉じる運用を案内する」を 1 行)。
- DTO / TS shape 同期・enum 網羅の型安全性: 既に設計に含まれる。**変更しない**。
