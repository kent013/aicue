## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本件の追加文脈】
- 対象リポジトリは /workspace。監査レポートは devnotes/20260805-1600-audit-cycle-2/ui-consistency.md。
- 直近サイクル T106 が passkey UI を導入し、その回帰として本件の欠陥が検出された。
- 実装はしない。本レビューは概念設計に対するもの。

---

## 概念設計

# 概念設計: ui-audit-remediation (再認証 UI の「踏破可能性の契約」を型と gate で固定する)

対象監査: `devnotes/20260805-1600-audit-cycle-2/ui-consistency.md` (F-1 Critical / F-2 High / F-3〜F-7 Medium・Low)

## 背景・課題

T099〜T106 サイクル後の多角監査 (UI 一貫性) で、**passkey-only ユーザーが機微操作で詰む**
経路が検出された。機械 gate (18 files / 117 tests) は全 green であり、**現行の gate では
検出できない種類の不整合**である点が本質。

3 つの発見は同一の失敗様式 —「**アカウントに能力はあるのに、その画面からは踏破できない**」— の変種:

| ID | 深刻度 | 内容 | 失敗様式 |
|---|---|---|---|
| F-1 | Critical | `RecentAuthModal` の `passkeyAvailable` が 6 呼び出し中 5 箇所で未配線 | 契約が optional prop で、配線漏れが**型でも gate でも検出されない** |
| F-2a | High | モーダルの回復 CTA が guest 限定 `/forgot-password` へリンク | 押すと無言リダイレクト = 踏破不能 CTA |
| F-2b | High | `PasskeySection` の「パスワードを設定する」→ `/settings` は `current_password` 必須 | 遷移先に**そのユーザーが踏める操作が無い** |

### F-1 の具体的な詰み (実在する母集団)

T106 の phantom password 撤去 (`Str::password(32)` の廃止) により
`User::hasPassword()` が正直になり、**password 無し / SSO 無し / passkey あり**のユーザーが実在する。
このユーザーが Settings/Index・Organizations/Settings・ApiKeys/Index・ApiKeys/Sessions・Admin/Users の
5 画面で機微操作を行うと:

1. サーバは `canSatisfy=true` / `passkeyAvailable=true` を返す
2. モーダルは `passkeyAvailable` の既定値 false でパスキーボタンを**描画しない**
3. `canSatisfy=true` なので回復導線ブロックにも入らない
4. 結果、`executableHere=false` の文言「このブラウザはパスキーに対応していません」が
   **対応ブラウザでも**出て、実行手段は 0、出口はキャンセルのみ

Codex 実装レビュー Round 1 の Critical「**全モーダル利用箇所で同一契約にすべき**」の
後半が未対応のまま APPROVED になった回帰であり、**再発防止の機械化が本設計の主眼**。

### F-2 の根因

「パスワード未設定ユーザーがパスワードを設定する UI 経路がアプリに存在しない」。
`EnsureLoginMethodRemains` の拒否文言は「先に別のログイン手段（**パスワードの設定**、
ソーシャル連携、他のパスキー）を追加してください」と案内しているが、
アプリ内にその操作が無い (= サーバの契約文がアプリの能力と食い違っている)。
`LoginMethodRequiredDto.settingsUrl` (= `settings.security`) はどのクライアントからも
消費されておらず (`grep settingsUrl resources/js` = 0 件)、フロントは別 URL (`/settings`) を
ハードコードしている。

## 改善アイデア

**「踏破可能な導線しか出さない」を、文言や個別修正ではなく契約・型・gate の 3 層で固定する。**

### 施策 1 [Critical] RecentAuthModal の契約を「status オブジェクト 1 個」に変える + call-site inventory gate

- `passwordSet` / `availableProviders` / `canSatisfy` / `passkeyAvailable` の 4 つの optional prop を
  廃し、**`/recent-auth/status` の応答 (`RecentAuthStatus`) をそのまま受ける必須 prop `status` 1 本**にする。
  分解して手渡す形である限り、フィールドが増えるたびに同じ配線漏れが再発する
  (今回まさに `passkeyAvailable` の追加で再発した)。
- **後方互換の並走を残さない** (AGENTS.md 思考原則 3): 旧 prop は同 PR で消す。
- `tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts` を新設し、
  既存 `logout-call-site-inventory.test.ts` と**同型の deny-by-default** で
  (a) 呼び出し側ファイル集合、(b) 全呼び出しが `status={...}` を渡していること、
  (c) 旧 prop 名を渡していないこと、を固定する。
