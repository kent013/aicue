# アプリの使命・禁止事項・思考原則 (AGENTS.md より)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【この設計の前提 — レビュー時に必ず尊重すること】
- effort 4 の小粒な作業である。過剰に大きくしないこと。機構の追加提案は AGENTS.md 思考原則 2 (今必要なものだけ作る) に照らして本当に必要か自問すること。
- 「スコープ外」節の (a) env 口の撤去 / (b) ProductionEnvGuard の同意版検査 / (c) 法務ページの版表示・文面確定 の 3 点は、**オーナー判断で本タスクのスコープ外と確定している**。これらを実装せよという指摘は受け付けられない (前提条件の記述が不十分だという指摘は受け付ける)。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: legal-consent-single-source (同意バージョン解決点の単一化と gate)

> 一次入力: `devnotes/20260809-0027-legal-consent-single-source/recon-brief.md`
> オーナー指示により **正典 t1 の本体のみ**。上積み 3 点 (env 口の撤去 / ProductionEnvGuard の
> 同意版検査 / 法務ページへの版表示・文面確定) は**実装しない**。

## 背景・課題

利用規約・プライバシーポリシーの同意バージョン (`legal.consent_version`) は、**同意の証跡**として
DB へ記録される。記録先は 2 テーブル:

- `users.consent_version` (nullable, `$guarded`/`$fillable` 外 = `forceFill` 代入)
- `inquiries.consent_version` (同上)

現在、この版番号を **config から取り出す箇所が app/ 配下に 3 本散在**している (実読で確認):

| # | ファイル:行 | 現在の形 |
|---|---|---|
| 1 | `app/Actions/Fortify/CreateNewUser.php:94` | `'consent_version' => config()->string('legal.consent_version'),` |
| 2 | `app/Services/Auth/SocialAccountService.php:75` | `'consent_version' => config()->string('legal.consent_version'),` |
| 3 | `app/Actions/Inquiry/CreateInquiryAction.php:32-33, 52` | `$consentVersion = config('legal.consent_version');` + `Assert::stringNotEmpty(...)` → `forceFill(['consent_version' => $consentVersion])` |

さらに **4 つ目の出所**が fixture 側に実在する:

| # | ファイル:行 | 現在の形 |
|---|---|---|
| 4 | `database/factories/InquiryFactory.php:30` | `'consent_version' => 'draft-1',` (literal) |

問題は 2 つある。

1. **形が 3 種類ある**。#1/#2 は `config()->string()` を式に直書き、#3 だけがローカル変数 +
   `Assert::stringNotEmpty` で **空版の fail-fast** を持つ。つまり「空版で証跡を書かない」という
   不変条件が **1 経路にしかない**。#1/#2 は `config()->string()` を通るだけなので、
   `LEGAL_CONSENT_VERSION=` (空文字) が設定された環境では **`consent_version = ''` の証跡行**が
   静かに作られる。証跡が空版で書かれると「どの版に同意したか」が事後に決定不能になる —
   これは同意記録という機能の名前が果たすべき役割そのものの破れである。
2. **新しい書き込み経路が増えても機械的に検出されない**。読み手が増えるたびに形が分岐しうる
   (実際に 3 形へ分岐している)。この分岐を止める Architecture gate が存在しない
   (`tests/Architecture/` の 81 ファイルに該当なし)。

`database/factories/InquiryFactory.php` の literal `'draft-1'` は config から独立した 4 つ目の出所
であり、config を変えても fixture は追随しない (現在は `.env.testing:77` が `draft-1` のため
**たまたま一致している**だけ)。

## 仮説

**版番号を決める場所を 1 箇所 (`App\Support\Legal\LegalConsent::version()`) にし、
そこに空版 fail-fast を集約すれば、(a) 空版の証跡は全経路で構造的に発生しなくなり、
(b) 新しい書き込み経路の追加は Architecture gate で必ず可視化される。**

検証方法 (成功判定):
- Unit テストで「空版 → 例外」が `LegalConsent` の 1 箇所で担保される。
- Architecture gate が、3 本を正準形へ揃えた後の状態で green、**mutation で赤化する**
  (負のコントロール + 母集団 floor を同梱して空振り green を排除する)。
- **既存の Feature テストが 1 行の変更もなく green** = アプリの振る舞いが変わっていない直接証拠。

## 改善アイデア

### 1. 解決点を 1 クラスに集約する

