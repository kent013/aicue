# 対応マトリクス: conceptual-review Round 1

## [Critical] 初回パスワード設定 route の受入条件が未明文化 (観点 5)
- 判断: 対応する
- 根拠: 認証手段を増やす操作の脅威 (セッション奪取からの永続化) に対し、
  「recent-auth を付ける」だけでは何が保証されるか読み手に伝わらない。
- 対応内容: 概念設計に「施策 3 の受入条件 (6 項目)」を追加。既存機構が既に満たしている項目
  (TTL = `RecentAuthWindow` / session 束縛 = `RecentAuthState` が session に記録し
  `$request->user()` を対象にする) と、本批で新規に満たす項目 (lock 下の
  `hasPassword()===false` 再確認 / `SecurityEventType::PasswordSet` 記録 / 他セッション失効) を
  区別して明記した。

## [Critical] inventory gate が文字列ベースだと `status={undefined}` を見逃す (観点 7)
- 判断: 対応する
- 根拠: 妥当。「渡し忘れ」は検出できても「間違った値を渡す」は検出できない。
- 対応内容: gate の検査を 3 段から 4 段に強化。
  (a) 呼び出し側ファイル集合の deny-by-default、(b) `status={recentAuthStatus}` の
  **識別子まで固定**した完全一致 (任意式を許さない)、(c) 旧 prop 名の不在、
  (d) 各呼び出し側が `withRecentAuth` を import し `onStale` で `recentAuthStatus` に格納していること。
  加えて component 側は `status: RecentAuthStatus | null` を必須 prop として宣言する。

## [Warning] DTO / JsonResource 境界が曖昧 (観点 2)
- 判断: 反論する (+ 明記して誤読を防ぐ)
- 根拠: `/recent-auth/status` は既に `RecentAuthStatusDto` → `RecentAuthStatusResource` 経由で
  返しており `response()->json()` の直書きは無い (`ConfirmRecentAuthController::status()`)。
  TS 側 `RecentAuthStatus` (resources/js/lib/recent-auth.ts) も全 field 非 optional で、
  `fetchRecentAuthStatus()` が欠損を既定値で埋めて型を確定させている。
- 対応内容: 「そのまま渡す」の意味を「**サーバ contract の shape を分解せずに 1 個の型として運ぶ**」
  と明記し、サーバ側 DTO/Resource が正本である旨を概念設計に追記した (契約変更は無い)。

## [Warning] `settingsUrl` 削除の根拠が弱い (観点 2)
- 判断: 対応する (削除方針は維持し、根拠と波及を明記)
- 根拠: `LoginMethodRequiredResource` は `EnsureLoginMethodRemains::reject()` の
  `expectsJson()` 分岐でのみ返る内部 XHR contract であり、`routes/api.php` には露出していない。
  消費者は 0 件 (`grep settingsUrl resources/js` = 0)。しかも指す先 (`settings.security`) には
  パスワード設定 UI が無く、フロントのハードコード (`/settings`) とも食い違う = phantom 契約。
  「正しい URL に直して段階的廃止」は AGENTS.md 思考原則 3 (後方互換の並走を残さない) に反する。
- 対応内容: 概念設計に「内部 XHR 専用 contract であり公開 API ではない」ことと、
  波及テスト (`tests/Feature/Auth/LoginMethodRetentionTest.php:78`) の更新を明記した。

## [Warning] `PasswordCredentialService` の責務分離が曖昧 (観点 3)
- 判断: 対応する
- 根拠: 妥当。共有すべきは「確定後処理」であり、検証前提 (current_password の有無) は別。
- 対応内容: 公開 API を `setInitial(User, string $plain)` / `change(User, string $plain)` の 2 本にし、
  private `apply()` が hash 保存・監査記録・他セッション失効・DB session 行削除を担う設計に明記。
  `current_password` の検証は Fortify 契約側 (`UpdateUserPassword` の Validator) に残す。

## [Warning] transaction 境界が未定義 (観点 3)
- 判断: 対応する
- 根拠: 妥当 (AGENTS.md 実装規約「transaction は Service 内」)。
- 対応内容: `setInitial()` が Service 内 `DB::transaction` + `lockForUpdate()` で
  再確認から保存まで完結する旨を明記した。

## [Suggestion] 6 画面の status 取得元を固定せよ (観点 4)
- 判断: 対応する
- 根拠: gate の検査対象が変わるという指摘は正しい。
- 対応内容: 6 画面すべて既に `withRecentAuth({onStale})` で受けた status を
  `recentAuthStatus` state に格納する形で統一されており、この形を gate (d) で固定する旨を明記。

## [Warning] logout と Inertia history clear 契約の整合 (観点 5)
- 判断: 対応する
- 根拠: 妥当。inventory の差し替えだけでは経路 C の保証条件の説明が落ちる。
- 対応内容: 新 molecule が `router.post("/logout")` (= Inertia visit) であること、
  既存 `logout-call-site-inventory` の第 2 不変条件 (inventory ファイルに fetch/axios を持ち込まない) と
  `InertiaHistoryGuardTest` が引き続き保証を担うこと、`docs/supported-browsers.md` の
  経路 C の呼び出し元記述を更新することを波及変更として明記した。

## [Warning] スコープが少し広い (F-4/F-7) (観点 6)
- 判断: 一部対応する
- 根拠: F-3 (提示様式) は書き換える 3 ファイルの表示契約そのもので必須。
  F-4/F-7 は同一ファイル内の局所修正で、確かに分離可能。
- 対応内容: 施策 4 を「必須 (F-3)」と「同一ファイル内の付随修正 (F-4/F-7、
  実装が膨らんだ場合に切り離せる)」に分けて明記した。

## [Warning] `availableProviders` を discriminated union にせよ (観点 7)
- 判断: 反論する
- 根拠: 現状すでに `AvailableReauthProvider { provider: string; capability: string; reauthUrl: string }`
  の interface であり文字列配列ではない。さらに**サーバが step-up satisfier 可能な provider だけを
  載せる** (`ConfirmRecentAuthController::buildStatus()` が `ProviderCapability::isStepUpSatisfier()` で
  絞る) ため、クライアントに `canStepUp` 分岐は不要 (載っている = 使える)。分岐フラグを増やすと
  「サーバで絞る」不変条件と二重管理になる。PHP 側も `RecentAuthProviderDto` +
  `RecentAuthStatusResource` で array shape が明示され PHPStan level 10 を通っている。
- 対応内容: 概念設計に上記の不変条件 (「載っている = この端末以外の条件は満たす」) を明記した。