- ⚠ 型だけでは強制できない: 本リポジトリの `pnpm typecheck` は `tsc --noEmit` であり
  **`.svelte` テンプレートの props は型検査されない** (svelte-check は未導入)。
  「必須 prop 化」は契約の宣言であって強制ではないため、**強制の実体は inventory gate** に置く。
  svelte-check 導入は別タスク (スコープ外・後述)。

### 施策 2 [High] 回復導線を単一の molecule に集約する (F-2a)

`RecentAuthModal` と `ConfirmRecentAuth` は「再認証手段が無い / この端末では実行できない」の
2 分岐を**別々に持ち、片方だけ旧作法 (`/forgot-password` 直リンク) のまま残った**。
文言と導線を `components/molecules/RecentAuthRecoveryNotice.svelte` に一本化し、両者が composition する。

- 配置が molecule なのは**構造的制約**: `RecentAuthModal` は organism であり、
  atomic-import-graph gate 上 organism は features を import できない (単方向 import)。
- ログアウト導線 (`router.post("/logout")`) はこの molecule が持つ。
  `logout-call-site-inventory` の inventory を molecule へ差し替える
  (ConfirmRecentAuth からは `/logout` リテラルが消える)。既存 gate の 2 つ目の不変条件
  「inventory 登録ファイルに fetch/axios を持ち込まない」は molecule で自然に満たされる
  (モーダル本体に `router.post("/logout")` を置くと fetch と同居して gate に触れる)。

### 施策 3 [High] パスワード**初回設定**経路を新設する (F-2b の根因解消)

**判断: 新設する。** 根拠と設計は「主要な判断」節に詳述。

- サーバ: `POST /settings/password` (`settings.password.store`)。**`recent-auth` middleware で保護**し、
  `RecentAuthRouteTest` の allowlist に登録する。`hasPassword()` が true なら fail-closed で拒否
  (`current_password` 必須の変更経路を迂回させない)。
- 画面: `/settings` のパスワードカードを `hasPassword` で出し分ける
  (true = 従来の「パスワード変更」、false = 「パスワードを設定」)。
  現状は password 無しユーザーにも `current_password` 必須フォームが出ており、
  **カード丸ごとが踏破不能** = F-2 と同species。
- `PasskeySection` の CTA は据え置き先 (`/settings`) が**踏破可能になる**ことで解消する。
  あわせて Alert 本文で同一ページ内の代替 (SSO 連携カード / 別パスキー登録) を案内する。
- `LoginMethodRequiredDto.settingsUrl` は**削除**する (どのクライアントも消費しておらず、
  指す先とフロントのハードコード先も食い違う phantom 契約)。

### 施策 4 [Medium] 同時に閉じる 3 件 (同一ファイルを触るため)

- **F-3 (提示様式の分裂)**: 「**非フィールド起因の操作失敗は Alert**」を DESIGN.md に規約化し、
  本批で書き換える 3 ファイル (`PasskeySection` / `RecentAuthModal` / `ConfirmRecentAuth`) を揃える。
  `Login.svelte` は既に Alert で準拠済み = **追加ファイルは 0**。
  併せて `RecentAuthModal` が password エラーと passkey ceremony エラーで
  **同一の `error` state を共有している**バグ (passkey 失敗がパスワード欄のエラーとして出る) を分離する。
- **F-4 (`nameError` が入力に追随しない)**: DESIGN.md §FormField の canonical 不変条件違反
  (T106 が唯一の逸脱)。`提示開始 boolean + $derived` 形へ書き換える。
- **F-7 (登録フローの細部)**: `registering` を `onStart`/`onFinish` で握る (連打で ceremony 多重を防ぐ) /
  サーバ validation を FormField へ流す / 拒否 Alert へフォーカス移動。

### スコープから外す (次サイクル送り)

- **F-5 (設定タブナビの molecule 化 + `aria-current`)**: 本批の主題は「踏破可能性の契約」であり、
  F-5 は共通化リファクタ。`ApiKeyTabNav` の汎用化 (改名) は ApiKeys 3 ページとそのテストへ波及し、
  本批の変更面 (認証・再認証) と交差しない。詰みも発生しない (現在地が分かりにくいだけ)。
- **F-6 (Login の非対応ブラウザ caption)**: Login 画面は本批で触らない唯一の passkey 面。
  かつ当該ユーザーにも「パスワードをお忘れの方」= 踏破可能な出口が**同一画面に見えている**ため詰みではない。