`App\Support\Legal\LegalConsent` を新設し、`version(): non-empty-string` の **1 メソッドだけ**を持たせる。

- **状態も DB 参照も持たない**、config アクセサ + fail-fast のみ。
- 静的メソッドにする。既存の `App\Support\EmailNormalizer` / `PasswordPolicy` / `Environment` と
  同じ流儀 (状態を持たない SSOT helper は static)。DI コンテナ登録も interface も作らない
  (思考原則 2: 今必要なものだけ作る)。
- 中身は `config()->string('legal.consent_version')` +
  `Assert::stringNotEmpty()` = **CreateInquiryAction の既存 fail-fast をそのまま昇格**させる形。
  fail-fast を弱めないどころか、3 経路すべてに広がる。

### 2. 呼び出し側 3 本を正準形 1 形へ揃える

正準形は **`LegalConsent::version()` の呼び出し** 1 形のみ。

- `CreateNewUser.php:94` / `SocialAccountService.php:75`:
  `config()->string('legal.consent_version')` → `LegalConsent::version()` (式の置換 1 行 + use 1 行)。
- `CreateInquiryAction.php:32-33`: config 読み + Assert の 2 行 → `LegalConsent::version()` の 1 行。
  52 行 (`'consent_version' => $consentVersion,`) は**変更しない** (ローカル変数名は維持)。
  → 並行タスク (queue-dispatch-atomicity) が読むだけの `dispatchNotification` 周辺には触らない。

### 3. fixture の 4 つ目の出所を潰す

`InquiryFactory.php:30` の literal `'draft-1'` → `LegalConsent::version()`。
`.env.testing:77` が `LEGAL_CONSENT_VERSION=draft-1` なので **テストレーンでは値が完全に同一**
(振る舞い不変)。config を変えたときに fixture が置き去りにならなくなる。

### 4. Architecture gate で正準形を固定する

`tests/Architecture/LegalConsentVersionSingleSourceTest.php` を新設。**deny-by-default** の
token 走査 (`CarbonOverflowArithmeticGateTest` / `ScenarioWritePathInventoryTest` と同じ
`token_get_all` 流儀 = コメント・文字列リテラル**内容**の誤検出を避ける) で 4 検出:

| 検出 | 内容 | 母集団 |
|---|---|---|
| G1 | 設定キー文字列 `'legal.consent_version'` の出現は `app/Support/Legal/LegalConsent.php` **のみ** | `app/**/*.php` |
| G2 | env 名 `LEGAL_CONSENT_VERSION` の出現は `app/` に **0 件** かつ `config/legal.php` に **存在する** | `app/**/*.php` + `config/legal.php` |
| G3 | `LegalConsent::version()` の呼び出し元集合が inventory と **exact-fit** (3 本) | `app/**/*.php` |
| G4 | プレースホルダ literal `'draft-1'` の出現は `app/` `database/` `tests/` に **0 件** | 同 3 ディレクトリ |

**走査規則を `legal.` 名前空間 / `LEGAL_CONSENT_VERSION` / `LegalConsent::version` に限定する**
のが本設計の中核判断である。素の識別子 `'consent_version'` で走査すると、実在する別 feature —
課金の自動購入同意 `config('billing.auto_recharge.consent_version')`
(`config/billing.php:92` / `BillingCheckoutRequest.php:74` / `ActivatePersonalRequest.php:48` /
`ticket_auto_recharges.consent_version`) — を巻き込んで false positive になる
(AGENTS.md 思考原則 4: 別物の概念を「似ているから」で統合しない)。

G3 を allowlist ではなく **exact-fit inventory** にするのが要点。allowlist だと「新しい経路を
足しても gate は黙る」ので、**新経路の追加が必ず gate を赤くする**形にする。
G1 が config 直読を封じているので、新経路が gate を迂回して版を取る道は
(下記「保証しないもの」の限界を除いて) 残らない。

さらに、単一出典の検査は**空振りで green になりやすい**ため、以下を必ず同梱する:

- **負のコントロール**: 実ファイルを書き換えず fixture ソース文字列に対して検出器が点灯すること。
- **母集団 floor**: 走査ファイル数 > 0 / 走査 token 数 > 0 / G3 inventory 件数 == 3。
  母集団が空に落ちたら fail する。

### 5. Unit テスト