- **F-9 (contrast gate の PENDING ペア)**: token 逸脱ゼロの現状に影響しない既知の宣言済み範囲外。
- **svelte-check の導入**: 施策 1 を「型でも」強制できるが、全 `.svelte` の一括型検査は
  既存エラー量が未知で本批の 3 倍規模になりうる。gate で強制できている以上、独立タスクにする。

## 期待効果

- **使命への貢献**: 現場作業者が使う撮影 PWA は「スマホ + パスキー / SSO」が主戦場であり、
  パスワードを持たないユーザーが**本アプリの想定 mainstream**。その母集団が機微操作
  (API キー・組織設定・アカウント削除) で詰む状態は「思考ゼロ」を掲げる製品の前提を壊す。
- **具体的な改善見込み**:
  - passkey-only ユーザーの step-up 成功経路が 1 画面 → 6 画面 (全画面) に回復
  - 踏破不能 CTA 2 本 → 0 本。表示条件と踏破条件が一致する
  - 「配線漏れ」型の回帰が **CI で機械検出**される (現状は多角監査でしか出ない)
  - サーバの拒否文言「パスワードの設定」がアプリの実能力と一致する

## 実装方針（概要）

| 層 | 変更 |
|---|---|
| Svelte components | `RecentAuthModal` (props 契約変更 + エラー分離) / 新 `RecentAuthRecoveryNotice` molecule / `PasskeySection` (Alert 統一・`$derived` 化・登録フロー) / `ConfirmRecentAuth` (molecule 利用・Alert 化) |
| Svelte pages | RecentAuthModal 呼び出し 6 ページの prop 差し替え / `Settings/Index` のパスワードカード分岐 / `Settings/Security` の `hasPassword` 受け渡し |
| Laravel | `PasswordSetupController` + `PasswordCredentialService` (パスワード確定後処理の単一化) / route 追加 / `ProfileController`・`SecurityController` の prop 追加 / `LoginMethodRequiredDto` から `settingsUrl` 削除 / `SecurityEventType::PasswordSet` 追加 |
| gate / テスト | `recent-auth-modal-call-site-inventory.test.ts` 新設 / `logout-call-site-inventory.test.ts` の inventory 更新 / `RecentAuthRouteTest` allowlist 追加 / Feature テスト (初回設定の成功・二重設定拒否・step-up 必須) / JS テスト (モーダル分岐・入力追随・未配線画面の回帰) |
| ドキュメント | DESIGN.md (RecentAuthModal 契約 / 新 molecule / 非フィールド起因エラーの規約) / `docs/supported-browsers.md` (経路 C の logout 呼び出し元) |

## 主要な判断: パスワード設定経路を「新設する」理由

課題文が要求する判断項目に沿って明示する。

### (1) 新設 vs CTA 除去

CTA を踏破可能な先 (SSO 連携 / 別パスキー / ログアウト → リセット) に**差し替えるだけでも詰みは消える**。
それでも新設する理由は 3 点:

1. **踏破不能なのは CTA だけでなく `/settings` のパスワードカード丸ごと**。password 無しユーザーに
   `current_password` 必須フォームを出し続ける限り、押せば必ず失敗する UI が残る。
   これを「隠す」で解決すると、そのユーザーにはパスワードを得る手段が**アプリ内に一切見えなくなる**。
2. **サーバの契約文が既に「パスワードの設定」を約束している** (`EnsureLoginMethodRemains`)。
   文言を実態に合わせて削るか、実態を文言に合わせるかの二択で、
   後者の方が母集団 (SSO/passkey 主体) の増加方向と一致する。
3. **ログアウト経由の回復は「最後の砦」としては正しいが、唯一の手段としては弱い**。
   ログアウト → メール → リセットリンクは、現場スマホ (会社メールを開けない端末) で切れやすい。
   ただし step-up 不能ユーザー (`canSatisfy=false`) には**この経路しか無い**ため、
   施策 2 の molecule で明示的に残す (2 層構成)。

### (2) `current_password` 不要な「初回設定」のセキュリティ保護

**step-up 再認証 (`recent-auth` middleware) を必須にする。**

- 認証手段を**増やす**操作であり `EnsureLoginMethodRemains` (減らす操作の関門) とは逆方向。
  減らす側の不変条件 (「最低 1 手段が残る」) は増加操作では自明に満たされるため、
  同 middleware は付けない (付けると `removalFor()` が fail-closed で例外を投げる設計と衝突する)。