`tests/Unit/Support/Legal/LegalConsentTest.php`:
正常系 (config の値をそのまま返す) / 空版 (`''`) で例外 / config 未設定で例外。

## 期待効果

- **使命への貢献**: 直接の機能追加ではないが、同意証跡は「現場作業者が使うサービス」を
  提供し続けるための法的基盤であり、**証跡が事後に決定不能になる破れ**を構造的に塞ぐ。
  正しさへの寄与が小さくない割に触る面が狭い (effort 4)。
- 空版証跡の発生経路が 3 → 0 になる (fail-fast が 1/3 経路 → 3/3 経路)。
- 版番号の出所が 4 (config 直読 3 + fixture literal 1) → 1 になる。
- 新しい同意書き込み経路の追加が、レビュー依存ではなく **CI で必ず可視化**される。

## 実装方針（概要）

| 種別 | ファイル | 変更 |
|---|---|---|
| 新規 | `app/Support/Legal/LegalConsent.php` | `version(): non-empty-string` のみ |
| 新規 | `tests/Architecture/LegalConsentVersionSingleSourceTest.php` | G1〜G4 + 負のコントロール + 母集団 floor |
| 新規 | `tests/Unit/Support/Legal/LegalConsentTest.php` | 正常 / 空版 / 未設定 |
| 変更 | `app/Actions/Fortify/CreateNewUser.php` | L94 の式 + `use` 1 行 |
| 変更 | `app/Services/Auth/SocialAccountService.php` | L75 の式 + `use` 1 行 |
| 変更 | `app/Actions/Inquiry/CreateInquiryAction.php` | L32-33 を 1 行へ + `use` 調整 (L52 は不変) |
| 変更 | `database/factories/InquiryFactory.php` | L30 の literal → `LegalConsent::version()` |
| **不変** | `tests/Feature/Auth/RegistrationTest.php:25` | **変更しない** (下記参照) |
| **不変** | `tests/Feature/Inquiry/ContactSubmissionTest.php:51` | **変更しない** (下記参照) |
| **不変** | `config/legal.php` / `.env.example` / 法務 blade / `ProductionEnvGuard` | スコープ外 |

### 既存 Feature テストを「揃えない」判断

`RegistrationTest.php:25` と `ContactSubmissionTest.php:51` は期待値を
`config()->string('legal.consent_version')` で作っている。これを `LegalConsent::version()` へ
揃えたくなるが、**揃えない**。理由:

1. 揃えると実装とテストが**同じ 1 関数を見る**ことになり、その関数が壊れても両方が同時に
   ずれて green のままになる (トートロジー化)。config を独立に読むことで
   「LegalConsent が config の値を忠実に返している」ことの behavioral な担保になる。
2. **既存テストが 1 行の変更もなく green である**ことが、アプリの振る舞いが変わっていない
   ことの直接証拠になる (本タスクの主要な検証手段)。
3. gate の母集団は `app/` であり、`tests/` の config 直読は G1 の違反にならない
   (G4 の `'draft-1'` literal 禁止だけが `tests/` に掛かる)。

## 制約・前提

- PHP 8.4 / Laravel 12 / PHPStan level 10 / Pest (`RefreshDatabase` はグローバル適用、`--parallel`)。
- `Assert::stringNotEmpty()` は PHPStan で `non-empty-string` に narrowing するため、
  `@return non-empty-string` を書いても level 10 を通る。
- Architecture lane は DB を使わない (`tests/Pest.php:98` で `TestCase` のみ)。gate は静的走査で完結。
- 並行タスク干渉: `CreateInquiryAction.php` は別タスクが**読むだけ**。本設計が触るのは
  L32-33 のみ (L52 は不変) で、別タスクの関心 (`dispatchNotification` = L58 以降) と行が離れている。

## 振る舞いの変化 (誇張せず正直に書く)

「アプリの振る舞いは変わらない」が原則だが、**厳密には 1 ケースだけ変わる**:

`LEGAL_CONSENT_VERSION=` (空文字) が設定された環境では、
`config()->string()` は空文字を返して通す (型検査のみ) ため、現在は
`users.consent_version = ''` の証跡行が静かに作られる。集約後は `LegalConsent::version()` が
`InvalidArgumentException` を投げるため、**その環境では登録 / SSO 登録が 500 になる**。