- 増加操作の脅威は「**放置端末・セッション奪取からの永続化**」(攻撃者が自分の知るパスワードを
  設定し、以後 passkey 無しで入れるようにする)。これは step-up が直接に潰す脅威であり、
  API キー発行・オーナー移譲と同機微度。よって `recent-auth` allowlist に登録する。
- `hasPassword()` が true の場合は **fail-closed で拒否**し、`current_password` 必須の変更経路へ誘導する
  (初回設定 route を「current_password を省略できる変更 route」に転用させない)。
  判定は対象 User 行の `lockForUpdate()` 下で行う (同時 2 リクエストの TOCTOU 回避。
  `EnsureLoginMethodRemains` と同じ作法)。
- パスワード確定後の後処理 (他デバイスのセッション失効・監査記録) は
  既存の `UpdateUserPassword` と**同一実装を共有**する (2 箇所に書くと片方だけ劣化する)。

### (3) SSO のみ / passkey のみユーザーの回復シナリオ

| 母集団 | step-up 可否 | 本設計での経路 |
|---|---|---|
| passkey あり (対応ブラウザ) | 可 (passkey satisfier) | **アプリ内でパスワード設定** (施策 3)。施策 1 で全 6 画面のモーダルが機能する |
| passkey あり (非対応ブラウザ) | 不可 | `RecentAuthRecoveryNotice` が「対応端末で開き直す or ログアウト → リセット」を提示 |
| passkey 端末を紛失 (セッションのみ生存) | 不可 (ceremony が失敗) | 同上。ログアウト → guest としてリセット (`RecentAuthPasswordRecoveryTest` が端まで固定済み) |
| SSO のみ (step-up 可能 provider) | 可 | 再 SSO で step-up → アプリ内でパスワード設定 |
| SSO のみ (identity_only provider) | 不可 (`canSatisfy=false`) | `RecentAuthRecoveryNotice` のログアウト経路 |

### (4) AGENTS.md 禁止事項 8 / 監査の Don't の両立

- **disabled にしない**: 新設する「パスワードを設定」ボタンは常時活性で、
  失敗理由 (強度不足・step-up 未成立) は押下後に提示する。
- **表示条件と踏破条件を一致させる**: `hasPassword=false` のときだけ初回設定フォームを出し、
  `hasPassword=true` のときだけ変更フォームを出す。CTA は「その状態のユーザーが実際に踏める先」だけを指す。
  step-up が成立しない端末では、モーダルが**手段を出さない代わりに必ず回復導線を出す**
  (無表示の行き止まりを作らない)。

## 制約・前提

- `resources/js` は TypeScript 必須 (禁止事項 7)。T102 の eslint `noInlineConfig` により
  inline `eslint-disable` 不可。svelte `no-undef` は error。
- `pnpm typecheck` = `tsc --noEmit` (svelte テンプレートは型検査対象外) → **強制は gate で行う**。
- DESIGN.md の token 体系・Atomic Design の単方向 import を維持する
  (hex 直書き 0 件・`ds-purity` / `typography-invariant` / `shape-ramp-purity` / `contrast-invariant` 維持)。
- 新 gate は既存 `logout-call-site-inventory.test.ts` の作法 (deny-by-default + 理由 docblock +
  既知の限界の明記) を踏襲する。
- 検証は `composer test` (2865 passed) / `pnpm test` (1130 passed) を起点に、
  T099 のグローバルロック経由で実行する。

## スコープ外

- F-5 (設定タブナビの molecule 化 / `aria-current`)、F-6 (Login の非対応 caption)、
  F-9 (contrast gate の PENDING ペア解消) — いずれも詰みを生まない既存負債。次サイクル送り。
- svelte-check の導入 (施策 1 の型強制を「宣言」から「検査」へ上げる後続タスク)。
- パスワード設定時のユーザー宛メール通知 (security notification)。監査記録
  (`SecurityEventType::PasswordSet`) は本批で入れる。
- `page-shell-structure.test.ts` の「踏破可能な離脱導線」検査をモーダル/Alert へ拡張する案
  (監査 TODO #2 の後半)。CTA の href 到達可能性を静的に判定する一般解が無く、
  本批は「回復導線を単一 molecule に集約する」ことで同じ再発を構造的に防ぐ。