これは**意図した強化**である (空版の証跡を書くくらいなら止める = fail-fast)。
`config/legal.php:22` の既定が `'draft-1'` であり `.env.example` / `.env.testing` の双方に
`draft-1` が入っているため、通常運用でこの分岐に入ることはない。
問い合わせ経路 (`CreateInquiryAction`) では**既に**この挙動なので、
**登録経路 2 本を問い合わせ経路に合わせる**という統一である (弱める方向ではない)。

## スコープ外 (将来入れるときの前提条件つき)

オーナー判断でスコープ外。**次の担当が判断できるよう前提条件を残す**。

### (a) `config/legal.php` の env 口を外す (spirux 形)

- **やらない理由**: 本番 / staging の `.env` に既に `LEGAL_CONSENT_VERSION` がある場合、
  env 口を外すと**設定が無言で無視される**。運用告知なしに入れられない変更である。
- **将来の前提条件**: (i) 本番 / staging の実 `.env` に当該キーが無いことを確認するか、
  値をコードの既定へ移送してから外す。(ii) `.env.example:168` の削除も同時に行う
  (`tests/Architecture/EnvExampleInvariantTest.php` の既存 assertion に
  `LEGAL_CONSENT_VERSION` は無いため機械的衝突は無い = 確認済み)。
  (iii) 本設計の G2 (「env 名は `config/legal.php` にのみ存在する」) を
  「どこにも存在しない」へ書き換える必要がある。

### (b) `ProductionEnvGuard` にプレースホルダ `draft-1` 拒否を足す (motivation 形)

- **やらない理由**: 既定値が `draft-1` のままなので、入れた瞬間に **production 起動が落ちる
  破壊的変更**になる (`TRUSTED_PROXIES` = T108 と同種)。規約文面を確定してリリースする
  タイミングで入れるのが自然。
- **将来の前提条件**: (i) 実際の版番号 (例 `2026-09-01`) を決めて本番 `.env` に設定してから
  guard を有効化する。(ii) `AGENTS.md` の「運用要件」節へ TRUSTED_PROXIES と同形で追記する
  (未設定なら起動が落ちる = 初回デプロイ前に設定が要る破壊的変更である旨)。
  (iii) `tests/Feature/Support/ProductionEnvGuardTest.php` に検査を追加する。

### (c) 法務ページへの適用版表示 / 規約文面の確定

- **やらない理由**: 文面確定は法務の領域でこちらでは決められない。
  現在 `resources/views/legal/terms.blade.php` の文面はプレースホルダ (「アプリ公開時に記入」)
  で最終改定日も未記入であり、版表示だけ足しても**空文面に版を貼る**ことになる。
- **将来の前提条件**: (i) 文面確定が先。(ii) 表示に使う値は本設計の `LegalConsent::version()`
  経由にする (blade で `config()` 直読しない)。ただし blade は本 gate の母集団外なので、
  版表示を入れるときは **G1 の母集団に `resources/views/` を足す**必要がある。
  (iii) spirux 形の「版 ↔ 文面 hash 台帳」まで踏み込むのは文面確定後。

### その他 (正典 t1 も要求していない)

規約改定時の再同意フロー / 同意履歴テーブル / 文面と版番号の対応づけ /
SSO 往復中の版固定は motivation が明示的にスコープ外としており、本設計も扱わない。
既に DB に書かれた過去の `consent_version` 値の遡及是正も行わない。

## 保証しないもの (誇張しない)

本設計の gate が**保証するのは、`app/` 配下の PHP ソースにおける静的に決定可能な参照形だけ**である。
以下には**無言で効かない**:

1. **間接読み**: `config('legal')['consent_version']` / `Config::get($key)` の変数キー /
   文字列連結でキーを組み立てる形は G1 の token 完全一致に掛からない。
2. **母集団外**: `resources/views/**` (blade) / `resources/js/**` (Svelte) / `routes/` /
   `config/` からの読みは G1/G3 の母集団に入らない。
3. **DB の既存値**: 過去に書かれた `consent_version` 行の整合は検査しない。
4. **版と文面の対応**: 版番号が実際の規約文面に対応していることは一切保証しない
   (文面は未確定プレースホルダ)。
5. **本番値の妥当性**: `draft-1` のまま本番が起動することは検出しない (= スコープ外 (b))。
6. **fail-fast の時点**: 空版で落ちるのは**版を書き込もうとした時点**であり、起動時ではない。
7. **billing の同意版**: `billing.auto_recharge.consent_version` は**意図的に対象外**
   (別 feature。統合しない)。

