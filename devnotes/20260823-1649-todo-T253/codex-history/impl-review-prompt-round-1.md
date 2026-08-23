## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

Laravel 12 + Svelte 5 (Inertia) アプリ「AI-CUE」の**実装レビュアー**である。
TODO T253「企業 IdP との OIDC SSO 採用」の実装差分をレビューせよ。

## レビュー観点

1. **設計との一致性** — 詳細設計 (下記) の不変条件・判断が実装に反映されているか。
   ★設計から**意図的に外した点**は「実装側の適応」節に列挙してある。その判断が妥当かを見よ。
2. **正確性** — 競合・認可・状態遷移・例外の扱いに実際のバグが無いか
3. **PHPStan level 10 適合性** — 型の緩め・widen が無いか (`composer phpstan` は green)
4. **DTO / JsonResource パターン** — `response()->json()` 直書きが無いか、モデルを境界の外へ出していないか
5. **テスト網羅性** — 不変条件が対応するテストで固定されているか。**空振りするテスト**が無いか
6. **セキュリティ** — 秘密の漏洩面・存在オラクル・SSRF・認可の抜け
7. **DESIGN.md 準拠** — color / radius / typography は design token 経由か。hex 直書きを増やしていないか
8. **Atomic Design 準拠** — `resources/js/components/` の階層 (atoms/molecules/organisms/features/templates/pages) を逆流していないか。アイコンは Lucide か

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に**全体判定**を `APPROVED` または `CHANGES_REQUESTED` で明示する

★**根拠のない推測で Critical を付けない**。指摘には「どの入力でどう壊れるか」を書け。

---

## 実装側の適応 (設計から意図的に外した点。ここを重点的に見てほしい)

設計は **T247 (組織 URL の /organizations/{organization:slug} 配下への移設) より前の main** を前提に
承認されている。T247 はマージ済みなので、route 配置・binding・URL 生成は現行 main に合わせた。
それ以外に、実装中に**既存の gate / vendor API の実態**と食い違った点を次のとおり適応した。

| # | 設計の記述 | 実装 | 理由 |
|---|---|---|---|
| 1 | `PinnedRequest::post(...)->withoutRedirects()->withMaxBodyBytes(...)` | `new PinnedRequest('POST', …, body:, contentType:)` + `fetch(…, followRedirects: false)` + **応答を受けた後にアプリが本文長を測って拒否** | ^0.4 の実 API に `withMaxBodyBytes` は無い (本文上限は transport の構築時に決まる)。設計の「存在しない API を確定形として残さない」に従い実 API へ追随した。用途別の上限は残す (transport の上限の内側でアプリが測る = 層が違う) |
| 2 | 接続の識別名の列は `slug` | 列名は **`login_slug`** | `OrganizationSlugWritePathTest` の走査器が「`slug` 列を持つ表は organizations だけ」を前提にキー名だけで表を特定しており、その docblock が「他表に `slug` が増えたら前提が崩れる」と明記している。免除目録は `database/migrations` と `tests` にしか登録できず `app/` の書き込みを登録できないため、**前提が真であり続ける側**を選んだ |
| 3 | 開始 route は `{connectionSlug}` を controller が手で解決する | `{connection}` の **explicit binder** (`PublicOidcConnectionBinder`) + route の `missing()` | `ModelDirectFetchInvariantTest` の「非主キー一意列によるモデル解決が 0 件」は**ハードな 0 件固定**であり、controller で `where('login_slug', …)` を書けない。binder が framework の `resolveRouteBinding()` を通し、**不在の識別名と使えない接続を同じ 404 に畳む** (実在オラクルを作らない)。`missing()` がそれを利用者向けの一様な案内へ変える |
| 4 | F4: 偽 IdP を `app/Services/EnterpriseSso/Fakes/` に置き、試験環境限定の `/testing/fake-idp/authorize` route を登録し `ExternalFakeDeclaration` / `ExternalSeamInventory` へ登録する | 偽 IdP を **`tests/Support/EnterpriseSso/FakeIdentityProvider`** に置き、**ssrf-pin が出荷している transport の seam** (`FakePinnedTransport`) だけを差し替える。app 側の fake クラスも testing route も**作らない** | (a) 未認証の GET で任意の subject を名乗れる route を本番の読み込み対象へ足さずに済む、(b) `UrlSafetyInspector` は**本物が動く**ので「実装が pin 済み経路を通ること」の検査を兼ねる (通らなければ fake に 1 件も要求が届かない)、(c) container 束縛を 1 つも変えないので `ExternalFakeDeclaration` (app サービスの差し替えの目録) の母集団に入らない。★**この判断が妥当か特に見てほしい** |
| 5 | B4 の実プロセス並行テストは別 TODO の並行ハーネス (`ConcurrencyProbeRunner`) に乗せる | **同一プロセス内の 2 本の DB 接続** (`tests/Support/EnterpriseSso/CommittedConnectionHarness`) + `lock_timeout` で行ロックの排他を実測する | 既存ハーネスの `run()` は idempotency 用の probe スクリプトに固定されており、企業 SSO 用に**新しい probe スクリプトと runner を作る**必要があった。採った形は「1 本目が行を掴んでいる間 2 本目が進めない」「1 本目が消したあと 2 本目には行が無い」を**実際の pgsql の行ロックで**測る。★**証明しないこと (実 OS プロセス 2 本の PHP 側の競合) はテストの docblock に明記した**。この切り分けが妥当か見てほしい |
| 6 | `ConnectionSecret::revealForTokenExchange()` の呼び出し元を exact-fit で 1 本に pin | 用途を **2 つに分けた** (`revealForTokenExchange` = 外向きの交換 / `revealForEncryptionAtRest` = 保存のための暗号化)。**それぞれを exact-fit で pin** | 保存のための暗号化 (`EncryptedSecretCast::set`) は平文を必要とするので、1 つの口にまとめると「保存のために要る」という理由で外向きの利用まで通ってしまう |
| 7 | (設計に記述なし) | `User::updateBlindIndexes()` を override し、**`email` が null の利用者は `email_index` の blind index 行を持たない**ようにした | 同梱 trait の既定は null もハッシュして 1 行書くので、そのままだと企業 SSO の利用者が**全員同じ blind index 値**を持ち、`blind_indexes_type_name_value_unique` (partial unique) に衝突して**2 人目から作成できない**。移行の docblock が言う「null 行は blind index を持たない」を実装として成立させた。★設計が見落としていた点である |
| 8 | (設計に記述なし) | `verify` の第 1 段が**行を読み直してから**スナップショットを撮る | 呼び出し側が渡したインスタンスの `getRawOriginal()` は、直前に保存した直後だと保存に使った暗号文と食い違う (暗号化のたびに別の暗号文になる)。比べたいのは「保存されている暗号文が取得の間に変わったか」なので、スナップショットも保存された値から撮る |
| 9 | 逸脱の登録は D37 | **D45 / D46 / D47 / D48** の 4 本 | D37 は現行 main で使用済み (番号は再利用しない規約)。D48 が設計の D37 に相当する。D45〜D47 は、**採用時債務一覧に在る 3 ファイル** (`RouteBindingTypes` / `CachePayloadPlainDataGateTest` / `RecentAuthRouteTest`) を触ったため、AGENTS.md の 3 択のうち「意図的逸脱として登録する」を選んだぶんである |
| 10 | メール昇格の Mailable | `ShouldQueue` を実装し、**投入は昇格の行を作るのと同一トランザクションの中**で行う | 既存の Mailable がすべて `ShouldQueue` であり、ドメイン規約 11 (キュー投入の原子性) が `afterCommit` に依存しないことを求めるため |

## 検証コマンドの結果 (全 green)

- `composer test`: **7237 tests / 7235 passed / 0 failed** (skipped 2 / risky 5 は既存)
- `composer phpstan`: OK (level 10。widen も baseline も無し)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm build`: passed
- `pnpm test`: **2398 tests / 179 files / 全 pass**
- `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`: passed

---

## 詳細設計書 (devnotes/20260823-0015-enterprise-oidc-sso-adoption/detailed-design.md)

# 詳細設計: enterprise-oidc-sso-adoption

> ## 状態: **APPROVED**（Codex 詳細設計レビュー Round 8。**16 施策すべて APPROVE**）
>
> 概念設計は Round 5 で APPROVED 済み。詳細設計は **Round 8 で全体判定 APPROVED** になった
> （Round 1〜5 は前任の設計セッション、Round 6〜8 は上限を +3 まで延長して継続した分である）。
>
> **実装の前提（着手条件）**:
> - 前段① `ssrf-pin-v04-upgrade` の完了（本設計は `kent013/laravel-ssrf-pin ^0.4` 前提）
> - 前段② `process-concurrency-harness-adoption` の完了（B4 / C1 / C2 の並行テストの土台）
> - 記載済みの検証コマンド・並行テスト・漏洩テストが受入条件である（「受入条件」節）
>
> **Round 8 の非阻害の注意も反映済み**: `beforeRespond` の中の `disable` が完了することを
> 「行ロックを保持していない証明」に使わない（**同一の DB 接続では行ロックは再入できる**ため）。
> 直接測る形（query listener で `for update` の不発行を見る）へ差し替えた。
>
> 以下は各ラウンドの反映点の記録である。
>
> ### Round 7 の残件 1 件
>
> **Round 7 で 16 施策中 14 が APPROVE**。残ったのは D1 / F4 の 1 論点だけで、
> `verify` の並行テストで **`DB::transactionLevel()` を絶対値 `0` と比べていた**こと。
> グローバル `RefreshDatabase` がテスト全体をトランザクションで包むため
> **Feature レーンでは通常 1 であって 0 ではなく、そのままでは新設テストが必ず赤になる**。
> **基準の段数との相対比較**（`verify()` の直前に読んだ値と等しいこと）へ直した。
> 固定したい不変条件は「**第 2 段が段を増やさない**」であって絶対値ではない。
>
> ### Round 6 の残件 3 件（Round 7 で APPROVE 済み）
>
> **Round 6 で 16 施策中 13 が APPROVE**（A2 / B4 / C1 / E1 を含む）。
> 残る D1 / D2 / F4 の 3 件は、いずれも `verify` の三段構成の**実装例**への指摘だった。
> 反映済みの内容は次のとおりである。
>
> 1. **ロック付き再取得を relation 起点へ統一**（クラス起点の主キー同一性クエリを書かない
>    = AGENTS.md セキュリティ不変条件 3）。5 操作すべてが
>    `$organization->oidcConnections()->whereKey(…)->lockForUpdate()` を通る
> 2. **比較子を 3 層にした** — `credentials_revision`（主）/ issuer・client_id の実値（第 2）/
>    **client secret の暗号文の digest**（第 3。★復号しない）。
>    第 2 層が issuer・client_id だけだと「secret を変えて `+1` を忘れた」場合に破れるため
> 3. **`verify` の割り込みテストから待ち合わせ（ready / go）を外した** —
>    同一プロセスで callback に待たせると `verify()` が戻らずデッドロックする。
>    **同期の割り込み注入**（callback が更新を実行してそのまま戻る）へ変えた
>
> ### Round 5 の残件 5 件（Round 6 で APPROVE 済み）
>
> **承認を妨げていた残件 2 件**:
> 1. **`verify` の線形化**（D1 / D2）— `verify` だけを**明示の三段構成**にした。
>    ロックなしでスナップショットを取り、**ロックを持たずに**外向き取得を行い、
>    その後にトランザクションを開いて接続の行を `lockForUpdate()` し、
>    **`credentials_revision` がスナップショットと一致するときだけ** `Verified` へ遷移する。
>    → D1「`verify` だけは三段構成にする」節が正本。A2 に `credentials_revision` 列を追加した
> 2. **メール昇格の確認画面の描画方式**（E1）— **専用の standalone Blade に確定**した
>    （既存の `resources/views/mcp/authorize.blade.php` と同じ形）。
>    → E1「確認画面の描画方式」節が正本
>
> **文書整合の残件 3 件**: B4 の docblock の矛盾 / C1 冒頭の旧記述（「subject の指紋」）/
> A2 の CHECK 制約の実体と制約名 — いずれも該当箇所を直した。
>
> 施策別では **A1・A3・B1・B2・B3・C2・F1・F2・F3・F4 が Round 5 時点で APPROVE** 済みである。

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 → 実行単位の 1 本道のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

> 本設計は 5・6 に該当する変更を持たない（LLM 呼び出しを一切足さない）。
> 7 は該当あり — 企業ログインの**確定時のみ** `redirect()->intended()` を使う（ログイン直後フローなので許される）。
> 組織側の接続管理の操作系はすべて `back()->with(...)` で完結させる。
> 8 は D2 の画面に該当あり（未入力でもボタンを押せる。押下時にエラー表示する）。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用済（個別 `DatabaseTransactions` 禁止）、`--parallel` 実行
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）。本設計は新モデル 4 本 → **Factory 4 本を施策に含む**
- **DTO + JsonResource** パターン / **アーリーリターン**
- `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

`devnotes/20260823-0015-enterprise-oidc-sso-adoption/conceptual-design.md`（APPROVED / Round 5）

正典: 家系の機能台帳 lctl `auth-enterprise-oidc`
（`feature_revision: 23-30a9407c8f19` / `canonical_version: v1 (AG-200)`）

## 実読で確定した前提（設計判断の根拠）

| 前提 | 実読の結果 |
|---|---|
| **DB は pgsql**（テストも本番も） | `phpunit.xml` が `DB_CONNECTION=pgsql` を `force="true"` で固定。SQLite は使わない → `SELECT … FOR UPDATE` の排他契約が本番と同じ |
| **`PinnedHttpClient` の API** | `fetch(PinnedRequest, Deadline): PinnedResponse\|PinnedFailure`。**^0.4 では要求・応答の body と大きさ制限つき読み出しを持つ**（前段 TODO の調査で実読済み） |
| **JWT の部品** | `firebase/php-jwt` **v7.0.5 が既に解決済み**（家系の台帳が言う「署名付きトークンの検証に使う部品」と同版）。推移依存なので `composer.json` へ明示する |
| **役割** | `OrganizationRole` は `Owner` / `Admin` / `Member` |
| **`users.email`** | 現在 **NOT NULL**（CipherSweet の ciphertext を `text` で保持。一意性は `blind_indexes` の partial unique） |
| **既存のソーシャル SSO** | `SocialAuthController::callback()` は `Auth::login()` で即時確定。2 要素の入力画面へ送る分岐を持たない（= AG-200 の形） |
| **並行テストのハーネス** | 別 TODO **`process-concurrency-harness-adoption`** が設計済み（`devnotes/20260822-2315-…`）。正典 `process-concurrency-test-harness` v1 の 6 要素をテンプレートからバイト一致で取り込む。**本設計の並行テストはこれに乗る**（グローバル `RefreshDatabase` のトランザクション内で作ったフィクスチャは別接続から見えないため、独自に組まない） |
| **`dontFlash` の実績** | リポジトリに**使用実績なし**。Laravel 12 の作法は `bootstrap/app.php` の `withExceptions()` 内 `$exceptions->dontFlash([...])` |
| **モデルの文書** | `docs/architecture.md` / `docs/factories.md` が実在する（新モデルの登録先） |
| **2 要素の組織義務づけ** | `RequireTwoFactorForEnforcedOrganizations` の転送先は `settings.security`（**設定ページ**であって入力画面ではない） |

## 正典の不変条件（全列挙。すべて本設計が満たす）

| # | 不変条件 | 本設計での保証機構 |
|---|---|---|
| I1 | **メールアドレスで利用者を引かない**（引き当ての鍵は **接続 × `COLLATE "C"` の subject**） | A2 の列設計 + C1 + gate G1 |
| I2 | **身元表の申告メールに索引を付けない**（暗号化はする） | A2 + G1 の「申告メールを含む索引が 0 本」検査 |
| I3 | **外部取得は必ず SsrfPin の窓口経由**（接続先情報 / 鍵 / トークン交換の 3 経路） | B1・B2 が `PinnedHttpClient` に一本化 + gate G2 |
| I4 | **接続の秘密を扱う前面は登録・更新フォーム 1 本のみ** | D2 + gate G3 |
| I5 | **受け渡しの型・例外に接続の秘密が存在しない**（例外は機械可読な理由文字列のみ） | A2 の値型 + B2 + D2 + gate G3 |
| I6 | **共通ログイン経路に 2 要素認証を挟まない**（AG-200） | C2 + gate G4 + 実挙動テスト 2 本 |
| I7 | **初回ログインでその場で利用者を作る (always-JIT)** | C1 |
| I8 | **メール昇格フローは `App\Services\Auth` 名前空間へ置く**（正典の設計判断ごと引き継ぐ） | E1 + gate G5 |

### AGENTS.md セキュリティ不変条件の対応

| AGENTS.md | 本設計での対応 |
|---|---|
| 不変条件 1（tenant キー不信） | 接続の組織は URL から解決。payload から `organization_id` を受けない（`MassAssignmentSafetyTest` の母集団に入る） |
| 不変条件 2 / 10（子は親に属する = 認可より前に 404 / 層 2 は binding 直後） | `{organization:slug}` → `{oidcConnection}` を `scopeBindings()` で解決（`Organization::oidcConnections()`）。F2 で `NestedRouteDefenseInventory` へ登録 |
| 不変条件 3（cross-org 不可） | 接続・身元はすべて組織スコープ解決。クラス起点の主キー同一性クエリを書かない |
| 不変条件 5（`laratrust_team_id` を明示） | C1 の所属・役割の付与で組織の team id を明示する |
| 不変条件 6（PII は CipherSweet） | 身元表の申告メールを暗号化。**blind index は付けない**（I2） |
| 不変条件 8（SSRF 窓口） | I3。境界は `config/ssrf-pin.php` の pin をそのまま使う（**本設計は同ファイルを変更しない**） |
| 不変条件 9（変更系 route は認可を通る） | 組織側 6 変更系は `Gate::authorize`。GET だが DB を変える開始 route は F2 で理由付きの分類を持つ |
| 不変条件 11（キャッシュは素のデータだけ） | B1 の短期保存は**素の配列とスカラーのみ**。読み戻しは DTO へ組み立て直し、失敗したら `forget` する。`CachePayloadPlainDataGateTest` の目録へ登録する |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A1 | 設定ファイル・値域 enum・**一時値の**指紋の導出 | `config/enterprise-sso.php`, `app/Enums/EnterpriseSso/*`, `app/Support/EnterpriseSso/AttemptFingerprint.php` | High |
| A2 | モデル 3 本 + 移行 3 本 + Factory 3 本 + 秘密の値型 + 文書 | `app/Models/*`, `database/migrations/*`, `database/factories/*`, `app/Casts/*`, `app/ValueObjects/*`, `docs/architecture.md`, `docs/factories.md` | High |
| A3 | `users.email` の nullable 化（企業 SSO のみの利用者） | `database/migrations/*`, `app/Models/User.php`, `app/Auth/EncryptedUserProvider.php` ほか波及 | High |
| B1 | 接続先情報と鍵の取得（`PinnedHttpClient` 一本化） | `app/Services/EnterpriseSso/OidcDiscoveryService.php` ほか DTO | High |
| B2 | トークン交換（body 付き pinned 要求） | `app/Services/EnterpriseSso/OidcTokenExchanger.php` ほか DTO | High |
| B3 | ID トークンの検証（`firebase/php-jwt`） | `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php` ほか DTO, `composer.json` | High |
| B4 | ログイン試行の保管（原子的 consume + ブラウザ結合） | `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` ほか, `routes/console.php` | High |
| C1 | 利用者の自動作成 (always-JIT) | `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php` | High |
| C2 | 開始と戻り口・controller・route 3 本 | `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`, `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`, `app/Http/Requests/Auth/*`, `routes/web.php` | High |
| D1 | 接続の状態遷移サービス（**`verify` は三段構成**） | `app/Services/EnterpriseSso/OidcConnectionTransitionService.php`, `app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php`, `app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php` | High |
| D2 | 組織側の接続管理 controller・route 7 本・画面 | `app/Http/Controllers/Organizations/*`, `app/Http/Requests/Organizations/*`, `resources/js/pages/Organizations/Sso/Index.svelte`, `routes/web.php` | High |
| E1 | メールアドレスの昇格フロー（**Auth 名前空間**）+ route 4 本 + **確認画面の standalone Blade 1 枚** | `app/Services/Auth/EmailPromotionService.php` ほか + 移行 1 本 + Factory 1 本 + `resources/views/auth/email-promotion/confirm.blade.php` + `routes/web.php`, `docs/architecture.md`, `docs/factories.md` | Medium |
| F1 | gate 5 本（G1〜G5）+ 走査器 | `tests/Architecture/*`, `tests/Support/EnterpriseSso/*`, `tests/Unit/Architecture/*` | High |
| F2 | aicue 側の目録登録（**新規 14 route の全分類**） | `app/Enums/Security/*`, `tests/Support/*`, `tests/Architecture/*` | High |
| F3 | 逸脱の登録 D37 + 台帳件数の pin | `docs/template-divergence.md`, `tests/Support/TemplateDivergence/LedgerPins.php` | High |
| F4 | 試験用の偽 IdP と外部到達点の登録 | `app/Services/EnterpriseSso/Fakes/*`, `app/Http/Controllers/Testing/*`, `app/Support/ExternalFakes/ExternalFakeDeclaration.php`, `tests/Support/ExternalSeam/ExternalSeamInventory.php` | High |

---

## A1: 設定ファイル・値域 enum・指紋の鍵導出

### 変更箇所
- 新規: `config/enterprise-sso.php`
- 新規: `app/Enums/EnterpriseSso/OidcConnectionStatus.php` / `OidcSigningAlgorithm.php` / `TokenEndpointAuthMethod.php`
- 新規: `app/Support/EnterpriseSso/AttemptFingerprint.php`（用途別の指紋の導出）

### 波及変更
- TypeScript型定義: **あり** — 接続の状態の値域を `resources/js/components/features/sso/oidc-connection.ts` へ写す（正典が 2026-08-08 に画面直書きから TS 定数へ切り出した形に合わせる）。既存の enum ↔ TS 同期 gate の母集団へ載せる（F2）
- API Resource/DTO: なし
- テストファイル: `tests/Architecture/EnvExampleInvariantTest` は**対象外**（新しい環境変数を足さないため。下記）

### 指紋の鍵の出所（Codex Round 1・2 の指摘への回答）

★**永続する値には指紋を使わない**。これが Round 2 の [Critical] への回答である。

`subject`（身元の主キー）を `APP_KEY` 由来の指紋にすると、
**`APP_KEY` をローテートした瞬間に既存の指紋を再現できなくなり、
次回ログインで別の利用者・別の身元が JIT で作られる**（アカウントの分裂）。
したがって `subject` は指紋にせず、**列の照合順序で byte 一致を担保する**（A2）。

`AttemptFingerprint` が扱うのは**寿命の短い一時値だけ**である。

```php
/** 指紋の用途。**相互に使い回せない** (domain separation)。永続する値は扱わない。 */
enum FingerprintPurpose: string
{
    case State = 'enterprise-sso.state';                    // 寿命 10 分
    case Nonce = 'enterprise-sso.nonce';                    // 寿命 10 分
    case BrowserBinding = 'enterprise-sso.browser-binding'; // 寿命 10 分
    case EmailPromotionToken = 'auth.email-promotion';      // 寿命 60 分
}

/**
 * **一時値**の指紋の導出。用途ごとに domain separation する。
 *
 * 鍵は **APP_KEY から用途別ラベル付きで導出する** (HKDF)。専用の秘密を新設しない —
 * 運用要件を 1 つ増やす価値が無い (思考原則 2)。判断の根拠:
 *   APP_KEY をローテートして失効するのは **進行中の試行 (10 分) と未確認の昇格 (60 分) だけ**である。
 *   ★**身元・接続・利用者はどれも指紋に依存しない** (subject は指紋を使わない) ので、
 *     ローテートで失われる永続的なものが無い。
 *   (対比: パスキーの利用者ハンドルは APP_KEY 由来だと**登録済みパスキーが全件無効**になるため
 *    専用の秘密を要求している。ここはその条件に当たらない。)
 *
 * ★**この型に永続する値の用途を足さない**。足すと上の根拠が崩れる。
 */
final class AttemptFingerprint
{
    public static function of(FingerprintPurpose $purpose, #[SensitiveParameter] string $value): string
    {
        return hash_hmac('sha256', $value, self::key($purpose));
    }

    /**
     * 鍵の導出の契約 (実装差を残さないために書く):
     *   - 入力鍵: `config('app.key')` の **`base64:` 接頭辞を外して base64 復号したバイト列**
     *     (復号できない設定は例外。黙って文字列のまま使わない)
     *   - salt:   空 (アプリ内で 1 つの入力鍵しか使わないので salt に載せる情報が無い)
     *   - info:   **用途の値そのもの** (`FingerprintPurpose::value`)。これが domain separation の実体
     *   - 出力長: 32 バイト
     */
    private static function key(FingerprintPurpose $purpose): string { /* hash_hkdf('sha256', …) */ }
}
```

### 変更後コード（config。**参照されない項目を作らない**）

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO
|--------------------------------------------------------------------------
| ★外部 URL の安全境界は **ここに書かない**。SSRF の境界の正本は
|   config/ssrf-pin.php であり、本設計はそれを変更しない (同じ事実を 2 か所に置かない)。
| ★環境変数を足さない。すべて固定値である (テンプレートの固定値方式に合わせる。
|   起源 spirux の環境変数方式とは割れているが、正典はテンプレート側である)。
*/

return [
    'discovery' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 5,
        'cache_ttl_seconds' => 300,
        // 未知 kid での鍵の再取得の最小間隔 (増幅を防ぐ)
        'jwks_refetch_min_interval_seconds' => 60,
        'max_body_bytes' => 262144,
    ],

    'token' => [
        'connect_timeout_seconds' => 3,
        'request_timeout_seconds' => 8,
        'max_body_bytes' => 65536,
    ],

    'id_token' => [
        // 許容する時刻ずれ。**顧客の入力では広げられない** (接続の登録項目にしない)。
        'leeway_seconds' => 60,
        'max_subject_length' => 255,
    ],

    'login_attempt' => [
        'ttl_seconds' => 600,
        // 掃除の 1 回あたりの上限 (長いトランザクションを作らない)
        'prune_chunk' => 1000,
    ],

    // メールアドレスの昇格 (E1)。Auth 名前空間の機能だが、設定は本ファイルに集約する
    // (企業 SSO でしか入れない利用者のための機構であり、単独では意味を持たない)。
    'email_promotion' => [
        'ttl_seconds' => 3600,
    ],
];
```

```php
enum OidcConnectionStatus: string
{
    case Draft = 'draft';       // 登録直後 / 認証材料を更新した直後。ログインに使えない
    case Verified = 'verified'; // 接続先情報の取得に成功した。まだ使えない
    case Active = 'active';     // ログインに使える
    case Disabled = 'disabled'; // 運営が止めた
}

/** ID トークンの署名方式の許可集合。`none` と対称鍵 (HMAC) は **case に持たない**。 */
enum OidcSigningAlgorithm: string
{
    case Rs256 = 'RS256';
    case Rs384 = 'RS384';
    case Rs512 = 'RS512';
    case Es256 = 'ES256';
    case Es384 = 'ES384';
}

/** token endpoint の client 認証方式。**body 漏洩面が小さい basic を優先する**。 */
enum TokenEndpointAuthMethod: string
{
    case ClientSecretBasic = 'client_secret_basic';
    case ClientSecretPost = 'client_secret_post';
}
```

> **`none` と HMAC を「拒否リスト」でなく「enum に持たない」形にする理由**:
> 許可集合を型で表せば、拒否漏れという失敗様式そのものが消える。
> 文字列の比較で弾く形は、比較の書き忘れ 1 つで通る。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（読み出しは `Config::integer()` で型を確定させる）
- [x] null安全（`Config::integer()` は null を返さない。準拠実装 `SnsCertificateFetcher`）
- [x] DTOを返している（本施策は値域と純関数のみ）
- [x] Genericsの型パラメータが正しい（該当なし）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoConfigTest.php`
  - **全整数が正数**であること（0・負数を弾く）
  - 大小関係: `connect_timeout <= request_timeout`（discovery / token とも）
  - **上限**: `id_token.leeway_seconds <= 300`（時刻ずれを無制限に広げられない）／
    `login_attempt.ttl_seconds <= 1800`（試行が長生きしない）／
    `email_promotion.ttl_seconds <= 86400`／
    `discovery.max_body_bytes <= 1048576`／`token.max_body_bytes <= 262144`
  - `jwks_refetch_min_interval_seconds >= 1`
- [ ] 新規 `tests/Unit/Enums/OidcSigningAlgorithmTest.php` — `none` / `HS256` が `tryFrom()` で null（負のコントロール）
- [ ] 新規 `tests/Unit/Support/AttemptFingerprintTest.php`
  - **同じ入力でも用途が違えば別の指紋になる**（domain separation の実挙動）
  - **`FingerprintPurpose` に永続する値の用途が無い**（case を名指しで pin。
    足したら赤になり、A1 の根拠の見直しがレビューに出る）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- `APP_KEY` のローテートで**進行中の試行（10 分）と未確認の昇格（60 分）**が失効する。
  **これは受容した判断**であり docblock に根拠を書く。
  永続する値（`subject`）は指紋を使わないので、ローテートで失われるものはこれだけである。

---

## A2: モデル 3 本 + 移行 3 本 + Factory 3 本 + 秘密の値型

### 変更箇所
- 変更: `docs/architecture.md` / `docs/factories.md`（**新モデル 3 本を登録する**。AGENTS.md の必須手順）
- 新規: `app/Models/OrganizationOidcConnection.php` / `EnterpriseIdentity.php` / `EnterpriseSsoLoginAttempt.php`
- 新規: `database/migrations/2026_08_23_000100_create_organization_oidc_connections_table.php`
- 新規: `database/migrations/2026_08_23_000200_create_enterprise_identities_table.php`
- 新規: `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php`
- 新規: `database/factories/OrganizationOidcConnectionFactory.php` / `EnterpriseIdentityFactory.php` / `EnterpriseSsoLoginAttemptFactory.php`
- 新規: `app/ValueObjects/EnterpriseSso/ConnectionSecret.php`（**暗黙の文字列化を持たない**秘密の値型）
- 新規: `app/Casts/EncryptedSecretCast.php`
- 変更: `app/Models/Organization.php`（`oidcConnections()` relation を追加）

### 波及変更
- TypeScript型定義: なし（画面へ出すのは D2 の DTO 経由）
- API Resource/DTO: D2 の `SsoConnectionSummary` が本モデルを入力にする
- テストファイル: `MassAssignmentSafetyTest` の母集団に 3 モデルが入る

### 移行 1: `organization_oidc_connections`

```php
Schema::create('organization_oidc_connections', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

    // 公開のログイン導線 (/enterprise/{slug}/redirect) で使う識別名。
    // ★**全体で一意**であり、**推測されてよい**。推測可能性に依存した防御を持たない —
    //   防御は接続の状態 (Active か) と state / PKCE / ブラウザ結合が担う。
    $table->string('slug', 64)->unique();

    $table->string('display_name', 100);

    // 顧客が入力する。https 必須・userinfo/query/fragment なし・正規化できる絶対 URL。
    $table->string('issuer', 255);
    $table->string('client_id', 255);

    // ★暗号化して保存する。読み出しは ConnectionSecret 値型を経由し、
    //   平文の取り出しは token 交換だけが呼ぶ 1 メソッドに集約する。索引を持たせない。
    $table->text('client_secret_encrypted');

    $table->string('status', 16)->default(OidcConnectionStatus::Draft->value);
    $table->timestamp('verified_at')->nullable();

    // ★**認証材料の版**。issuer / client_id / client_secret のいずれかが変わるたびに +1 する。
    //   用途は 1 つだけ — D1 の `verify` が「**外向き取得の間に認証材料が変わっていないこと**」を
    //   ロックの中で確かめるための比較子である (D1「verify だけは三段構成にする」節が正本)。
    //   ★**`updated_at` で代用しない**: 時刻の精度で同一に見えうるうえ、
    //     認証に関与しない表示名の更新まで巻き込んで verify を落とす。
    //   ★書き手は D1 の 1 メソッドだけであり、そこで**必ず** Draft 化と同時に +1 する。
    $table->unsignedBigInteger('credentials_revision')->default(1);

    $table->timestamps();

    // 1 組織に複数の接続を許す (合併・複数 IdP の企業がある)。組織単位の検索用。
    $table->index('organization_id');
});
```

### 移行 2: `enterprise_identities`

```php
Schema::create('enterprise_identities', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // ★IdP の subject。**これが身元の主キーである**。
    //   照合を **COLLATE "C" (バイト単位)** に固定する — 既定の照合順序では
    //   `Alice` と `alice` が同一視されうる環境があり、そうなると
    //   **別人が同じアカウントに入る**。
    //   ★指紋 (HMAC) にはしない。指紋は鍵に依存するので、APP_KEY をローテートすると
    //     既存の身元へ到達できなくなり**アカウントが分裂する** (Round 2 の指摘)。
    //     列の照合順序なら鍵に依存しない。
    $table->string('subject', 255)->collation('C');
    // ★境界は 2 層で閉じる:
    //   (1) 入力側 — VerifiedIdTokenClaims の構築時に「**バイト長 1〜255**」
    //       「制御文字を含まない」を検査する
    //   (2) DB 側 — ★pgsql の `varchar(255)` は **255 バイトではなく 255 文字**なので、
    //       バイト長の保証にならない。**CHECK 制約を別に置く**
    //       (身元の主キーなので、型の検査だけに頼らず DB でも閉じる)。実体は下の生 SQL。

    // ★申告メール: 暗号化して持つが **索引を意図的に付けない**。
    //   索引を付けると「メールで引ける」経路が実装として復活し、正典 v1 の I1/I2 が崩れる。
    //   blind index も付けない (configureCipherSweet で addBlindIndex を呼ばない)。
    $table->text('claimed_email_encrypted')->nullable();

    $table->timestamp('last_login_at')->nullable();
    $table->timestamps();

    // ★**最後の防波堤**である。競合制御の本体は C2 が張る接続の行ロックであり、
    //   C1 はこの制約違反を**捕まえない** (違反はそのまま伝播させる。
    //   握り潰すと「直列化が壊れた」という重大な事実が競合として隠れる)。
    //   制約名を明示するのは、違反が起きたときに出所が一目で分かるようにするためである。
    $table->unique(
        ['organization_oidc_connection_id', 'subject'],
        'enterprise_identities_connection_subject_unique',
    );

    $table->index('user_id');
});

// ★CHECK 制約は Blueprint に API が無いので**生 SQL で置く**。
//   pgsql 固定でよい (phpunit.xml が DB_CONNECTION=pgsql を force しており、テストも本番も pgsql)。
//   ★**制約名を明示する** — (1) 違反したときに出所が一目で分かる
//   (2) スキーマ読み取りテストが `pg_constraint.conname` を名前で引ける
//   (名前を DB に決めさせると、テストが「在ることの確認」を書けない)。
DB::statement(<<<'SQL'
    ALTER TABLE enterprise_identities
        ADD CONSTRAINT enterprise_identities_subject_octet_length_check
        CHECK (octet_length(subject) BETWEEN 1 AND 255)
    SQL);

// ★制御文字の禁止も **DB の不変条件に含める**（DTO だけの保証にしない）。
//   身元の主キーなので、上のバイト長と同じ理由で 2 層目を DB に置く。
//   ★**名前を分ける** — 長さ違反と文字種違反を、違反の名前だけで切り分けられるようにする。
DB::statement(<<<'SQL'
    ALTER TABLE enterprise_identities
        ADD CONSTRAINT enterprise_identities_subject_no_control_chars_check
        CHECK (subject !~ E'[\\x01-\\x1F\\x7F]')
    SQL);
```

> **この 2 本の CHECK が保証する範囲（誇張しない）**
>
> - 対象は **C0 制御文字（`U+0001`〜`U+001F`）と `DEL`（`U+007F`）だけ**である。
>   **入力側 DTO（`VerifiedIdTokenClaims`）の検査と同じ集合**に揃えてある
>   （2 層が違う集合を見ていると、片方だけ通る値が生まれて二層の意味が消える）。
> - **`U+0000`（NUL）は集合に書けないし、書く必要も無い** — pgsql の `text` / `varchar` は
>   NUL を格納できず、ドライバの段で失敗する（`E'...'` の文字クラスにも NUL は書けない）。
>   **格納層で不可能なものを CHECK で二重に書かない**。
> - **C1 制御文字（`U+0080`〜`U+009F`）と Unicode の書式文字（`U+200B` 等）は対象外**である。
>   これらは**許す**。「制御文字を一切通さない」とは書かない（言い過ぎになる）。
> - **down() で制約を明示的に落とす必要は無い** — 表ごと `dropIfExists` するので制約も消える。
> - 実装時の細目: 移行ファイルに **`Illuminate\Support\Facades\DB` の import** が要る
>   （`Schema` しか使っていない既存の移行から写すと落ちる）。

### 移行 3: `enterprise_sso_login_attempts`

```php
Schema::create('enterprise_sso_login_attempts', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();

    // state の **指紋だけ**を持つ (原文を保存しない)。一意制約が使用権の唯一性の根拠。
    $table->char('state_fingerprint', 64)->unique();

    // nonce も **指紋だけ**。ID トークンの nonce を同じ用途ラベルで指紋化して定時間比較する。
    $table->char('nonce_fingerprint', 64);

    // 開始したブラウザとの結合 (login CSRF を塞ぐ本体)。
    // セッションへ置いた「結び付けの秘密」の指紋。**session ID は保存しない**。
    $table->char('browser_binding_fingerprint', 64);

    // PKCE の検証子だけは token 交換でそのまま送るので原文が要る → 暗号化して保存。
    $table->text('pkce_verifier_encrypted');

    $table->timestamp('expires_at');
    $table->timestamps();

    $table->index('expires_at');   // 期限切れ掃除の走査用
});
```

### 秘密の値型（Codex Round 1 の [Critical] への回答）

```php
/**
 * 接続の秘密 (client secret) の値型。
 *
 * ★**暗黙の文字列化を持たない** — `__toString()` を実装しない。
 *   これにより「うっかり文字列連結・ログ・例外・DTO へ載る」経路が**型で消える**。
 * ★平文の取り出しは {@see self::revealForTokenExchange()} の 1 メソッドだけである。
 *   このメソッドを呼んでよいのは OidcTokenExchanger だけであり、
 *   tests/Architecture/EnterpriseSsoSecretExposureGateTest が呼び出し元を exact-fit で pin する。
 *
 * ## 保証する範囲 (誇張しない)
 *
 * `__debugInfo()` が効くのは **`var_dump()` 系だけ**である。
 * ★**`var_export()` / `serialize()` / Reflection からは平文が見える**。
 *   任意の PHP の内省に対して隠せるとは**主張しない**。
 *   したがって守りは 3 層に分ける:
 *     1. 型 — 暗黙の文字列化を持たない (うっかりの連結・出力を消す)
 *     2. gate — **この値型をログ・dump・直列化の関数へ渡す記法**を G3 が禁じる
 *     3. **主たる証明** — 実挙動の漏洩テスト (例外・監査・ログ・要求の記録に出ない)
 */
final readonly class ConnectionSecret
{
    private function __construct(private string $plaintext) {}

    public static function fromPlaintext(#[SensitiveParameter] string $plaintext): self
    {
        return new self($plaintext);
    }

    /** ★token 交換だけが呼ぶ。他所からの呼び出しは gate が落とす。 */
    public function revealForTokenExchange(): string
    {
        return $this->plaintext;
    }

    /**
     * ★`var_dump()` 系にだけ効く。`var_export()` / `serialize()` / Reflection には効かない。
     *
     * @return array{client_secret: string}
     */
    public function __debugInfo(): array
    {
        return ['client_secret' => '********'];
    }
}
```

### モデル（要点）

```php
final class EnterpriseIdentity extends Model implements CipherSweetEncrypted
{
    /** @use HasFactory<EnterpriseIdentityFactory> */
    use HasFactory, UsesCipherSweet;

    /**
     * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
     *   引き当ての鍵は **(organization_oidc_connection_id, 生の subject)** だけである
     *   (subject 列は `COLLATE "C"` で byte 一致。**指紋にしない** = 鍵のローテーションに依存しない)。
     *   申告メールは暗号化して持つが **blind index を付けない** —
     *   索引があると「メールで引ける」経路が復活する。
     *   これは tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest が
     *   記法の走査と **「申告メールを含む索引が 0 本」のスキーマ検査** の二層で固定する。
     */
    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
    {
        // addBlindIndex を **呼ばない**。これが不変条件の実体である。
        $encryptedRow->addField('claimed_email_encrypted');
    }

    /** @var list<string> */
    protected $fillable = [];  // 生成は Provisioner が明示的に組み立てる (mass assignment を作らない)
}
```

### モデルの契約（cast / hidden / relation / Factory generics）

| モデル | cast | `$hidden` | relation | Factory |
|---|---|---|---|---|
| `OrganizationOidcConnection` | `status` → `OidcConnectionStatus::class` / `verified_at` → `immutable_datetime` / `client_secret_encrypted` → `EncryptedSecretCast::class`（`ConnectionSecret` を返す） | `client_secret_encrypted` | `organization()` (BelongsTo) / `identities()` (HasMany) / `loginAttempts()` (HasMany) | `@use HasFactory<OrganizationOidcConnectionFactory>` |
| `EnterpriseIdentity` | `last_login_at` → `immutable_datetime`（申告メールは CipherSweet が担当） | `claimed_email_encrypted` | `connection()` (BelongsTo) / `user()` (BelongsTo) | `@use HasFactory<EnterpriseIdentityFactory>` |
| `EnterpriseSsoLoginAttempt` | `expires_at` → `immutable_datetime` / `pkce_verifier_encrypted` → `encrypted` | `pkce_verifier_encrypted` / `state_fingerprint` / `nonce_fingerprint` / `browser_binding_fingerprint` | `connection()` (BelongsTo) | `@use HasFactory<EnterpriseSsoLoginAttemptFactory>` |

- `$fillable` は **3 モデルとも空**（生成は Service が明示的に組み立てる。mass assignment を作らない）
- **`toArray()` から暗号文も秘密の値型も出ない**ことをテストで固定する（`$hidden` の実効確認）
- ★`credentials_revision` は **cast も `$hidden` も要らない**（`unsignedBigInteger` がそのまま int で来る、
  秘密ではない）。ただし **D2 の `SsoConnectionSummary` には載せない** —
  画面が使う値ではなく、**D1 の内部の比較子**である。
  外へ出すと「画面から見える版番号」として別の意味を持ち始める（I4 と同じ思想）

```php
// app/Models/Organization.php へ追加 (D2 の scopeBindings が引く relation)
/** @return HasMany<OrganizationOidcConnection, $this> */
public function oidcConnections(): HasMany
{
    return $this->hasMany(OrganizationOidcConnection::class);
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（relation は generics つき）
- [x] null安全（`claimed_email_encrypted` / `verified_at` は nullable を型で明示）
- [x] DTOを返している（モデルは境界の外へ出さない。D2 で DTO へ畳む）
- [x] Genericsの型パラメータが正しい（`@use HasFactory<XxxFactory>` を 3 モデルとも書く）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityIsolationTest.php`
  - **申告メールの列（または対応する blind index）を含む索引が 0 本**である
    （**スキーマの読み取りのみ**。`migrate:fresh` 等の破壊操作を伴わない = 禁止事項 3）
  - **`subject` 列の照合順序が `C` である**（スキーマの読み取り。設定が外れたら赤）
  - **`Alice` と `alice` が別の身元になる**（照合順序の実挙動。上の検査と二層）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoModelHidingTest.php` —
      3 モデルの `toArray()` に暗号文・秘密の値型が出ない
- [ ] `subject` の**バイト長 256 以上**と**制御文字を含む値**が DTO の構築で拒否される
- [ ] **DB の CHECK 制約 2 本が名前で実在する**（`pg_constraint.conname` を読む。
      `enterprise_identities_subject_octet_length_check` /
      `enterprise_identities_subject_no_control_chars_check`。**スキーマの読み取りのみ**）
- [ ] **DTO を迂回して直接書いても DB が拒む**（二層目が実際に効くことの実挙動。
      256 バイトの `subject` / `"a\x1Fb"` を直に insert して**制約違反になる**）
- [ ] **C1 制御文字（`U+0085`）と `U+200B` は通る**（★負のコントロール。
      「制御文字を一切通さない」と誤読させないため、**保証外が保証外のままである**ことを固定する）
- [ ] **`credentials_revision` の既定値が 1 である**（Factory で作った接続が 1 から始まる）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdentityCipherSweetTest.php` — 申告メールが平文で保存されない
- [ ] 新規 `tests/Unit/ValueObjects/ConnectionSecretTest.php`
  - **`__toString()` を持たない**（`method_exists` が false）
  - `__debugInfo()` と `json_encode()` に平文が出ない
  - ★**`var_export` / `serialize` に出ないとは検査しない**（保証していないため。
    誤った安心を与えるテストを置かない）
- [ ] Factory 3 本が `RefreshDatabase` 下で動く
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### 身元がある接続を物理削除させない（Round 3 の [Critical] への回答）

`cascadeOnDelete` は「接続を消すと身元も消える」を意味するが、**利用者は残る**。
その後で同じ IdP を再登録し、同じ `subject` で入ると
**新しい利用者が JIT で作られる**（アカウントの分裂）。
企業 SSO でしか入れない利用者は、元のアカウントへ**二度と戻れない**。

したがって:

- **身元が 1 件でもある接続は物理削除できない**（D1 の状態遷移と D2 の `destroy` で拒否する）
- 削除できるのは**身元が 0 件の接続だけ**である（登録し損ねた `Draft` の後始末が主用途）
- 運用は **無効化 (`Disabled`)** で行う。無効化なら身元は残り、
  再び有効にしたときに**同じ利用者へ戻る**
- **組織そのものの削除に伴う連鎖は本設計の対象外**とする
  （組織削除は利用者・課金・データ全体を扱う別の運用であり、ここで再設計しない）

### リスク
- **移行 3 本目（試行表）はテンプレートに無い上積み**である → F3 で逸脱 D37 として登録する。

---

## A3: `users.email` の nullable 化（企業 SSO のみの利用者）

### 変更箇所
- 新規: `database/migrations/2026_08_23_000050_make_users_email_nullable.php`
- 変更: `app/Models/User.php`（`email` の型注釈・`$fillable` の扱い）
- 変更: `app/Auth/EncryptedUserProvider.php`（null のメールで引かれない）
- 変更: `app/Actions/Fortify/UpdateUserProfileInformation.php` ほか
  「`email` を null → 非 null にしうる経路」（`email_verified_at` を消す）
- 変更: `docs/architecture.md`（`email_verified_at` の意味と更新規約）

### なぜ必要か（Codex Round 1 の [Critical] への回答）

企業 SSO でしか入れない利用者は **使えるメールアドレスを 1 件も持たない**。
選択肢は 3 つあり、採るのは (c) である:

| 案 | 判断 |
|---|---|
| (a) 仮のメール文字列を作る（`sub@example.invalid` 等） | **採らない**。偽のメールは nOAuth の再現面と衝突の温床になり、通知の誤送先にもなる |
| (b) `hasVerifiedEmail()` を認証方式込みで再定義する | **採らない**。既存の `verified` middleware の意味論を変えるのは波及が広すぎる |
| (c) **`email` を nullable にし、`email_verified_at` は now() で作る** | **採る**。「IdP が本人確認した。**確認すべきメールが無い**」の意味であり、`hasVerifiedEmail()` は既存の実装のまま真になる。middleware の意味論を変えない |

### `email_verified_at` の意味を壊さないための規約（Round 2 の [Warning] への回答）

`email = null` かつ `email_verified_at != null` という状態は、
**後から別経路で email だけが入ると、その新しいメールが自動的に確認済みになる**という穴を持つ。
したがって:

- **`email` を null → 非 null にする経路を棚卸しし、
  メール昇格（E1）以外のすべての経路で `email_verified_at` を必ず消す**
- **メール昇格の確定では、`email_verified_at` を「以前の値のまま」にせず、
  新しいメールを実際に確認した時刻へ更新する**（監査上の意味を保つ）
- 棚卸しの対象: Fortify のプロフィール更新（`UpdateUserProfileInformation`）／
  管理画面（Filament）／seeder / Factory ／その他 `email` を書く全経路
- これを **Architecture テストで deny-by-default にはしない**（`email` を書く経路の
  網羅的な静的判定は本設計の範囲を超える）。代わりに**実挙動テストで主要経路を固定**し、
  規約を `docs/architecture.md` に書く（保証範囲を誇張しない）

### 変更後コード

```php
Schema::table('users', function (Blueprint $table): void {
    // 企業 SSO でしか入れない利用者は使えるメールを持たない (正典 v1 の always-JIT)。
    // ★email の一意性は平文 unique ではなく blind_indexes の **partial unique** が担うため、
    //   null 化しても一意性の担保は変わらない (null 行は blind index を持たない)。
    $table->text('email')->nullable()->change();
});
```

### 波及変更
- **TypeScript型定義**: 設定画面などで `email` を表示している Props を `string | null` へ
- **API Resource/DTO**: 利用者を返す DTO / Resource の `email` を nullable へ
- **テストファイル**:
  - `app/Auth/EncryptedUserProvider.php` — `whereBlind('email', …)` は null 行に当たらない（挙動は変わらないが**テストで固定する**）
  - Filament の管理画面（`/manage/users`）が null のメールで壊れない
  - 通知（`Notifiable`）が null のメール宛に送らない
  - `MassAssignmentSafetyTest`（`$fillable` は変えない）

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`User::$email` の型注釈を `?string` にし、参照箇所を PHPStan level 10 が洗い出す）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php`
  - メールを持たない利用者が **`verified` middleware を通る**（`email_verified_at` が入っているため）
  - メールを持たない利用者が **パスワード再設定を要求できない**（宛先が無い）
  - **昇格以外の経路で `email` を入れると `email_verified_at` が消える**
    （自動で確認済みにならない）
  - **メールでのログイン（`EncryptedUserProvider`）が null 行に当たらない**
  - 管理画面の一覧が null のメールで壊れない
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **既存テーブルへの破壊的でない変更**だが、`email` を非 null 前提で書いた既存箇所が PHPStan で洗い出される。
  洗い出しの結果が大きすぎる場合でも **型を緩めて黙らせない**（禁止事項 2）。

---

## B1: 接続先情報と鍵の取得（`PinnedHttpClient` 一本化）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcDiscoveryService.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcProviderMetadata.php` / `OidcJsonWebKeySet.php`

### 設計の転回（Codex Round 1 の [Critical] への回答）

**`Http` ファサード・`HttpFactory` を企業 SSO の名前空間から締め出し、`PinnedHttpClient` に一本化する。**

`SnsCertificateFetcher` が「inspect → `Http::` で取得」の形なのは、
**v0.2 の `PinnedHttpClient` が本文を返せなかったから**である。
^0.4 ではその制約が無い。制約が消えたのに古い形を写すのは**後退**であり、
検査と接続の間の TOCTOU（DNS rebinding）を自分から作り直すことになる。

したがって:

- 3 経路（discovery / JWKS / トークン交換）とも `PinnedHttpClient::fetch()` を使う
- **「DNS rebinding は解消しない」という但し書きを書かない**（pin 済み経路には当てはまらない）
- G2 が `App\Services\EnterpriseSso` 配下の `Http` / `HttpFactory` の使用を**許可一覧なしで**弾く

### 接続先 URL の入力規則（[Critical] への回答）

`config/ssrf-pin.php` は `http` も許している（他用途のため）。
**企業 OIDC 自身の入力規則として https を必須化する** — でなければ
client secret・認可コード・トークンが平文で流れる。

```php
/**
 * issuer の値オブジェクト。**型で規則を担保する** (呼び出し側の作法に頼らない)。
 *
 * 規則: https のみ / userinfo なし / query なし / fragment なし / 絶対 URL / 長さ上限。
 *
 * ★**末尾のスラッシュを正規化しない**。OIDC の issuer は**識別子であって URL の正規化対象ではない** —
 *   `https://idp.example/tenant` と `https://idp.example/tenant/` は**別の issuer** になりうる。
 *   登録した文字列をそのまま保ち、discovery 文書の issuer と**仕様どおり完全一致**させる。
 *
 * ★well-known の URL は「issuer のパスの**後ろに**」付ける
 *   (`https://idp.example/tenant` → `https://idp.example/tenant/.well-known/openid-configuration`)。
 *   パス付きの issuer で正しく組み立つことをテストが固定する。
 */
final readonly class OidcIssuerUrl { /* … */ }
```

### 変更後コード（要点）

```php
final readonly class OidcDiscoveryService
{
    public function __construct(
        private PinnedHttpClient $pinned,   // ★Http ファサード・HttpFactory を注入しない
        private CacheRepository $cache,
    ) {}

    /**
     * discovery 文書の取得と検証。
     *
     * 防御:
     *  1. **pin 済み経路** — 検査・名前解決・接続が同じ経路 (AGENTS.md 不変条件 8)。
     *     境界の正本は config/ssrf-pin.php (本設計は変更しない)
     *  2. **リダイレクトを追従しない** — 転送先が未検査のまま取得されるのを防ぐ。
     *     2xx 以外は一様に拒否する
     *  3. **issuer の完全一致** — 文書の issuer が登録済み issuer と一致すること
     *  4. **endpoint は https の絶対 URL・userinfo なし・fragment なし** —
     *     ★同一 origin は**要求しない**。OIDC 標準の要件ではなく、
     *     実在の IdP (issuer と JWKS が別 origin) を拒否する。正典も要件にしていない。
     *     ★**query は禁じない** (禁じる標準上の根拠が無い)。
     *     各 endpoint は個別に pin 済み経路を通る
     *  5. **応答サイズ上限** — 期待と違う応答を DTO に固定しない
     *
     * @throws EnterpriseSsoAttemptRejectedException ★**理由の enum だけ**を持つ
     *         (`::of(RejectionReason::…)` で作る。`previous` を受け取れない構築子なので、
     *          vendor 例外の連鎖で body が展開される経路が型で消える)
     */
    public function fetchMetadata(OidcIssuerUrl $issuer): OidcProviderMetadata
    {
        $cached = $this->cachedMetadata($issuer);
        if ($cached !== null) {
            return $cached;   // アーリーリターン
        }

        $body = $this->fetchPinned(
            $issuer->wellKnownUrl(),
            Config::integer('enterprise-sso.discovery.max_body_bytes'),
            Config::integer('enterprise-sso.discovery.connect_timeout_seconds'),
            Config::integer('enterprise-sso.discovery.request_timeout_seconds'),
        );

        $metadata = OidcProviderMetadata::fromResponseBody($body, expectedIssuer: $issuer);

        $this->rememberMetadata($issuer, $metadata);

        return $metadata;
    }
}
```

```php
final readonly class OidcProviderMetadata
{
    /**
     * @param  non-empty-list<TokenEndpointAuthMethod>  $tokenEndpointAuthMethods
     * @param  non-empty-list<OidcSigningAlgorithm>  $idTokenSigningAlgorithms  IdP が広告した署名方式
     */
    private function __construct(
        public OidcIssuerUrl $issuer,
        public string $authorizationEndpoint,
        public string $tokenEndpoint,
        public string $jwksUri,
        public array $tokenEndpointAuthMethods,
        public array $idTokenSigningAlgorithms,
    ) {}

    /**
     * ★**未知の要素を array<string, mixed> のまま内側へ出さない**。
     *   必要な要素だけを「存在」と「具体型」を検査してから組み立てる。
     */
    public static function fromResponseBody(string $body, OidcIssuerUrl $expectedIssuer): self
    {
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // ★例外は **理由の enum だけ**を受け取る形に統一する (G3)。
            //   previous を受け取れない構築子なので、body が例外の連鎖で展開される経路が型で消える。
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotJson);
        }

        if (! is_array($decoded)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotObject);
        }

        $issuer = OidcIssuerUrl::fromString(self::requireString($decoded, 'issuer'));
        if (! hash_equals($expectedIssuer->value, $issuer->value)) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryIssuerMismatch);
        }

        // 各 endpoint は https の絶対 URL であること (同一 origin は要求しない)。
        $authorization = self::requireHttpsUrl($decoded, 'authorization_endpoint');
        $token = self::requireHttpsUrl($decoded, 'token_endpoint');
        $jwks = self::requireHttpsUrl($decoded, 'jwks_uri');

        // client 認証方式。★**この項目は OIDC Discovery で optional であり、
        //   欠落時の既定は client_secret_basic である** (仕様)。
        //   欠落を「対応方式なし」として拒否すると**仕様準拠の IdP を拒否する**。
        //   明示されている場合だけ basic → post の優先で選び、
        //   明示されていて **どちらも無い**場合だけ拒否する。
        $methods = self::supportedAuthMethods($decoded);   // 欠落 → [ClientSecretBasic]
        if ($methods === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedAuthMethod);
        }

        // ★id_token_signing_alg_values_supported は OIDC Discovery の **必須項目**である。
        //   アプリの許可集合との共通部分を取り、空なら拒否する。
        //   B3 は「alg が **アプリの許可集合と IdP の広告集合の両方**に入る」ことを要求する
        //   (広告外の alg で署名されたトークンを通さない)。
        $algorithms = self::supportedSigningAlgorithms($decoded);   // 必須・非空・具体型を検査
        if ($algorithms === []) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedSigningAlg);
        }

        return new self($issuer, $authorization, $token, $jwks, $methods, $algorithms);
    }
}
```

### キャッシュの保存スキーマ（不変条件 11）

| キー | 値 |
|---|---|
| `enterprise-sso:metadata:{issuer の sha256}` | `array{issuer: string, authorization_endpoint: string, token_endpoint: string, jwks_uri: string, auth_methods: non-empty-list<string>, id_token_signing_algorithms: non-empty-list<string>}`（**素の配列とスカラーのみ**）★**広告された署名方式も保存する** — 保存しないとキャッシュ hit の後に B3 の「アプリの許可集合 ∩ IdP の広告集合」が成立しない |
| `enterprise-sso:jwks:{issuer の sha256}` | `array<int, array<string, string>>`（JWK の必要要素のみ。**素の配列**） |
| `enterprise-sso:jwks-refetched-at:{接続 id}` | `int`（UNIX 時刻。**スカラー**） |

読み戻しは **DTO へ明示的に組み立て直して検査**し、失敗したら `forget` して miss 扱いにする
（**破損 / 空配列 / 未知の値**のいずれでも `forget` する）。
`CachePayloadPlainDataGateTest` の目録へ本サービスを登録する（F2）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`requireString` / `requireHttpsUrl` が存在と型を確定させる）
- [x] DTOを返している（配列返却なし）
- [x] Genericsの型パラメータが正しい（`non-empty-list<TokenEndpointAuthMethod>`）

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php`
  - issuer 不一致を拒否する
  - **endpoint が別 origin でも受理する**（実在の IdP を拒否しないことの回帰）
  - endpoint が http / userinfo つき / fragment つきなら拒否する。**query つきは受理する**
  - **パス付きの issuer で well-known の URL が正しく組み立つ**
  - **末尾スラッシュの有無で issuer が別物として扱われる**（正規化していないことの回帰）
  - `token_endpoint_auth_methods_supported` の **欠落 / 空配列 / 未知値だけ /
    basic と post の混在**を**別々に**検査する（欠落は basic として受理する）
  - `id_token_signing_alg_values_supported` の **欠落 / 空配列 / アプリの許可集合と交わらない**を拒否する
  - 3xx 応答を**成功として扱わない**
  - サイズ上限超過を拒否する
  - JSON でない / オブジェクトでない応答を拒否する
  - 対応する client 認証方式が無い IdP を拒否する
  - **キャッシュの破損値・空配列・未知の値を読み戻したら `forget` して取り直す**
    （`auth_methods` と `id_token_signing_algorithms` の両方について）
  - **キャッシュ hit の後でも B3 の「広告集合との共通部分」が成立する**
    （広告された署名方式が保存されていることの回帰）
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryPinnedPathTest.php` —
      **実装が `PinnedHttpClient` を通る**（ssrf-pin のテスト seam で観測。F4）
- [ ] 新規 `tests/Unit/ValueObjects/OidcIssuerUrlTest.php` —
      http / userinfo つき / query つき / fragment つき / 相対 URL を拒否し、
      **末尾スラッシュを正規化しない**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- pin 済み経路に一本化することで、`PinnedHttpClient` の障害が discovery の単一障害点になる。
  これは**意図した集約**であり、迂回路を作らないことが不変条件 I3 の実体である。

---

## B2: トークン交換（body 付き pinned 要求）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcTokenExchanger.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php`
- 新規: `app/Support/EnterpriseSso/BasicCredentials.php`（RFC 6749 §2.3.1 準拠の符号化）

### 変更後コード（要点）

```php
/**
 * 認可コードとトークンの交換。
 *
 * ★**本サービスは kent013/laravel-ssrf-pin ^0.4 の「要求 body を運べる pin 済み取得」を必要とする**。
 *   v0.2 系では実装そのものが成立しない (正典が明記)。前段 TODO ssrf-pin-v04-upgrade が先行する。
 *
 * ## 秘密を漏らさないための 4 点
 *
 *  1. **vendor の例外を外へ連結しない** — previous に載せると、要求 body (認可コード /
 *     client secret / code_verifier) が例外の連鎖からログへ展開されうる。
 *     境界で**固定の理由コードの例外**へ変換する。
 *     ★`EnterpriseSsoAttemptRejectedException` は **`previous` を受け取れない構築子**を持つ
 *     (理由の enum だけを受ける)。型で連鎖が起きない (F1 の G3 と対)
 *  2. 平文を受ける引数に **`#[SensitiveParameter]`** を付ける (スタックトレースに出さない)
 *  3. client secret は **ConnectionSecret::revealForTokenExchange()** で
 *     ここでだけ平文化する (呼び出し元は gate が exact-fit で pin する)
 *  4. client 認証は **client_secret_basic を優先** (body 漏洩面が小さい)。
 *     IdP が対応しない場合だけ client_secret_post へ落とす
 */
final readonly class OidcTokenExchanger
{
    public function __construct(private PinnedHttpClient $pinned) {}

    public function exchange(
        OrganizationOidcConnection $connection,
        OidcProviderMetadata $metadata,
        #[SensitiveParameter] string $code,
        #[SensitiveParameter] string $codeVerifier,
    ): OidcTokenResponse {
        $method = $this->chooseAuthMethod($metadata);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => route('enterprise-sso.callback'),
            'client_id' => $connection->client_id,
            'code_verifier' => $codeVerifier,            // ★PKCE の往復の片端
        ];

        $headers = [];
        if ($method === TokenEndpointAuthMethod::ClientSecretBasic) {
            // ★RFC 6749 §2.3.1: client_id と client_secret を
            //   **application/x-www-form-urlencoded の規則で符号化してから** `:` で連結し base64 する。
            //   自前の rawurlencode 連結にしない (空白・`+`・`:`・非 ASCII で壊れる)。
            $headers['Authorization'] = BasicCredentials::header(
                $connection->client_id,
                $connection->clientSecret()->revealForTokenExchange(),
            );
        } else {
            $form['client_secret'] = $connection->clientSecret()->revealForTokenExchange();
        }

        $result = $this->pinned->fetch(
            PinnedRequest::post($metadata->tokenEndpoint, $form, $headers)
                ->withoutRedirects()
                ->withMaxBodyBytes(Config::integer('enterprise-sso.token.max_body_bytes')),
            // ★期限の渡し方は **^0.4 の確定 API に合わせて実装時に確定する**
            //   (接続と全体を別々に受けない API なら token.connect_timeout_seconds を設定ごと削除する)。
            //   存在しない API を確定形として残さない。
            $this->deadlineFromConfig(),
        );

        // ★fetch() は PinnedResponse|PinnedFailure を返す。**失敗は値で返る**ので
        //   catch では捕まらない。明示的に分岐して固定の理由コードへ変換する。
        if ($result instanceof PinnedFailure) {
            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenExchangeFailed);
        }

        // DTO を組み立てる **前に** 応答の形を確定させる。
        //   2xx / body 上限 / JSON オブジェクト / 必須の id_token
        return OidcTokenResponse::fromPinnedResponse($result);
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`PinnedFailure` と `PinnedResponse` を型で分岐する）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcTokenExchangerTest.php`
  - `code_verifier` が要求に載る（PKCE の往復の片端）
  - IdP が `client_secret_basic` に対応していれば **body に client_secret を載せない**
  - 対応方式が無ければ拒否する
  - **`PinnedFailure` が値で返ったときに固定の理由コードの例外になる**（catch では捕まらない経路）
  - 3xx を成功として扱わない
  - body 上限超過 / JSON でない / オブジェクトでない / **`id_token` が無い**応答を拒否する
  - ★漏洩の検査は**2 つに分ける**（実送信には資格情報が必ず在るので、混ぜると成立しない）:
    - **実送信要求**（transport の seam が捕らえるもの）: Basic なら Authorization ヘッダに、
      post なら body に、**資格情報が正しく含まれる**
    - **ログ / 監査 / 例外 / 診断用の HTTP 履歴**: client secret / 認可コード / トークンが
      **平文・base64・form-urlencoded のいずれの形でも残らない**
      （G3 の実挙動側の裏取り。**主たる証明はここにある**）
- [ ] 新規 `tests/Unit/Support/BasicCredentialsTest.php` —
      空白・`+`・`:`・非 ASCII を含む資格情報が RFC 6749 §2.3.1 のとおり符号化される
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### 着手条件
- 前段①（`ssrf-pin-v04-upgrade`）が完了していること
- ★**^0.4 の確定 API へ本設計を追随済みであること**（`PinnedRequest` の body の載せ方・
  期限の渡し方・`PinnedFailure` の形）。追随の結果
  `token.connect_timeout_seconds` が使えないと分かったら**設定ごと削除する**
  （「参照されない設定を作らない」を守る）

### リスク
- 前段の版上げが未了だと本施策は着手できない（TODO の依存に明記する）。

---

## B3: ID トークンの検証（`firebase/php-jwt`）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php`
- 変更: `composer.json` / **`composer.lock`**（`firebase/php-jwt` を**直接依存として明示**。
  既に v7.0.5 が解決済みなので解決版は変わらないが、`composer.json` を触る以上
  `composer.lock` も同じ変更でコミットする = AGENTS.md の worktree 規則）

### 拒否条件（deny-by-default。1 つでも該当したらその試行を拒否）

| 層 | 拒否する条件 |
|---|---|
| JWT の形 | malformed（3 セグメントでない / base64url でない / ヘッダが JSON でない） |
| ヘッダ | `alg` が `OidcSigningAlgorithm` の case でない（`none` / HMAC は enum に無い）／**`alg` が IdP の広告集合（`id_token_signing_alg_values_supported`）に無い**（= アプリの許可集合と広告集合の**両方**に入ることを要求する）／`kid` の欠落 |
| JWKS | `kid` に一致する鍵が無い（→ **再取得を 1 回だけ**）／**`kid` の重複**／`kty` が `alg` と不整合／EC の `crv` が `alg` と不整合／**`use` が存在するのに** `sig` でない／**`key_ops` が存在するのに** `verify` を含まない（★`use` と `key_ops` はどちらも optional。存在するときだけ検査する。欠落を理由に有効な鍵を拒否しない） |
| 署名 | 検証に失敗した |
| claim の型 | `iss` / `sub` / `nonce` が文字列でない／`aud` が文字列でも文字列配列でもない／`exp` / `iat` / `nbf` が整数でない |
| claim の値 | `iss` が登録済み issuer と不一致／`sub` が空・長さ超過／**`exp` の欠落**／**`iat` の欠落**／`exp` 超過／`iat` が未来／`nbf` が未来（いずれも `leeway_seconds` の範囲で）／`nonce` の指紋が試行と不一致 |
| audience（★論理和で書かず 3 条に分ける） | (1) **`aud` は必ず client_id を含む** / (2) **`aud` が複数なら `azp` は必須** / (3) **`azp` が存在するなら文字列で client_id と一致** |

> **`firebase/php-jwt` の戻り値も信頼済みの型と見なさない。**
> `JWT::decode()` は `stdClass` を返す。各 claim について**存在と具体型を再検査してから**
> `VerifiedIdTokenClaims` を組み立てる（`mixed` を DTO の中へ押し込めない）。

### 鍵ローテーションの追従（[Warning] への回答）

```php
/**
 * 未知の kid での鍵の再取得。
 *
 *  - **接続 id 単位のロック**を取り、同時要求でも再取得が 1 回になる
 *  - 最終再取得時刻を **スカラー**でキャッシュに持ち、
 *    jwks_refetch_min_interval_seconds の内側では再取得しない (増幅を防ぐ)
 *  - **ロック基盤の障害時はその試行を拒否する** (再取得を無制限に許さない)
 *  - 再取得は **1 回だけ**。それでも見つからなければ拒否する
 */
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`VerifiedIdTokenClaims`）
- [x] null安全（claim ごとに存在と型を検査してから構築）
- [x] DTOを返している
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseIdTokenVerifierTest.php` —
      **上表の拒否条件を 1 行ずつ dataset で負例にする**（malformed / `alg: none` / HMAC 署名 /
      `kid` 欠落 / **`kid` 重複** / `kty` 不整合 / `crv` 不整合 / `use` が `enc` / `key_ops` に `verify` なし /
      署名不一致 / `aud` の型不正 / `exp` 欠落 / `iat` 欠落 / `iss` 不一致 / `aud` に自分がいない /
      **複数 audience で `azp` が無い** / `azp` 不一致 / `sub` 欠落・空・長さ超過 / `exp` 超過 / `nonce` 不一致）
- [ ] **広告外の `alg`** で署名されたトークンを拒否する（アプリの許可集合には在るが IdP が広告していない場合）
- [ ] **正のコントロール**: `use` と `key_ops` を**持たない**有効な鍵が受理される
      （optional な項目の欠落で有効な IdP を拒否しないことの回帰）
- [ ] 鍵ローテーション: 未知 `kid` で**再取得が 1 回だけ**起き、最小間隔の内側では再取得しない
- [ ] **同時要求でも再取得が 1 回**（接続 id 単位のロック）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 拒否条件が多いので「正常系が通らない」障害の切り分けが難しくなる。
  理由コードを条件ごとに分けて**ログには理由コードだけ**を出す（利用者への応答は一様）。

---

## B4: ログイン試行の保管（原子的 consume + ブラウザ結合）

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php` / `AttemptConsumeResult.php`
- 新規: `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php`
- 変更: `routes/console.php`（日次の掃除を登録。準拠実装 `idempotency:prune`）

### 変更後コード（**トランザクションの巻き戻しバグを直した形**）

```php
/**
 * ログイン試行の保管。
 *
 * ## 不変条件 (これが正本。「1 文で書く」は手段ではない)
 *
 *   **同じ試行の使用権を、ちょうど 1 つの要求だけが得る。**
 *   **かつ、その試行を開始したブラウザだけが使える。**
 *
 * ## 契約する DB は pgsql である
 *
 * phpunit.xml が DB_CONNECTION=pgsql を force しており、**テストも本番も pgsql** である
 * (SQLite は使わない)。したがって `SELECT … FOR UPDATE` の排他契約は本番と同じである。
 * ★「ドライバに依存しない」とは書かない — SQLite の FOR UPDATE は同じ契約を持たない。
 *
 * ## なぜセッションに置かないか
 *
 * 同一セッションへの並行要求は route 側で `->block()` を書かない限り直列化が
 * 保証されず、「普通の get() + forget() を書いても契約を満たしたと誤認できる」形になる。
 *
 * ## なぜブラウザ結合が要るか (login CSRF)
 *
 * state の役割は「推測不能であること」だけではない。**その認可要求を開始した
 * ユーザーエージェントに結び付いていること**が要る。グローバルな表だけを根拠にすると、
 * 攻撃者が自分のブラウザで開始し自分の IdP アカウントで認可した callback URL を
 * 被害者に開かせることで、**被害者のブラウザが攻撃者のアカウントへログインする**。
 *
 * ## ブラウザ結合の秘密の一生
 *
 *  - 開始時に **CSPRNG で 32 バイト**生成する
 *  - セッションのキーは **state の指紋ごとに分ける** (複数タブが共存できる)
 *  - callback で取り出して照合する。**取り出したキーの値が非空の文字列でなければ、
 *    外向き取得を始める前に一様拒否する**
 *  - 破棄の規則:
 *    - **行が不可逆に consume された** (成功 / 期限切れで削除された) 失敗と成功では、
 *      **対応するセッションの値も削除する** (再開できない試行の秘密を残さない =
 *      開始と失敗を繰り返してセッションが太らない)
 *    - **結合の不一致のように行を保持する**場合は**秘密も保持する**
 *      (攻撃者が被害者の結合を消せる形にしない)
 *
 * ## 保存の形
 *
 * | 項目 | 形 |
 * |---|---|
 * | state | 指紋だけ (原文を保存しない) |
 * | nonce | 指紋だけ |
 * | ブラウザ結合 | セッションへ置いた秘密の指紋 (session ID は保存しない) |
 * | PKCE の検証子 | 交換でそのまま送るので原文が要る → 暗号化して保存 |
 *
 * 指紋は **用途別のラベルで導出する** ({@see AttemptFingerprint})。
 *
 * ## 保証しないもの
 *
 * - セッション cookie ごと奪われた場合のブラウザ結合は破れる (結合はセッションの秘密に依存する)
 * - 期限切れ行の掃除は日次の実行点とオンアクセスの二段であり、**即時削除ではない**
 */
final readonly class EnterpriseLoginAttemptStore
{
    /**
     * 使用権を取得する。取得できた要求だけが値を読み出せる。
     *
     * ★**業務上の拒否では例外を投げない。DB・基盤の障害は例外として伝播し巻き戻す。**
     *   - **業務上の拒否**（行が無い / 期限切れ / ブラウザ結合の不一致）は
     *     すべて {@see AttemptConsumeResult} の分類として**返す**。ここを例外にすると、
     *     同じトランザクションで行っている**期限切れ行のオンアクセス掃除まで巻き戻り**、
     *     「オンアクセスでも掃除する」が成立しない。
     *   - **DB・基盤の障害**（{@see EnterpriseSsoAttemptStoreFailure} と、その他の
     *     予期しない例外）は**握り潰さず伝播させ**、トランザクションごと巻き戻す。
     *     ★このときオンアクセス掃除が巻き戻ることは**受け入れる** —
     *     掃除の正本は日次の実行点であり、オンアクセスはその前倒しに過ぎない。
     *     基盤の障害を分類へ混ぜると、**壊れていることが「普通の失敗」として見えなくなる**。
     * ★**本メソッドは業務上の拒否について例外を投げず、分類結果をそのまま返す**。
     *   呼び出し側 ({@see EnterpriseCallbackAuthenticator}) が
     *   「行が消えた失敗か / 行を保持した失敗か」で**セッションの秘密の始末を分け**、
     *   その後で**外向きの一様な例外へ変換する**。
     *   (HTTP の応答が一様であることと、内部で理由を区別することは両立する。)
     */
    public function consume(string $state, #[SensitiveParameter] string $browserBindingSecret): AttemptConsumeResult
    {
        return DB::transaction(function () use ($state, $browserBindingSecret): AttemptConsumeResult {
            $row = EnterpriseSsoLoginAttempt::query()
                ->where('state_fingerprint', AttemptFingerprint::of(FingerprintPurpose::State, $state))
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return AttemptConsumeResult::notFound();
            }

            if ($row->expires_at->isPast()) {
                $row->delete();                       // ★この削除は commit される
                return AttemptConsumeResult::expired();
            }

            $expected = AttemptFingerprint::of(FingerprintPurpose::BrowserBinding, $browserBindingSecret);
            if (! hash_equals($row->browser_binding_fingerprint, $expected)) {
                // ★行を消さない (攻撃者が被害者の試行を消せる形にしない)。
                return AttemptConsumeResult::bindingMismatch();
            }

            // ★`delete()` が真を返さないのは **DB の障害**であって業務上の拒否ではない。
            //   一様な拒否へ握り潰すと「排他が壊れた」という重大な事実が隠れる。
            //   例外を投げてトランザクションを巻き戻す (行もセッションの秘密も残る)。
            if ($row->delete() !== true) {
                throw new EnterpriseSsoAttemptStoreFailure('attempt row delete did not affect a row');
            }

            // 行をそのまま外へ出さない。具体型・期限・復号結果を検査して DTO へ畳む。
            return AttemptConsumeResult::consumed(ConsumedLoginAttempt::fromModel($row));
        });
    }
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`first()` の null を早期に処理。アーリーリターン）
- [x] DTOを返している（`ConsumedLoginAttempt`。Eloquent モデルを外へ出さない）
- [x] Genericsの型パラメータが正しい

### セッションの秘密の始末は誰がやるか（Round 3 の [Critical] への回答）

`consume()` が**返す**分類は 4 通り（成功 / 不在 / 期限切れ / 結合の不一致）である。
これらは**業務上の判定**であり、例外ではない。
一方、`delete()` が行に当たらないような **DB の障害は例外**（`EnterpriseSsoAttemptStoreFailure`）として
投げ、トランザクションを巻き戻す — **業務上の拒否と基盤の障害を混ぜない**
（混ぜると「排他が壊れた」という事実が一様な拒否に隠れる）。
このうち**行が不可逆に消えたのは「成功」と「期限切れ」**で、
**行が残っているのは「不在」（そもそも無い）と「結合の不一致」**である。

`EnterpriseCallbackAuthenticator`（application service）が調停する:

| 分類 | 行 | セッションの秘密 | 外向きの応答 |
|---|---|---|---|
| 成功 | 消えた | **消す** | ログイン確定へ進む |
| 期限切れ | 消えた | **消す** | 一様な失敗 |
| 不在 | 無い | **消す**（再開できる試行が無い） | 一様な失敗 |
| 結合の不一致 | **残る** | **残す**（攻撃者が被害者の結合を消せる形にしない） | 一様な失敗 |
| （例外）DB の障害 | **残る**（巻き戻る） | **残す** | 500（一様な失敗に畳まない） |

外向きの応答は 4 通りとも**同一**である。区別は内部にだけ存在する。

### 並行テストの土台（Round 2 の [Critical] への回答）

グローバル `RefreshDatabase` の下では、テストのトランザクションの中で作ったフィクスチャは
**別接続から見えない**。したがって「2 接続を使う」だけでは並行テストは成立しない。

**別 TODO `process-concurrency-harness-adoption` のハーネスに乗る**（前段依存の 2 本目）。
本設計が使うのはその 6 要素のうち:

- **transaction 外フィクスチャ**（子から見えるように独立接続で作り、末尾で明示的に片付ける）
- **ready / go ファイルによる同期点**と**締切つきの待ち**
- **子のキャッシュ store を配列固定**（アプリ側のロックを共有させず、**DB 層だけで守れる**ことを確かめる）

正典の規定どおり **実プロセス版は 1 本に絞る**（重いため）。細かい分岐は同一プロセスのテストへ回す。
B4・C1・C2 の並行テストは**同じハーネスを共有**する。

★**D1 の `verify` の割り込みテストは、このハーネスに乗せない**。理由は形が違うからである。
B4 / C1 / C2 が要るのは「**2 つの DB トランザクションを本当に同時に走らせる**」ことで、
これは実プロセスでないと作れない。一方 `verify` で要るのは**順序**だけである —
「スナップショットを取った**後**・応答を返す**前**に更新が割り込む」が作れれば足りる。

★**待ち合わせ（ready / go）を使わない**（Round 6 の [Critical] への回答）。
同一プロセスで「callback が ready を立てて go を待つ」形にすると**必ずデッドロックする**:
PHP の呼び出しは同期なので、(1) テストが `verify()` を呼ぶ → (2) callback が go 待ちで止まる →
(3) `verify()` が戻らないので**テストは更新も go の作成もできない**。

★採る形は**同期の割り込み注入**である。偽 IdP（F4）の応答直前の callback が、
**そのまま自分で割り込みを行って戻る**:

```php
// ★**基準の段数を先に取る**。グローバル RefreshDatabase がテスト全体を
//   1 つのトランザクションで包むので、**Feature レーンでは通常 1 であって 0 ではない**。
//   本番では 0 だが、テストで 0 を期待すると**新設テストが必ず赤になる**。
//   ここで保証したいのは「**第 2 段が段数を増やしていない**」ことであって、絶対値ではない。
$baselineLevel = DB::transactionLevel();

$fake->beforeRespond(function () use ($organization, $connection, $baselineLevel): void {
    // ★この時点は「スナップショットを取った後・応答を返す前」である。

    // (a) 第 2 段が段数を増やしていない
    expect(DB::transactionLevel())->toBe($baselineLevel);

    // (b) ここで認証材料を更新する = 割り込みそのもの。待たない。
    //     ★**この更新が完了することを「ロックを保持していない証明」として使わない**
    //       (Round 8 の注意)。同一プロセス・**同一の DB 接続**では、自分が既に取った
    //       行ロックは**再入できる**ので、ロックを持っていても止まらない。
    //       ロックを持っていないことの主たる根拠は (a) の段数の相対比較と、
    //       三段構成のコードの配置そのものである。
    $this->transitions->update($organization, $connection, issuer: 'https://new.example.test');

    // (c) 割り込みの後も基準へ戻っている (更新が段を開いたまま抜けていない)
    expect(DB::transactionLevel())->toBe($baselineLevel);
});
```

- **時間に依存しない**（sleep も締切も要らない）。順序は呼び出しの構造そのものが保証する
- **新しい同期の道具を足さない**（ready / go すら使わない）
- ★**段数は基準との相対で見る**（絶対値の 0 で書かない）。
  本番は 0、グローバル `RefreshDatabase` の下では通常 1 になる

★この分割は手抜きではなく**保証の切り分け**である。`verify` の線形化が依存しているのは
(1)「取得の間ロックを持たない」(2)「ロックの中で版と値を比べる」の 2 点で、
**本テストが直接示すのは (1) と (2) の判定そのもの**である。

★**保証しないことも書く**: 上記は「**同時に走る 2 つの `verify` の第 3 段が互いに排他される**」を
**直接は示さない**（同一プロセスの待ち合わせでは 2 つの実トランザクションを同時に走らせられない）。
第 3 段の排他が依拠するのは `lockForUpdate()` という**同じ 1 つの機構**であり、
それが実プロセスで効くことは **B4 の実プロセス版 1 本**が示している。
つまりここは「**機構は別途証明済み、本テストは適用箇所を証明する**」という
2 段の論拠であって、`verify` の同時実行そのものの実測ではない。

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php`
  - **実プロセスの並行 1 本**（上記ハーネス）: 1 本目が行ロックを保持している間に 2 本目を開始し、
    **片方だけが成功する**（`--parallel` を同時アクセスの代用にしない）
  - **別のブラウザで callback URL を開くと失敗する**（login CSRF。結合不一致で拒否）
  - 結合不一致では**行が消えない**（他人の試行を消せない）
  - **期限切れの行は拒否と同時に消える**（トランザクションが巻き戻らないことの回帰）
  - 用途別の指紋が相互に使い回せない（`state` の指紋を結合の指紋として使えない）
  - 複数タブで同時に開始しても互いの結合の秘密を壊さない
  - **結合の秘密がセッションに無い / 非文字列のとき、外向き取得を始めずに拒否する**
  - **DB の障害（削除が行に当たらない）が一様な拒否に畳まれず例外になる**（負のコントロール）
  - **不可逆に consume された失敗ではセッションの秘密も消える**／
    **結合の不一致では行もセッションの秘密も残る**
- [ ] 新規 `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` —
      期限切れ行だけが消え、**進行中の通常の試行を巻き込まない**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 行ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
  **consume はトランザクションを閉じてから外向き取得を始める**（C2 の並び順で保証する）。

---

## C1: 利用者の自動作成 (always-JIT)

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseUserProvisioner.php`

### 変更後コード（要点）

```php
/**
 * 初回ログインでの利用者の自動作成 (always-JIT)。
 *
 * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
 *   引き当ての鍵は **接続 id と生の subject** だけである
 *   (列の照合順序が `COLLATE "C"` なので**バイト一致**。A2 の移行 2 が正本)。
 *   ★**指紋 (HMAC) にしない** — 鍵に依存する値を鍵にすると、APP_KEY をローテートした瞬間に
 *     既存の身元へ到達できなくなり**アカウントが分裂する**。列の照合順序なら鍵に依存しない。
 *   申告メールは EnterpriseIdentity に暗号化して持つが、**引き当てには使わない**。
 *
 * ## 作る利用者の姿 (A3 と一体)
 *
 *  - `email` = **null** (企業 SSO でしか入れない利用者は使えるメールを持たない。
 *    仮のメール文字列を作らない — 偽のメールは衝突と誤送の温床である)
 *  - `email_verified_at` = **now()** (「IdP が本人確認した。確認すべきメールが無い」の意味。
 *    既存の verified middleware の意味論を変えずに通す)
 *  - `password` = **null** (パスワードは持たない。初回設定は既存の settings.password.store が担う)
 *  - `name` = ID トークンの `name` claim があればそれ、無ければ表示用の既定値
 *  - 所属は **接続が属する組織のみ**、役割は **OrganizationRole::Member** (最小)。
 *    付与のすべてで **組織の team id を明示する** (AGENTS.md 不変条件 5)
 *
 * ## 並行初回ログインの競合
 *
 * ★**競合制御は C2 が張る接続の行ロックが唯一の担い手である**。
 *   同一接続の callback は行ロックで直列化されるので、事前検索 → 作成の間に
 *   別の要求が割り込むことがない。
 *  - 利用者の作成・身元の作成・組織所属の作成は
 *    **C2 が開いた 1 トランザクション** (接続の行ロックを含む) の中で行う
 *  - 身元表の enterprise_identities_connection_subject_unique は
 *    **最後の防波堤として残す**が、**捕まえない** (上のコード参照)
 *  - 失敗すればトランザクション全体が巻き戻るので**孤児は残らない**
 */
/**
 * ★本メソッドは **C2 が張った接続の行ロックの中**で呼ばれる (線形化点は C2 が持つ)。
 *   ここでトランザクションを開き直さない。
 */
public function resolve(OrganizationOidcConnection $connection, VerifiedIdTokenClaims $claims): User
{
    // ★relation 起点で引く。クラス起点 (EnterpriseIdentity::query()->where('connection_id', …)) で
    //   書かない — 組織スコープの出所を型と relation で固定する (AGENTS.md 不変条件 3)。
    //   引き当ての鍵は subject の値そのもの (列の照合が COLLATE "C" なので byte 一致)。
    $existing = $connection->identities()->where('subject', $claims->subject)->first();
    if ($existing !== null) {
        return $existing->user;   // アーリーリターン
    }

    // ★一意制約違反を**捕まえない**。理由は 2 つ:
    //   (1) C2 が接続の行を lockForUpdate() しているので、同一接続の callback は既に直列化されており、
    //       正規経路でこの競合は起きない (競合制御は行ロックが唯一の担い手である)
    //   (2) pgsql は一度 SQL エラーが出るとトランザクション全体が aborted になり、
    //       **同じトランザクションの中では再検索できない** (savepoint まで戻さない限り次の SELECT も失敗する)。
    //       つまり「catch して引き当て直す」は**そもそも動かない**。
    //   一意制約は**最後の防波堤として残す**が、捕まえない。予期しない違反はそのまま伝播させる
    //   (握り潰すと、直列化が壊れたという重大な事実が「競合」として隠れる)。
    return $this->createUserWithIdentityAndMembership($connection, $claims);
}
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全（`findIdentity` の null を早期に処理）
- [x] DTOを返している（`User` は認証境界の値。画面へは DTO 経由で出す）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseUserProvisionerTest.php`
  - 初回で利用者・身元・所属が 1 件ずつできる
  - 作られた利用者は `email = null` / `email_verified_at != null` / `password = null`
  - 役割が `Member` で、**別組織の役割が参照されない**（team id を明示していることの実挙動）
  - **同じ申告メールを持つ別 subject が別の利用者になる**（メールで引かないことの裏取り）
  - **大小文字違いの subject が別の利用者になる**
  - **並行初回ログインでも 1 利用者・1 身元・1 所属だけが成立する**（並行ハーネス。
    証明の主体は**接続の行ロック**である）
  - 失敗した側に**孤児の利用者が残らない**
  - **一意制約違反を握り潰さない**（意図的に違反を起こすと例外が伝播する = 負のコントロール）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- always-JIT は「接続が有効な組織の IdP が subject を出せば誰でも入れる」ことを意味する。
  絞りは**接続の有効・無効**（D1）だけである。これは正典の形であり、追加の絞りを足さない。

---

## C2: 開始と戻り口・controller・route 3 本

### 変更箇所
- 新規: `app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php`
- 新規: `app/Http/Controllers/Auth/EnterpriseSsoLoginController.php`
- 新規: `app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php`
- 変更: `routes/web.php`

### 波及変更
- **TypeScript型定義**: ログイン画面に企業ログインの導線（`resources/js/pages/Auth/Login.svelte` の Props）
- API Resource/DTO: なし（Inertia のリダイレクトのみ。**JSON API は新設しない**）
- **テストファイル**: F2 の 6 目録

### 開始側（[Critical] への回答）

```php
/**
 * 開始。**行を作ってからリダイレクトする** (逆順だと戻ってきた state が存在しない)。
 *
 *  1. 接続を slug で解決し、**Active であること**を確かめる
 *  2. CSPRNG で state / nonce / PKCE の検証子 / ブラウザ結合の秘密を各 32 バイト生成し、
 *     base64url で符号化する
 *  3. ブラウザ結合の秘密を**セッションへ置く** (キーは state の指紋ごとに分ける)
 *  4. 試行の行を作る (state / nonce / 結合の指紋 + 暗号化した検証子 + 期限)
 *  5. 認可要求の URL を組み立ててリダイレクトする
 *
 * 認可要求の必須引数:
 *   response_type=code / scope=openid / client_id / redirect_uri /
 *   state / nonce / code_challenge / code_challenge_method=S256
 */
```

### 戻り口（AG-200 の要）

```php
/**
 * 企業 SSO の戻り口。
 *
 * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点で Auth::login() で
 *   ログインを確定させ、画面へ送る。2 要素認証の入力画面へ転送する分岐を**持たない**。
 *   これは tests/Architecture/SsoTwoFactorInterpositionGateTest が
 *   企業・ソーシャルの両 controller に対して静的に裏当てし、
 *   主たる証明は tests/Feature/Auth/EnterpriseSsoLoginTest の実挙動側にある。
 *
 * ## 順序 (理由つき)
 *
 *  1. **入力の検査** (FormRequest) — スカラー型・長さ上限・code と error の排他。
 *     ★不正な入力では**外向き取得を一切開始しない**
 *  2. IdP の error 応答は一様な失敗として扱う
 *  3. セッションから**結合の秘密**を取り出す (state の指紋から試行ごとのキーを導く)。
 *     **非空の文字列でなければ、外向き取得を始めずに一様拒否する**
 *  4. **consume** (試行の行のロック。トランザクションを**閉じる**) —
 *     ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
 *     ★`consume()` は**投げずに分類を返す**ので、**本サービスが**
 *     「行が消えた失敗ならセッションの秘密も消す / 結合の不一致なら残す」を決め、
 *     そのうえで**外向きの一様な例外**へ変換する (B4 の表)
 *  5. 外向き取得 (discovery → token 交換 → JWKS) と ID トークンの検証。
 *     ★この間はどのロックも持たない
 *  6. **線形化の区間**: 1 つのトランザクションで
 *       接続の行を `lockForUpdate()` → **Active を確認** → **JIT** → commit
 *  7. Auth::login(remember: false) → session()->regenerate() → 結合の秘密を破棄
 *
 * ## 無効化 (disable) との線形化 (Round 2 の [Critical] への回答)
 *
 * 「Active を 2 回読む」だけでは競合を閉じられない (最終確認の直後に disable が commit され、
 * その後ログインが確定する窓が残る)。また JIT を確認より前に置くと、
 * **拒否されたのに利用者・身元・所属だけが残る**。
 *
 * ★**線形化点を接続の行ロックに定める**。上の 6 が線形化の区間であり、
 *   {@see OidcConnectionTransitionService} の無効化も**同じ行を `lockForUpdate()` する**。
 *   したがって両者は直列化され、次の 2 つが成り立つ:
 *     - **無効化が先に線形化したら、JIT もログインも起きない** (Active の確認で落ち、
 *       同一トランザクションなので副作用が巻き戻る)
 *     - **callback が先なら、無効化はその後に成立する** (次回から入れない)
 *   commit の後・Auth::login の前に disable が入る窓は残るが、それは
 *   「無効化より前に線形化したログイン」であり、**既存セッションの即時失効はスコープ外**という
 *   本設計の主張と整合する。
 *
 * ## 身元の名前空間を壊さない (Round 3 の [Critical] への回答)
 *
 * OIDC の身元は実質 **(issuer, subject)** であり、pairwise subject では
 * **client_id も名前空間を変えうる**。したがって、同じ接続の issuer や client_id を
 * 別の IdP のものへ変えた後に偶然同じ subject が返ると、**以前の利用者へ誤ってログインさせる**。
 * これを防ぐのは D1 の更新規則である —
 * **身元が 1 件でもある接続では issuer と client_id を変更できない** (新しい接続を作る)。
 * 本サービスは「接続 id で身元を引く」形のままでよい (名前空間の不変性を D1 が保証する)。
 *
 * ★`remember: false` である。remember cookie を許すと、接続を無効化した後も
 *   cookie から新しいセッションを開始できてしまい、
 *   「次回ログインができなくなる」という効果の主張と整合しない。
 */
public function callback(EnterpriseSsoCallbackRequest $request, EnterpriseCallbackAuthenticator $authenticator): RedirectResponse
{
    $user = $authenticator->authenticate($request->validatedInput());   // 失敗はすべて一様に例外

    Auth::login($user, remember: false);
    $request->session()->regenerate();

    return redirect()->intended(route('dashboard'));
}
```

### route

```php
/*
|--------------------------------------------------------------------------
| エンタープライズ OIDC SSO (組織 OIDC 接続 + always-JIT)
|--------------------------------------------------------------------------
| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
| リダイレクト先 IdP に適用されるため。social.redirect と同じ理由)。
|
| ★**この経路にアプリ側の 2 要素認証を挟まない** (家系の裁定 AG-200)。
|   確認できた時点でログインを確定させる。組織義務づけの強制は別関門
|   (RequireTwoFactorForEnforcedOrganizations) が**ログイン確定後**に
|   アカウント全体のゲートとして担い、転送先は 2 要素の**設定ページ**である。
*/
Route::get('/enterprise/login', [EnterpriseSsoLoginController::class, 'show'])
    ->name('enterprise-sso.login');

// ★GET だが **DB に試行の行を作る変更操作**である (OAuth の開始)。
//   CSRF トークンの代わりに state・ブラウザ結合・流量制限・no-store が守る (F2 で分類)。
Route::get('/enterprise/{connectionSlug}/redirect', [EnterpriseSsoLoginController::class, 'redirect'])
    ->middleware(['throttle:enterprise-sso-start', 'no-store'])
    ->name('enterprise-sso.redirect');

// 戻り口。**未認証で外部へ HTTP を発射する経路**である (token 交換 + JWKS)。
Route::get('/enterprise/callback', [EnterpriseSsoLoginController::class, 'callback'])
    ->middleware(['throttle:enterprise-sso-callback', 'no-store'])
    ->name('enterprise-sso.callback');
```

### PHPStan適合チェック
- [x] 戻り値の型が明示されている
- [x] null安全（`Assert::isInstanceOf` で `User` を確定させる。既存 `SocialAuthController` と同形）
- [x] DTOを返している（`response()->json()` を書かない。FormRequest は入力 DTO へ変換する）
- [x] Genericsの型パラメータが正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EnterpriseSsoLoginTest.php`
  - **2 要素認証が有効な利用者も、企業ログインでそのままログインが確定する**（AG-200 の主証明①）
  - **組織義務づけの下でも、企業ログイン後に 2 要素の設定ページへ到達できる**（AG-200 の主証明②）
  - 開始で **行が作られてからリダイレクトする**（順序）
  - 認可要求に `scope=openid` / `code_challenge_method=S256` / `state` / `nonce` が載る
  - **不正な入力（配列・長さ超過・`code` と `error` の同時）では外向き取得を開始しない**
  - IdP の `error` 応答が一様な失敗になる
  - **開始後に接続を無効化するとログインできない**
  - **並行**（並行ハーネス）: 無効化と callback を同時に走らせ、
    **無効化が先なら JIT もログインも起きない**（利用者・身元・所属が残らない）／
    **callback が先なら無効化はその後に成立する**（次回から入れない）
  - **`remember` cookie を発行しない**
  - `session()->regenerate()` が確定後に走る（セッション固定化）
  - 失敗の応答が**一様**で、接続や利用者の存在を読み取れない
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseOidcRouteRoundTripTest.php` — 偽の IdP（F4）で route の往復
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **禁止事項 7**（操作系 POST で `redirect()->intended()`）に見えるが、本経路は**ログイン直後フロー**であり
  同項の明示的な適用範囲内である（既存 `SocialAuthController` と同じ形）。

---

## D1: 接続の状態遷移サービス

### 変更箇所
- 新規: `app/Services/EnterpriseSso/OidcConnectionTransitionService.php`
- 新規: `app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php`
  （`verify` の第 1 段が読む**認証材料のスナップショット**。
  ★**client secret の平文も値型も持たない** — 持つのは
  **暗号文そのものの SHA-256 digest** だけである（復号せずに「書き換わったか」だけを見る）。
  `$hidden` や `toArray()` の対象にもならない内部の値であり、**画面へ出さない**）
- 新規: `app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php`
  （`verified` / `alreadyVerified` / `staleCredentials` / `connectionGone` の 4 値。
  ★**画面へは一様に出さない** — これは運営の操作の結果なので、
  「材料が変わったのでやり直してください」と**具体的に伝える**。
  存在を隠す必要があるのは未認証の経路であって、認可を通った運営操作ではない）

### 変更後コード（要点。[Critical] への回答）

```php
/**
 * 接続の状態遷移。
 *
 * 許す遷移 (これ以外は例外):
 *   Draft            → Verified  (接続先情報の取得に成功した)
 *   Verified         → Active    (運営が有効にした)
 *   Active           → Disabled  (運営が止めた)
 *   Disabled         → Active    (運営が戻した。verified_at が残っている場合のみ)
 *   Verified/Active/Disabled → Draft  (★**client secret を更新した**)
 *
 * ## 更新の規則は 3 段に分かれる (Round 3 の [Critical] への回答)
 *
 * | 変えるもの | 規則 | 理由 |
 * |---|---|---|
 * | **issuer / client_id** | ★**身元が 1 件でもあれば変更禁止**。新しい接続を作らせる。**身元が 0 件なら変更できるが、その場合も必ず `Draft` へ戻し `verified_at` を消す** (未検証の新構成で直ちにログインできる状態を作らない) | OIDC の身元は実質 (issuer, subject) であり、pairwise subject では client_id も名前空間を変えうる。変えた後に偶然同じ subject が返ると**以前の利用者へ誤ってログインさせる** |
 * | **client_secret** | **Draft へ差し戻し + verified_at を消す** (再確認と再有効化が必須) | 名前空間は変わらないが、未検証の構成で直ちにログインできる状態を作らない |
 * | **表示名** | 状態を変えない | 認証に関与しない |
 *
 *  - 更新と状態変更は **同一トランザクション**で行う (片方だけが残る窓を作らない)
 *  - **身元が 1 件でもある接続は物理削除できない** (削除すると身元だけが消え、
 *    利用者が残ってアカウントが分裂する。運用は無効化で行う)
 *
 * ## 接続を変える操作はすべて接続の行をロックする (C2 との線形化)
 *
 * ★対象は **無効化だけではない**。`disable` / `activate` / `update` / `destroy` の**すべて**が
 * **接続の行を `lockForUpdate()` した同一トランザクション**で、
 * 「身元の有無の確認 → 検査 → 変更」を行う。
 * C2 の callback も同じ行をロックして「Active の確認 → JIT」を行うので、両者は直列化される。
 *
 * ★**`verify` だけはこの形にしない**。`verify` は外向き HTTP を伴うので、同じ形にすると
 *   **通信の間ずっと DB のロックを保持する**ことになり、B4 / C2 が避けている形と矛盾する。
 *   `verify` は下の**三段構成**で線形化する。
 *
 * ★ロックしないと次の競合が起きる:
 *   (1) 管理操作が「身元 0 件」を確認 → (2) callback が行をロックして JIT →
 *   (3) 管理操作が issuer を更新 / 物理削除
 *   = **身元があるのに名前空間が変わる / 身元だけが消える**。
 *
 * ★**ロック付きの再取得は 5 操作とも relation 起点に統一する** (Round 6 の [Critical] への回答)。
 *
 *     $organization->oidcConnections()->whereKey($id)->lockForUpdate()->first()
 *
 *   クラス起点の主キー同一性クエリ (`OrganizationOidcConnection::query()->whereKey(…)`) で書かない —
 *   AGENTS.md セキュリティ不変条件 3 が deny-by-default で分類を求める形であり、
 *   かつ**再取得の経路そのものが組織スコープを失う**。
 *   親の `$organization` は route の scoped binding が解決したものだけを受け取り、
 *   **payload 由来の組織 id を入れない** (不変条件 1)。
 *   ★入口の binding が済んでいても**再取得の側で改めて relation 起点にする**。
 *   「入口で確認したから中は自由」は、経路が増えたときに必ず崩れる。
 *
 * ★**ロックの取得順を統一する** (接続の行が唯一のロック対象。他の行を先に取らない)。
 * 保証されるのは次の 2 つである:
 *   - **callback が先なら**、更新・削除は「身元あり」として拒否される
 *   - **更新・削除が先なら**、callback は `Draft` 化 (または接続の不在) により JIT しない
 *
 * ## 取得の失敗で接続を殺さない
 *
 * IdP の 5xx・鍵ローテーションの途中・DNS の一時障害を理由に**自動で無効化しない**
 * (可用性の後退になる)。失敗はすべて「そのログイン試行だけを fail-closed で拒否する」に留め、
 * 接続の状態を変えるのは**本サービスを通した運営操作だけ**である。
 */
```

### `verify` だけは三段構成にする（Round 5 の [Critical] D1 / D2 への回答）

**解きたい競合**は次の 3 手順である。

1. `verify` が**旧**の issuer で discovery / JWKS を取得する
2. その間に `update` が issuer / client_id / client secret を変える
3. `verify` が接続の行をロックし、**新しい認証材料を旧い取得結果で `Verified` にする**

外向き取得の前にロックを取れば消えるが、それは**通信の間ロックを保持する**形であり、
B4 / C2 が避けている形と同じになる（IdP が遅い・落ちているときに管理操作が全部詰まる）。
そこで **`verify` だけを明示の三段構成**にする。

```php
/**
 * 接続先情報の取得に成功したことを確認し、Draft → Verified へ進める。
 *
 * ★**外向き取得の間、DB のロックを一切保持しない**。段は 3 つに分かれる。
 *
 *   第 1 段 (ロックなし): 検証の対象となる**スナップショット**を読む
 *   第 2 段 (ロックなし・トランザクションの外): 外向き取得と検証
 *   第 3 段 (トランザクション + 行ロック): 一致の再確認と遷移
 *
 * ★**第 2 段をトランザクションの中に入れない**。中に入れると、ロックを取っていなくても
 *   pgsql のトランザクションが外部 HTTP の往復のあいだ開きっぱなしになる
 *   (idle in transaction が積み上がる)。開くのは第 3 段だけである。
 */
public function verify(Organization $organization, OrganizationOidcConnection $connection): VerifyOutcome
{
    // ── 第 1 段: スナップショット (ロックなし)
    // ★client secret の**平文も値型も持たない**。verify は discovery と JWKS を取るだけで
    //   秘密を必要としない = **verify の経路は秘密を一度も復号しない** (D2 の DTO と同じ思想)。
    //   ただし「secret が変わったか」を復号せずに見るため、**暗号文そのものの digest** は持つ (下記)。
    $snapshot = ConnectionCredentialsSnapshot::of($connection);
    //   → readonly {int $connectionId, string $issuer, string $clientId,
    //               int $credentialsRevision, string $clientSecretCiphertextDigest}

    // ── 第 2 段: 外向き取得 (ロックなし・トランザクションの外)
    // 取得の失敗で接続の状態を変えない (上の「取得の失敗で接続を殺さない」)。
    $metadata = $this->discovery->fetch($snapshot->issuer);   // B1。PinnedHttpClient 経由

    // ── 第 3 段: 一致の再確認と遷移 (ここで初めてトランザクションと行ロック)
    return DB::transaction(function () use ($organization, $snapshot, $metadata): VerifyOutcome {
        // ★**relation 起点で引く**。クラス起点の主キー同一性クエリ
        //   (OrganizationOidcConnection::query()->whereKey(…)) で書かない —
        //   AGENTS.md セキュリティ不変条件 3 が deny-by-default で分類を求める形であり、
        //   かつ**再取得の経路そのものが組織スコープを失う**。
        //   親は scoped binding で解決済みの $organization であり、
        //   ★**payload 由来の組織 id をここへ入れない** (不変条件 1)。
        $fresh = $organization->oidcConnections()
            ->whereKey($snapshot->connectionId)
            ->lockForUpdate()
            ->first();

        // 接続が消えていた (または組織の外へ出た) → 結果を捨てる (アーリーリターン)
        if ($fresh === null) {
            return VerifyOutcome::connectionGone();
        }

        // ★**主の比較子は credentials_revision** である。
        //   認証材料 (issuer / client_id / client secret) を変える経路は D1 の 1 メソッドだけで、
        //   そこが必ず +1 する。1 つの整数で「材料が変わったか」を漏れなく表せる。
        if ($fresh->credentials_revision !== $snapshot->credentialsRevision) {
            return VerifyOutcome::staleCredentials();   // ★結果を捨てる。Draft のまま
        }

        // ★**第 2 の比較子**として、認証材料の**値そのもの**を 3 つとも突き合わせる。
        //   これは主の比較子の代わりではなく、「**+1 を忘れた書き手がいたら落ちる**」ための層である
        //   (revision は書き手の規律に依存する値なので、値を見る層を 1 枚重ねる)。
        //   ★**3 つとも見る**のが要点である。issuer / client_id だけだと、
        //     「**client secret を変えたのに +1 を忘れた**」場合に古い結果が採用されてしまい、
        //     この層が主張している「規律に依存しない」が client secret について成立しない。
        //   ★client secret は**復号しない**。**暗号文そのものの digest** を比べる —
        //     復号せずに「値が書き換わったか」だけを見られる (verify は平文を必要としない)。
        //     暗号文は保存のたびに変わりうる (同じ平文でも再暗号化で別の暗号文になる) ので、
        //     この比較は**空振りする側 = 拒否する側**へ倒れる。fail-closed であり安全側である。
        $freshDigest = hash('sha256', (string) $fresh->getRawOriginal('client_secret_encrypted'));

        if ($fresh->issuer !== $snapshot->issuer
            || $fresh->client_id !== $snapshot->clientId
            || ! hash_equals($snapshot->clientSecretCiphertextDigest, $freshDigest)
        ) {
            return VerifyOutcome::staleCredentials();
        }

        // ★**同じ材料を別の要求が既に Verified にしていた場合は、何もせず成功とする**。
        //   revision が一致している = 検証したのと同じ材料なので、これは競合ではなく重複である。
        //   遷移表に Verified → Verified を足さない (表を正確に保つ) 代わりに、
        //   ここで明示的に「遷移しない成功」として扱う。
        if ($fresh->status === OidcConnectionStatus::Verified) {
            return VerifyOutcome::alreadyVerified();
        }

        // Draft 以外 (Active / Disabled) からは遷移しない。定義外の遷移は例外。
        $this->transitionToVerified($fresh, $metadata);   // status = Verified, verified_at = now()

        return VerifyOutcome::verified();
    });
}
```

**認証材料を変える側（`update`）が守る規約**

```php
// ★issuer / client_id / client_secret のいずれかを変える**唯一の書き手**。
//   3 つを必ず 1 か所に閉じ込めるのは、credentials_revision の +1 を
//   「書き手が思い出す規律」ではなく「経路の性質」にするためである。
private function applyCredentialChange(OrganizationOidcConnection $locked, ...): void
{
    // …変更を適用…
    $locked->credentials_revision = $locked->credentials_revision + 1;   // ★必ず +1
    $locked->status = OidcConnectionStatus::Draft;                       // ★必ず Draft へ
    $locked->verified_at = null;
    $locked->save();
}
```

**比較子は 3 層である（Round 6 の [Critical] への回答）**

| 層 | 見るもの | 何を捕まえるか |
|---|---|---|
| **主** | `credentials_revision` | 認証材料の**あらゆる**変更（書き手が規律を守っている限り） |
| **第 2** | `issuer` / `client_id` の**実値** | ★**`+1` を忘れた書き手**（issuer / client_id を変えた場合） |
| **第 3** | `client_secret_encrypted` の**暗号文の digest** | ★**`+1` を忘れた書き手**（client secret を変えた場合）。★復号しない |

> Round 6 の指摘のとおり、第 2 層が issuer / client_id **だけ**だと、
> 「client secret を変えながら revision を増やし忘れた」場合に古い結果が採用されてしまい、
> この層の主張（**唯一の書き手という規律だけに依存しない**）が client secret について破れる。
> **暗号文の digest を比べれば、平文を復号せずに同じ層を張れる**。

**この形が保証すること / しないこと**

| | 内容 |
|---|---|
| **保証する** | 外向き取得の**開始から完了までの間**に認証材料が変わったなら、その `verify` の結果は**採用されない**（`Draft` のまま拒否される） |
| **保証する** | 外向き取得の**間、接続の行のロックを保持しない**（IdP が遅くても管理操作が詰まらない） |
| **保証する** | `verify` の経路は **client secret を一度も復号しない**（比べるのは暗号文の digest だけ） |
| **保証しない** | 「取得した瞬間に IdP 側が正しかった」こと。IdP は `verify` の**後**にいつでも構成を変えられる。`Verified` は**そのときの取得が成功した**という記録に過ぎず、以後の有効性の証明ではない |
| **保証しない** | 拒否された `verify` の**自動再実行**。運営がもう一度押す（拒否は画面にそのまま出す） |

> **なぜ `updated_at` で代用しないか**（Round 5 の指摘のとおり）: 時刻は精度によって
> 同一に見えうるうえ、**認証に関与しない表示名の更新まで巻き込んで** `verify` を落とす。
> 専用の版番号なら「認証材料が変わったときだけ」を正確に表せる。

### テスト計画
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcConnectionTransitionServiceTest.php`
  - 定義外の遷移が例外になる
  - **身元がある接続の issuer / client_id の変更が拒否される**
  - **拒否された後も、旧接続で既存の利用者へログインできる**
  - **身元が 0 件の接続なら issuer / client_id を変更できる**（正のコントロール）
  - **client secret の更新は Draft へ戻り `verified_at` が消える**
  - **表示名だけの更新では状態が変わらない**
  - **新しい接続で同じ subject が来ても、旧接続の利用者へは結合されない**
  - **身元がある接続の物理削除が拒否される**／**身元が 0 件なら削除できる**
  - **身元 0 件で issuer / client_id を変更すると `Draft` へ戻り `verified_at` が消える**
  - **並行**（並行ハーネス）: callback と「更新 / 削除」を同時に走らせ、
    **callback が先なら更新・削除が身元ありとして拒否される**／
    **更新・削除が先なら callback は JIT しない**
  - **discovery の失敗で接続の状態が変わらない**（可用性の後退がないことの証明）
  - 更新の途中で失敗したとき、更新と状態変更のどちらも残らない（同一トランザクション）
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcConnectionVerifyLinearizationTest.php`
      （`verify` の三段構成。**Round 5 の [Critical] が要求した並行テスト**）
  ★同期の割り込み注入（F4 の `beforeRespond`）で作る。**待ち合わせを使わない**
  （理由は B4「並行テストの土台」節。同一プロセスで待たせるとデッドロックする）
  - ★**本命**: **`verify` の外部取得中に認証材料を更新すると、古い `verify` の結果が採用されない** —
    `beforeRespond` の中で issuer を変えて戻る。`verify()` から戻ったあと、接続が
    **`Draft` のまま**で `verified_at` が null であることを確かめる
  - **client secret だけを変えた場合も採用されない**（issuer / client_id は同じなので、
    **revision と暗号文 digest のどちらか**が効いていることの証明）
  - ★**`credentials_revision` を据え置いたまま client secret だけ変えても採用されない**
    （**第 3 の比較子＝暗号文 digest の単独の証明**。DB へ直接書いて revision を増やさずに
    secret を差し替える。この 1 本が無いと「+1 を忘れた書き手」への主張が
    client secret について空手形になる = Round 6 の指摘そのもの）
  - ★**`credentials_revision` を据え置いたまま issuer だけ変えても採用されない**
    （**第 2 の比較子の単独の証明**）
  - **表示名だけを変えた場合は `verify` が成功する**（★負のコントロール。
    `updated_at` で代用していたら落ちる。認証に関与しない更新を巻き込まないこと）
  - ★**同じ平文の client secret を保存し直しただけでも採用されない**
    （通常経路では revision の +1 が先に効くが、**digest の層も同じ向きに倒れる**
    ことをここで固定する。暗号文は再暗号化で変わるので、digest 比較は
    **偽陽性の側＝拒否の側**へ倒れる = fail-closed である。運営はもう一度押せばよい。
    ★この挙動を「バグ」として後から緩めないための記録である）
  - **接続が取得中に削除されたら `Verified` にしない**（行が消えている）
  - ★**他組織の接続 id では再取得できない** — relation 起点であることの証明
    （組織を跨いだ id を渡すと `connectionGone` になり、`Verified` にならない）
  - **同じ材料の `verify` が二重に走っても例外にならず、2 回目は遷移しない成功になる**
  - **`Active` / `Disabled` から `verify` を呼ぶと定義外の遷移として例外になる**
  - **外向き取得の間に接続の行がロックされていない** —
    ★**`beforeRespond` の中の `disable` が完了することを根拠にしない**（Round 8 の注意）。
    同一プロセス・**同一の DB 接続**では自分が取った行ロックは**再入できる**ので、
    ロックを持っていても止まらず、**証明にならない**。
    ★代わりに **query listener で直接測る**: `DB::listen()` で SQL を集め、
    `beforeRespond` に到達した時点で **`for update` を含む文が 1 つも発行されていない**ことを表明する。
    これは再入の影響を受けず、「第 2 段までロックを取っていない」を**そのまま**測る
  - **第 2 段がトランザクションの段数を増やしていない** — `beforeRespond` の
    **入り口と出口の両方**で `DB::transactionLevel()` が**基準の段数と等しい**ことを表明する。
    ★**絶対値の `0` で書かない**（Round 7 の [Critical] への回答）—
    グローバル `RefreshDatabase` がテスト全体を 1 つのトランザクションで包むので
    **Feature レーンでは通常 1 であって 0 ではなく、0 を期待すると必ず赤になる**。
    基準は `verify()` を呼ぶ**直前**に `DB::transactionLevel()` を読んで取る。
    固定したい不変条件は「**第 2 段が段を増やさない**」であって絶対値ではない
- [ ] `update` が認証材料を変えると **`credentials_revision` が +1 される**／
      **表示名だけの更新では増えない**（`OidcConnectionTransitionServiceTest` 側）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 状態が増えると画面の分岐が増える。4 状態で固定し、追加しない。

---

## D2: 組織側の接続管理 controller・route 7 本・画面

### 変更箇所
- 新規: `app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php`
- 新規: `app/Http/Requests/Organizations/StoreSsoConnectionRequest.php` / `UpdateSsoConnectionRequest.php`
- 新規: `app/DataTransferObjects/Organizations/SsoConnectionSummary.php`
- 新規: `app/Policies/OrganizationOidcConnectionPolicy.php`
- 新規: `resources/js/pages/Organizations/Sso/Index.svelte`
- 新規: `resources/js/components/features/sso/oidc-connection.ts`
- 変更: `routes/web.php`
- 変更: **`bootstrap/app.php`** — `withExceptions()` 内で
  `$exceptions->dontFlash(['client_secret'])` を登録する
  （★本リポジトリに `dontFlash` の使用実績が無いので、**実装点をここに確定させる**。
  登録しないと validation 失敗時に秘密が old input としてセッションへ残る）
  ★**`code` / `state` / `token` のような一般名はグローバルに登録しない** —
  他のフォームの入力復元まで黙って変えてしまう。
  これらは**経路側で閉じる**（企業 SSO の callback とメール昇格の確認は、
  失敗時に `withInput()` を使わない = 入力を一切 flash しない）

### 波及変更
- **TypeScript型定義: あり** — Inertia Props の型と状態の値域の定数
- **API Resource/DTO: あり** — `SsoConnectionSummary`（**秘密を一度も復号しない**）
- **テストファイル: あり** — `ControllerAuthorizationGateTest` / `RecentAuthRouteTest` /
  `ThrottleCoverageInventoryTest` / `NestedRouteDefenseInventory` / `TenantBoundaryOrderingTest` /
  `InertiaRenderPageExistsInvariantTest` / `DocumentTitleCoverageTest` / `ValidationAttributeCoverageTest`

### route（**更新系はすべて再認証必須**）

```php
Route::get('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'index'])
    ->name('organizations.sso.index');

// {oidcConnection} は Organization::oidcConnections() 経由で scopeBindings が解決する。
// 親に属さない id は **binding 段で 404** (認可より前。AGENTS.md 不変条件 2 / 10)。
Route::scopeBindings()->group(function (): void {
    // 登録・更新は**接続の秘密を扱う唯一の前面**である (正典 v1 / I4)。
    Route::post('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'store'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.store');

    Route::patch('/organizations/{organization:slug}/sso/{oidcConnection}', [OrganizationSsoConnectionController::class, 'update'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.update');

    // 確認 (接続先情報を実際に取りに行く)。**外向きの取得を伴う唯一の管理操作**なので
    // 専用の流量制限を持つ (他の管理操作と bucket を共有しない)。
    Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/verify', [OrganizationSsoConnectionController::class, 'verify'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-verify'])
        ->name('organizations.sso.verify');

    Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/activate', [OrganizationSsoConnectionController::class, 'activate'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.activate');

    Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/disable', [OrganizationSsoConnectionController::class, 'disable'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.disable');

    Route::delete('/organizations/{organization:slug}/sso/{oidcConnection}', [OrganizationSsoConnectionController::class, 'destroy'])
        ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
        ->name('organizations.sso.destroy');
});
```

### 秘密の扱い（[Critical] への回答）

```php
/**
 * 接続の登録・更新の入力。**接続の秘密を扱ってよい唯一の前面**である (正典 v1 / I4)。
 *
 * ★Laravel は validation の失敗時に入力をセッションへ flash する。
 *   したがって client_secret を **dontFlash へ登録する** (登録しないと
 *   秘密が old input としてセッションに残る)。
 * ★**伏字の見本をそのまま更新値として受け付けない** — 未入力なら据え置きにする
 *   (伏字文字列がそのまま秘密として保存される事故を型と規則で消す)。
 * ★validation の応答・監査ログ・例外・要求の記録にも含めない。
 */
```

### action ごとの認可（**読み取りも認可を通る**）

| action | ability | 非メンバー | メンバー（権限なし） | owner / admin |
|---|---|---|---|---|
| `index` | `viewAny`（`OrganizationOidcConnection::class`, `$organization`） | **404**（binding） | **403** | 200 |
| `store` | `create` | 404 | 403 | 302（back） |
| `update` | `update` | 404 | 403 | 302 |
| `verify` | `update` | 404 | 403 | 302 |
| `activate` | `update` | 404 | 403 | 302 |
| `disable` | `update` | 404 | 403 | 302 |
| `destroy` | `delete` | 404 | 403 | 302（★**身元が 1 件でもあれば拒否**。下記） |

接続の管理は組織のログイン経路そのものを変える操作なので、
**閲覧も含めて `owner` / `admin` に限る**（`OrganizationPolicy::update` と同じ境界）。

### 削除と更新の制限（Round 3 の [Critical] への回答）

| 操作 | 身元が 0 件 | 身元が 1 件以上 |
|---|---|---|
| `destroy` | できる | **拒否**（押下時に「無効化してください」とエラー表示。★ボタンを disabled にしない = 禁止事項 8） |
| `update`（issuer / client_id） | できる（★**`Draft` へ戻る**） | **拒否**（押下時に「新しい接続を作ってください」とエラー表示） |
| `update`（client secret） | できる（`Draft` へ戻る） | できる（`Draft` へ戻る） |
| `update`（表示名） | できる | できる |
| `disable` | できる | **できる（推奨経路）** |

★**7 route のうち状態や認証材料を変える 5 本（`update` / `verify` / `activate` / `disable` / `destroy`）は、
すべて D1 を通して callback と直列化される**。ただし**形は 2 通りある**:

| 経路 | 形 | 理由 |
|---|---|---|
| `update` / `activate` / `disable` / `destroy` の **4 本** | 接続の行を `lockForUpdate()` した**同一トランザクション**で「身元の有無の確認 → 検査 → 変更」 | 外向き通信を伴わないので、ロックを持ったまま完結できる |
| **`verify` の 1 本** | **三段構成**（ロックなしでスナップショット → ロックなしで外向き取得 → トランザクション + 行ロックで `credentials_revision` の一致を再確認 → 一致時のみ遷移） | ★**外向き HTTP の間ロックを保持しない**ため。詳細は D1「`verify` だけは三段構成にする」節が正本 |

★したがって controller 側でも `verify` だけは**トランザクションの張り方が違う**。
`verify` の action は D1 の `verify()` を呼ぶだけにし、
**controller 側で外向き取得を包むトランザクションを張らない**。

★**5 操作すべてで、controller は D1 へ「scoped binding で解決した `Organization`」を渡す**
（Round 6 の [Critical] への回答）。D1 側はロック付きの再取得を
`$organization->oidcConnections()->whereKey(…)->lockForUpdate()` の**relation 起点に統一**する。
route は既に `scopeBindings()` で親子の整合を binding の段で閉じている（層 2 = 404）ので、
controller が**組織 id を payload から受け取ることは無い**（不変条件 1）。
★**「入口で binding が通ったから中は自由」にしない** — 再取得の側でも組織スコープを通す。

### DTO（**一覧では秘密を一度も復号しない**）

```php
/**
 * 画面へ返す接続の要約。
 *
 * ★**接続の秘密を持たない。伏字すら持たない** —
 *   伏字の項目を持つと「一覧の生成時に復号する」実装へ誘導される。
 *   在る・無いだけを bool で返せば、一覧の経路は秘密に一度も触らない。
 */
final readonly class SsoConnectionSummary
{
    public function __construct(
        public int $id,
        public string $slug,
        public string $displayName,
        public string $issuer,
        public string $clientId,
        public OidcConnectionStatus $status,
        public bool $hasClientSecret,          // ★復号しない
        public ?CarbonImmutable $verifiedAt,
    ) {}

    /**
     * Inertia へ渡す形。enum は value、時刻は ISO 8601 文字列、キーは camelCase。
     * TypeScript の Props と一致することをテストが固定する。
     *
     * @return array{id: int, slug: string, displayName: string, issuer: string,
     *               clientId: string, status: string, hasClientSecret: bool, verifiedAt: string|null}
     */
    public function toArray(): array { /* … */ }
}
```

### 画面（DESIGN.md / Atomic Design）

- 既存の **atoms / molecules** を使う（入力欄・ボタン・バッジは新設しない。
  無ければ molecule として足し、organism から atom を作らない = 階層を逆流させない）
- 色・角丸・字は **design token 経由**で参照する（hex 直書きを増やさない）。
  token を増やす必要が出たら `resources/css/tokens.css` との同期を同じ変更で行う
- アイコンは **Lucide**（SVG 直書きを新設しない）
- **必須条件が未充足でもボタンを disabled にしない**。押下時にエラーを表示する（禁止事項 8）
- 画面は **1 枚**（一覧 + 登録・更新フォーム）。秘密を扱う前面を 2 枚に割らない（I4）

### PHPStan適合チェック
- [x] 戻り値の型が明示されている（`toArray()` は shape つき）
- [x] null安全（`verifiedAt` は nullable を型で明示）
- [x] DTOを返している（`response()->json()` なし。Inertia へ DTO の `toArray()` を渡す）
- [x] Genericsの型パラメータが正しい（一覧は `list<SsoConnectionSummary>`）

### テスト計画
- [ ] 新規 `tests/Feature/Organizations/OrganizationSsoConnectionTest.php`
  - **他組織の接続 id を URL に入れると 403 ではなく 404**（不変条件 2 / 存在オラクル）
  - **一覧を含む 7 route すべてで**、権限のないメンバーは 403（`Gate::authorize`）
  - **更新系 6 route すべてが再認証なしで弾かれる**
  - **validation 失敗時に client secret がセッションへ残らない**（`dontFlash`）
  - **伏字の見本を送っても秘密が上書きされない**（未入力は据え置き）
  - **一覧の生成が秘密を一度も復号しない**（復号を観測する seam で検査）
  - 応答・Inertia props に client secret の原文が出ない
  - 確認 (`verify`) が専用の流量制限を持ち、他の管理操作と bucket を共有しない
  - ★**5 操作のロック付き再取得がすべて relation 起点である** —
    `ModelDirectFetchInvariantTest` / `DirectFetchInventory` に
    **本設計由来のクラス起点の主キー同一性クエリが 1 件も増えない**ことで固定する
    （deny-by-default なので、増やせば目録への登録が要り、レビューで必ず見える）
  - **`verify` の action が外向き取得を包むトランザクションを張らない**
    （D1 の三段構成を controller 側が壊していないことの結線。
    偽 IdP の `beforeRespond` で `DB::transactionLevel()` が**基準の段数と等しい**ことを観測する。
    ★**絶対値の `0` で書かない** — グローバル `RefreshDatabase` の下では通常 1 である）
  - **`verify` が `staleCredentials` を返したとき、画面に「材料が変わったのでやり直す」旨が出る**
    （★一様な応答にしない。認可を通った運営操作なので理由を具体的に伝える）
  - **client secret を更新すると一覧の状態が Draft になる**（D1 との結線）
  - **身元がある接続の削除・issuer/client_id の更新が拒否され、押下時にエラーが表示される**
    （ボタンが disabled になっていないことも確認する = 禁止事項 8）
  - **callback と確認の失敗で入力が flash されない**（`code` / `state` / `token` が old input に残らない）
- [ ] 新規 `tests/js/.../oidc-connection.test.ts` — 状態の値域の TS 定数が PHP enum と一致する
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **7 route を一度に足す**ので目録の登録漏れが最大の赤要因。F2 で 6 目録を明示的に潰す。

---

## E1: メールアドレスの昇格フロー（Auth 名前空間）

### 変更箇所
- 新規: `app/Services/Auth/EmailPromotionService.php`（**`App\Services\EnterpriseSso` ではない**）
- 新規: `app/Models/EmailPromotion.php` + 移行 1 本 + Factory 1 本
- 新規: `app/Http/Controllers/Auth/EmailPromotionController.php`
- 新規: `app/Http/Requests/Auth/StoreEmailPromotionRequest.php` / `ConfirmEmailPromotionRequest.php`
- 新規: `app/Mail/EmailPromotionMail.php`
- 新規: `app/Exceptions/Auth/EmailPromotionConflictException.php`
- 新規: `app/DataTransferObjects/Auth/VerifiedEmail.php`
- 新規: `app/Console/Commands/Auth/PruneEmailPromotions.php`
- **新規: `resources/views/auth/email-promotion/confirm.blade.php`**
  （★確認画面は **standalone Blade**。Inertia の props にトークンを載せないための選択。
  「確認画面の描画方式」節が正本）
- 変更: `routes/web.php`（route 4 本。**既存の認証済み group の内側**）/ `routes/console.php`（掃除の登録）
- 変更: `docs/architecture.md` / `docs/factories.md`（**新モデル 1 本を登録する**）

### 波及変更

- **TypeScript 型定義: なし** — ★確認画面は standalone Blade で、**Svelte のページを 1 枚も足さない**
  （「確認画面の描画方式」節）。Inertia の Props も増えない
- **API Resource / DTO: あり** — `app/DataTransferObjects/Auth/VerifiedEmail.php`（新規）
- **テストファイル: あり** —
  `ControllerAuthorizationGateTest`（自分自身の資源として exemption 登録）/
  `RecentAuthRouteTest`（`store` / `resend` は必要、確認 2 本は不要の分類）/
  `ThrottleCoverageInventoryTest`（`email-promotion` / `email-promotion-confirm`）/
  `MassAssignmentSafetyTest`（新モデル 1 本）/ `ValidationAttributeCoverageTest`
- ★**`DocumentTitleCoverageTest` / `InertiaRenderPageExistsInvariantTest` は母集団外**
  （Inertia を render しないため。理由は「確認画面の描画方式」節）

### 名前空間の配置

```php
/**
 * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
 *
 * ## なぜ EnterpriseSso ではなく Auth の名前空間に置くか
 *
 * 正典 (laravel-claude-template) の設計判断をそのまま引き継ぐ。
 * 「メールでの引き当てを禁じる設計検査の走査範囲へ入れないための意図的な配置」である。
 *
 * ★**これは検査の回避ではない**。昇格フローも**メールで利用者を引かない** —
 *   引き当ての鍵は常に Auth::id() (自分自身) であり、メール文字列は
 *   「その利用者に紐づける値」としてしか現れない。走査から外すのは、
 *   **メール文字列を正当に扱う唯一の場所**を禁止語の走査へ巻き込まないためであって、
 *   引き当ての禁止を緩めるためではない。この主張は
 *   tests/Architecture/EmailPromotionIdentityGateTest (G5) が
 *   「メールから利用者を引く記法を持たない」「既存アカウントとの併合をしない」の
 *   2 点で固定する。
 */
```

### 表の設計（A2 と同じ粒度）

```php
Schema::create('email_promotions', function (Blueprint $table): void {
    $table->id();
    $table->foreignId('user_id')->constrained()->cascadeOnDelete();

    // ★トークンは **原文を保存せず指紋だけ** (用途ラベル EmailPromotionToken)。
    //   一意制約が「一回だけ consume できる」の根拠。
    $table->char('token_fingerprint', 64)->unique();

    // 昇格しようとしているメール。**CipherSweet で暗号化する** (PII。不変条件 6)。
    // ★ここにも blind index を付けない — 確定するまでは users のメールではないので、
    //   引き当てに使う理由が無い (I1 と同じ思想)。
    $table->text('email_encrypted');

    $table->timestamp('expires_at');
    $table->timestamps();

    // ★利用者ごとの未消費は **1 件だけ**にする (再送で旧トークンが失効することの DB 側の担保)。
    //   消費は行の削除なので、未消費 = 行が在ることである。
    $table->unique('user_id', 'email_promotions_user_unique');

    $table->index('expires_at');   // 期限切れ掃除の走査用
});
```

| モデル | cast | `$hidden` | relation | Factory |
|---|---|---|---|---|
| `EmailPromotion` | `expires_at` → `immutable_datetime`（メールは CipherSweet） | `email_encrypted` / `token_fingerprint` | `user()` (BelongsTo) | `@use HasFactory<EmailPromotionFactory>` |

`$fillable` は空（Service が明示的に組み立てる）。`MassAssignmentSafetyTest` の母集団に入る。

| 項目 | 形 |
|---|---|
| トークン | **原文を保存せず指紋のみ**（用途ラベル `EmailPromotionToken`。B4 と同じ導出） |
| 結合 | `user_id` を持ち、確認時に**認証済みの利用者と一致**すること |
| 期限 | `expires_at`（`config('enterprise-sso.email_promotion.ttl_seconds')`） |
| 一回使用 | **B4 と同じ原子的な形**（`SELECT … FOR UPDATE` → 検査 → `DELETE` → commit の後に拒否） |
| 再送 | 新しいトークンを発行したら**旧トークンを失効させる**（`user_id` の一意制約 + 発行時の削除） |
| 掃除 | 期限切れ行は `PruneLoginAttempts` と同じ形の日次の掃除に載せる（別コマンド） |

### route 4 本（Round 3 の [Critical] への回答）

★**すべて既存の認証済み group の中**に置く
（`Route::middleware(['auth', 'verified', 'not-pending-deletion'])->group(…)` の内側）。
`auth` を書き忘れると未認証で叩ける経路になるので、**group の外に置かない**。

```php
// （既存の認証済み group の内側）
// ログイン済みの利用者が、自分のメールアドレスを持つための昇格。
// 認可は「自分の資源」なので Gate を通さない (controller が Auth::id() だけを使う)。
// ★ControllerAuthorizationGateTest の exemption 対象になるので理由付きで inventory へ登録する (F2)。

Route::post('/settings/email-promotion', [EmailPromotionController::class, 'store'])
    ->middleware(['recent-auth', 'throttle:email-promotion'])
    ->name('settings.email-promotion.store');

Route::post('/settings/email-promotion/resend', [EmailPromotionController::class, 'resend'])
    ->middleware(['recent-auth', 'throttle:email-promotion'])
    ->name('settings.email-promotion.resend');

// ★メールのリンクが開く**確認画面** (GET)。**状態を変えない**。
//   トークンを画面へ渡し、利用者が明示のボタンで POST する。
//   メールクライアントの先読み・プレビューでは**この画面が開くだけ**で確定しない。
Route::get('/settings/email-promotion/confirm', [EmailPromotionController::class, 'showConfirm'])
    ->middleware(['throttle:email-promotion-confirm', 'no-store'])
    ->name('settings.email-promotion.confirm.show');

// 確定 (POST のみ)。
Route::post('/settings/email-promotion/confirm', [EmailPromotionController::class, 'confirm'])
    ->middleware(['throttle:email-promotion-confirm', 'no-store'])
    ->name('settings.email-promotion.confirm');
```

| route | 認証 | 認可 | throttle | recent-auth | no-store | CSRF |
|---|---|---|---|---|---|---|
| `settings.email-promotion.store` | **必要**（group） | 自分自身（exemption 登録） | `throttle:email-promotion` | **必要**（認証手段を増やす操作） | — | web の既定 |
| `settings.email-promotion.resend` | **必要**（group） | 同上 | 同上 | **必要** | — | web の既定 |
| `settings.email-promotion.confirm.show` | **必要**（group） | 母集団外（GET・状態を変えない） | `throttle:email-promotion-confirm` | 不要 | **付ける** | — |
| `settings.email-promotion.confirm` | **必要**（group） | 自分自身（exemption 登録） | 同上 | 不要（**救済の性格**。関門を足すと確定できず詰む） | **付ける** | web の既定 |

> **なぜ確認を GET 画面 + POST に割るか**: 署名付き GET のリンクだけだと、
> メールクライアントの先読みやプレビューで**利用者が意図せず確定してしまう**。
> リンクが開くのは画面までにして、**状態を変えるのは明示の POST に限る**。

### 確認トークンの受け渡しと、その保証範囲（Round 4 の [Warning] への回答）

メールのリンクは **URL の query にトークンを載せる**（`?token=…`）。
これは「メールから 1 クリックで確認画面へ来られる」ために要る。
**露出を隠しきれる方式ではない**ので、保証する範囲と**保証しない範囲**を書き切る。

**固定すること**:

- **GET は DB の状態を変えない**（画面を返すだけ）
- **トークンの有効・無効で画面を変えない**（一様。存在の探り当てを作らない）
- **`no-referrer`** を効かせる（**方式は下の「確認画面の描画方式」節が正本**。
  ヘッダではなく **`<meta name="referrer">`** で document に効かせる。理由も同節に書く）
- 確認画面は**外部リソースを一切読み込まない**（Referer が出る経路を作らない）
- **アプリのログ・監査・例外に完全な URL を記録しない**（トークンは平文でも指紋でも出さない）
- 画面から POST へ渡すときは **専用 Blade が描画した form の hidden 項目**に載せる
  （★**Inertia を使わない画面にする**。したがって Inertia の props にも
  `history.state` の page object にも載らず、履歴の暗号化に依存しない。下節が正本）
- 失敗時に入力を **flash しない**（`withInput()` を使わない）。
  `token` は一般名なのでグローバルの `dontFlash` へ足さず、**経路側で閉じる**（D2 と同じ判断）
- トークンを受ける引数に **`#[SensitiveParameter]`** を付ける

**保証しないこと（誇張しない）**:

- **リバースプロキシや CDN のアクセスログ**、**ブラウザの履歴**、
  **利用者が URL を他人へ貼ること**による露出は防げない。
  緩和は **60 分の期限**と **一回だけの consume** であり、
  露出しても**使われる窓が短く、1 回しか効かない**ことに寄せている

### 確認画面の描画方式（Round 5 の [Critical] E1 への回答）

**結論: 専用の standalone Blade を 1 枚足す。Inertia を使わない。**

本設計の他の画面はすべて Inertia だが、**本リポジトリには既に同じ形の先例がある** —
`resources/views/mcp/authorize.blade.php`（外部 OAuth client の consent 画面）である。
そこも「**サーバが描画した hidden にトークン相当の値を載せ、明示の POST で確定する**」形で、
docblock に「Inertia / Vite に依存しない standalone Blade（consent はアプリ本体の SPA shell を
必要としないため）」と書いてある。**確認画面はこれと同じ性格**である:
メールのリンクから 1 枚だけ開き、押したら別の画面へ抜ける。SPA の shell も、
前後の画面遷移も、共有 props も要らない。

**なぜ Inertia の prop を採らないか**（Round 5 が挙げた選択肢 (b) を採らない理由）:
Inertia は page object を `history.state` へ載せるため、prop へ置いた瞬間に
**トークンがブラウザの履歴に残る**。`encryptHistory()` で緩和はできるが、
それは「履歴の暗号化に依存する」ことであり、当初の意図をそのまま捨てる判断になる。
Blade なら**そもそも履歴に載らない**ので、依存を増やさずに意図を満たせる。
「他の画面が Inertia だから」は、この 1 枚だけ性格が違う以上、決め手にならない。

#### 変更ファイル

- **新規: `resources/views/auth/email-promotion/confirm.blade.php`**
- 変更: `app/Http/Controllers/Auth/EmailPromotionController.php` —
  `showConfirm()` が `response()->view('auth.email-promotion.confirm', [...])` を返す
  （★`Inertia::render` を呼ばない）

#### 画面の中身（守ること）

```blade
{{-- メール昇格の確認画面。**standalone Blade** (Inertia / Vite に依存しない)。
     形の先例は resources/views/mcp/authorize.blade.php。
     トークンを Inertia の page object へ載せない = ブラウザ履歴に残さないための選択である。 --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{-- ★この document からの Referer を止める。ヘッダで上書きしない理由は下記。 --}}
    <meta name="referrer" content="no-referrer">
    <meta name="robots" content="noindex">
    <title>メールアドレスの確認 | {{ config('app.name') }}</title>
    <style>/* インライン CSS のみ。@vite / 外部 CSS / 外部フォントを一切読み込まない */</style>
</head>
<body>
    <form method="POST" action="{{ route('settings.email-promotion.confirm') }}">
        @csrf
        {{-- ★サーバが描画した hidden。props にも history.state にも載らない --}}
        <input type="hidden" name="token" value="{{ $token }}">
        <button type="submit">このメールアドレスを確定する</button>
    </form>
</body>
</html>
```

| 要求（Round 5） | どう満たすか |
|---|---|
| **CSRF** | `@csrf`。POST は web group の既定の CSRF 検査を通る |
| **`no-store`** | route に **`no-store` alias**（`App\Http\Middleware\NoStoreResponse`）を付ける。加えて認証済み応答には `NoStoreCacheHeadersForAuthenticatedPages` の baseline も当たる（**二重だが、後者は「認証済み」に依存するので明示的に付ける方を正本にする**） |
| **`Referrer-Policy: no-referrer`** | ★**ヘッダではなく `<meta name="referrer">` で効かせる**（理由は下） |
| **外部リソースなし** | `@vite` なし・外部 CSS / フォント / 画像なし・インライン `<style>` のみ・外部リンクなし |
| **design token** | ★**参照しない。参照できない**（下記） |

#### なぜヘッダではなく `<meta name="referrer">` か（実読して確定した）

`App\Http\Middleware\SecurityHeaders` は **web group の middleware** であり、
`$response = $next($request)` の**後**に `Referrer-Policy: strict-origin-when-cross-origin` を
**無条件に `set()` する**。group の middleware は route middleware より外側なので、
**route 側で `no-referrer` を立てても SecurityHeaders が後から上書きする**。
つまり「route に middleware を足す」ではこの要求は満たせない。

満たす道は 2 つある。

1. `SecurityHeaders` に route 名の allowlist を足す
   （既存の `security.capture_permissions_policy_routes` と同じ作法）
2. **document 側の `<meta name="referrer" content="no-referrer">`**

★**2 を採る**。理由は 2 つある。

- **1 は乖離台帳の負債を踏む**。`app/Http/Middleware/SecurityHeaders.php` は
  `docs/template-fingerprints.json` の `entries` に**在り**、かつ
  `tests/Support/TemplateDivergence/adoption-debt.tsv` にも**在る**（実読で確認）。
  採用時債務に在るパスを変更すると「変更したまま債務に残す」が選べず
  （突合 gate が `mutatedDebtPaths` で落ちる）、**同期か逸脱登録かの判断を強制される**。
  **1 枚の画面の Referer を止めるために、テンプレート共有の baseline ヘッダ機構へ
  route 名の分岐を持ち込むのは釣り合わない**（思考原則 2）。
- **baseline だけでも第三者への漏れは既に無い**。`strict-origin-when-cross-origin` は
  **cross-origin には origin しか送らない**ので、トークンを含む完全な URL が
  外部へ出ることはそもそも無い。`meta` が足すのは**同一オリジン内でも送らない**という
  一段の締めであり、これは document 単位で閉じれば足りる。

★したがって F3 の「テンプレート共有ファイルに触れない」という結論は**維持される**
（本節の判断はその結論を守るための選択でもある）。

#### design token を参照しないことの明示

本 Blade は **Vite / Tailwind のパイプラインに乗らない**ので、
`resources/css/tokens.css` の CSS 変数も Tailwind の utility も使えない。
これは本リポジトリで**既に確立した扱い**である —
`resources/views/errors/layout.blade.php` の docblock が
「DESIGN.md の『生 CSS / inline style 禁止』は Vite/Tailwind パイプラインに乗る Svelte
コンポーネントへの規約であり、本 blade はそのパイプラインに依存できないため
inline CSS が正当な例外。色は DS token を参照できないためニュートラルな
プレースホルダを hex 直書きで固定する」と宣言しており、
`legal/layout.blade.php` と `mcp/authorize.blade.php` も同じ形である。

★**本画面も同じ扱いにする**。すなわち **design token を参照しない**。
「token 経由で参照する」と書くと**実装できない約束**になるので書かない。
代わりに**同じ docblock を本 Blade にも置き、例外である理由を明示する**。
色は既存 standalone Blade と同じニュートラル系の hex に揃える
（新しいパレットを作らない）。`tests/js/architecture/contrast-invariant.test.ts` は
token inventory を入力にする検査であり、**Blade は母集団に入らない**（実読で確認）。

#### 目録・gate への影響（Inertia でないことの波及）

- ★`DocumentTitleCoverageTest` は **「Inertia を render する GET named route」だけ**を
  母集団にする（実読で確認）。本 route は Inertia を render しないので**母集団に入らない**。
  タイトルは Blade の `<title>` が持つ。**exemption の登録も不要**である。
- ★`InertiaRenderPageExistsInvariantTest` も同様に無関係（`Inertia::render` を呼ばない）。
- したがって E1 の波及は **`resources/js/` に 1 行も無い**（Svelte のページを足さない）。

### 昇格の条件と衝突

- **本人確認**: 確認メールのトークンを踏んだときにだけ確定する（IdP の申告メールをそのまま昇格させない）
- **認可**: 対象は**認証済みの自分自身のみ**
- **監査**: 変更を記録する（既存の監査基盤へ載せる）
- **確定時の `email_verified_at`**: ★「以前の値のまま」にせず、
  **新しいメールを実際に確認した時刻へ更新する**（A3 の規約と対。timestamp の意味を保つ）
- **衝突**: 確認済みメールが既存利用者のメールと重なったとき、
  **既存利用者を一切変更せず・併合せず・昇格も行わない**。応答は**一様**。
  ★**メールの blind index の一意制約違反だけ**を一様な応答へ変換し、
  それ以外の一意制約違反と DB の障害は**握り潰さない**。

### メール送信

新しい送信経路が 1 本増える。**既存の送信の作法**（送信基盤・目録・流量制限）へ登録する
（独自機構を足さない）。

### PHPStan適合チェック
- [x] 戻り値の型が明示されている / null安全 / DTOを返している / Generics 正しい

### テスト計画
- [ ] 新規 `tests/Feature/Auth/EmailPromotionTest.php`
  - トークンを踏むまで昇格しない
  - **他人のトークンでは昇格しない**（`user_id` の結合）
  - **他人のアカウントを併合しない**
  - **衝突時の応答が一様**（存在を漏らさない）
  - **blind index 以外の一意制約違反は握り潰さない**（負のコントロール）
  - **競合実行**でも 1 件しか確定しない
  - 再送で旧トークンが失効する
  - 期限切れのトークンが拒否される
  - **確定で `email_verified_at` が確認した時刻へ更新される**
  - **並行の確認**（並行ハーネス）で 1 件しか確定しない
  - **確認が GET では確定しない**（GET は画面を返すだけ。状態が変わらない）
  - **確認の失敗でトークンが old input に残らない**
  - **ログ・例外・監査にトークンが出ない**
  - ★**確認画面が Inertia ではない** — 応答が `X-Inertia` を持たず、
    本文に Inertia の root（`data-page` 属性）が**無い**
    （トークンが page object 経由で履歴へ載る経路が存在しないことの証明）
  - ★**トークンが hidden 項目として描画される**（`name="token"` の hidden が 1 つ在る）
  - ★**`<meta name="referrer" content="no-referrer">` が在る**
  - ★**応答が `Cache-Control: no-store` を持つ**
  - ★**外部リソースを 1 つも読み込まない** — 本文に `@vite` の産物・
    外部 host を指す `<link>` / `<script>` / `<img>` が**無い**
  - ★**GET が状態を変えない**（既出）／**GET だけでは `email_verified_at` が動かない**
- [ ] 新規 `tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php`（A3 と共有）—
      昇格前は `email = null` でパスワード再設定が使えず、昇格後に使えるようになる。
      `email_verified_at` は昇格の前後とも**確認済みのまま**
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 昇格は「メールを持たない利用者が持つようになる」変化なので、
  通知・請求・管理画面の各所が `email = null` 前提と非 null 前提の両方を跨ぐ。A3 の波及と一体で潰す。

---

## F1: gate 5 本（G1〜G5）+ 走査器

### 変更箇所
- 新規: `tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest.php`（G1）
- 新規: `tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php`（G2）
- 新規: `tests/Architecture/EnterpriseSsoSecretExposureGateTest.php`（G3）
- 新規: `tests/Architecture/SsoTwoFactorInterpositionGateTest.php`（G4）
- 新規: `tests/Architecture/EmailPromotionIdentityGateTest.php`（G5）
- 新規: `tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php`（走査器の本体）
- 新規: `tests/Unit/Architecture/EnterpriseSsoSourceScannerTest.php`（走査器の自己検査 = 負例）
- 新規: `tests/Architecture/fixtures/enterprise-sso/*`（見本ファイル）

### 走査器共通規約（AGENTS.md）への適合

**発火条件に 5 本とも該当する**（走査ロジック・走査対象・名前解決・判定条件・目録の新設）。

| 条 | 適用 | 本設計での形 |
|---|---|---|
| (a) クラス参照は完全修飾名で突き合わせる | G1〜G5 | `use` / group use / 別名つき取り込みを解いた完全修飾名で比べる。構文解析ライブラリは必須ではなく、字句走査 + 取り込み対応表でよい（家系の裁定 AG-154 (2)）。既存の `Tests\Support\PhpReferenceScanner` / `PhpTokenScan` を再利用する |
| (b) 解決できない形は落とす | G1〜G5 | **下記のとおり「保証外」にせず fail-closed へ倒す** |
| (c) 検出力は負例で裏取りする | G1〜G5 | 違反する入力を検出できること／規定どおりの入力を誤検出しないことの**両方向**。置き場は `tests/Architecture/fixtures/enterprise-sso/` と `tests/Unit/Architecture/` の 2 通りで、**gate の docblock から辿れる** |
| (d) 集めた結果を必ず判定に使う | G1〜G5 | 収集して参照しない出力・数えるだけで比べない目録を作らない |
| (e) 語彙一致はトークン完全一致 | G1・G3・G4・G5 | 区切り文字集合を**走査ごとに宣言**する。負例に**接頭辞つき・打ち消しつき・接尾辞つきの 3 形**を置く |

### (b) を「保証外」ではなく fail-closed で満たす（Round 1 [Critical] / Round 2 [Critical] への回答）

正典の G2 は「変数経由の間接呼び出しは検出できない」と自ら書いている。
しかし AGENTS.md (b) は「**保証範囲の外にした構文で保護対象の操作を書けるなら、
検出力の主張を狭めるか、未解決として失敗させるかのどちらか**」と定める。

一方で **「変数経由の呼び出しをすべて未解決として落とす」は実装不能**である
（`$this->pinned->fetch()` のような通常のオブジェクト呼び出しが大量にあり、
字句走査では受け手の型まで解決できない。Round 2 の指摘）。

**採る形**（走査根が `App\Services\EnterpriseSso` という**自分たちが書く小さな領域**であることを使う）:

| 構文 | 扱い |
|---|---|
| `Http::` ファサード / `HttpFactory` の型注入 / `new Client()` 等の**固定の名前** | **完全修飾名で判定**する（(a)） |
| `$this->x->method()` のような**固定のメソッド名**の呼び出し | 受け手の**宣言型**（構築子の引数・プロパティの型・PHPDoc）から解決できる範囲で判定する。**解決できる範囲を docblock に明記**し、「すべての呼び出しを解決できる」とは主張しない |
| **動的なメソッド名** `$obj->$name()` / **可変クラス名** `new $cls` / `$cls::method()` / **可変 callable**（`call_user_func` 系・文字列や配列からの呼び出し） | ★**検出したら gate を失敗させる**（未解決を無言で候補から外さない）。走査根の中でこれらを使う正当な理由が無いので、禁じても実装が困らない |
| **保護対象らしい固定の語彙**（`request` / `get` / `post` / `send` / `fetch` …）の呼び出しで、**受け手の型が解決できない**もの（局所変数・factory の戻り値など） | ★**gate を失敗させる**（Round 3 の指摘。動的構文でなくても解決範囲の外に落ちうるため） |

### G2 が主張する範囲（Round 3 の [Critical] への回答）

G2 が主張するのは**次の 3 つの積**だけである（これ以上を主張しない）:

1. 走査根の中に**既知の禁止型・ファサードの参照**（`Http` / `HttpFactory` / vendor の HTTP クライアント）が無い
2. 走査根の中に**動的な呼び出しの形**が無い
3. 走査根の中に**受け手の型が解決できない保護対象語彙の呼び出し**が無い

★**「外向きは `PinnedHttpClient` だけである」という主張の主証明は静的側に置かない。**
主証明は次の 2 本に移す（gate の docblock がそう宣言する）:

- **DI の結線テスト**: 企業 SSO の 3 サービスへ注入されるのが `PinnedHttpClient` だけであること
- **`PinnedHttpClient` の実挙動テスト**（B1 / F4）: 実装が pin 済み経路を実際に通ること

- 完全な型解決が要る判定は作らない（PHPStan / AST への依存を持ち込まない）
- G1・G3・G4・G5 も同じ扱いにする

### 各 gate が固定するもの

| gate | 固定する内容 |
|---|---|
| G1 | 企業 SSO の名前空間・controller・身元モデルに**メールで利用者を引く記法**が無い（`whereBlind('email', …)` を含む）。加えて**申告メールの列（または対応する blind index）を含む索引が 0 本**であることを**スキーマの読み取りだけ**で確かめる（破壊操作を伴わない = 禁止事項 3） |
| G2 | `App\Services\EnterpriseSso` 配下に **`Http` ファサード・`HttpFactory` の使用が無い**（許可一覧を持たない）。**動的な呼び出しの形**と**受け手の型が解決できない保護対象語彙の呼び出し**が無い。**自動リダイレクト追従を有効にする記法**も無い。★**「外向きは `PinnedHttpClient` だけ」の主証明は DI の結線テストと実挙動テストにあると gate 自身が宣言する** |
| G3 | 接続の秘密が**受け渡しの型に存在しない**（語彙の走査に加え、**対象の型の構築子引数・公開項目・直列化の形を型単位で検査**する）。`ConnectionSecret` を**ログ・dump・直列化の関数へ渡す記法**が無い。`ConnectionSecret::revealForTokenExchange()` の**呼び出し元を exact-fit で pin**（トークン交換 1 本だけ）。例外の型が**理由の enum だけを受け取り `previous` を受け取れない構築子**を持つ（★型で連鎖が起きないので「例外に秘密が載らない」を構造で担保する）。**主たる証明は実挙動の漏洩テスト（B2・D2）にあると gate 自身が宣言する** |
| G4 | 企業・ソーシャル**両方**の戻り口に、待機ログインを作る記述・2 要素の入力画面への転送が無い。**主たる証明は実挙動側（C2 のテスト）にあると gate 自身が宣言する** |
| G5 | 昇格フローが**メールから利用者を引かない** / **既存アカウントとの併合をしない** |

### 4 点（同じ変更で揃える）

1. **負例と正例。テストファーストで先に赤くしてから本体を書く**（移植で最初から緑になる場合は、負例が押さえる分岐を一時的に壊して赤を確認する）
2. **解決できない形を落とす分岐**（上記のとおり fail-closed）
3. **走査が空振りしていないことの検査** — 母集団が空でないこと／走査根がそれぞれ生きていること。
   走査根は `Tests\Support\TrackedPhpSourceFiles` を使い、同じ列挙を 2 本持たない。
   母集団がそれより狭い走査は自分の根を持ってよいが、**存在しない根は fail-fast** で落とす
4. **docblock に走査対象と保証しないものを書く**（正本は docblock 側。本設計へ写さない）

### テスト計画
- [ ] 各 gate に対応する負例を先に書き、**赤を確認してから**本体を書く
- [ ] 走査根が空でないことの検査を 5 本とも持つ
- [ ] 走査器の自己検査で
      「**動的なメソッド名・可変クラス名・可変 callable** を未解決として落とす」ことと、
      「**通常の固定メソッド呼び出しを誤検出しない**」ことの**両方向**を固定する
- [ ] `EnterpriseSsoAttemptRejectedException` が **`previous` を受け取る構築子を持たない**
      （型の検査。B2 の「vendor 例外を連結しない」を構造で担保する）
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseSsoHttpWiringTest.php` —
      **企業 SSO の 3 サービスへ注入される HTTP の担い手が `PinnedHttpClient` だけである**
      （G2 の主張のうち「外向きは pin 済み経路だけ」の**主証明**。静的側では主張しない）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- **走査器を 1 本に共有する**ので、走査根の定義を誤ると 5 本が同時に空振りする。3 の空振り検査がその唯一の防波堤になる。

---

## F2: aicue 側の目録登録（新規 14 route の全分類）

### 変更箇所
- 変更: `app/Enums/Security/ThrottleCoverageExemption.php`（既存 case で足りるなら足さない）
- 変更: `app/Enums/Security/ControllerAuthorizationExemption.php`（同上）
- 変更: `tests/Support/Routing/NestedRouteDefenseInventory.php`
- 変更: `tests/Architecture/RecentAuthRouteTest.php`（**8 route を allowlist へ**）
- 変更: `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録（B1 のキャッシュ経路）
- 変更: named limiter の登録と関連目録（`RateLimiterKeyConventionTest` /
  `ThrottleLaneAssignmentTest` / `InlineThrottleInventoryTest`）— **6 本を新設**する:
  `enterprise-sso-start` / `enterprise-sso-callback` / `enterprise-sso-manage` /
  `enterprise-sso-verify` / `email-promotion` / `email-promotion-confirm`
- **変更しない**: `tests/Architecture/TwoFactorEnforcementAllowlistTest.php` / `app/Enums/Account/AccountDeletionFreezeAllowance.php`

### 追加する **14 route** の全分類（Round 1〜3 の指摘への回答）

| # | route | throttle | 認可 | nested 防御 | recent-auth |
|---|---|---|---|---|---|
| 1 | `enterprise-sso.login` | **持たない** → exemption（外向き通信をしない開始画面。GET・DB を変えない） | 母集団外（GET） | parameter なし | 不要（未認証面） |
| 2 | `enterprise-sso.redirect` | `throttle:enterprise-sso-start` | **母集団外だが明示分類する**（下記） | `{connectionSlug}` は `NonResourceParameter`（組織に属さない公開の識別名） | 不要（未認証面） |
| 3 | `enterprise-sso.callback` | `throttle:enterprise-sso-callback` | 母集団外（GET） | parameter なし | 不要（未認証面） |
| 4 | `organizations.sso.index` | **持たない** → exemption（読み取り専用。既存の一覧 route と同じ扱い） | **`Gate::authorize` あり**／`ControllerAuthorizationGateTest` の**母集団外**（GET） | `{organization}` = `ScopedBinder` | 不要（閲覧のみ） |
| 5 | `organizations.sso.store` | `throttle:enterprise-sso-manage` | `Gate::authorize` | `{organization}` = `ScopedBinder` | **必要** |
| 6 | `organizations.sso.update` | 同上 | `Gate::authorize` | + `{oidcConnection}` = `ScopeBindings` | **必要** |
| 7 | `organizations.sso.verify` | `throttle:enterprise-sso-verify`（専用） | `Gate::authorize` | 同上 | **必要** |
| 8 | `organizations.sso.activate` | `throttle:enterprise-sso-manage` | `Gate::authorize` | 同上 | **必要** |
| 9 | `organizations.sso.disable` | 同上 | `Gate::authorize` | 同上 | **必要** |
| 10 | `organizations.sso.destroy` | 同上 | `Gate::authorize` | 同上 | **必要** |

| 11 | `settings.email-promotion.store` | `throttle:email-promotion` | **exemption**（自分自身の資源のみを扱い、Gate を通さない。理由付きで inventory へ登録） | 母集団外（parameter なし） | **必要** |
| 12 | `settings.email-promotion.resend` | 同上 | 同上 | 母集団外 | **必要** |
| 13 | `settings.email-promotion.confirm.show` | `throttle:email-promotion-confirm` | 母集団外（GET・状態を変えない） | 母集団外 | 不要 |
| 14 | `settings.email-promotion.confirm` | 同上 | exemption（自分自身の資源） | 母集団外 | 不要（救済の性格） |

→ named limiter を持つのは **12 本**（2・3・5〜14）。throttle の exemption は **2 本**（1・4）。
認可の exemption は **3 本**（11・12・14）。`recent-auth` は **8 本**（5〜10・11・12）。
`no-store` は **4 本**（2・3・13・14）。

### `recent-auth` の allowlist へ足す 8 本

- 組織側の更新系 **6 本**（store / update / verify / activate / disable / destroy）—
  接続の秘密と組織のログイン経路を変える操作であり、既存の「API キー発行・失効」と同水準
- メール昇格の**発行・再送 2 本** — 認証手段を増やす操作であり、既存の `settings.password.store` と同水準
- ★**確認 (`confirm`) は足さない** — 救済の性格であり、関門を足すと確定できず詰む
  （既存の「退会予約の取消を allowlist に入れない」と同じ判断）

### 副作用のある GET の明示分類（[Warning] への回答）

`enterprise-sso.redirect` は **GET だが DB に試行の行を作る**。
`ControllerAuthorizationGateTest` の母集団は変更系 HTTP メソッドなので、
**既存の検査だけでは見落とす**。したがって:

- **OAuth の開始 GET** として理由付きで明示分類する
  （CSRF トークンの代わりに `state`・ブラウザ結合・流量制限が守る）
- 固定するテスト:
  - 未認証で叩けること自体は正常（ログインの開始である）
  - **無効な接続では行を作らない**
  - **`no-store`** が付く
  - 流量制限が効く
  - 作られる行が期限を持つ（無制限に溜まらない）

### 登録しない判断

| 目録 | 判断 |
|---|---|
| `TwoFactorEnforcementAllowlistTest`（件数 21） | **追加しない**。組織側の接続管理は業務面であり、2 要素義務づけの下で到達できなくてよい。ログイン導線は未認証面なのでゲートの母集団に入らない |
| `AccountDeletionFreezeAllowance` | **追加しない**。企業 SSO の接続管理は退会予約中に実行できなくてよい |

### テスト計画
- [ ] 目録を触った各 gate が緑
- [ ] `TwoFactorEnforcementAllowlistTest` の件数 pin が **21 のまま**（意図せぬ追加をしていない）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- exemption の case を「汎用に見えるもの」へ押し込むと gate が形骸化する。当てはまる case が無ければ、それは throttle / 認可を足すべき route である。

---

## F3: 逸脱の登録 D37 + 台帳件数の pin

### 変更箇所
- 変更: `docs/template-divergence.md`（エントリ D37 を追加。冒頭の「登録エントリ: N 件」を 36 → 37）
- 変更: `tests/Support/TemplateDivergence/LedgerPins.php`（`DIVERGENCE_ENTRY_COUNT` を 36 → 37）

### 乖離台帳の確認（app-design Phase 3-0 の確認段）

`docs/template-fingerprints.json` の `entries`（281 件）を実読して突き合わせた:

- 本設計が**新規作成**するファイルは**いずれも `entries` に無い**（テンプレートに無い領域）
- 本設計が**変更**する既存ファイル（`routes/web.php` / `routes/console.php` /
  `app/Models/User.php` / `app/Models/Organization.php` / `app/Auth/EncryptedUserProvider.php` /
  `composer.json` / `tests/Architecture/*` / `app/Enums/Security/*` / `tests/Support/*` /
  `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php`）も
  **`entries` に無い**
- `entries` に在る config は `audit` / `cache` / `ciphersweet` / `laratrust` / `ssrf-pin` の 5 本で、
  **本設計はどれも変更しない**（`config/enterprise-sso.php` は新規で、共有ファイルではない）
- したがって **`adoption-debt.tsv`（171 件）に触れるパスも無い**（`mutatedDebtPaths` で落ちない）

> ★**この結論は「たまたま」ではなく、E1 で一度守る判断をしている**。
> 確認画面へ `Referrer-Policy: no-referrer` を付ける素直な実装は
> `app/Http/Middleware/SecurityHeaders.php` に route 名の allowlist を足す形だが、
> 同ファイルは **`entries` に在り、かつ `adoption-debt.tsv` にも在る**（実読で確認）。
> 触れば「変更したまま債務に残す」が選べなくなり、同期か逸脱登録かを迫られる。
> E1 は**画面側の `<meta name="referrer">` で閉じる**ことでこれを避けた
> （E1「確認画面の描画方式」節が正本）。
> **共有ファイルへ手を伸ばす前に、画面・route 側で閉じられないかを先に問うこと。**

→ **形式上の登録義務は発生しない**。しかし記録の原則が
「**登録するか迷ったら登録する**」「テンプレートに無い領域への上積みは登録側へ倒す」と定めており、
ログイン試行の機構は**正典 v1 に無い上積み**である。よって登録する。

> **実装時の再確認**: 上記は本設計の時点の照合である。着手時に
> `docs/template-fingerprints.json` を取り直して同じ突き合わせを行う
> （テンプレート台帳が更新されていれば結論が変わりうる）。

### D37 の内容（登録メタ表 9 行）

| 行 | 値 |
|---|---|
| 対象パス | `app/Models/EnterpriseSsoLoginAttempt.php` / `app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php` / `app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php` / `app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php` / `app/Support/EnterpriseSso/AttemptFingerprint.php` / `app/Enums/EnterpriseSso/FingerprintPurpose.php` / `app/Console/Commands/EnterpriseSso/PruneLoginAttempts.php` / `database/migrations/2026_08_23_000300_create_enterprise_sso_login_attempts_table.php` / `database/factories/EnterpriseSsoLoginAttemptFactory.php` / `tests/Feature/EnterpriseSso/EnterpriseLoginAttemptStoreTest.php` / `tests/Feature/EnterpriseSso/PruneLoginAttemptsTest.php` / `tests/Architecture/EnterpriseSsoPruneScheduleTest.php` |
| 業務要件起因の説明 | 正典はログイン試行の保管先を表として持たない。aicue は `state` の使用権の唯一性を**セッションドライバの種別と `->block()` の書き忘れに依存させない**ため、DB の一意制約と行ロックへ寄せた。あわせて**一時トークンの指紋方式**（用途ラベルで domain separation する導出）を機構横断の部品として持つ — 企業 SSO のログイン試行とメールアドレスの昇格が同じ導出を使う |
| 揃え続ける不変条件と保証機構 | 「同じ試行の使用権をちょうど 1 つの要求だけが得る」「その試行を開始したブラウザだけが使える」を `EnterpriseLoginAttemptStoreTest` の並行検査と別ブラウザ検査が固定する |
| 再判定の条件 | 本形が正典へ還流されて正典側の版が上がったら、独自差分ではなく**新しい正典追従**になるので登録を消す。また正典が同等の原子性とブラウザ結合を別方式で持ったときも見直す。★**メールアドレスの昇格の側が正典で指紋方式を採ったときも見直す**（本登録は機構横断の一時トークンの指紋方式を含むため、昇格側だけが正典化したら対象パスの線引きを引き直す） |
| 決めた日 | `2026-08-23` |
| 決めた人 | `開発者` |
| 根拠 | `devnotes/20260823-0015-enterprise-oidc-sso-adoption/` |
| 状態 | `監視中` |
| 見直し期限 | `2027-08-23`（基準日から 400 日以内） |

### 対象パスの線引き（Round 2 の [Warning] への回答）

| パス | 入れるか | 根拠 |
|---|---|---|
| 試行の表・モデル・Store・DTO 2 本・掃除コマンド・移行・Factory・対応テスト | **入れる** | DB 試行方式そのものを構成する固有の資産 |
| `AttemptFingerprint` / `FingerprintPurpose` | **入れる** | 一時トークンの指紋方式の中核。★**メールアドレスの昇格でも使う**ので、D37 の説明は「ログイン試行だけの資産」ではなく「**機構横断の一時トークンの指紋方式**」として書く（対象と説明の意味を食い違わせない） |
| `routes/console.php` | **入れない** | ★既存の**共有ファイル**であり、掃除の登録 1 行のためにファイル全体を D37 の対象にすると、この 1 ファイルを触る将来の逸脱と**必ず衝突する**（値域の要件「全登録の和集合で重複しない」）。**追跡は切れない** — 掃除の本体は `PruneLoginAttempts` コマンド（D37 の対象）に在り、`routes/console.php` の 1 行はその**呼び出しの登録**にすぎないため、コマンドが D37 に載っている限り機構としての追跡は保たれる。この根拠を D37 の本文に書く |
| `EnterpriseSsoLoginController` / `EnterpriseCallbackAuthenticator` | **入れない** | **正典にも在る資産**である（保管先の実装が違うだけ）。逸脱は「保管先を表にしたこと」であって controller の存在ではない |

### テスト計画
- [ ] `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が緑（9 行ちょうど・順序・値域・**和集合で重複しない**）
- [ ] `tests/Architecture/TemplateDivergenceFingerprintTest.php` が緑（件数 3 点一致）
- [ ] 新規 `tests/Architecture/EnterpriseSsoPruneScheduleTest.php` —
      **掃除コマンド 2 本が scheduler へ日次で登録されている**
      （★コマンドが在るだけでは日次の掃除は成立しない。登録そのものを固定する）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 件数の pin は**宣言行・見出しの実数・定数の 3 点一致**なので、1 か所でも忘れると赤になる。
  マージ直前に現物から取り直す（他の TODO が同時に登録を増やしうる）。

---

## F4: 試験用の偽 IdP と外部到達点の登録

### 変更箇所
- 新規: `app/Services/EnterpriseSso/Fakes/FakeOidcDiscoveryService.php` ほか（正典の「試験用の接続先 4 クラス」に相当）
- 新規: `app/Http/Controllers/Testing/FakeIdpAuthorizeController.php`（**試験環境限定で登録される**）
- 変更: `app/Support/ExternalFakes/ExternalFakeDeclaration.php`
- 変更: `tests/Support/ExternalSeam/ExternalSeamInventory.php`

### 設計の要点

- **テストレーンは外向き HTTP を既定で拒否する**（AGENTS.md）。実 IdP へ出ない。
- 偽の IdP の許可環境は**外部ログインと同じ `testing` / `bughunt.local`** に絞る
  （`local` を外す理由は既存の `SSO_ENVIRONMENTS` の docblock と同じ）
- **同じ事実を 2 か所に書かない**（AGENTS.md ドメイン規約 9）:
  差し替えの宣言は `ExternalFakeDeclaration`、外部到達点の目録は `ExternalSeamInventory` が持つ
- 本番コードが偽の実装のクラス名を参照しないことは既存の `FakeClassReferenceInvariantTest` が全走査する
- **接続先 URL の入力規則は https 必須**なので、偽の IdP は**本番のモデルに登録しない**。
  差し替えの seam でだけ扱う
- ★**discovery の応答に「割り込みの注入点」を差し込めるようにする**（D1 の `verify` の割り込みテスト用）。
  `FakeOidcDiscoveryService` は、**テストが渡したときだけ**応答を返す直前に呼ぶ
  callback（`?Closure $beforeRespond`）を持つ。既定は `null` で**何もしない**。
  ★**callback は「待つ」ものではなく「やって戻る」もの**である（Round 6 の [Critical] への回答）。
  同一プロセスで callback に待たせると、`verify()` が戻らないためテスト本体が
  割り込みを起こせず**デッドロックする**。テストは callback の中で更新を実行してそのまま戻る。
  ★したがって **sleep も ready / go も締切も持たせない** — 順序は呼び出しの構造が保証する

### テスト計画
- [ ] `ExternalFakeWiringInvariantTest` / `ExternalSeamInventoryTest` /
      `LaneExternalFakeBindingTest` / `FakeClassReferenceInvariantTest` が緑
- [ ] 新規 `tests/Feature/EnterpriseSso/EnterpriseOidcFakeRoundTripTest.php` — 偽の IdP で往復が通る
- [ ] 新規 `tests/Architecture/FakeIdpRouteAbsenceTest.php`
  - **production 相当の環境で偽の route が route の一覧に存在しない**
  - **フラグ無効時に route も結線も存在しない**
- [ ] 新規 `tests/Feature/EnterpriseSso/OidcDiscoveryPinnedPathTest.php`（B1 と共有）—
      **偽への全面差し替えとは別に**、実装が `PinnedHttpClient` を通ることを
      ssrf-pin のテスト seam で観測する
- [ ] `ProductionEnvGuard` が本番での有効化を止める（既存機構に載るだけ）
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク
- 偽の IdP は**未認証の GET で任意の subject を名乗れる**。許可環境の絞りが唯一の防波堤なので、
  既存の `SSO_ENVIRONMENTS` と同じ集合を使い、独自に緩めない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) 新規ファイルが 50 本前後、`routes/web.php` に **14 route** を足し、9 つの目録と 2 つの台帳ファイルを触る。(2) **前段依存が 2 本**（`ssrf-pin-v04-upgrade` と `process-concurrency-harness-adoption`）あり、どちらも別 TODO で先行するため、完了を待って独立ブランチで積む必要がある。(3) A3（`users.email` の nullable 化）は既存テーブルへの変更で、PHPStan の洗い出しが広範囲に及ぶ。(4) gate 5 本をテストファーストで赤→緑にする工程が長く、他施策と混ぜると赤の出所が分からなくなる |
| 競合リスク | `routes/web.php` / `app/Models/User.php` / `tests/Support/Routing/NestedRouteDefenseInventory.php` / `tests/Architecture/RecentAuthRouteTest.php` / `docs/template-divergence.md` / `tests/Support/TemplateDivergence/LedgerPins.php` は**他の TODO も触る中心ファイル**である。とくに `LedgerPins.php` の件数 pin は他の逸脱登録と衝突しやすいので、マージ直前に件数を取り直す |

### 段の順序（直列。前段が緑になってから次へ）

| 段 | 施策 | 前提 |
|---|---|---|
| 前段① | — | **`ssrf-pin-v04-upgrade` の完了**（受入条件 3 点: GET の本文取得 / **body 付き POST の本文取得** / どちらも SSRF 判定を通る） |
| 前段② | — | **`process-concurrency-harness-adoption` の完了**（B4・C1・C2 の並行テストが乗る土台。グローバル `RefreshDatabase` の下では独自に組めない） |
| A | A1・A2・A3・F3 | 前段 |
| B | B1・B2・B3・B4・F4 | A |
| C | C1・C2 | B |
| D | D1・D2 | C |
| E | E1 | D（A3 と一体で意味を持つ） |
| F | F1・F2 | 各段が自分の gate を持って緑にしたうえで、取りまとめる |

> **gate は最後にまとめて足さない**。各段が自分の gate を同じ変更で持って緑にする
> （禁止事項 1: 不変条件は対応するテストへの登録まで含めて「実装済み」）。
> F は目録の登録漏れを閉じる取りまとめの段である。

## 受入条件（検証コマンド）

実装の完了は**次のすべてが緑**であることをもって判断する
（AGENTS.md「検証コマンド」の一覧に従う）:

| コマンド | 対象 |
|---|---|
| `composer test` | PHP のテスト全数（Architecture / Feature / Unit） |
| `composer phpstan` | PHPStan level 10（**widen も baseline も行わない** = 禁止事項 2） |
| `vendor/bin/pint --test` | PHP の整形 |
| `pnpm lint` | JS/TS の静的検査 |
| `pnpm typecheck` | TypeScript の型 |
| `pnpm test` | **JS 側の gate の正本のレーン**（enum ↔ TS 定数の同期はここでしか走らない） |
| `pnpm build` | 本番ビルド |
| `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` | ワークスペースの package |

★**`composer fix` / `pnpm lint:fix` は検証の代替にならない**（整形を当てるだけで判定しない）。
★段ごとに緑にする。最後にまとめて走らせない。

## スコープ外（明記）

- **ソーシャル SSO の作り替え**（`auth-sso-social`）。既に AG-200 の形なので**挙動を変えない**。
  本設計が触るのは「その形を機械で固定する gate（G4）を 1 本足す」ことだけである。
- **運営側 SSO**（`auth-admin-sso`）。
- **`acr_values` による認証強度の要求**。AG-200 が「強度を上げたい要件はこれで行う」と書いているが、
  aicue に該当要件は無い（思考原則 2「今必要なものだけ作る」）。
- **SCIM / 自動デプロビジョニング**、および **IdP 側の停止に連動した既存セッションの即時失効**。
  入退社連動は「次回ログインができなくなる」までとする。
- **IdP 起点のログイン（IdP-initiated SSO）**。RP 起点のみ。
- **接続を無効にした後の猶予窓**（spirux にだけ設定値があるが強制する仕組みは未実装で、正典の形ではない）。
- **`kent013/laravel-ssrf-pin` の版上げそのもの**（別 TODO `ssrf-pin-v04-upgrade`）。
  本設計は `config/ssrf-pin.php` を**変更しない**。
- **既存ログイン手段の削除・変更**（`EnsureLoginMethodRemains` の意味論は変えない）。
- **refresh token の保存とバックグラウンドでの更新**。ログインの確定にのみトークンを使い、保存しない。
- **`userinfo` endpoint の呼び出し**。身元は ID トークンの claim だけから決める（外向きの経路を増やさない）。

---

## 実装差分 1/3: 本体 (app / routes / config / database / bootstrap / lang / resources)

```diff
diff --git a/app/Actions/Fortify/UpdateUserProfileInformation.php b/app/Actions/Fortify/UpdateUserProfileInformation.php
index 77f711a8..e32d1c28 100644
--- a/app/Actions/Fortify/UpdateUserProfileInformation.php
+++ b/app/Actions/Fortify/UpdateUserProfileInformation.php
@@ -62,11 +62,17 @@ public function update(User $user, array $input): void
         // config('app.key') が文字列であることを要求するため、前提が崩れているなら
         // 不可逆な状態変更 (アドレスの書き換え・確認済みの解除・旧アドレスへの通知) が
         // 起きる前に落ちるほうが安全である。
+        // ★旧アドレスは **null になりうる** — 企業 SSO でしか入れない利用者は使えるメールを
+        //   1 件も持たない (T253 / A3)。宛先が無いので旧アドレスの鍵つきハッシュも通知も無い。
         $auditMetadata = [
-            'old_email_hash' => EmailHash::compute($oldEmail),
+            'old_email_hash' => $oldEmail === null ? null : EmailHash::compute($oldEmail),
             'new_email_hash' => EmailHash::compute($email),
         ];
 
+        // ★`email_verified_at` は**必ず消す** (T253 / A3 の規約)。
+        //   企業 SSO の利用者は `email = null` かつ `email_verified_at != null` という状態を持つので、
+        //   ここで消さないと**別経路で入れたメールが自動的に確認済みになる**。
+        //   メールを確認済みにしてよいのはメール昇格 (E1) の確定だけである。
         $user->forceFill([
             'name' => $name,
             'email' => $email,
@@ -85,9 +91,12 @@ public function update(User $user, array $input): void
         // **観測専用**である。この 2 値で分岐する処理は 1 つも作らない。
         $this->recorder->record(SecurityEventType::EmailChanged, $user, $auditMetadata);
 
-        // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)
-        Notification::route('mail', $oldEmail)
-            ->notify(new EmailChangedSecurityNotification);
+        // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)。
+        // ★旧アドレスが無い (企業 SSO のみの利用者) なら送り先が無いので送らない。
+        if ($oldEmail !== null) {
+            Notification::route('mail', $oldEmail)
+                ->notify(new EmailChangedSecurityNotification);
+        }
 
         $user->sendEmailVerificationNotification();
     }
diff --git a/app/Casts/EncryptedSecretCast.php b/app/Casts/EncryptedSecretCast.php
new file mode 100644
index 00000000..fdaf0bd1
--- /dev/null
+++ b/app/Casts/EncryptedSecretCast.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Casts;
+
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Support\Facades\Crypt;
+use InvalidArgumentException;
+
+/**
+ * 暗号化して保存する秘密を {@see ConnectionSecret} として出し入れする cast。
+ *
+ * ★受け取るのも返すのも**値型だけ**である。素の文字列を set できる道を作らない
+ *   (作ると「うっかり平文を代入する」経路が復活する)。
+ * ★復号できない暗号文は **null を返さず例外**にする。null に畳むと
+ *   「秘密が無い接続」と「壊れた暗号文」が区別できなくなり、
+ *   D2 の `hasClientSecret` が黙って false になる。
+ *
+ * @implements CastsAttributes<ConnectionSecret, ConnectionSecret>
+ */
+final class EncryptedSecretCast implements CastsAttributes
+{
+    /**
+     * @param  array<string, mixed>  $attributes
+     */
+    public function get(Model $model, string $key, mixed $value, array $attributes): ?ConnectionSecret
+    {
+        if ($value === null || $value === '') {
+            return null;
+        }
+
+        if (! is_string($value)) {
+            throw new InvalidArgumentException(sprintf('%s は文字列の暗号文である必要があります。', $key));
+        }
+
+        $plaintext = Crypt::decryptString($value);
+
+        return ConnectionSecret::fromPlaintext($plaintext);
+    }
+
+    /**
+     * @param  array<string, mixed>  $attributes
+     * @return array<string, string>
+     */
+    public function set(Model $model, string $key, mixed $value, array $attributes): array
+    {
+        if (! $value instanceof ConnectionSecret) {
+            throw new InvalidArgumentException(sprintf('%s には ConnectionSecret だけを代入できます。', $key));
+        }
+
+        return [$key => Crypt::encryptString($value->revealForEncryptionAtRest())];
+    }
+}
diff --git a/app/Console/Commands/Auth/PruneEmailPromotionsCommand.php b/app/Console/Commands/Auth/PruneEmailPromotionsCommand.php
new file mode 100644
index 00000000..db5312f0
--- /dev/null
+++ b/app/Console/Commands/Auth/PruneEmailPromotionsCommand.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Auth;
+
+use App\Models\EmailPromotion;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\Config;
+use Webmozart\Assert\Assert;
+
+/**
+ * 期限切れのメール昇格の確認待ちを物理削除する。
+ *
+ * ★`email_promotions` は利用者ごとに 1 行しか持てない (`email_promotions_user_unique`)。
+ *   期限切れの行を消さないと、その利用者は**二度と昇格を始められない**
+ *   (発行時に自分の古い行を消す経路はあるが、日次の掃除が最後の受け皿である)。
+ */
+class PruneEmailPromotionsCommand extends Command
+{
+    /** @var string */
+    protected $signature = 'auth:prune-email-promotions';
+
+    /** @var string */
+    protected $description = '期限切れのメール昇格の確認待ちを物理削除する';
+
+    public function handle(): int
+    {
+        $cutoff = CarbonImmutable::now();
+        $chunk = Config::integer('enterprise-sso.login_attempt.prune_chunk');
+
+        // ★**主キーを名指ししない**。期限だけを条件にして、pgsql の `ctid` 経由の
+        //   限定つき DELETE (Laravel の Postgres grammar) で 1 回あたりの件数を抑える。
+        //   id の一覧を先に引いて `whereIn('id', …)` する形にすると、
+        //   テナントスコープ外の主キー同一性クエリになり分類が要る (AGENTS.md 不変条件 3)。
+        $deleted = EmailPromotion::query()
+            ->where('expires_at', '<=', $cutoff)
+            ->limit($chunk)
+            ->delete();
+
+        Assert::integer($deleted, 'delete() must return the affected row count.');
+
+        if ($deleted === 0) {
+            $this->info('期限切れの昇格はありません');
+
+            return self::SUCCESS;
+        }
+
+        $this->info("{$deleted} 件削除");
+
+        return self::SUCCESS;
+    }
+}
diff --git a/app/Console/Commands/EnterpriseSso/PruneLoginAttemptsCommand.php b/app/Console/Commands/EnterpriseSso/PruneLoginAttemptsCommand.php
new file mode 100644
index 00000000..a5ee2b30
--- /dev/null
+++ b/app/Console/Commands/EnterpriseSso/PruneLoginAttemptsCommand.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\EnterpriseSso;
+
+use App\Models\EnterpriseSsoLoginAttempt;
+use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Support\Facades\Config;
+use Webmozart\Assert\Assert;
+
+/**
+ * 期限切れの企業 SSO ログイン試行を物理削除する。
+ *
+ * 掃除は二段である — **日次の本コマンド**と、callback での**オンアクセス掃除**
+ * ({@see EnterpriseLoginAttemptStore::consume()})。
+ * 即時削除ではないので「期限が切れた瞬間に行が消える」とは主張しない。
+ *
+ * ★1 回あたりの削除件数に上限を置く (長いトランザクションを作らない)。
+ *   上限に達したら次回の実行が続きを消す (単調増加はしない)。
+ */
+class PruneLoginAttemptsCommand extends Command
+{
+    /** @var string */
+    protected $signature = 'enterprise-sso:prune-login-attempts';
+
+    /** @var string */
+    protected $description = '期限切れの企業 SSO ログイン試行を物理削除する';
+
+    public function handle(): int
+    {
+        $cutoff = CarbonImmutable::now();
+        $chunk = Config::integer('enterprise-sso.login_attempt.prune_chunk');
+
+        // ★**主キーを名指ししない**。期限だけを条件にして、pgsql の `ctid` 経由の
+        //   限定つき DELETE (Laravel の Postgres grammar) で 1 回あたりの件数を抑える。
+        //   id の一覧を先に引いて `whereIn('id', …)` する形にすると、
+        //   テナントスコープ外の主キー同一性クエリになり分類が要る (AGENTS.md 不変条件 3)。
+        $deleted = EnterpriseSsoLoginAttempt::query()
+            ->where('expires_at', '<=', $cutoff)
+            ->limit($chunk)
+            ->delete();
+
+        Assert::integer($deleted, 'delete() must return the affected row count.');
+
+        if ($deleted === 0) {
+            $this->info('期限切れの試行はありません');
+
+            return self::SUCCESS;
+        }
+
+        $this->info("{$deleted} 件削除");
+
+        return self::SUCCESS;
+    }
+}
diff --git a/app/DataTransferObjects/Admin/MemberRowData.php b/app/DataTransferObjects/Admin/MemberRowData.php
index e5c38904..db9c7d39 100644
--- a/app/DataTransferObjects/Admin/MemberRowData.php
+++ b/app/DataTransferObjects/Admin/MemberRowData.php
@@ -15,6 +15,8 @@
  * 表示状態 (roleState) は org ロール × Default Project pivot から毎回導出する (概念設計 D2(a))。
  * email は CipherSweet 復号値。本画面は manageMembers 権限者しか到達できない (403) ため
  * 行レベルの可視性分岐は持たない (PII 可視性は画面到達境界で担保)。
+ * ★email は **null になりうる** — 企業 SSO でしか入れない利用者は使えるメールを 1 件も持たない
+ *   (T253 / A3)。UI は「メールなし」と出す (空文字へ畳まない = 「空のメール」と誤読させない)。
  *
  * lastLoginAt は「最後にいつ入ったか」であり、users の列ではなく security_audit_events の
  * login 行から導出する (App\Services\Security\LastLoginLookup)。**履歴は持たない**。
@@ -26,7 +28,7 @@
     public function __construct(
         public int $id,
         public string $name,
-        public string $email,
+        public ?string $email,
         public string $roleState,       // MemberRoleState value
         public string $roleLabel,
         public string $twoFactorStatus, // disabled|pending|enabled
diff --git a/app/DataTransferObjects/Auth/VerifiedEmail.php b/app/DataTransferObjects/Auth/VerifiedEmail.php
new file mode 100644
index 00000000..6cca114f
--- /dev/null
+++ b/app/DataTransferObjects/Auth/VerifiedEmail.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Auth;
+
+use App\Support\EmailNormalizer;
+
+/**
+ * 確認を通したメールアドレス。
+ *
+ * ★**この型を経由したものだけを `users.email` へ書く**。素の文字列を
+ *   昇格の確定へ渡す道を型で消す (「確認していないメールを昇格させる」経路を作らない)。
+ */
+final readonly class VerifiedEmail
+{
+    private function __construct(public string $value) {}
+
+    /**
+     * ★呼んでよいのは**確認トークンの照合を通した後**だけである。
+     */
+    public static function afterConfirmation(string $email): self
+    {
+        return new self(EmailNormalizer::normalize($email));
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php b/app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php
new file mode 100644
index 00000000..1ad92bad
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/AttemptConsumeResult.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptStoreFailure;
+
+/**
+ * 試行の使用権の取得結果 (**業務上の判定**であって例外ではない)。
+ *
+ * 4 分類と、行・セッションの秘密・外向きの応答の対応:
+ *
+ * | 分類 | 行 | セッションの秘密 | 外向きの応答 |
+ * |---|---|---|---|
+ * | 成功 | 消えた | **消す** | ログイン確定へ進む |
+ * | 期限切れ | 消えた | **消す** | 一様な失敗 |
+ * | 不在 | 無い | **消す** (再開できる試行が無い) | 一様な失敗 |
+ * | 結合の不一致 | **残る** | **残す** (攻撃者が被害者の結合を消せる形にしない) | 一様な失敗 |
+ *
+ * 外向きの応答は 4 通りとも**同一**である。区別は内部にだけ存在する。
+ *
+ * ★DB・基盤の障害は本型に**入らない** — 例外として伝播しトランザクションごと巻き戻る
+ *   ({@see EnterpriseSsoAttemptStoreFailure})。
+ *   混ぜると「排他が壊れた」という重大な事実が一様な拒否に隠れる。
+ */
+final readonly class AttemptConsumeResult
+{
+    private function __construct(
+        public bool $succeeded,
+        /** 行が不可逆に消えたか (セッションの秘密を消してよいかの判断に使う)。 */
+        public bool $rowIsGone,
+        public ?ConsumedLoginAttempt $attempt,
+    ) {}
+
+    public static function consumed(ConsumedLoginAttempt $attempt): self
+    {
+        return new self(true, true, $attempt);
+    }
+
+    /** 行が無い (そもそも作られていない / 既に使われた)。再開できる試行が無い。 */
+    public static function notFound(): self
+    {
+        return new self(false, true, null);
+    }
+
+    /** 期限切れ。**拒否と同時に行を消す** (トランザクションは巻き戻さない)。 */
+    public static function expired(): self
+    {
+        return new self(false, true, null);
+    }
+
+    /** ブラウザ結合の不一致 (login CSRF)。**行もセッションの秘密も残す**。 */
+    public static function bindingMismatch(): self
+    {
+        return new self(false, false, null);
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php b/app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php
new file mode 100644
index 00000000..a3d835dc
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/ConnectionCredentialsSnapshot.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Models\OrganizationOidcConnection;
+
+/**
+ * `verify` の第 1 段が読む**認証材料のスナップショット**。
+ *
+ * ★**client secret の平文も値型も持たない** — 持つのは**暗号文そのものの SHA-256 digest**
+ *   だけである (復号せずに「書き換わったか」だけを見る)。
+ *   verify は discovery を取るだけで秘密を必要としないので、
+ *   **verify の経路は client secret を一度も復号しない**。
+ * ★`$hidden` や `toArray()` の対象にもならない内部の値であり、**画面へ出さない**。
+ */
+final readonly class ConnectionCredentialsSnapshot
+{
+    private function __construct(
+        public int $connectionId,
+        public string $issuer,
+        public string $clientId,
+        public int $credentialsRevision,
+        public string $clientSecretCiphertextDigest,
+    ) {}
+
+    public static function of(OrganizationOidcConnection $connection): self
+    {
+        return new self(
+            connectionId: $connection->id,
+            issuer: $connection->issuer,
+            clientId: $connection->client_id,
+            credentialsRevision: $connection->credentials_revision,
+            clientSecretCiphertextDigest: $connection->clientSecretCiphertextDigest(),
+        );
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php b/app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php
new file mode 100644
index 00000000..75c9ebaa
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/ConsumedLoginAttempt.php
@@ -0,0 +1,41 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Models\EnterpriseSsoLoginAttempt;
+use App\Models\OrganizationOidcConnection;
+use SensitiveParameter;
+use Webmozart\Assert\Assert;
+
+/**
+ * 使用権を得た試行の中身 (**試行の行そのものは外へ出さない**)。
+ *
+ * ★接続は **relation 起点で解決したモデル**を持つ。
+ *   id だけを持ち回って呼び出し側でクラス起点の主キー同一性クエリを書かせない
+ *   (AGENTS.md セキュリティ不変条件 3。`DirectFetchInventory` の母集団を増やさない)。
+ * ★PKCE の検証子だけは token 交換でそのまま送るので平文で持つ。
+ *   `#[SensitiveParameter]` を付け、他の秘密 (state / nonce / 結合) は**指紋のまま**扱う。
+ */
+final readonly class ConsumedLoginAttempt
+{
+    private function __construct(
+        public OrganizationOidcConnection $connection,
+        public string $nonceFingerprint,
+        #[SensitiveParameter] public string $codeVerifier,
+    ) {}
+
+    public static function fromModel(EnterpriseSsoLoginAttempt $attempt): self
+    {
+        // ★relation 起点。FK が cascade で担保しているので不在は不変条件の破れ = fail-fast。
+        $connection = $attempt->connection;
+        Assert::isInstanceOf($connection, OrganizationOidcConnection::class);
+
+        return new self(
+            connection: $connection,
+            nonceFingerprint: $attempt->nonce_fingerprint,
+            codeVerifier: $attempt->pkce_verifier_encrypted,
+        );
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php b/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php
new file mode 100644
index 00000000..c85cabaf
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/OidcJsonWebKeySet.php
@@ -0,0 +1,213 @@
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
+        if (array_key_exists('key_ops', $key) && ! str_contains($key['key_ops'], 'verify')) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+        }
+
+        return $key;
+    }
+
+    /**
+     * JWK の要素を「文字列だけの素の配列」へ落とす。
+     *
+     * `key_ops` は配列で来るので、要素を空白区切りの 1 文字列へ畳む
+     * (キャッシュの保存が素のスカラーで済み、判定は `verify` の有無だけで足りる)。
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
+                if (! is_array($value)) {
+                    throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+                }
+                $operations = [];
+                foreach ($value as $operation) {
+                    if (! is_string($operation)) {
+                        throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+                    }
+                    $operations[] = $operation;
+                }
+                $members['key_ops'] = implode(' ', $operations);
+
+                continue;
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
diff --git a/app/DataTransferObjects/EnterpriseSso/OidcProviderMetadata.php b/app/DataTransferObjects/EnterpriseSso/OidcProviderMetadata.php
new file mode 100644
index 00000000..c09c02d5
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/OidcProviderMetadata.php
@@ -0,0 +1,287 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Enums\EnterpriseSso\TokenEndpointAuthMethod;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use JsonException;
+
+/**
+ * IdP の接続先情報 (OIDC Discovery 文書のうち**本アプリが使う要素だけ**)。
+ *
+ * ★**未知の要素を `array<string, mixed>` のまま内側へ出さない**。
+ *   必要な要素だけを「存在」と「具体型」を検査してから組み立てる。
+ */
+final readonly class OidcProviderMetadata
+{
+    /**
+     * @param  non-empty-list<TokenEndpointAuthMethod>  $tokenEndpointAuthMethods
+     * @param  non-empty-list<OidcSigningAlgorithm>  $idTokenSigningAlgorithms  IdP が広告した署名方式
+     */
+    private function __construct(
+        public OidcIssuerUrl $issuer,
+        public string $authorizationEndpoint,
+        public string $tokenEndpoint,
+        public string $jwksUri,
+        public array $tokenEndpointAuthMethods,
+        public array $idTokenSigningAlgorithms,
+    ) {}
+
+    /**
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public static function fromResponseBody(string $body, OidcIssuerUrl $expectedIssuer): self
+    {
+        try {
+            /** @var mixed $decoded */
+            $decoded = json_decode($body, associative: true, flags: JSON_THROW_ON_ERROR);
+        } catch (JsonException) {
+            // ★例外は **理由の enum だけ**を受け取る形に統一する。
+            //   previous を受け取れない構築子なので、body が例外の連鎖で展開される経路が型で消える。
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotJson);
+        }
+
+        if (! is_array($decoded)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNotObject);
+        }
+
+        $issuer = OidcIssuerUrl::fromString(self::requireString($decoded, 'issuer'));
+        if (! hash_equals($expectedIssuer->value, $issuer->value)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryIssuerMismatch);
+        }
+
+        // 各 endpoint は https の絶対 URL であること。
+        // ★**同一 origin は要求しない** — OIDC 標準の要件ではなく、実在の IdP
+        //   (issuer と JWKS が別 origin) を拒否してしまう。
+        // ★**query は禁じない** (禁じる標準上の根拠が無い)。
+        $authorization = self::requireHttpsUrl($decoded, 'authorization_endpoint');
+        $token = self::requireHttpsUrl($decoded, 'token_endpoint');
+        $jwks = self::requireHttpsUrl($decoded, 'jwks_uri');
+
+        // ★`token_endpoint_auth_methods_supported` は OIDC Discovery で **optional** であり、
+        //   欠落時の既定は `client_secret_basic` である (仕様)。
+        //   欠落を「対応方式なし」として拒否すると**仕様準拠の IdP を拒否する**。
+        $methods = self::supportedAuthMethods($decoded);
+        if ($methods === []) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedAuthMethod);
+        }
+
+        // ★`id_token_signing_alg_values_supported` は OIDC Discovery の **必須項目**である。
+        //   アプリの許可集合との共通部分を取り、空なら拒否する。
+        $algorithms = self::supportedSigningAlgorithms($decoded);
+        if ($algorithms === []) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedSigningAlg);
+        }
+
+        return new self($issuer, $authorization, $token, $jwks, $methods, $algorithms);
+    }
+
+    /**
+     * キャッシュから読み戻す (**素の配列から明示的に組み立て直して検査する**)。
+     *
+     * 破損 / 空配列 / 未知の値のいずれでも null を返し、呼び出し側が `forget` する。
+     *
+     * @param  array<array-key, mixed>  $payload
+     */
+    public static function fromCachePayload(array $payload): ?self
+    {
+        $issuerValue = $payload['issuer'] ?? null;
+        $authorization = $payload['authorization_endpoint'] ?? null;
+        $token = $payload['token_endpoint'] ?? null;
+        $jwks = $payload['jwks_uri'] ?? null;
+        $methods = $payload['auth_methods'] ?? null;
+        $algorithms = $payload['id_token_signing_algorithms'] ?? null;
+
+        if (! is_string($issuerValue) || ! is_string($authorization) || ! is_string($token) || ! is_string($jwks)) {
+            return null;
+        }
+
+        if (! is_array($methods) || ! is_array($algorithms)) {
+            return null;
+        }
+
+        if (! OidcIssuerUrl::isValid($issuerValue) || ! self::isHttpsAbsoluteUrl($authorization)
+            || ! self::isHttpsAbsoluteUrl($token) || ! self::isHttpsAbsoluteUrl($jwks)
+        ) {
+            return null;
+        }
+
+        /** @var list<TokenEndpointAuthMethod> $decodedMethods */
+        $decodedMethods = [];
+        foreach ($methods as $method) {
+            if (! is_string($method)) {
+                return null;
+            }
+            $case = TokenEndpointAuthMethod::tryFrom($method);
+            if ($case === null) {
+                return null;
+            }
+            $decodedMethods[] = $case;
+        }
+
+        /** @var list<OidcSigningAlgorithm> $decodedAlgorithms */
+        $decodedAlgorithms = [];
+        foreach ($algorithms as $algorithm) {
+            if (! is_string($algorithm)) {
+                return null;
+            }
+            $case = OidcSigningAlgorithm::tryFrom($algorithm);
+            if ($case === null) {
+                return null;
+            }
+            $decodedAlgorithms[] = $case;
+        }
+
+        if ($decodedMethods === [] || $decodedAlgorithms === []) {
+            return null;
+        }
+
+        return new self(
+            OidcIssuerUrl::fromString($issuerValue),
+            $authorization,
+            $token,
+            $jwks,
+            $decodedMethods,
+            $decodedAlgorithms,
+        );
+    }
+
+    /**
+     * キャッシュへ入れる形 (**素の配列とスカラーだけ**。セキュリティ不変条件 11)。
+     *
+     * ★**広告された署名方式も保存する** — 保存しないとキャッシュ hit の後に
+     *   「アプリの許可集合 ∩ IdP の広告集合」が成立しない。
+     *
+     * @return array{issuer: string, authorization_endpoint: string, token_endpoint: string,
+     *               jwks_uri: string, auth_methods: non-empty-list<string>,
+     *               id_token_signing_algorithms: non-empty-list<string>}
+     */
+    public function toCachePayload(): array
+    {
+        $methods = [];
+        foreach ($this->tokenEndpointAuthMethods as $method) {
+            $methods[] = $method->value;
+        }
+
+        $algorithms = [];
+        foreach ($this->idTokenSigningAlgorithms as $algorithm) {
+            $algorithms[] = $algorithm->value;
+        }
+
+        return [
+            'issuer' => $this->issuer->value,
+            'authorization_endpoint' => $this->authorizationEndpoint,
+            'token_endpoint' => $this->tokenEndpoint,
+            'jwks_uri' => $this->jwksUri,
+            'auth_methods' => $methods,
+            'id_token_signing_algorithms' => $algorithms,
+        ];
+    }
+
+    /** IdP が広告した署名方式にこの alg が含まれるか。 */
+    public function advertises(OidcSigningAlgorithm $algorithm): bool
+    {
+        return in_array($algorithm, $this->idTokenSigningAlgorithms, true);
+    }
+
+    /**
+     * @param  array<array-key, mixed>  $decoded
+     */
+    private static function requireString(array $decoded, string $key): string
+    {
+        $value = $decoded[$key] ?? null;
+
+        if (! is_string($value) || $value === '') {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryMissingField);
+        }
+
+        return $value;
+    }
+
+    /**
+     * @param  array<array-key, mixed>  $decoded
+     */
+    private static function requireHttpsUrl(array $decoded, string $key): string
+    {
+        $value = self::requireString($decoded, $key);
+
+        if (! self::isHttpsAbsoluteUrl($value)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryInvalidEndpoint);
+        }
+
+        return $value;
+    }
+
+    /** https の絶対 URL で userinfo と fragment を持たないこと (query は許す)。 */
+    private static function isHttpsAbsoluteUrl(string $value): bool
+    {
+        $parts = parse_url($value);
+
+        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
+            return false;
+        }
+
+        if (($parts['host'] ?? '') === '') {
+            return false;
+        }
+
+        return ! isset($parts['user']) && ! isset($parts['pass']) && ! isset($parts['fragment']);
+    }
+
+    /**
+     * ★欠落は `[ClientSecretBasic]` (仕様の既定)。明示されていてどちらも無いときだけ空を返す。
+     *
+     * @param  array<array-key, mixed>  $decoded
+     * @return list<TokenEndpointAuthMethod>
+     */
+    private static function supportedAuthMethods(array $decoded): array
+    {
+        if (! array_key_exists('token_endpoint_auth_methods_supported', $decoded)) {
+            return [TokenEndpointAuthMethod::ClientSecretBasic];
+        }
+
+        $declared = $decoded['token_endpoint_auth_methods_supported'];
+        if (! is_array($declared)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryMissingField);
+        }
+
+        // ★basic を優先する (body 漏洩面が小さい)。
+        $supported = [];
+        foreach ([TokenEndpointAuthMethod::ClientSecretBasic, TokenEndpointAuthMethod::ClientSecretPost] as $method) {
+            if (in_array($method->value, $declared, true)) {
+                $supported[] = $method;
+            }
+        }
+
+        return $supported;
+    }
+
+    /**
+     * ★必須項目。欠落・非配列・具体型の違反はいずれも拒否する。
+     *
+     * @param  array<array-key, mixed>  $decoded
+     * @return list<OidcSigningAlgorithm>
+     */
+    private static function supportedSigningAlgorithms(array $decoded): array
+    {
+        $declared = $decoded['id_token_signing_alg_values_supported'] ?? null;
+
+        if (! is_array($declared)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryMissingField);
+        }
+
+        $supported = [];
+        foreach (OidcSigningAlgorithm::cases() as $algorithm) {
+            if (in_array($algorithm->value, $declared, true)) {
+                $supported[] = $algorithm;
+            }
+        }
+
+        return $supported;
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php b/app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php
new file mode 100644
index 00000000..5fd5f06f
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/OidcTokenResponse.php
@@ -0,0 +1,46 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use JsonException;
+use SensitiveParameter;
+
+/**
+ * token endpoint の応答のうち**本アプリが使う要素だけ**。
+ *
+ * ★`access_token` / `refresh_token` は**持たない**。
+ *   ログインの確定に使うのは ID トークンだけで、他のトークンは保存も利用もしない
+ *   (外向きの経路と保管する秘密を増やさない)。
+ */
+final readonly class OidcTokenResponse
+{
+    private function __construct(#[SensitiveParameter] public string $idToken) {}
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
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMalformed);
+        }
+
+        if (! is_array($decoded)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMalformed);
+        }
+
+        $idToken = $decoded['id_token'] ?? null;
+        if (! is_string($idToken) || $idToken === '') {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMissingIdToken);
+        }
+
+        return new self($idToken);
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php b/app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php
new file mode 100644
index 00000000..dd2fedc2
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/VerifiedIdTokenClaims.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+
+/**
+ * 検証を通った ID トークンの claim のうち**本アプリが使うものだけ**。
+ *
+ * ★`firebase/php-jwt` の戻り値 (`stdClass`) を**信頼済みの型と見なさない**。
+ *   各 claim について存在と具体型を再検査してからここへ入れる (`mixed` を DTO の中へ押し込めない)。
+ *
+ * ## `subject` の境界は 2 層で閉じる
+ *
+ *  1. **入力側 (ここ)** — バイト長 1〜255 / 制御文字を含まない
+ *  2. **DB 側** — `enterprise_identities_subject_octet_length_check` /
+ *     `enterprise_identities_subject_no_control_chars_check`
+ *
+ * ★2 層は**同じ集合**を見る (違う集合を見ていると片方だけ通る値が生まれて二層の意味が消える)。
+ *   対象は **C0 制御文字 (U+0001〜U+001F) と DEL (U+007F) だけ**である。
+ *   C1 制御文字 (U+0080〜U+009F) と Unicode の書式文字 (U+200B 等) は**許す**
+ *   (「制御文字を一切通さない」とは言わない)。
+ */
+final readonly class VerifiedIdTokenClaims
+{
+    private function __construct(
+        public string $issuer,
+        public string $subject,
+        public ?string $claimedEmail,
+        public ?string $name,
+    ) {}
+
+    /**
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public static function of(
+        string $issuer,
+        string $subject,
+        ?string $claimedEmail,
+        ?string $name,
+        int $maxSubjectLength,
+    ): self {
+        if (! self::isAcceptableSubject($subject, $maxSubjectLength)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenSubjectInvalid);
+        }
+
+        return new self($issuer, $subject, $claimedEmail, $name);
+    }
+
+    /** バイト長 1〜上限 / C0 制御文字と DEL を含まないこと。 */
+    public static function isAcceptableSubject(string $subject, int $maxSubjectLength): bool
+    {
+        $length = strlen($subject);
+
+        if ($length < 1 || $length > $maxSubjectLength) {
+            return false;
+        }
+
+        return preg_match('/[\x01-\x1F\x7F]/', $subject) !== 1;
+    }
+}
diff --git a/app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php b/app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php
new file mode 100644
index 00000000..33137eca
--- /dev/null
+++ b/app/DataTransferObjects/EnterpriseSso/VerifyOutcome.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\EnterpriseSso;
+
+/**
+ * `verify` の結果 (4 値)。
+ *
+ * ★**画面へは一様に出さない** — これは運営の操作の結果なので、
+ *   「材料が変わったのでやり直してください」と**具体的に伝える**。
+ *   存在を隠す必要があるのは未認証の経路であって、認可を通った運営操作ではない。
+ */
+enum VerifyOutcome: string
+{
+    /** Draft → Verified へ進んだ。 */
+    case Verified = 'verified';
+
+    /**
+     * 同じ材料を別の要求が既に Verified にしていた。
+     *
+     * revision が一致している = 検証したのと同じ材料なので、これは競合ではなく**重複**である。
+     * 遷移表に Verified → Verified を足さない代わりに「遷移しない成功」として扱う。
+     */
+    case AlreadyVerified = 'already_verified';
+
+    /** 外向き取得の間に認証材料が変わった。結果は採用しない (Draft のまま)。 */
+    case StaleCredentials = 'stale_credentials';
+
+    /** 取得の間に接続が消えた (または組織の外へ出た)。 */
+    case ConnectionGone = 'connection_gone';
+
+    public function succeeded(): bool
+    {
+        return $this === self::Verified || $this === self::AlreadyVerified;
+    }
+
+    public function message(): string
+    {
+        return match ($this) {
+            self::Verified => '接続先情報を確認しました。「有効化」を押すとログインに使えるようになります。',
+            self::AlreadyVerified => 'この接続は既に確認済みです。',
+            self::StaleCredentials => '確認中に接続の設定が変更されたため、結果を破棄しました。'
+                .'もう一度「確認」を押してください。',
+            self::ConnectionGone => '確認中にこの接続が削除されました。',
+        };
+    }
+}
diff --git a/app/DataTransferObjects/Organizations/SsoConnectionSummary.php b/app/DataTransferObjects/Organizations/SsoConnectionSummary.php
new file mode 100644
index 00000000..ac8b7a75
--- /dev/null
+++ b/app/DataTransferObjects/Organizations/SsoConnectionSummary.php
@@ -0,0 +1,77 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Organizations;
+
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Models\OrganizationOidcConnection;
+use Carbon\CarbonImmutable;
+
+/**
+ * 画面へ返す接続の要約。
+ *
+ * ★**接続の秘密を持たない。伏字すら持たない** —
+ *   伏字の項目を持つと「一覧の生成時に復号する」実装へ誘導される。
+ *   在る・無いだけを bool で返せば、**一覧の経路は秘密に一度も触らない**。
+ * ★`credentials_revision` も載せない — これは D1 の内部の比較子であって、
+ *   画面が使う値ではない。外へ出すと「画面から見える版番号」として別の意味を持ち始める。
+ */
+final readonly class SsoConnectionSummary
+{
+    public function __construct(
+        public int $id,
+        public string $loginSlug,
+        public string $displayName,
+        public string $issuer,
+        public string $clientId,
+        public OidcConnectionStatus $status,
+        public bool $hasClientSecret,          // ★復号しない
+        public ?CarbonImmutable $verifiedAt,
+        public bool $hasIdentities,
+    ) {}
+
+    /**
+     * ★`$hasIdentities` は呼び出し側が**まとめて数えた結果**を渡す
+     *   (一覧のたびに 1 件ずつ数えない = N+1 を作らない)。
+     */
+    public static function fromModel(OrganizationOidcConnection $connection, bool $hasIdentities): self
+    {
+        return new self(
+            id: $connection->id,
+            loginSlug: $connection->login_slug,
+            displayName: $connection->display_name,
+            issuer: $connection->issuer,
+            clientId: $connection->client_id,
+            status: $connection->status,
+            // ★暗号文の有無だけを見る (復号しない)。
+            hasClientSecret: $connection->hasClientSecret(),
+            verifiedAt: $connection->verified_at,
+            hasIdentities: $hasIdentities,
+        );
+    }
+
+    /**
+     * Inertia へ渡す形。enum は value、時刻は ISO 8601 文字列、キーは camelCase。
+     * TypeScript の Props と一致することをテストが固定する。
+     *
+     * @return array{id: int, loginSlug: string, displayName: string, issuer: string,
+     *               clientId: string, status: string, hasClientSecret: bool,
+     *               verifiedAt: string|null, hasIdentities: bool}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'loginSlug' => $this->loginSlug,
+            'displayName' => $this->displayName,
+            'issuer' => $this->issuer,
+            'clientId' => $this->clientId,
+            'status' => $this->status->value,
+            'hasClientSecret' => $this->hasClientSecret,
+            // オフセット付きで出す (端末側 Intl が UTC を現地時刻と誤解釈しないため)。
+            'verifiedAt' => $this->verifiedAt?->toIso8601String(),
+            'hasIdentities' => $this->hasIdentities,
+        ];
+    }
+}
diff --git a/app/Enums/EnterpriseSso/ConnectionTransitionRejection.php b/app/Enums/EnterpriseSso/ConnectionTransitionRejection.php
new file mode 100644
index 00000000..e87bba5d
--- /dev/null
+++ b/app/Enums/EnterpriseSso/ConnectionTransitionRejection.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\EnterpriseSso;
+
+/**
+ * 接続の管理操作を拒否した理由。
+ *
+ * ★企業ログインの拒否 ({@see RejectionReason}) と**別の型**である。
+ *   あちらは未認証の経路なので応答を一様にするが、**こちらは認可を通った運営操作**なので
+ *   「何が起きたのか」を画面へ具体的に伝える (存在を隠す必要がある相手ではない)。
+ */
+enum ConnectionTransitionRejection: string
+{
+    /** 身元が 1 件でもある接続では issuer / client_id を変更できない。 */
+    case IdentitiesExistCannotChangeNamespace = 'identities_exist_cannot_change_namespace';
+
+    /** 身元が 1 件でもある接続は物理削除できない (運用は無効化で行う)。 */
+    case IdentitiesExistCannotDelete = 'identities_exist_cannot_delete';
+
+    /** 遷移表に無い状態変化を求められた。 */
+    case UndefinedTransition = 'undefined_transition';
+
+    public function message(): string
+    {
+        return match ($this) {
+            self::IdentitiesExistCannotChangeNamespace => 'この接続では既に利用者がログインしているため、'
+                .'発行者 URL とクライアント ID は変更できません。新しい接続を作成してください。',
+            self::IdentitiesExistCannotDelete => 'この接続では既に利用者がログインしているため、削除できません。'
+                .'停止する場合は「無効化」を使ってください (登録済みの利用者はそのまま残ります)。',
+            self::UndefinedTransition => 'この接続の現在の状態では、その操作を実行できません。'
+                .'画面を再読み込みして状態を確認してください。',
+        };
+    }
+}
diff --git a/app/Enums/EnterpriseSso/FingerprintPurpose.php b/app/Enums/EnterpriseSso/FingerprintPurpose.php
new file mode 100644
index 00000000..96fb74ed
--- /dev/null
+++ b/app/Enums/EnterpriseSso/FingerprintPurpose.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\EnterpriseSso;
+
+use App\Support\EnterpriseSso\AttemptFingerprint;
+
+/**
+ * 指紋の用途。**相互に使い回せない** (domain separation)。
+ *
+ * ★**永続する値の用途をここへ足さない**。
+ *   足すと {@see AttemptFingerprint} の
+ *   「APP_KEY 由来の鍵でよい」という根拠が崩れる (ローテートで永続的なものが失われる)。
+ *   この禁止は tests/Unit/Support/AttemptFingerprintTest.php が case を名指しで pin する。
+ */
+enum FingerprintPurpose: string
+{
+    /** 寿命 10 分。 */
+    case State = 'enterprise-sso.state';
+
+    /** 寿命 10 分。 */
+    case Nonce = 'enterprise-sso.nonce';
+
+    /** 寿命 10 分。 */
+    case BrowserBinding = 'enterprise-sso.browser-binding';
+
+    /** 寿命 60 分。 */
+    case EmailPromotionToken = 'auth.email-promotion';
+}
diff --git a/app/Enums/EnterpriseSso/OidcConnectionStatus.php b/app/Enums/EnterpriseSso/OidcConnectionStatus.php
new file mode 100644
index 00000000..bc281840
--- /dev/null
+++ b/app/Enums/EnterpriseSso/OidcConnectionStatus.php
@@ -0,0 +1,27 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\EnterpriseSso;
+
+use App\Services\EnterpriseSso\OidcConnectionTransitionService;
+
+/**
+ * 組織 OIDC 接続の状態 (4 値で固定する。増やさない)。
+ *
+ * 遷移の唯一の書き手は {@see OidcConnectionTransitionService} である。
+ */
+enum OidcConnectionStatus: string
+{
+    /** 登録直後 / 認証材料を更新した直後。ログインに使えない。 */
+    case Draft = 'draft';
+
+    /** 接続先情報の取得に成功した。まだログインには使えない。 */
+    case Verified = 'verified';
+
+    /** ログインに使える。 */
+    case Active = 'active';
+
+    /** 運営が止めた。 */
+    case Disabled = 'disabled';
+}
diff --git a/app/Enums/EnterpriseSso/OidcSigningAlgorithm.php b/app/Enums/EnterpriseSso/OidcSigningAlgorithm.php
new file mode 100644
index 00000000..d2e85e76
--- /dev/null
+++ b/app/Enums/EnterpriseSso/OidcSigningAlgorithm.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\EnterpriseSso;
+
+/**
+ * ID トークンの署名方式の**許可集合**。
+ *
+ * ★`none` と対称鍵 (HMAC) は **case に持たない**。
+ *   許可集合を型で表せば「拒否の書き忘れ」という失敗様式そのものが消える
+ *   (文字列比較で弾く形は、比較を 1 つ忘れれば通る)。
+ */
+enum OidcSigningAlgorithm: string
+{
+    case Rs256 = 'RS256';
+    case Rs384 = 'RS384';
+    case Rs512 = 'RS512';
+    case Es256 = 'ES256';
+    case Es384 = 'ES384';
+
+    /** JWK の `kty` として妥当な値 (署名検証前の整合検査に使う)。 */
+    public function keyType(): string
+    {
+        return match ($this) {
+            self::Rs256, self::Rs384, self::Rs512 => 'RSA',
+            self::Es256, self::Es384 => 'EC',
+        };
+    }
+
+    /** EC のときに要求する `crv`。RSA では null。 */
+    public function curve(): ?string
+    {
+        return match ($this) {
+            self::Es256 => 'P-256',
+            self::Es384 => 'P-384',
+            self::Rs256, self::Rs384, self::Rs512 => null,
+        };
+    }
+}
diff --git a/app/Enums/EnterpriseSso/RejectionReason.php b/app/Enums/EnterpriseSso/RejectionReason.php
new file mode 100644
index 00000000..137bbb97
--- /dev/null
+++ b/app/Enums/EnterpriseSso/RejectionReason.php
@@ -0,0 +1,63 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\EnterpriseSso;
+
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+
+/**
+ * 企業 SSO の試行を拒否した理由。**機械可読な固定文字列だけ**である。
+ *
+ * ★{@see EnterpriseSsoAttemptRejectedException} は
+ *   本 enum しか受け取らない。外部由来の文字列・vendor の例外・要求 body は
+ *   例外の中へ入る道が**型で存在しない**。
+ * ★利用者への応答は理由によらず**一様**である。区別は内部のログ (理由コードだけ) にしか出ない。
+ */
+enum RejectionReason: string
+{
+    // --- discovery ---
+    case DiscoveryFetchFailed = 'discovery_fetch_failed';
+    case DiscoveryNotJson = 'discovery_not_json';
+    case DiscoveryNotObject = 'discovery_not_object';
+    case DiscoveryIssuerMismatch = 'discovery_issuer_mismatch';
+    case DiscoveryMissingField = 'discovery_missing_field';
+    case DiscoveryInvalidEndpoint = 'discovery_invalid_endpoint';
+    case DiscoveryNoSupportedAuthMethod = 'discovery_no_supported_auth_method';
+    case DiscoveryNoSupportedSigningAlg = 'discovery_no_supported_signing_alg';
+    case DiscoveryBodyTooLarge = 'discovery_body_too_large';
+
+    // --- JWKS ---
+    case JwksFetchFailed = 'jwks_fetch_failed';
+    case JwksMalformed = 'jwks_malformed';
+    case JwksKeyNotFound = 'jwks_key_not_found';
+    case JwksDuplicateKeyId = 'jwks_duplicate_key_id';
+    case JwksRefetchUnavailable = 'jwks_refetch_unavailable';
+
+    // --- token 交換 ---
+    case TokenExchangeFailed = 'token_exchange_failed';
+    case TokenResponseMalformed = 'token_response_malformed';
+    case TokenResponseMissingIdToken = 'token_response_missing_id_token';
+
+    // --- ID トークン ---
+    case IdTokenMalformed = 'id_token_malformed';
+    case IdTokenAlgorithmNotAllowed = 'id_token_algorithm_not_allowed';
+    case IdTokenKeyIdMissing = 'id_token_key_id_missing';
+    case IdTokenSignatureInvalid = 'id_token_signature_invalid';
+    case IdTokenClaimTypeInvalid = 'id_token_claim_type_invalid';
+    case IdTokenIssuerMismatch = 'id_token_issuer_mismatch';
+    case IdTokenAudienceMismatch = 'id_token_audience_mismatch';
+    case IdTokenExpired = 'id_token_expired';
+    case IdTokenNotYetValid = 'id_token_not_yet_valid';
+    case IdTokenIssuedInFuture = 'id_token_issued_in_future';
+    case IdTokenSubjectInvalid = 'id_token_subject_invalid';
+    case IdTokenNonceMismatch = 'id_token_nonce_mismatch';
+
+    // --- 試行・接続 ---
+    case AttemptNotFound = 'attempt_not_found';
+    case AttemptExpired = 'attempt_expired';
+    case AttemptBindingMismatch = 'attempt_binding_mismatch';
+    case AttemptBindingMissing = 'attempt_binding_missing';
+    case ConnectionNotUsable = 'connection_not_usable';
+    case ProviderReturnedError = 'provider_returned_error';
+}
diff --git a/app/Enums/EnterpriseSso/TokenEndpointAuthMethod.php b/app/Enums/EnterpriseSso/TokenEndpointAuthMethod.php
new file mode 100644
index 00000000..7f696408
--- /dev/null
+++ b/app/Enums/EnterpriseSso/TokenEndpointAuthMethod.php
@@ -0,0 +1,14 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\EnterpriseSso;
+
+/**
+ * token endpoint の client 認証方式。**body 漏洩面が小さい basic を優先する**。
+ */
+enum TokenEndpointAuthMethod: string
+{
+    case ClientSecretBasic = 'client_secret_basic';
+    case ClientSecretPost = 'client_secret_post';
+}
diff --git a/app/Enums/Security/OrgAccessRevocationExemption.php b/app/Enums/Security/OrgAccessRevocationExemption.php
index c05c65e4..2af185f9 100644
--- a/app/Enums/Security/OrgAccessRevocationExemption.php
+++ b/app/Enums/Security/OrgAccessRevocationExemption.php
@@ -14,12 +14,18 @@ enum OrgAccessRevocationExemption: string
 {
     case JoinOrganization = 'OrganizationMembershipService::joinOrganization';
 
+    case AttachJustInTimeMember = 'OrganizationMembershipService::attachJustInTimeMember';
+
     public function rationale(): string
     {
         return match ($this) {
             self::JoinOrganization => '招待受諾は組織に入れる操作であり、その時点でその人が'
                 .'その組織で持つ資格情報は 1 件も存在し得ない (発行には所属が前提のため)。'
                 .'したがって失効の対象が構造的に空である。',
+            self::AttachJustInTimeMember => '企業 SSO の初回ログインで**直前に作られた利用者**を'
+                .'組織へ入れる操作である。利用者そのものが同じトランザクションで生まれたばかりなので、'
+                .'その人がその組織で持つ資格情報は 1 件も存在し得ない。'
+                .'したがって失効の対象が構造的に空である (joinOrganization と同じ理由)。',
         };
     }
 }
diff --git a/app/Exceptions/Auth/EmailPromotionConflictException.php b/app/Exceptions/Auth/EmailPromotionConflictException.php
new file mode 100644
index 00000000..667284ad
--- /dev/null
+++ b/app/Exceptions/Auth/EmailPromotionConflictException.php
@@ -0,0 +1,17 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Auth;
+
+use RuntimeException;
+
+/**
+ * 昇格しようとしたメールが**既に他の利用者のもの**だったことを表す例外。
+ *
+ * ★応答は**一様**である (存在を漏らさない)。既存の利用者は**一切変更せず・併合せず**、
+ *   昇格も行わない。
+ * ★変換してよいのは**メールの blind index の一意制約違反だけ**である。
+ *   それ以外の一意制約違反と DB の障害は**握り潰さない** (伝播させる)。
+ */
+final class EmailPromotionConflictException extends RuntimeException {}
diff --git a/app/Exceptions/EnterpriseSso/EnterpriseSsoAttemptRejectedException.php b/app/Exceptions/EnterpriseSso/EnterpriseSsoAttemptRejectedException.php
new file mode 100644
index 00000000..c3cdded9
--- /dev/null
+++ b/app/Exceptions/EnterpriseSso/EnterpriseSsoAttemptRejectedException.php
@@ -0,0 +1,31 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\RejectionReason;
+use RuntimeException;
+
+/**
+ * 企業 SSO の試行を拒否したことを表す例外。
+ *
+ * ★**構築子は理由の enum しか受け取らない**。`previous` を受け取れないので、
+ *   vendor の例外を連鎖させて要求 body (認可コード / client secret / code_verifier) が
+ *   ログへ展開される経路が**型で存在しない**。
+ *   この形は tests/Architecture/EnterpriseSsoSecretExposureGateTest が構築子の引数で固定する。
+ * ★message は理由の値そのものである (外部由来の文字列を混ぜない)。
+ * ★利用者への応答は理由によらず**一様**である。区別は内部にしか無い。
+ */
+final class EnterpriseSsoAttemptRejectedException extends RuntimeException
+{
+    private function __construct(public readonly RejectionReason $reason)
+    {
+        parent::__construct($reason->value);
+    }
+
+    public static function of(RejectionReason $reason): self
+    {
+        return new self($reason);
+    }
+}
diff --git a/app/Exceptions/EnterpriseSso/EnterpriseSsoAttemptStoreFailure.php b/app/Exceptions/EnterpriseSso/EnterpriseSsoAttemptStoreFailure.php
new file mode 100644
index 00000000..cbff78ec
--- /dev/null
+++ b/app/Exceptions/EnterpriseSso/EnterpriseSsoAttemptStoreFailure.php
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\AttemptConsumeResult;
+use RuntimeException;
+
+/**
+ * ログイン試行の保管そのものが壊れたことを表す例外 (**業務上の拒否ではない**)。
+ *
+ * ★「行を消したのに 1 行も影響しなかった」のような **DB の障害**だけがここに来る。
+ *   業務上の拒否 (行が無い / 期限切れ / 結合の不一致) は
+ *   {@see AttemptConsumeResult} の分類として**返る**。
+ *   混ぜると「排他が壊れた」という重大な事実が一様な拒否に隠れる。
+ */
+final class EnterpriseSsoAttemptStoreFailure extends RuntimeException {}
diff --git a/app/Exceptions/EnterpriseSso/OidcConnectionTransitionException.php b/app/Exceptions/EnterpriseSso/OidcConnectionTransitionException.php
new file mode 100644
index 00000000..ec23d27d
--- /dev/null
+++ b/app/Exceptions/EnterpriseSso/OidcConnectionTransitionException.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\ConnectionTransitionRejection;
+use RuntimeException;
+
+/**
+ * 接続の管理操作を拒否したことを表す例外。
+ *
+ * ★構築子は**理由の enum しか受け取らない** (`previous` を持たない)。
+ *   秘密が例外の連鎖で展開される経路を型で消すのは
+ *   {@see EnterpriseSsoAttemptRejectedException} と同じ思想である。
+ */
+final class OidcConnectionTransitionException extends RuntimeException
+{
+    private function __construct(public readonly ConnectionTransitionRejection $rejection)
+    {
+        parent::__construct($rejection->value);
+    }
+
+    public static function of(ConnectionTransitionRejection $rejection): self
+    {
+        return new self($rejection);
+    }
+}
diff --git a/app/Http/Controllers/Auth/EmailPromotionController.php b/app/Http/Controllers/Auth/EmailPromotionController.php
new file mode 100644
index 00000000..f66a888f
--- /dev/null
+++ b/app/Http/Controllers/Auth/EmailPromotionController.php
@@ -0,0 +1,102 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Auth;
+
+use App\Exceptions\Auth\EmailPromotionConflictException;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Auth\ConfirmEmailPromotionRequest;
+use App\Http\Requests\Auth\StoreEmailPromotionRequest;
+use App\Models\User;
+use App\Services\Auth\EmailPromotionService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Http\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * 企業 SSO でしか入れない利用者が、自分で使えるメールアドレスを持つための昇格。
+ *
+ * ★**認可は「自分の資源」である**。Gate を通さず `Auth::id()` (= `$request->user()`) だけを使う
+ *   (`ControllerAuthorizationGateTest` の exemption へ理由付きで登録する)。
+ * ★確認は **GET の画面 + POST の確定**に割る。署名付き GET のリンクだけだと、
+ *   メールクライアントの先読みやプレビューで**利用者が意図せず確定してしまう**。
+ * ★確認画面は **standalone Blade** である (`Inertia::render` を呼ばない)。
+ *   Inertia は page object を `history.state` へ載せるため、prop へ置いた瞬間に
+ *   **トークンがブラウザの履歴に残る**。
+ * ★失敗しても `withInput()` を使わない (トークンを old input に残さない)。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * リバースプロキシや CDN のアクセスログ、ブラウザの履歴、利用者が URL を他人へ貼ることに
+ * よる露出は防げない。緩和は **60 分の期限**と **一回だけの consume** であり、
+ * 露出しても**使われる窓が短く、1 回しか効かない**ことに寄せている。
+ */
+class EmailPromotionController extends Controller
+{
+    /** 確定・失敗のどちらでも同じ行き先 (存在を漏らさない)。 */
+    private const string SETTINGS_ROUTE = 'settings.security';
+
+    public function __construct(private readonly EmailPromotionService $promotions) {}
+
+    /** 発行 (確認メールを送る)。 */
+    public function store(StoreEmailPromotionRequest $request): RedirectResponse
+    {
+        $this->promotions->issue($this->currentUser($request), $request->emailValue());
+
+        return back()->with('success', '確認メールを送信しました。メール内のリンクから登録を完了してください。');
+    }
+
+    /** 再送 (**発行と同じ入口**。旧トークンは失効する)。 */
+    public function resend(StoreEmailPromotionRequest $request): RedirectResponse
+    {
+        $this->promotions->issue($this->currentUser($request), $request->emailValue());
+
+        return back()->with('success', '確認メールを再送しました。');
+    }
+
+    /**
+     * 確認画面 (GET)。
+     *
+     * ★**状態を変えない**。トークンを画面へ渡し、利用者が明示のボタンで POST する。
+     * ★**トークンの有効・無効で画面を変えない** (一様。存在の探り当てを作らない)。
+     */
+    public function showConfirm(Request $request): Response
+    {
+        return response()->view('auth.email-promotion.confirm', [
+            'token' => $request->string('token')->value(),
+        ]);
+    }
+
+    /** 確定 (POST のみ)。 */
+    public function confirm(ConfirmEmailPromotionRequest $request): RedirectResponse
+    {
+        try {
+            $confirmed = $this->promotions->confirm($this->currentUser($request), $request->tokenValue());
+        } catch (EmailPromotionConflictException) {
+            // ★衝突の応答は**一様**である (既存利用者の存在を漏らさない)。
+            //   既存利用者は一切変更せず・併合せず・昇格も行わない。
+            return redirect()->route(self::SETTINGS_ROUTE)->withErrors([
+                'email_promotion' => 'このメールアドレスは登録できませんでした。別のアドレスをお試しください。',
+            ]);
+        }
+
+        if (! $confirmed) {
+            return redirect()->route(self::SETTINGS_ROUTE)->withErrors([
+                'email_promotion' => 'この確認リンクは無効か、有効期限が切れています。もう一度手続きをやり直してください。',
+            ]);
+        }
+
+        return redirect()->route(self::SETTINGS_ROUTE)
+            ->with('success', 'メールアドレスを登録しました。');
+    }
+
+    private function currentUser(Request $request): User
+    {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        return $user;
+    }
+}
diff --git a/app/Http/Controllers/Auth/EnterpriseSsoLoginController.php b/app/Http/Controllers/Auth/EnterpriseSsoLoginController.php
new file mode 100644
index 00000000..00162465
--- /dev/null
+++ b/app/Http/Controllers/Auth/EnterpriseSsoLoginController.php
@@ -0,0 +1,178 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Auth;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Auth\EnterpriseSsoCallbackRequest;
+use App\Models\OrganizationOidcConnection;
+use App\Services\EnterpriseSso\EnterpriseCallbackAuthenticator;
+use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
+use App\Services\EnterpriseSso\OidcDiscoveryService;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\Log;
+use Inertia\Inertia;
+use Inertia\Response as InertiaResponse;
+
+/**
+ * 企業 IdP との OIDC SSO のログイン導線。
+ *
+ * ★**待機ログインを作らない** (家系の裁定 AG-200)。確認できた時点で `Auth::login()` で
+ *   ログインを確定させ、画面へ送る。**2 要素認証の入力画面へ転送する分岐を持たない**。
+ *   これは tests/Architecture/SsoTwoFactorInterpositionGateTest が企業・ソーシャルの
+ *   両 controller に対して静的に裏当てし、主たる証明は
+ *   tests/Feature/Auth/EnterpriseSsoLoginTest の実挙動側にある。
+ *   組織の 2 要素義務づけの強制は別関門 (`RequireTwoFactorForEnforcedOrganizations`) が
+ *   **ログイン確定後**にアカウント全体のゲートとして担い、転送先は 2 要素の**設定ページ**である。
+ *
+ * ★`remember: false` である。remember cookie を許すと、接続を無効化した後も
+ *   cookie から新しいセッションを開始できてしまい、
+ *   「次回ログインができなくなる」という効果の主張と整合しない。
+ *
+ * ★失敗の応答は**一様**である (接続や利用者の存在を読み取れない)。
+ *   理由の区別が出るのは**内部のログの理由コードだけ**である。
+ */
+class EnterpriseSsoLoginController extends Controller
+{
+    /** 失敗時に利用者へ見せる**唯一の**文言 (理由で出し分けない)。 */
+    private const string UNIFORM_FAILURE_MESSAGE = '企業アカウントでのログインを完了できませんでした。'
+        .'もう一度お試しいただくか、組織の管理者にお問い合わせください。';
+
+    /**
+     * 企業ログインの入口 (識別名の入力画面)。
+     *
+     * ★外向き通信をしない。DB も変えない。
+     */
+    public function show(): InertiaResponse
+    {
+        return Inertia::render('Auth/EnterpriseLogin');
+    }
+
+    /**
+     * 開始。**行を作ってからリダイレクトする** (逆順だと戻ってきた state が存在しない)。
+     *
+     *  1. 接続を識別名で解決し、**Active であること**を確かめる
+     *  2. CSPRNG で state / nonce / PKCE の検証子 / ブラウザ結合の秘密を各 32 バイト生成する
+     *  3. ブラウザ結合の秘密を**セッションへ置く** (キーは state の指紋ごとに分ける = 複数タブ共存)
+     *  4. 試行の行を作る (state / nonce / 結合の指紋 + 暗号化した検証子 + 期限)
+     *  5. 認可要求の URL を組み立ててリダイレクトする
+     *
+     * ★GET だが **DB に試行の行を作る変更操作**である (OAuth の開始)。
+     *   CSRF トークンの代わりに state・ブラウザ結合・流量制限・no-store が守る。
+     */
+    public function redirect(
+        Request $request,
+        OrganizationOidcConnection $connection,
+        EnterpriseLoginAttemptStore $attempts,
+        OidcDiscoveryService $discovery,
+    ): RedirectResponse {
+        // ★接続の解決と「使えるか」の判定は PublicOidcConnectionBinder が済ませている。
+        //   **不在の識別名と使えない接続は binder の段で同じ 404 に畳まれ**、
+        //   route の missing() が利用者向けの一様な案内へ変える (実在オラクルを作らない)。
+        //   したがって**無効な接続で行が作られることはない**。
+
+        try {
+            $metadata = $discovery->fetchMetadata(OidcIssuerUrl::fromString($connection->issuer));
+        } catch (EnterpriseSsoAttemptRejectedException $e) {
+            return $this->uniformFailure($e->reason);
+        }
+
+        $state = AttemptFingerprint::newSecret();
+        $nonce = AttemptFingerprint::newSecret();
+        $codeVerifier = AttemptFingerprint::newSecret();
+        $bindingSecret = AttemptFingerprint::newSecret();
+
+        // ★セッションのキーは state の指紋ごとに分ける (複数タブが共存できる)。
+        $request->session()->put(
+            EnterpriseCallbackAuthenticator::bindingSessionKey(
+                AttemptFingerprint::of(FingerprintPurpose::State, $state),
+            ),
+            $bindingSecret,
+        );
+
+        // ★**行を作ってからリダイレクトする**。
+        $attempts->start($connection, $state, $nonce, $codeVerifier, $bindingSecret);
+
+        return redirect()->away($this->authorizationUrl($metadata->authorizationEndpoint, [
+            'response_type' => 'code',
+            'scope' => 'openid email profile',
+            'client_id' => $connection->client_id,
+            'redirect_uri' => route('enterprise-sso.callback'),
+            'state' => $state,
+            'nonce' => $nonce,
+            'code_challenge' => $this->codeChallenge($codeVerifier),
+            'code_challenge_method' => 'S256',
+        ]));
+    }
+
+    /**
+     * 戻り口。
+     *
+     * ★ここで `redirect()->intended()` を使うのは**ログイン直後フロー**だからである
+     *   (禁止事項 7 の明示的な適用範囲内。既存の `SocialAuthController` と同じ形)。
+     */
+    public function callback(
+        EnterpriseSsoCallbackRequest $request,
+        EnterpriseCallbackAuthenticator $authenticator,
+    ): RedirectResponse {
+        if ($request->providerReturnedError()) {
+            return $this->uniformFailure(RejectionReason::ProviderReturnedError);
+        }
+
+        try {
+            $user = $authenticator->authenticate(
+                $request->session(),
+                $request->stateValue(),
+                $request->codeValue(),
+                route('enterprise-sso.callback'),
+            );
+        } catch (EnterpriseSsoAttemptRejectedException $e) {
+            return $this->uniformFailure($e->reason);
+        }
+
+        Auth::login($user, remember: false);
+        $request->session()->regenerate();
+
+        return redirect()->intended(route('app.entry'));
+    }
+
+    /**
+     * 失敗の応答。**理由によらず同じ文言・同じ行き先**である。
+     *
+     * ★理由コードは**ログにだけ**出す (利用者に返す応答へ入れない)。
+     * ★`withInput()` を使わない — `code` / `state` を old input に残さない。
+     */
+    private function uniformFailure(RejectionReason $reason): RedirectResponse
+    {
+        Log::info('enterprise-sso login rejected', ['reason' => $reason->value]);
+
+        return redirect()->route('login')->withErrors([
+            'enterprise_sso' => self::UNIFORM_FAILURE_MESSAGE,
+        ]);
+    }
+
+    /**
+     * @param  array<string, string>  $parameters
+     */
+    private function authorizationUrl(string $endpoint, array $parameters): string
+    {
+        // ★endpoint は query を持ちうる (discovery で禁じていない)。既存の query を保つ。
+        $separator = str_contains($endpoint, '?') ? '&' : '?';
+
+        return $endpoint.$separator.http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
+    }
+
+    /** PKCE (S256) の challenge。 */
+    private function codeChallenge(string $codeVerifier): string
+    {
+        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
+    }
+}
diff --git a/app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php b/app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php
new file mode 100644
index 00000000..d263c229
--- /dev/null
+++ b/app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php
@@ -0,0 +1,189 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Organizations;
+
+use App\DataTransferObjects\Organizations\SsoConnectionSummary;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Exceptions\EnterpriseSso\OidcConnectionTransitionException;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Organizations\StoreSsoConnectionRequest;
+use App\Http\Requests\Organizations\UpdateSsoConnectionRequest;
+use App\Models\Organization;
+use App\Models\OrganizationOidcConnection;
+use App\Services\EnterpriseSso\OidcConnectionTransitionService;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Support\Facades\Gate;
+use Inertia\Inertia;
+use Inertia\Response;
+
+/**
+ * 組織の企業 OIDC 接続の管理 (一覧・登録・更新・確認・有効化・無効化・削除)。
+ *
+ * ★**接続の秘密を扱う前面は登録・更新フォーム 1 本だけ**である (正典 v1 / I4)。
+ *   画面は 1 枚 (一覧 + フォーム) で、秘密を扱う前面を 2 枚に割らない。
+ *   一覧の生成は **秘密を一度も復号しない** ({@see SsoConnectionSummary} が
+ *   `hasClientSecret` の bool しか持たない)。
+ *
+ * ★操作系はすべて `back()->with(...)` で完結させる (`redirect()->intended()` は
+ *   ログイン直後フロー専用 = 禁止事項 7)。
+ *
+ * ★`{oidcConnection}` は `Organization::oidcConnections()` 経由で `scopeBindings()` が解決する。
+ *   親に属さない id は **binding 段で 404** (認可より前。AGENTS.md 不変条件 2 / 10)。
+ *
+ * ★**`verify` だけはトランザクションの張り方が違う**。D1 の三段構成
+ *   (ロックなしでスナップショット → ロックなしで外向き取得 → ロック下で再確認) を
+ *   controller 側が壊さないよう、**外向き取得を包むトランザクションをここで張らない**。
+ */
+class OrganizationSsoConnectionController extends Controller
+{
+    public function __construct(
+        private readonly OidcConnectionTransitionService $transitions,
+    ) {}
+
+    /**
+     * 一覧 (閲覧も owner / admin に限る)。
+     *
+     * ★秘密を一度も復号しない。身元の有無は**まとめて数える** (N+1 を作らない)。
+     */
+    public function index(Organization $organization): Response
+    {
+        Gate::authorize('viewAny', [OrganizationOidcConnection::class, $organization]);
+
+        $connections = $organization->oidcConnections()
+            ->withCount('identities')
+            ->orderBy('id')
+            ->get()
+            ->map(fn (OrganizationOidcConnection $connection): array => SsoConnectionSummary::fromModel(
+                $connection,
+                hasIdentities: ($connection->identities_count ?? 0) > 0,
+            )->toArray())
+            ->values()
+            ->all();
+
+        return Inertia::render('Organizations/Sso/Index', [
+            'organization' => [
+                'id' => $organization->id,
+                'name' => $organization->name,
+                'slug' => $organization->slug,
+            ],
+            'connections' => $connections,
+            // 利用者に案内する戻り口の URL (IdP 側へ登録してもらう値)。
+            'callbackUrl' => route('enterprise-sso.callback'),
+        ]);
+    }
+
+    /** 登録 (常に `Draft` から始まる)。 */
+    public function store(StoreSsoConnectionRequest $request, Organization $organization): RedirectResponse
+    {
+        Gate::authorize('create', [OrganizationOidcConnection::class, $organization]);
+
+        $this->transitions->create(
+            $organization,
+            $request->loginSlugValue(),
+            $request->displayNameValue(),
+            $request->issuerValue(),
+            $request->clientIdValue(),
+            $request->clientSecretValue(),
+        );
+
+        return back()->with('success', '接続を登録しました。「確認」を押して接続先情報を取得してください。');
+    }
+
+    /** 更新 (認証材料を変えたら必ず `Draft` へ戻る)。 */
+    public function update(
+        UpdateSsoConnectionRequest $request,
+        Organization $organization,
+        OrganizationOidcConnection $oidcConnection,
+    ): RedirectResponse {
+        Gate::authorize('update', $oidcConnection);
+
+        try {
+            $this->transitions->update(
+                $organization,
+                $oidcConnection->id,
+                $request->displayNameValue(),
+                $request->issuerValue(),
+                $request->clientIdValue(),
+                $request->clientSecretValue(),
+            );
+        } catch (OidcConnectionTransitionException $e) {
+            // ★押下時にエラーを表示する (ボタンを disabled にしない = 禁止事項 8)。
+            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
+        }
+
+        return back()->with('success', '接続を更新しました。');
+    }
+
+    /**
+     * 確認 (接続先情報を実際に取りに行く)。
+     *
+     * ★**外向きの取得を伴う唯一の管理操作**なので専用の流量制限を持つ。
+     * ★結果は**一様にしない** — 認可を通った運営操作なので理由を具体的に伝える。
+     */
+    public function verify(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
+    {
+        Gate::authorize('update', $oidcConnection);
+
+        try {
+            $outcome = $this->transitions->verify($organization, $oidcConnection);
+        } catch (EnterpriseSsoAttemptRejectedException $e) {
+            // ★取得の失敗で接続の状態は変わらない (可用性の後退を作らない)。
+            //   理由コードは画面へ出さない (外部由来の情報を運営画面へ持ち込まない)。
+            return back()->withErrors([
+                'sso_connection' => '接続先情報を取得できませんでした。発行者 URL と IdP 側の設定を確認してください。',
+            ]);
+        } catch (OidcConnectionTransitionException $e) {
+            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
+        }
+
+        if (! $outcome->succeeded()) {
+            return back()->withErrors(['sso_connection' => $outcome->message()]);
+        }
+
+        return back()->with('success', $outcome->message());
+    }
+
+    /** 有効化 (ここから企業ログインが使えるようになる)。 */
+    public function activate(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
+    {
+        Gate::authorize('update', $oidcConnection);
+
+        try {
+            $this->transitions->activate($organization, $oidcConnection->id);
+        } catch (OidcConnectionTransitionException $e) {
+            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
+        }
+
+        return back()->with('success', '接続を有効にしました。');
+    }
+
+    /** 無効化 (運用の推奨経路。**身元は残る**ので再び有効にすれば同じ利用者へ戻る)。 */
+    public function disable(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
+    {
+        Gate::authorize('update', $oidcConnection);
+
+        try {
+            $this->transitions->disable($organization, $oidcConnection->id);
+        } catch (OidcConnectionTransitionException $e) {
+            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
+        }
+
+        return back()->with('success', '接続を無効にしました。次回以降この IdP ではログインできません。');
+    }
+
+    /** 削除 (★**身元が 1 件でもあれば拒否**。運用は無効化で行う)。 */
+    public function destroy(Organization $organization, OrganizationOidcConnection $oidcConnection): RedirectResponse
+    {
+        Gate::authorize('delete', $oidcConnection);
+
+        try {
+            $this->transitions->destroy($organization, $oidcConnection->id);
+        } catch (OidcConnectionTransitionException $e) {
+            return back()->withErrors(['sso_connection' => $e->rejection->message()]);
+        }
+
+        return back()->with('success', '接続を削除しました。');
+    }
+}
diff --git a/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php b/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php
new file mode 100644
index 00000000..e6195abd
--- /dev/null
+++ b/app/Http/Requests/Auth/ConfirmEmailPromotionRequest.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Auth;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * メールアドレスの昇格の確定。
+ *
+ * ★確定は **POST だけ**である (GET の確認画面は状態を変えない)。
+ *   署名付き GET のリンクだけだと、メールクライアントの先読みやプレビューで
+ *   **利用者が意図せず確定してしまう**。
+ * ★失敗しても `withInput()` を使わない (トークンを old input に残さない)。
+ *   `token` は一般名なのでグローバルの `dontFlash` へは足さず、**経路側で閉じる**。
+ */
+class ConfirmEmailPromotionRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<string>>
+     */
+    public function rules(): array
+    {
+        // 長さの上限は指紋の元になる一時値の実長 (base64url 43 文字) に十分な余裕を持たせる。
+        return [
+            'token' => ['required', 'string', 'max:'.(AttemptFingerprint::HEX_LENGTH * 4)],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'token' => '確認トークン',
+        ];
+    }
+
+    public function tokenValue(): string
+    {
+        return $this->string('token')->value();
+    }
+}
diff --git a/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php b/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php
new file mode 100644
index 00000000..df4de3be
--- /dev/null
+++ b/app/Http/Requests/Auth/EnterpriseSsoCallbackRequest.php
@@ -0,0 +1,68 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Auth;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * 企業 SSO の戻り口の入力。
+ *
+ * ★**不正な入力では外向き取得を一切開始しない**。ここで弾く。
+ * ★`code` と `error` は**排他**である (両方来た応答は仕様外なので受けない)。
+ * ★失敗しても `withInput()` を使わない (経路側で入力を一切 flash しない)。
+ *   `code` / `state` は一般名なのでグローバルの `dontFlash` へは足さない
+ *   — 他のフォームの入力復元まで黙って変えてしまうため。
+ */
+class EnterpriseSsoCallbackRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    /** 未認証で到達する経路である (ログインの戻り口)。認可は接続の状態が担う。 */
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<string>>
+     */
+    public function rules(): array
+    {
+        return [
+            'state' => ['required', 'string', 'max:512'],
+            'code' => ['nullable', 'string', 'max:4096', 'required_without:error', 'prohibits:error'],
+            'error' => ['nullable', 'string', 'max:256'],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'state' => '状態値',
+            'code' => '認可コード',
+            'error' => 'エラー',
+        ];
+    }
+
+    /** IdP が error を返したか (一様な失敗として扱う)。 */
+    public function providerReturnedError(): bool
+    {
+        return $this->string('error')->isNotEmpty();
+    }
+
+    public function stateValue(): string
+    {
+        return $this->string('state')->value();
+    }
+
+    public function codeValue(): string
+    {
+        return $this->string('code')->value();
+    }
+}
diff --git a/app/Http/Requests/Auth/StoreEmailPromotionRequest.php b/app/Http/Requests/Auth/StoreEmailPromotionRequest.php
new file mode 100644
index 00000000..3b5d18bf
--- /dev/null
+++ b/app/Http/Requests/Auth/StoreEmailPromotionRequest.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Auth;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * メールアドレスの昇格の開始 (確認メールの発行)。
+ *
+ * ★認可は「自分の資源」なので Gate を通さない (controller が `Auth::id()` だけを使う)。
+ * ★ここで受けるのは**宛先のメールアドレスだけ**である。利用者を選ぶ入力を受けない。
+ */
+class StoreEmailPromotionRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<string>>
+     */
+    public function rules(): array
+    {
+        return [
+            'email' => ['required', 'string', 'email', 'max:255'],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'email' => 'メールアドレス',
+        ];
+    }
+
+    public function emailValue(): string
+    {
+        return $this->string('email')->value();
+    }
+}
diff --git a/app/Http/Requests/Organizations/StoreSsoConnectionRequest.php b/app/Http/Requests/Organizations/StoreSsoConnectionRequest.php
new file mode 100644
index 00000000..6570aa2a
--- /dev/null
+++ b/app/Http/Requests/Organizations/StoreSsoConnectionRequest.php
@@ -0,0 +1,87 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Organizations;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Rules\OidcIssuerUrlRule;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+
+/**
+ * 接続の登録の入力。**接続の秘密を扱ってよい唯一の前面**である (正典 v1 / I4)。
+ *
+ * ★`client_secret` は `bootstrap/app.php` の `dontFlash` に登録済みである
+ *   (登録しないと validation 失敗時に秘密が old input としてセッションに残る)。
+ * ★validation の応答・監査ログ・例外・要求の記録にも含めない。
+ * ★認可は route の `Gate::authorize` が担う (FormRequest では判定しない)。
+ */
+class StoreSsoConnectionRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        return [
+            'login_slug' => [
+                'required', 'string', 'max:64', 'regex:/\A[a-z0-9][a-z0-9-]*[a-z0-9]\z/',
+                Rule::unique('organization_oidc_connections', 'login_slug'),
+            ],
+            'display_name' => ['required', 'string', 'max:100'],
+            'issuer' => ['required', 'string', 'max:255', new OidcIssuerUrlRule],
+            'client_id' => ['required', 'string', 'max:255'],
+            'client_secret' => ['required', 'string', 'max:1024'],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'login_slug' => '識別名',
+            'display_name' => '表示名',
+            'issuer' => '発行者 URL',
+            'client_id' => 'クライアント ID',
+            'client_secret' => 'クライアントシークレット',
+        ];
+    }
+
+    public function loginSlugValue(): string
+    {
+        return $this->string('login_slug')->value();
+    }
+
+    public function displayNameValue(): string
+    {
+        return $this->string('display_name')->value();
+    }
+
+    public function issuerValue(): OidcIssuerUrl
+    {
+        return OidcIssuerUrl::fromString($this->string('issuer')->value());
+    }
+
+    public function clientIdValue(): string
+    {
+        return $this->string('client_id')->value();
+    }
+
+    /** ★平文が現れる**唯一の**場所。値型へ包んですぐ渡す (素の文字列を持ち回らない)。 */
+    public function clientSecretValue(): ConnectionSecret
+    {
+        return ConnectionSecret::fromPlaintext($this->string('client_secret')->value());
+    }
+}
diff --git a/app/Http/Requests/Organizations/UpdateSsoConnectionRequest.php b/app/Http/Requests/Organizations/UpdateSsoConnectionRequest.php
new file mode 100644
index 00000000..17caadbb
--- /dev/null
+++ b/app/Http/Requests/Organizations/UpdateSsoConnectionRequest.php
@@ -0,0 +1,84 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Organizations;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Rules\OidcIssuerUrlRule;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Illuminate\Foundation\Http\FormRequest;
+
+/**
+ * 接続の更新の入力。**接続の秘密を扱ってよい唯一の前面**である (正典 v1 / I4)。
+ *
+ * ★**伏字の見本をそのまま更新値として受け付けない** — 未入力なら据え置きにする
+ *   (伏字文字列がそのまま秘密として保存される事故を型と規則で消す)。
+ *   画面は秘密の伏字を**描かない** (`SsoConnectionSummary` が持たない) ので、
+ *   「見本が送られてくる」経路はそもそも存在しないが、**空文字は据え置き**として扱う。
+ */
+class UpdateSsoConnectionRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        return [
+            'display_name' => ['nullable', 'string', 'max:100'],
+            'issuer' => ['nullable', 'string', 'max:255', new OidcIssuerUrlRule],
+            'client_id' => ['nullable', 'string', 'max:255'],
+            'client_secret' => ['nullable', 'string', 'max:1024'],
+        ];
+    }
+
+    /**
+     * @return array<string, string>
+     */
+    public function attributes(): array
+    {
+        return [
+            'display_name' => '表示名',
+            'issuer' => '発行者 URL',
+            'client_id' => 'クライアント ID',
+            'client_secret' => 'クライアントシークレット',
+        ];
+    }
+
+    public function displayNameValue(): ?string
+    {
+        $value = $this->string('display_name')->value();
+
+        return $value === '' ? null : $value;
+    }
+
+    public function issuerValue(): ?OidcIssuerUrl
+    {
+        $value = $this->string('issuer')->value();
+
+        return $value === '' ? null : OidcIssuerUrl::fromString($value);
+    }
+
+    public function clientIdValue(): ?string
+    {
+        $value = $this->string('client_id')->value();
+
+        return $value === '' ? null : $value;
+    }
+
+    /** ★未入力 (空文字) は**据え置き**である。伏字が保存されることはない。 */
+    public function clientSecretValue(): ?ConnectionSecret
+    {
+        $value = $this->string('client_secret')->value();
+
+        return $value === '' ? null : ConnectionSecret::fromPlaintext($value);
+    }
+}
diff --git a/app/Http/Routing/PublicOidcConnectionBinder.php b/app/Http/Routing/PublicOidcConnectionBinder.php
new file mode 100644
index 00000000..3885a814
--- /dev/null
+++ b/app/Http/Routing/PublicOidcConnectionBinder.php
@@ -0,0 +1,79 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Routing;
+
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Models\OrganizationOidcConnection;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Illuminate\Routing\Route;
+
+/**
+ * 公開の企業ログイン導線 (`/enterprise/{connection}/redirect`) の `{connection}` を解決する
+ * explicit binder。
+ *
+ * ## 何を担うか
+ *
+ *  1. **入力の正規化** — 識別名の書式に合わない値は DB へ触れる前に落とす
+ *     (pgsql へ長大な文字列や制御文字を渡さない)
+ *  2. **解決はフレームワークの binding 規約に委ねる** —
+ *     {@see OrganizationOidcConnection::resolveRouteBinding()} を通す。
+ *     アプリ側で `where('login_slug', …)` を書かないので、
+ *     「クラス起点の非主キー一意列によるモデル解決」を 1 件も増やさない
+ *     (`ModelDirectFetchInvariantTest` の provenance 前提を崩さない)
+ *  3. **応答の一様化** — **不在の識別名と、実在するが使えない接続 (Draft / Verified /
+ *     Disabled) を同じ `ModelNotFoundException` に畳む**。
+ *     分けると「429 / 404 になるまでの違い」が接続の実在オラクルになる。
+ *     route 側の `missing()` がこれを受けて、利用者には**同じ**案内を返す
+ *
+ * ## 担わないもの
+ *
+ * 識別名は**全体で一意な公開の値**であり、推測されてよい。
+ * 推測可能性に依存した防御はここに無い — 防御は接続の状態 (Active か) と、
+ * state / PKCE / ブラウザ結合が担う。
+ *
+ * `{connection}` は {@see RouteBindingTypes::CUSTOM_BINDER} 分類である
+ * (識別名は数値ではないので `Route::pattern` の型制約を掛けられない)。
+ * {@see NormalizesRouteBindingInput} はその分類を型で宣言する marker である。
+ */
+final class PublicOidcConnectionBinder implements NormalizesRouteBindingInput
+{
+    /** DB の `login_slug` 列 (varchar 64) と対。 */
+    private const int MAX_LENGTH = 64;
+
+    /** 登録時の書式 (StoreSsoConnectionRequest と同じ形)。 */
+    private const string SLUG_PATTERN = '/\A[a-z0-9][a-z0-9-]*[a-z0-9]\z/';
+
+    /**
+     * @throws ModelNotFoundException<OrganizationOidcConnection>
+     */
+    public function bind(mixed $value, ?Route $route = null): OrganizationOidcConnection
+    {
+        if (! is_string($value) || strlen($value) > self::MAX_LENGTH
+            || preg_match(self::SLUG_PATTERN, $value) !== 1
+        ) {
+            throw $this->notFound();
+        }
+
+        $connection = (new OrganizationOidcConnection)->resolveRouteBinding($value, 'login_slug');
+
+        // ★不在と「使えない状態」を**同じ例外**に畳む (実在オラクルを作らない)。
+        if (! $connection instanceof OrganizationOidcConnection
+            || $connection->status !== OidcConnectionStatus::Active
+        ) {
+            throw $this->notFound();
+        }
+
+        return $connection;
+    }
+
+    /** @return ModelNotFoundException<OrganizationOidcConnection> */
+    private function notFound(): ModelNotFoundException
+    {
+        /** @var ModelNotFoundException<OrganizationOidcConnection> $exception */
+        $exception = (new ModelNotFoundException)->setModel(OrganizationOidcConnection::class);
+
+        return $exception;
+    }
+}
diff --git a/app/Http/Routing/RouteBindingTypes.php b/app/Http/Routing/RouteBindingTypes.php
index f1ab8c60..1d00e727 100644
--- a/app/Http/Routing/RouteBindingTypes.php
+++ b/app/Http/Routing/RouteBindingTypes.php
@@ -11,6 +11,7 @@
 use App\Models\Item;
 use App\Models\OauthSession;
 use App\Models\OrganizationInvitation;
+use App\Models\OrganizationOidcConnection;
 use App\Models\Project;
 use App\Models\RenderJob;
 use App\Models\Take;
@@ -59,6 +60,7 @@ final class RouteBindingTypes
         'invitation' => OrganizationInvitation::class,
         'item' => Item::class,
         'manual' => VideoManual::class,
+        'oidcConnection' => OrganizationOidcConnection::class,
         'project' => Project::class,
         'renderJob' => RenderJob::class,
         'take' => Take::class,
@@ -145,6 +147,9 @@ final class RouteBindingTypes
         // Route::pattern を掛けると vendor の route 定義変更に追随できないため、
         // binder が「認証ユーザー所有 + 数値正規化」を担う (他人の passkey は 404)。
         'passkey' => SelfScopedPasskeyBinder::class,
+        // {connection} は公開の企業ログイン導線の識別名 (数値ではないので pattern を掛けられない)。
+        // binder が書式の正規化と「不在 / 使えない接続」の一様化を担う (実在オラクルを作らない)。
+        'connection' => PublicOidcConnectionBinder::class,
     ];
 
     /**
diff --git a/app/Mail/EmailPromotionMail.php b/app/Mail/EmailPromotionMail.php
new file mode 100644
index 00000000..dc1723b0
--- /dev/null
+++ b/app/Mail/EmailPromotionMail.php
@@ -0,0 +1,49 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Mail;
+
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Mail\Mailable;
+use Illuminate\Mail\Mailables\Content;
+use Illuminate\Mail\Mailables\Envelope;
+use Illuminate\Queue\SerializesModels;
+use SensitiveParameter;
+
+/**
+ * メールアドレスの昇格の確認メール。
+ *
+ * ★既存の送信の作法にそろえて**キューへ載せる** (`ShouldQueue`)。
+ *   投入は昇格の行を作るのと**同一トランザクションの中**で行う (AGENTS.md ドメイン規約 11。
+ *   `afterCommit` に依存しない = 行が巻き戻ればメールも投入されない)。
+ * ★本文に載せるのは**確認画面の URL だけ**である。宛先のメールも利用者の名前も載せない
+ *   (万一 victim のアドレスが入力されても、攻撃者が任意の文面を送れない形にする)。
+ * ★トークンは `#[SensitiveParameter]` で受ける (スタックトレースに出さない)。
+ */
+class EmailPromotionMail extends Mailable implements ShouldQueue
+{
+    use Queueable;
+    use SerializesModels;
+
+    public function __construct(#[SensitiveParameter] private readonly string $token) {}
+
+    public function envelope(): Envelope
+    {
+        return new Envelope(
+            subject: 'メールアドレスの確認',
+        );
+    }
+
+    public function content(): Content
+    {
+        return new Content(
+            text: 'emails.auth.email-promotion',
+            with: [
+                // ★確認画面 (GET) の URL。**状態を変えない画面**であり、確定は明示の POST である。
+                'confirmUrl' => route('settings.email-promotion.confirm.show', ['token' => $this->token]),
+            ],
+        );
+    }
+}
diff --git a/app/Models/EmailPromotion.php b/app/Models/EmailPromotion.php
new file mode 100644
index 00000000..98e734b6
--- /dev/null
+++ b/app/Models/EmailPromotion.php
@@ -0,0 +1,63 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models;
+
+use Carbon\CarbonImmutable;
+use Database\Factories\EmailPromotionFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use ParagonIE\CipherSweet\EncryptedRow;
+use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
+use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
+
+/**
+ * メールアドレスの昇格の確認待ち。
+ *
+ * ★トークンは**原文を保存せず指紋だけ**。★確定するまでは users のメールではないので
+ *   blind index も付けない (引き当てに使う理由が無い)。
+ *
+ * @property int $id
+ * @property int $user_id
+ * @property string $token_fingerprint
+ * @property string|null $email_encrypted
+ * @property CarbonImmutable $expires_at
+ */
+class EmailPromotion extends Model implements CipherSweetEncrypted
+{
+    /** @use HasFactory<EmailPromotionFactory> */
+    use HasFactory;
+
+    use UsesCipherSweet;
+
+    /** @var list<string> */
+    protected $fillable = [];
+
+    /** @var list<string> */
+    protected $hidden = [
+        'email_encrypted',
+        'token_fingerprint',
+    ];
+
+    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
+    {
+        // addBlindIndex を **呼ばない** (メールで引く経路を作らない)。
+        $encryptedRow->addField('email_encrypted');
+    }
+
+    /** @return BelongsTo<User, $this> */
+    public function user(): BelongsTo
+    {
+        return $this->belongsTo(User::class);
+    }
+
+    /** @return array<string, string> */
+    protected function casts(): array
+    {
+        return [
+            'expires_at' => 'immutable_datetime',
+        ];
+    }
+}
diff --git a/app/Models/EnterpriseIdentity.php b/app/Models/EnterpriseIdentity.php
new file mode 100644
index 00000000..02965ddc
--- /dev/null
+++ b/app/Models/EnterpriseIdentity.php
@@ -0,0 +1,78 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models;
+
+use Carbon\CarbonImmutable;
+use Database\Factories\EnterpriseIdentityFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use ParagonIE\CipherSweet\EncryptedRow;
+use Spatie\LaravelCipherSweet\Concerns\UsesCipherSweet;
+use Spatie\LaravelCipherSweet\Contracts\CipherSweetEncrypted;
+
+/**
+ * IdP の身元 (接続 × subject) と利用者の対応。
+ *
+ * @property int $id
+ * @property int $organization_oidc_connection_id
+ * @property int $user_id
+ * @property string $subject
+ * @property string|null $claimed_email_encrypted
+ * @property CarbonImmutable|null $last_login_at
+ */
+class EnterpriseIdentity extends Model implements CipherSweetEncrypted
+{
+    /** @use HasFactory<EnterpriseIdentityFactory> */
+    use HasFactory;
+
+    use UsesCipherSweet;
+
+    /** @var list<string> */
+    protected $fillable = [];
+
+    /** @var list<string> */
+    protected $hidden = [
+        'claimed_email_encrypted',
+    ];
+
+    /**
+     * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
+     *   引き当ての鍵は **(organization_oidc_connection_id, 生の subject)** だけである
+     *   (subject 列は `COLLATE "C"` で byte 一致。**指紋にしない** = 鍵のローテーションに依存しない)。
+     *   申告メールは暗号化して持つが **blind index を付けない** —
+     *   索引があると「メールで引ける」経路が復活する。
+     *   これは tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest が
+     *   記法の走査と **「申告メールを含む索引が 0 本」のスキーマ検査** の二層で固定する。
+     */
+    public static function configureCipherSweet(EncryptedRow $encryptedRow): void
+    {
+        // ★列は nullable なので `addOptionalTextField` を使う
+        //   (`addField` は null で fieldNotOptional 例外になる = Inquiry / Organization の先例)。
+        //   メールを出さない IdP があるため null は正常な値である。
+        // ★addBlindIndex を **呼ばない**。これが不変条件の実体である。
+        $encryptedRow->addOptionalTextField('claimed_email_encrypted');
+    }
+
+    /** @return BelongsTo<OrganizationOidcConnection, $this> */
+    public function connection(): BelongsTo
+    {
+        return $this->belongsTo(OrganizationOidcConnection::class, 'organization_oidc_connection_id');
+    }
+
+    /** @return BelongsTo<User, $this> */
+    public function user(): BelongsTo
+    {
+        return $this->belongsTo(User::class);
+    }
+
+    /** @return array<string, string> */
+    protected function casts(): array
+    {
+        return [
+            'last_login_at' => 'immutable_datetime',
+        ];
+    }
+}
diff --git a/app/Models/EnterpriseSsoLoginAttempt.php b/app/Models/EnterpriseSsoLoginAttempt.php
new file mode 100644
index 00000000..22c72d2e
--- /dev/null
+++ b/app/Models/EnterpriseSsoLoginAttempt.php
@@ -0,0 +1,57 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models;
+
+use Carbon\CarbonImmutable;
+use Database\Factories\EnterpriseSsoLoginAttemptFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+
+/**
+ * 企業 SSO のログイン試行 (state の使用権の唯一性とブラウザ結合を持つ行)。
+ *
+ * ★state / nonce / ブラウザ結合の秘密は **指紋だけ**を保存する (原文は保存しない)。
+ *   PKCE の検証子だけは token 交換でそのまま送るので暗号化して原文を保存する。
+ *
+ * @property int $id
+ * @property int $organization_oidc_connection_id
+ * @property string $state_fingerprint
+ * @property string $nonce_fingerprint
+ * @property string $browser_binding_fingerprint
+ * @property string $pkce_verifier_encrypted
+ * @property CarbonImmutable $expires_at
+ */
+class EnterpriseSsoLoginAttempt extends Model
+{
+    /** @use HasFactory<EnterpriseSsoLoginAttemptFactory> */
+    use HasFactory;
+
+    /** @var list<string> */
+    protected $fillable = [];
+
+    /** @var list<string> */
+    protected $hidden = [
+        'pkce_verifier_encrypted',
+        'state_fingerprint',
+        'nonce_fingerprint',
+        'browser_binding_fingerprint',
+    ];
+
+    /** @return BelongsTo<OrganizationOidcConnection, $this> */
+    public function connection(): BelongsTo
+    {
+        return $this->belongsTo(OrganizationOidcConnection::class, 'organization_oidc_connection_id');
+    }
+
+    /** @return array<string, string> */
+    protected function casts(): array
+    {
+        return [
+            'expires_at' => 'immutable_datetime',
+            'pkce_verifier_encrypted' => 'encrypted',
+        ];
+    }
+}
diff --git a/app/Models/Organization.php b/app/Models/Organization.php
index e55eed3c..eaf235ee 100644
--- a/app/Models/Organization.php
+++ b/app/Models/Organization.php
@@ -143,6 +143,19 @@ public function invitations(): HasMany
         return $this->hasMany(OrganizationInvitation::class);
     }
 
+    /**
+     * 企業 IdP との OIDC 接続 (D2 の scoped binding と D1 のロック付き再取得が引く relation)。
+     *
+     * ★接続の解決は**必ずこの relation 起点**で行う。クラス起点の主キー同一性クエリで
+     *   書くと、再取得の経路そのものが組織スコープを失う (AGENTS.md セキュリティ不変条件 3)。
+     *
+     * @return HasMany<OrganizationOidcConnection, $this>
+     */
+    public function oidcConnections(): HasMany
+    {
+        return $this->hasMany(OrganizationOidcConnection::class);
+    }
+
     /**
      * 識別名の改名履歴 (30 日 5 回の回数判定に使う。家系裁定 AG-046)。
      *
diff --git a/app/Models/OrganizationOidcConnection.php b/app/Models/OrganizationOidcConnection.php
new file mode 100644
index 00000000..845a36f8
--- /dev/null
+++ b/app/Models/OrganizationOidcConnection.php
@@ -0,0 +1,118 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Models;
+
+use App\Casts\EncryptedSecretCast;
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Services\EnterpriseSso\OidcConnectionTransitionService;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use Carbon\CarbonImmutable;
+use Database\Factories\OrganizationOidcConnectionFactory;
+use Illuminate\Database\Eloquent\Factories\HasFactory;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Database\Eloquent\Relations\BelongsTo;
+use Illuminate\Database\Eloquent\Relations\HasMany;
+
+/**
+ * 組織の OIDC 接続 (企業 IdP との結び付け)。
+ *
+ * ★`$fillable` は**空**である。生成・更新は
+ *   {@see OidcConnectionTransitionService} が明示的に組み立てる
+ *   (mass assignment を作らない)。
+ * ★`client_secret_encrypted` は {@see EncryptedSecretCast} を通して
+ *   {@see ConnectionSecret} でしか出し入れできない (素の文字列を代入する道が型で無い)。
+ * ★`credentials_revision` は **cast も `$hidden` も要らない** (秘密ではない)。
+ *   ただし画面へ出す DTO には**載せない** — これは D1 の内部の比較子であって、
+ *   画面が使う値ではない。
+ *
+ * @property int $id
+ * @property int $organization_id
+ * @property string $login_slug
+ * @property string $display_name
+ * @property string $issuer
+ * @property string $client_id
+ * @property ConnectionSecret|null $client_secret_encrypted
+ * @property OidcConnectionStatus $status
+ * @property CarbonImmutable|null $verified_at
+ * @property int $credentials_revision
+ */
+class OrganizationOidcConnection extends Model
+{
+    /** @use HasFactory<OrganizationOidcConnectionFactory> */
+    use HasFactory;
+
+    /** @var list<string> */
+    protected $fillable = [];
+
+    /** @var list<string> */
+    protected $hidden = [
+        'client_secret_encrypted',
+    ];
+
+    /** @return BelongsTo<Organization, $this> */
+    public function organization(): BelongsTo
+    {
+        return $this->belongsTo(Organization::class);
+    }
+
+    /** @return HasMany<EnterpriseIdentity, $this> */
+    public function identities(): HasMany
+    {
+        return $this->hasMany(EnterpriseIdentity::class, 'organization_oidc_connection_id');
+    }
+
+    /** @return HasMany<EnterpriseSsoLoginAttempt, $this> */
+    public function loginAttempts(): HasMany
+    {
+        return $this->hasMany(EnterpriseSsoLoginAttempt::class, 'organization_oidc_connection_id');
+    }
+
+    /**
+     * 保存された client secret。
+     *
+     * ★**復号する唯一の読み出し口**である。一覧の生成 (D2 の DTO) はここを通らない。
+     */
+    public function clientSecret(): ConnectionSecret
+    {
+        $secret = $this->client_secret_encrypted;
+
+        if (! $secret instanceof ConnectionSecret) {
+            throw new \RuntimeException('接続に client secret が保存されていません。');
+        }
+
+        return $secret;
+    }
+
+    /** 秘密が保存されているか (★**復号しない**。暗号文の有無だけを見る)。 */
+    public function hasClientSecret(): bool
+    {
+        $raw = $this->getRawOriginal('client_secret_encrypted');
+
+        return is_string($raw) && $raw !== '';
+    }
+
+    /**
+     * 保存されている暗号文そのものの digest (★復号しない)。
+     *
+     * D1 の `verify` が「外向き取得の間に秘密が書き換わっていないか」を
+     * **平文に触れずに**見るための比較子である。
+     */
+    public function clientSecretCiphertextDigest(): string
+    {
+        $raw = $this->getRawOriginal('client_secret_encrypted');
+
+        return hash('sha256', is_string($raw) ? $raw : '');
+    }
+
+    /** @return array<string, string> */
+    protected function casts(): array
+    {
+        return [
+            'status' => OidcConnectionStatus::class,
+            'verified_at' => 'immutable_datetime',
+            'client_secret_encrypted' => EncryptedSecretCast::class,
+        ];
+    }
+}
diff --git a/app/Models/User.php b/app/Models/User.php
index 69afba82..6e307cd5 100644
--- a/app/Models/User.php
+++ b/app/Models/User.php
@@ -14,6 +14,7 @@
 use Illuminate\Database\Eloquent\Relations\HasMany;
 use Illuminate\Foundation\Auth\User as Authenticatable;
 use Illuminate\Notifications\Notifiable;
+use Illuminate\Support\Facades\DB;
 use Laratrust\Contracts\LaratrustUser;
 use Laratrust\Traits\HasRolesAndPermissions;
 use Laravel\Fortify\TwoFactorAuthenticatable;
@@ -38,6 +39,9 @@
  */
 class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable, PasskeyUser
 {
+    /** メールの blind index の名前 (`add_unique_to_blind_indexes_table` の partial unique と対)。 */
+    public const string EMAIL_BLIND_INDEX = 'email_index';
+
     // Passport OAuth guard (mcp-oauth / api-oauth) が withAccessToken() / token() を要求する
     use HasApiTokens;
 
@@ -73,8 +77,12 @@ class User extends Authenticatable implements CipherSweetEncrypted, LaratrustUse
 
     public static function configureCipherSweet(EncryptedRow $encryptedRow): void
     {
+        // ★email は **nullable** である (T253 / A3)。企業 SSO でしか入れない利用者は
+        //   使えるメールを 1 件も持たないため、`addField` (非 optional) では null の保存で
+        //   fieldNotOptional 例外になる。blind index は非 null の行にだけ作られるので、
+        //   partial unique による一意性の担保はそのまま効く。
         $encryptedRow
-            ->addField('email')
+            ->addOptionalTextField('email')
             ->addBlindIndex('email', new BlindIndex('email_index'));
 
         // name も blind index 化し、管理画面 (Filament) の暗号化氏名検索を成立させる。
@@ -95,6 +103,57 @@ public function socialAccounts(): HasMany
         return $this->hasMany(SocialAccount::class);
     }
 
+    /**
+     * blind index の書き込み。
+     *
+     * ★**メールを持たない利用者は `email_index` の行を持たない** (T253 / A3)。
+     *   同梱 trait の既定は「null もハッシュして 1 行書く」形なので、そのままだと
+     *   企業 SSO でしか入れない利用者は**全員が同じ blind index 値**を持ち、
+     *   `blind_indexes_type_name_value_unique` (partial unique) に衝突して
+     *   **2 人目から作成できなくなる**。
+     *   移行 `add_unique_to_blind_indexes_table` の docblock が言う「null 行は blind index を
+     *   持たない」を、実装として成立させるための override である。
+     * ★null へ**戻した**ときは既存の行を消す (残すと消えたはずの旧メールで引けてしまう)。
+     * ★`name_index` はこの分岐の対象外である (name は NOT NULL で一意でもない)。
+     */
+    public function updateBlindIndexes(): void
+    {
+        $hasEmail = $this->getAttribute('email') !== null;
+
+        foreach (static::getCipherSweetEncryptedRow()->getAllBlindIndexes($this->getAttributes()) as $name => $blindIndex) {
+            if (! $hasEmail && $name === self::EMAIL_BLIND_INDEX) {
+                DB::table('blind_indexes')
+                    ->where('indexable_type', $this->getMorphClass())
+                    ->where('indexable_id', $this->getKey())
+                    ->where('name', $name)
+                    ->delete();
+
+                continue;
+            }
+
+            DB::table('blind_indexes')->upsert([
+                'value' => $blindIndex,
+                'indexable_type' => $this->getMorphClass(),
+                'indexable_id' => $this->getKey(),
+                'name' => $name,
+            ], ['indexable_type', 'indexable_id', 'name']);
+        }
+    }
+
+    /**
+     * メールアドレスの昇格の確認待ち (T253 / E1)。
+     *
+     * ★利用者ごとに**未消費は 1 件だけ**である (`email_promotions_user_unique`)。
+     *   relation で持つのは、昇格の経路が**常に自分自身を起点に**引くためである
+     *   (メールから利用者を引く経路を作らない)。
+     *
+     * @return HasMany<EmailPromotion, $this>
+     */
+    public function emailPromotions(): HasMany
+    {
+        return $this->hasMany(EmailPromotion::class);
+    }
+
     /*
      * 登録済みパスキー (WebAuthn credential) の relation `passkeys()` は
      * PasskeyAuthenticatable trait が供給する (実体クラスは
diff --git a/app/Policies/OrganizationOidcConnectionPolicy.php b/app/Policies/OrganizationOidcConnectionPolicy.php
new file mode 100644
index 00000000..a4fa74d2
--- /dev/null
+++ b/app/Policies/OrganizationOidcConnectionPolicy.php
@@ -0,0 +1,55 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Policies;
+
+use App\Models\Organization;
+use App\Models\OrganizationOidcConnection;
+use App\Models\User;
+
+/**
+ * 組織 OIDC 接続の認可。
+ *
+ * ★境界は `OrganizationPolicy::update` と**同じ** (owner / admin) である。
+ *   接続の管理は**組織のログイン経路そのものを変える操作**なので、
+ *   **閲覧も含めて** owner / admin に限る (一覧だけ緩めない — issuer と client_id が見える)。
+ *
+ * ★テナント境界 (層 2 = 404) は route の scoped binding が**認可より前**に閉じている。
+ *   本 policy は層 3 (403) だけを担う。
+ */
+class OrganizationOidcConnectionPolicy
+{
+    /** 一覧の閲覧。 */
+    public function viewAny(User $user, Organization $organization): bool
+    {
+        return $this->canManage($user, $organization);
+    }
+
+    /** 新しい接続の登録。 */
+    public function create(User $user, Organization $organization): bool
+    {
+        return $this->canManage($user, $organization);
+    }
+
+    /** 更新・確認・有効化・無効化 (状態と認証材料を変える操作)。 */
+    public function update(User $user, OrganizationOidcConnection $connection): bool
+    {
+        $organization = $connection->organization;
+
+        return $organization !== null && $this->canManage($user, $organization);
+    }
+
+    /** 物理削除 (身元が 0 件のときだけ D1 が実際に許す)。 */
+    public function delete(User $user, OrganizationOidcConnection $connection): bool
+    {
+        $organization = $connection->organization;
+
+        return $organization !== null && $this->canManage($user, $organization);
+    }
+
+    private function canManage(User $user, Organization $organization): bool
+    {
+        return $user->organizationRole($organization)?->canManage() ?? false;
+    }
+}
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 63fd0f7b..36ad1d34 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -7,6 +7,7 @@
 use App\Auth\EncryptedUserProvider;
 use App\Auth\Guards\ApiKeyGuard;
 use App\Http\Routing\MembershipScopedOrganizationBinder;
+use App\Http\Routing\PublicOidcConnectionBinder;
 use App\Http\Routing\RouteBindingTypes;
 use App\Listeners\Audit\RejectNonCriticalAudit;
 use App\Listeners\Auth\ClearRecentAuthOnPasskeyChange;
@@ -223,6 +224,9 @@ public function boot(): void
         // tests/Architecture/OrganizationRouteParamWebOnlyInvariantTest が適用境界を pin)
         Route::bind('organization', MembershipScopedOrganizationBinder::class);
 
+        // 公開の企業ログイン導線の {connection} (識別名で解決し、使えない接続は不在と同じ 404 にする)。
+        Route::bind('connection', PublicOidcConnectionBinder::class);
+
         // 認証済みで guest 専用 route (ログイン / パスワード再設定要求 等) を開いたときの着地。
         // ★framework の既定は「`dashboard` という名前の route があればそこへ」だが、
         //   本アプリの `dashboard` は組織 URL 配下 (`{organization}` 必須) なので、
@@ -302,6 +306,7 @@ public function boot(): void
         $this->configureActorScopedRateLimiters();
         $this->configureApiRateLimiters();
         $this->configureAuthSurfaceRateLimiters();
+        $this->configureEnterpriseSsoRateLimiters();
         $this->configureInquiryRateLimiter();
         $this->configureRenderRateLimiter();
         $this->configureWebhookRateLimiters();
@@ -386,6 +391,59 @@ private function configureAuthSurfaceRateLimiters(): void
             ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
     }
 
+    /**
+     * 企業 OIDC SSO (T253) の RateLimiter。
+     *
+     * ★**閾値は発明しない** (AG-096)。既に本番稼働している同性質の endpoint と同値を充てる:
+     *   - enterprise-sso-start / enterprise-sso-callback = 10/min。未認証で到達する認証面の
+     *     IP レーンとして `social-callback` (10/min) と同値である
+     *   - enterprise-sso-manage / enterprise-sso-verify = 10/min。認証済み actor の業務操作として
+     *     `invitation-accept-submit` / `plan-activate` (10/min) と同値である
+     *   - email-promotion = 6/min。認証手段を増やす操作として `password-set` (6/min) と同値である
+     *   - email-promotion-confirm = 10/min。**救済の性格**なので発行より緩い側 (10/min) を充てる
+     *     (確定できずに詰む形を作らない)
+     *
+     * ★レーンは 6 本に分ける (相乗りさせない)。とくに:
+     *   - **開始と戻り口を分ける**。開始の連打で正当な戻り口が 429 になると、
+     *     IdP まで行った利用者が戻れず詰む
+     *   - **確認 (verify) を管理操作から分ける**。verify は**外向きの取得を伴う唯一の管理操作**であり、
+     *     数えたい量が違う (IdP が遅いときの連打で一覧・有効化まで止めない)
+     *   - **昇格の発行と確認を分ける**。確認はメールのリンクから来る救済経路である
+     *
+     * ★キーに route parameter を混ぜない (`{connectionSlug}` / `{oidcConnection}`)。
+     *   混ぜると bucket が id ごとに分かれ、「429 になるまでの回数」が実在オラクルになる。
+     *
+     * ★**無効リクエストも同じ bucket を消費する** (throttle は controller より前に走る)。
+     *   これは「未認証面を IP で数える」ことの必然であり、引き換えに得ているのは
+     *   **外向き HTTP と token 照合の総量が有界になること**である。
+     */
+    private function configureEnterpriseSsoRateLimiters(): void
+    {
+        // 企業ログインの開始 (GET だが試行の行を作る)。未認証面なので IP レーン。
+        RateLimiter::for('enterprise-sso-start', fn (Request $request): Limit => Limit::perMinute(10)
+            ->by('enterprise-sso-start:ip:'.($request->ip() ?? 'unknown')));
+
+        // 企業ログインの戻り口。1 要求で IdP へ discovery + token + JWKS が飛びうる。
+        RateLimiter::for('enterprise-sso-callback', fn (Request $request): Limit => Limit::perMinute(10)
+            ->by('enterprise-sso-callback:ip:'.($request->ip() ?? 'unknown')));
+
+        // 組織側の接続管理 (登録 / 更新 / 有効化 / 無効化 / 削除)。認証済み actor レーン。
+        RateLimiter::for('enterprise-sso-manage', fn (Request $request): Limit => Limit::perMinute(10)
+            ->by(RateLimiterKeys::actorOrIp($request, 'enterprise-sso-manage')));
+
+        // 接続先情報の確認。**外向きの取得を伴う唯一の管理操作**なので専用レーンにする。
+        RateLimiter::for('enterprise-sso-verify', fn (Request $request): Limit => Limit::perMinute(10)
+            ->by(RateLimiterKeys::actorOrIp($request, 'enterprise-sso-verify')));
+
+        // メール昇格の発行・再送 (認証手段を増やす操作)。
+        RateLimiter::for('email-promotion', fn (Request $request): Limit => Limit::perMinute(6)
+            ->by(RateLimiterKeys::actorOrIp($request, 'email-promotion')));
+
+        // メール昇格の確認画面と確定 (救済の性格。発行より緩い側に置く)。
+        RateLimiter::for('email-promotion-confirm', fn (Request $request): Limit => Limit::perMinute(10)
+            ->by(RateLimiterKeys::actorOrIp($request, 'email-promotion-confirm')));
+    }
+
     /**
      * 未認証 webhook (SES/SNS 通知・Stripe) の RateLimiter。
      *
diff --git a/app/Rules/OidcIssuerUrlRule.php b/app/Rules/OidcIssuerUrlRule.php
new file mode 100644
index 00000000..512bdb5c
--- /dev/null
+++ b/app/Rules/OidcIssuerUrlRule.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Rules;
+
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Closure;
+use Illuminate\Contracts\Validation\ValidationRule;
+use Illuminate\Translation\PotentiallyTranslatedString;
+
+/**
+ * issuer の入力規則 (https のみ / userinfo なし / query なし / fragment なし / 絶対 URL)。
+ *
+ * ★規則の**正本は {@see OidcIssuerUrl}** である。ここはその述語を validation へ橋渡しするだけで、
+ *   条件を写さない (2 か所に書くと必ず食い違う)。
+ */
+class OidcIssuerUrlRule implements ValidationRule
+{
+    /**
+     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
+     */
+    public function validate(string $attribute, mixed $value, Closure $fail): void
+    {
+        if (! is_string($value) || ! OidcIssuerUrl::isValid($value)) {
+            $fail('発行者 URL は https で始まり、ユーザー情報・クエリ・フラグメントを含まない絶対 URL である必要があります。');
+        }
+    }
+}
diff --git a/app/Services/Auth/EmailPromotionService.php b/app/Services/Auth/EmailPromotionService.php
new file mode 100644
index 00000000..e0b7342d
--- /dev/null
+++ b/app/Services/Auth/EmailPromotionService.php
@@ -0,0 +1,164 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Auth;
+
+use App\DataTransferObjects\Auth\VerifiedEmail;
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Exceptions\Auth\EmailPromotionConflictException;
+use App\Mail\EmailPromotionMail;
+use App\Models\EmailPromotion;
+use App\Models\User;
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
+ * ## トークンの一生
+ *
+ * | 項目 | 形 |
+ * |---|---|
+ * | トークン | **原文を保存せず指紋のみ** (用途ラベル `EmailPromotionToken`) |
+ * | 結合 | `user_id` を持ち、確認時に**認証済みの利用者と一致**すること |
+ * | 期限 | `expires_at` (`config('enterprise-sso.email_promotion.ttl_seconds')`) |
+ * | 一回使用 | `SELECT … FOR UPDATE` → 検査 → `DELETE` → commit (B4 と同じ原子的な形) |
+ * | 再送 | 新しいトークンを発行したら**旧トークンを失効させる** (発行時の削除 + `user_id` の一意制約) |
+ */
+final readonly class EmailPromotionService
+{
+    /** メールの blind index の partial unique index 名 (`add_unique_to_blind_indexes_table` が正本)。 */
+    private const string EMAIL_BLIND_INDEX_CONSTRAINT = 'blind_indexes_type_name_value_unique';
+
+    /** PostgreSQL の unique_violation。 */
+    private const string SQLSTATE_UNIQUE_VIOLATION = '23505';
+
+    /**
+     * 昇格を始める (確認メールを送る)。
+     *
+     * ★**再送も同じ入口**である。発行のたびに自分の古い行を消すので、旧トークンは失効する。
+     */
+    public function issue(User $user, string $email): void
+    {
+        $normalized = EmailNormalizer::normalize($email);
+        $token = AttemptFingerprint::newSecret();
+
+        DB::transaction(function () use ($user, $normalized, $token): void {
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
+     * @return bool true = 確定した / false = トークンが無効・期限切れ・他人のもの
+     *
+     * @throws EmailPromotionConflictException 確認済みメールが既存利用者のものと重なった
+     */
+    public function confirm(User $user, #[SensitiveParameter] string $token): bool
+    {
+        $fingerprint = AttemptFingerprint::of(FingerprintPurpose::EmailPromotionToken, $token);
+
+        return DB::transaction(function () use ($user, $fingerprint): bool {
+            // ★relation 起点で引く (自分の行だけを見る = 他人のトークンでは何も起きない)。
+            $promotion = $user->emailPromotions()
+                ->where('token_fingerprint', $fingerprint)
+                ->lockForUpdate()
+                ->first();
+
+            if ($promotion === null || $promotion->expires_at->isPast()) {
+                return false;
+            }
+
+            $email = $promotion->email_encrypted;
+            if (! is_string($email) || $email === '') {
+                return false;
+            }
+
+            $promotion->delete();
+
+            $this->applyVerifiedEmail($user, VerifiedEmail::afterConfirmation($email));
+
+            return true;
+        });
+    }
+
+    /**
+     * ★`users.email` を書く**唯一の経路**である (昇格の側)。
+     *
+     * @throws EmailPromotionConflictException
+     */
+    private function applyVerifiedEmail(User $user, VerifiedEmail $email): void
+    {
+        try {
+            $user->forceFill([
+                'email' => $email->value,
+                // ★**新しいメールを実際に確認した時刻**へ更新する (以前の値のままにしない)。
+                'email_verified_at' => now(),
+            ])->save();
+        } catch (QueryException $e) {
+            // ★変換してよいのは**メールの blind index の一意制約違反だけ**である。
+            //   それ以外の一意制約違反と DB の障害は握り潰さず伝播させる。
+            if ($this->isEmailBlindIndexConflict($e)) {
+                throw new EmailPromotionConflictException('email is already taken by another user');
+            }
+
+            throw $e;
+        }
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
diff --git a/app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php b/app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php
new file mode 100644
index 00000000..91e364ea
--- /dev/null
+++ b/app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Models\User;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Illuminate\Contracts\Session\Session;
+use Illuminate\Support\Facades\DB;
+use SensitiveParameter;
+
+/**
+ * 企業 SSO の戻り口の application service。
+ *
+ * ## 順序 (理由つき)
+ *
+ *  1. **入力の検査**は FormRequest が済ませている (スカラー型・長さ上限・code と error の排他)。
+ *     ★不正な入力では**外向き取得を一切開始しない**
+ *  2. IdP の error 応答は一様な失敗として扱う (呼び出し側が判定済み)
+ *  3. セッションから**結合の秘密**を取り出す (state の指紋から試行ごとのキーを導く)。
+ *     **非空の文字列でなければ、外向き取得を始めずに一様拒否する**
+ *  4. **consume** (試行の行のロック。トランザクションを**閉じる**) —
+ *     ロックの保持中に外向き HTTP を行うと、ロックが外部の応答時間に引きずられる。
+ *     ★`consume()` は**投げずに分類を返す**ので、**本サービスが**
+ *     「行が消えた失敗ならセッションの秘密も消す / 結合の不一致なら残す」を決め、
+ *     そのうえで**外向きの一様な例外**へ変換する
+ *  5. 外向き取得 (discovery → token 交換 → JWKS) と ID トークンの検証。
+ *     ★この間はどのロックも持たない
+ *  6. **線形化の区間**: 1 つのトランザクションで
+ *       接続の行を `lockForUpdate()` → **Active を確認** → **JIT** → commit
+ *
+ * ## 無効化 (disable) との線形化
+ *
+ * 「Active を 2 回読む」だけでは競合を閉じられない (最終確認の直後に disable が commit され、
+ * その後ログインが確定する窓が残る)。また JIT を確認より前に置くと、
+ * **拒否されたのに利用者・身元・所属だけが残る**。
+ *
+ * ★**線形化点を接続の行ロックに定める**。上の 6 が線形化の区間であり、
+ *   {@see OidcConnectionTransitionService} の無効化も**同じ行を `lockForUpdate()` する**。
+ *   したがって両者は直列化され、次の 2 つが成り立つ:
+ *     - **無効化が先に線形化したら、JIT もログインも起きない** (Active の確認で落ち、
+ *       同一トランザクションなので副作用が巻き戻る)
+ *     - **callback が先なら、無効化はその後に成立する** (次回から入れない)
+ *   commit の後・`Auth::login` の前に disable が入る窓は残るが、それは
+ *   「無効化より前に線形化したログイン」であり、**既存セッションの即時失効はスコープ外**という
+ *   本設計の主張と整合する。
+ *
+ * ## 身元の名前空間を壊さない
+ *
+ * OIDC の身元は実質 **(issuer, subject)** であり、pairwise subject では
+ * **client_id も名前空間を変えうる**。同じ接続の issuer や client_id を別の IdP のものへ
+ * 変えた後に偶然同じ subject が返ると、**以前の利用者へ誤ってログインさせる**。
+ * これを防ぐのは D1 の更新規則である —
+ * **身元が 1 件でもある接続では issuer と client_id を変更できない**。
+ * 本サービスは「接続 id で身元を引く」形のままでよい (名前空間の不変性を D1 が保証する)。
+ */
+final readonly class EnterpriseCallbackAuthenticator
+{
+    /** セッションに置くブラウザ結合の秘密のキーの接頭辞 (**state の指紋ごとに分ける**)。 */
+    private const string BINDING_SESSION_PREFIX = 'enterprise-sso.binding.';
+
+    public function __construct(
+        private EnterpriseLoginAttemptStore $attempts,
+        private OidcDiscoveryService $discovery,
+        private OidcTokenExchanger $exchanger,
+        private EnterpriseIdTokenVerifier $verifier,
+        private EnterpriseUserProvisioner $provisioner,
+    ) {}
+
+    /** 開始側がセッションへ結合の秘密を置くときのキー (state の指紋ごとに分ける)。 */
+    public static function bindingSessionKey(string $stateFingerprint): string
+    {
+        return self::BINDING_SESSION_PREFIX.$stateFingerprint;
+    }
+
+    /**
+     * 戻り口の本体。失敗はすべて**一様な例外**になる。
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public function authenticate(
+        Session $session,
+        #[SensitiveParameter] string $state,
+        #[SensitiveParameter] string $code,
+        string $redirectUri,
+    ): User {
+        $bindingKey = self::bindingSessionKey(AttemptFingerprint::of(FingerprintPurpose::State, $state));
+
+        /** @var mixed $bindingSecret */
+        $bindingSecret = $session->get($bindingKey);
+
+        // ★結合の秘密がセッションに無い / 非文字列なら、**外向き取得を始めずに**拒否する。
+        if (! is_string($bindingSecret) || $bindingSecret === '') {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::AttemptBindingMissing);
+        }
+
+        $result = $this->attempts->consume($state, $bindingSecret);
+
+        // ★行が不可逆に消えた失敗ではセッションの秘密も消す (再開できない試行の秘密を残さない)。
+        //   結合の不一致では**行もセッションの秘密も残す** (攻撃者が被害者の結合を消せる形にしない)。
+        if ($result->rowIsGone) {
+            $session->forget($bindingKey);
+        }
+
+        if (! $result->succeeded || $result->attempt === null) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::AttemptNotFound);
+        }
+
+        $attempt = $result->attempt;
+        $connection = $attempt->connection;
+
+        // ★明らかに使えない接続で外部へ出ないための足切り。
+        //   これは競合を閉じる線形化点ではない (線形化点は下の行ロックである)。
+        if ($connection->status !== OidcConnectionStatus::Active) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::ConnectionNotUsable);
+        }
+
+        // ★ここから外向き取得。**どのロックも持っていない**。
+        $metadata = $this->discovery->fetchMetadata(OidcIssuerUrl::fromString($connection->issuer));
+
+        $tokens = $this->exchanger->exchange($connection, $metadata, $redirectUri, $code, $attempt->codeVerifier);
+
+        $jwks = $this->discovery->fetchJwks($metadata);
+
+        $claims = $this->verifier->verify(
+            $connection,
+            $metadata,
+            $jwks,
+            $tokens->idToken,
+            $attempt->nonceFingerprint,
+        );
+
+        // ★**線形化の区間**。接続の行をロックして Active を確認してから JIT する。
+        return DB::transaction(function () use ($connection, $claims): User {
+            $locked = $connection->organization?->oidcConnections()
+                ->whereKey($connection->id)
+                ->lockForUpdate()
+                ->first();
+
+            if ($locked === null || $locked->status !== OidcConnectionStatus::Active) {
+                // ★同一トランザクションなので、ここで落ちれば副作用は 1 バイトも残らない。
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::ConnectionNotUsable);
+            }
+
+            return $this->provisioner->resolve($locked, $claims);
+        });
+    }
+}
diff --git a/app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php b/app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php
new file mode 100644
index 00000000..e1eb0a93
--- /dev/null
+++ b/app/Services/EnterpriseSso/EnterpriseIdTokenVerifier.php
@@ -0,0 +1,291 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\OidcJsonWebKeySet;
+use App\DataTransferObjects\EnterpriseSso\OidcProviderMetadata;
+use App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims;
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Enums\EnterpriseSso\OidcSigningAlgorithm;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Models\OrganizationOidcConnection;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Firebase\JWT\JWK;
+use Firebase\JWT\JWT;
+use Firebase\JWT\Key;
+use Illuminate\Support\Facades\Config;
+use SensitiveParameter;
+use stdClass;
+use Throwable;
+
+/**
+ * ID トークンの検証 (deny-by-default。1 つでも該当したらその試行を拒否する)。
+ *
+ * ## 拒否条件
+ *
+ * | 層 | 拒否する条件 |
+ * |---|---|
+ * | JWT の形 | malformed (3 セグメントでない / base64url でない / ヘッダが JSON でない) |
+ * | ヘッダ | `alg` が {@see OidcSigningAlgorithm} の case でない (`none` / HMAC は enum に無い) /
+ * |        | **`alg` が IdP の広告集合に無い** / `kid` の欠落 |
+ * | JWKS | `kid` に一致する鍵が無い (→ **再取得を 1 回だけ**) / `kid` の重複 /
+ * |      | `kty` が `alg` と不整合 / EC の `crv` が不整合 /
+ * |      | **`use` が存在するのに** `sig` でない / **`key_ops` が存在するのに** `verify` を含まない |
+ * | 署名 | 検証に失敗した |
+ * | claim の型 | `iss` / `sub` / `nonce` が文字列でない / `aud` が文字列でも文字列配列でもない /
+ * |            | `exp` / `iat` / `nbf` が整数でない |
+ * | claim の値 | `iss` 不一致 / `sub` が空・長さ超過 / **`exp` の欠落** / **`iat` の欠落** /
+ * |            | `exp` 超過 / `iat` が未来 / `nbf` が未来 / `nonce` の指紋が試行と不一致 |
+ * | audience | (1) `aud` は必ず client_id を含む / (2) `aud` が複数なら `azp` は必須 /
+ * |          | (3) `azp` が存在するなら文字列で client_id と一致 |
+ *
+ * ★理由コードは条件ごとに分けるが、**利用者への応答は一様**である
+ *   (区別が出るのは内部のログだけ)。
+ */
+final readonly class EnterpriseIdTokenVerifier
+{
+    public function __construct(private OidcDiscoveryService $discovery) {}
+
+    /**
+     * @param  string  $expectedNonceFingerprint  試行が保持している nonce の指紋 (原文ではない)
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public function verify(
+        OrganizationOidcConnection $connection,
+        OidcProviderMetadata $metadata,
+        OidcJsonWebKeySet $jwks,
+        #[SensitiveParameter] string $idToken,
+        string $expectedNonceFingerprint,
+    ): VerifiedIdTokenClaims {
+        $header = $this->decodeHeader($idToken);
+
+        $algorithm = OidcSigningAlgorithm::tryFrom($header['alg']);
+        if ($algorithm === null) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAlgorithmNotAllowed);
+        }
+
+        // ★アプリの許可集合と **IdP の広告集合の両方**に入ることを要求する。
+        if (! $metadata->advertises($algorithm)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAlgorithmNotAllowed);
+        }
+
+        // ★未知の kid なら**1 回だけ**取り直す (再帰しない)。
+        if (! $jwks->has($header['kid'])) {
+            $jwks = $this->discovery->refetchJwks($metadata, $connection->id);
+        }
+
+        $jwk = $jwks->keyFor($header['kid'], $algorithm);
+
+        $payload = $this->decodePayload($idToken, $jwk, $algorithm);
+
+        return $this->claimsFrom($connection, $metadata, $payload, $expectedNonceFingerprint);
+    }
+
+    /**
+     * @return array{alg: string, kid: string}
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function decodeHeader(#[SensitiveParameter] string $idToken): array
+    {
+        $segments = explode('.', $idToken);
+        if (count($segments) !== 3) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenMalformed);
+        }
+
+        $raw = base64_decode(strtr($segments[0], '-_', '+/'), true);
+        if ($raw === false) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenMalformed);
+        }
+
+        /** @var mixed $decoded */
+        $decoded = json_decode($raw, associative: true);
+        if (! is_array($decoded)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenMalformed);
+        }
+
+        $algorithm = $decoded['alg'] ?? null;
+        if (! is_string($algorithm) || $algorithm === '') {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAlgorithmNotAllowed);
+        }
+
+        $keyId = $decoded['kid'] ?? null;
+        if (! is_string($keyId) || $keyId === '') {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenKeyIdMissing);
+        }
+
+        return ['alg' => $algorithm, 'kid' => $keyId];
+    }
+
+    /**
+     * @param  array<string, string>  $jwk
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function decodePayload(
+        #[SensitiveParameter] string $idToken,
+        array $jwk,
+        OidcSigningAlgorithm $algorithm,
+    ): stdClass {
+        // ★`firebase/php-jwt` は既定で `exp` / `nbf` / `iat` を見るが、
+        //   **欠落は例外にしない**ので、値の検査は本クラスが自分で行う (下の claimsFrom)。
+        //   ここで vendor に任せるのは**署名の検証だけ**である。
+        JWT::$leeway = Config::integer('enterprise-sso.id_token.leeway_seconds');
+
+        try {
+            $material = JWK::parseKey($jwk, $algorithm->value);
+            if (! $material instanceof Key) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksMalformed);
+            }
+
+            $payload = JWT::decode($idToken, $material);
+        } catch (EnterpriseSsoAttemptRejectedException $e) {
+            throw $e;
+        } catch (Throwable) {
+            // ★vendor の例外を**連結しない** (previous を受け取れない構築子なので型で無理)。
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenSignatureInvalid);
+        }
+
+        return $payload;
+    }
+
+    /**
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function claimsFrom(
+        OrganizationOidcConnection $connection,
+        OidcProviderMetadata $metadata,
+        stdClass $payload,
+        string $expectedNonceFingerprint,
+    ): VerifiedIdTokenClaims {
+        /** @var array<string, mixed> $claims */
+        $claims = get_object_vars($payload);
+
+        $issuer = $claims['iss'] ?? null;
+        if (! is_string($issuer)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+        }
+        if (! hash_equals($metadata->issuer->value, $issuer)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenIssuerMismatch);
+        }
+
+        $subject = $claims['sub'] ?? null;
+        if (! is_string($subject)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+        }
+
+        $this->assertAudience($claims, $connection->client_id);
+        $this->assertTiming($claims);
+
+        $nonce = $claims['nonce'] ?? null;
+        if (! is_string($nonce)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+        }
+        if (! hash_equals($expectedNonceFingerprint, AttemptFingerprint::of(FingerprintPurpose::Nonce, $nonce))) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenNonceMismatch);
+        }
+
+        $email = $claims['email'] ?? null;
+        $name = $claims['name'] ?? null;
+
+        return VerifiedIdTokenClaims::of(
+            issuer: $issuer,
+            subject: $subject,
+            claimedEmail: is_string($email) && $email !== '' ? $email : null,
+            name: is_string($name) && $name !== '' ? $name : null,
+            maxSubjectLength: Config::integer('enterprise-sso.id_token.max_subject_length'),
+        );
+    }
+
+    /**
+     * audience の 3 条 (★論理和で書かず 3 条に分ける)。
+     *
+     * @param  array<string, mixed>  $claims
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function assertAudience(array $claims, string $clientId): void
+    {
+        $audience = $claims['aud'] ?? null;
+
+        if (is_string($audience)) {
+            $audiences = [$audience];
+        } elseif (is_array($audience)) {
+            $audiences = [];
+            foreach ($audience as $entry) {
+                if (! is_string($entry)) {
+                    throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+                }
+                $audiences[] = $entry;
+            }
+        } else {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+        }
+
+        // (1) `aud` は必ず client_id を含む
+        if (! in_array($clientId, $audiences, true)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAudienceMismatch);
+        }
+
+        $authorizedParty = $claims['azp'] ?? null;
+
+        // (2) `aud` が複数なら `azp` は必須
+        if (count($audiences) > 1 && $authorizedParty === null) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAudienceMismatch);
+        }
+
+        // (3) `azp` が存在するなら文字列で client_id と一致
+        if ($authorizedParty !== null) {
+            if (! is_string($authorizedParty)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+            }
+            if (! hash_equals($clientId, $authorizedParty)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenAudienceMismatch);
+            }
+        }
+    }
+
+    /**
+     * 時刻の claim (**`exp` と `iat` は欠落そのものを拒否する**)。
+     *
+     * @param  array<string, mixed>  $claims
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function assertTiming(array $claims): void
+    {
+        $leeway = Config::integer('enterprise-sso.id_token.leeway_seconds');
+        $now = time();
+
+        $expiresAt = $claims['exp'] ?? null;
+        if (! is_int($expiresAt)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+        }
+        if ($expiresAt + $leeway < $now) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenExpired);
+        }
+
+        $issuedAt = $claims['iat'] ?? null;
+        if (! is_int($issuedAt)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+        }
+        if ($issuedAt - $leeway > $now) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenIssuedInFuture);
+        }
+
+        // `nbf` は optional。在るときだけ見る。
+        if (array_key_exists('nbf', $claims)) {
+            $notBefore = $claims['nbf'];
+            if (! is_int($notBefore)) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenClaimTypeInvalid);
+            }
+            if ($notBefore - $leeway > $now) {
+                throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::IdTokenNotYetValid);
+            }
+        }
+    }
+}
diff --git a/app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php b/app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php
new file mode 100644
index 00000000..79eb91bc
--- /dev/null
+++ b/app/Services/EnterpriseSso/EnterpriseLoginAttemptStore.php
@@ -0,0 +1,155 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\AttemptConsumeResult;
+use App\DataTransferObjects\EnterpriseSso\ConsumedLoginAttempt;
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptStoreFailure;
+use App\Models\EnterpriseSsoLoginAttempt;
+use App\Models\OrganizationOidcConnection;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Illuminate\Support\Facades\Config;
+use Illuminate\Support\Facades\DB;
+use SensitiveParameter;
+
+/**
+ * ログイン試行の保管。
+ *
+ * ## 不変条件 (これが正本)
+ *
+ *   **同じ試行の使用権を、ちょうど 1 つの要求だけが得る。**
+ *   **かつ、その試行を開始したブラウザだけが使える。**
+ *
+ * ## 契約する DB は pgsql である
+ *
+ * `phpunit.xml` が `DB_CONNECTION=pgsql` を force しており、**テストも本番も pgsql** である。
+ * したがって `SELECT … FOR UPDATE` の排他契約は本番と同じである。
+ * ★「ドライバに依存しない」とは書かない — SQLite の FOR UPDATE は同じ契約を持たない。
+ *
+ * ## なぜセッションに置かないか
+ *
+ * 同一セッションへの並行要求は route 側で `->block()` を書かない限り直列化が保証されず、
+ * 「普通の get() + forget() を書いても契約を満たしたと誤認できる」形になる。
+ *
+ * ## なぜブラウザ結合が要るか (login CSRF)
+ *
+ * state の役割は「推測不能であること」だけではない。**その認可要求を開始した
+ * ユーザーエージェントに結び付いていること**が要る。グローバルな表だけを根拠にすると、
+ * 攻撃者が自分のブラウザで開始し自分の IdP アカウントで認可した callback URL を
+ * 被害者に開かせることで、**被害者のブラウザが攻撃者のアカウントへログインする**。
+ *
+ * ## 保存の形
+ *
+ * | 項目 | 形 |
+ * |---|---|
+ * | state | 指紋だけ (原文を保存しない) |
+ * | nonce | 指紋だけ |
+ * | ブラウザ結合 | セッションへ置いた秘密の指紋 (session ID は保存しない) |
+ * | PKCE の検証子 | 交換でそのまま送るので原文が要る → 暗号化して保存 |
+ *
+ * ## 保証しないもの
+ *
+ * - セッション cookie ごと奪われた場合のブラウザ結合は破れる (結合はセッションの秘密に依存する)
+ * - 期限切れ行の掃除は日次の実行点とオンアクセスの二段であり、**即時削除ではない**
+ */
+final readonly class EnterpriseLoginAttemptStore
+{
+    /**
+     * 試行の行を作る。**リダイレクトより前に呼ぶ** (逆順だと戻ってきた state が存在しない)。
+     */
+    public function start(
+        OrganizationOidcConnection $connection,
+        #[SensitiveParameter] string $state,
+        #[SensitiveParameter] string $nonce,
+        #[SensitiveParameter] string $codeVerifier,
+        #[SensitiveParameter] string $browserBindingSecret,
+    ): EnterpriseSsoLoginAttempt {
+        $attempt = new EnterpriseSsoLoginAttempt;
+
+        // ★$fillable は空なので保護キーは forceFill で明示代入する。
+        $attempt->forceFill([
+            'organization_oidc_connection_id' => $connection->id,
+            'state_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::State, $state),
+            'nonce_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::Nonce, $nonce),
+            'browser_binding_fingerprint' => AttemptFingerprint::of(
+                FingerprintPurpose::BrowserBinding,
+                $browserBindingSecret,
+            ),
+            'pkce_verifier_encrypted' => $codeVerifier,
+            'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.login_attempt.ttl_seconds')),
+        ])->save();
+
+        return $attempt;
+    }
+
+    /**
+     * 使用権を取得する。取得できた要求だけが値を読み出せる。
+     *
+     * ★**業務上の拒否では例外を投げない。DB・基盤の障害は例外として伝播し巻き戻す。**
+     *   - **業務上の拒否** (行が無い / 期限切れ / ブラウザ結合の不一致) はすべて
+     *     {@see AttemptConsumeResult} の分類として**返す**。ここを例外にすると、
+     *     同じトランザクションで行っている**期限切れ行のオンアクセス掃除まで巻き戻り**、
+     *     「オンアクセスでも掃除する」が成立しない。
+     *   - **DB・基盤の障害** ({@see EnterpriseSsoAttemptStoreFailure} と、その他の
+     *     予期しない例外) は**握り潰さず伝播させ**、トランザクションごと巻き戻す。
+     *     ★このときオンアクセス掃除が巻き戻ることは**受け入れる** —
+     *     掃除の正本は日次の実行点であり、オンアクセスはその前倒しに過ぎない。
+     *
+     * ★呼び出し側 ({@see EnterpriseCallbackAuthenticator}) が
+     *   「行が消えた失敗か / 行を保持した失敗か」で**セッションの秘密の始末を分け**、
+     *   その後で**外向きの一様な例外へ変換する**。
+     */
+    public function consume(
+        #[SensitiveParameter] string $state,
+        #[SensitiveParameter] string $browserBindingSecret,
+    ): AttemptConsumeResult {
+        $stateFingerprint = AttemptFingerprint::of(FingerprintPurpose::State, $state);
+        $expectedBinding = AttemptFingerprint::of(FingerprintPurpose::BrowserBinding, $browserBindingSecret);
+
+        return DB::transaction(function () use ($stateFingerprint, $expectedBinding): AttemptConsumeResult {
+            $row = EnterpriseSsoLoginAttempt::query()
+                ->where('state_fingerprint', $stateFingerprint)
+                ->lockForUpdate()
+                ->first();
+
+            if ($row === null) {
+                return AttemptConsumeResult::notFound();
+            }
+
+            if ($row->expires_at->isPast()) {
+                $this->deleteOrFail($row);   // ★この削除は commit される
+
+                return AttemptConsumeResult::expired();
+            }
+
+            if (! hash_equals($row->browser_binding_fingerprint, $expectedBinding)) {
+                // ★行を消さない (攻撃者が被害者の試行を消せる形にしない)。
+                return AttemptConsumeResult::bindingMismatch();
+            }
+
+            // 行をそのまま外へ出さない。DTO へ畳む。
+            $attempt = ConsumedLoginAttempt::fromModel($row);
+
+            $this->deleteOrFail($row);
+
+            return AttemptConsumeResult::consumed($attempt);
+        });
+    }
+
+    /**
+     * ★`delete()` が真を返さないのは **DB の障害**であって業務上の拒否ではない。
+     *   一様な拒否へ握り潰すと「排他が壊れた」という重大な事実が隠れる。
+     *   例外を投げてトランザクションを巻き戻す (行もセッションの秘密も残る)。
+     *
+     * @throws EnterpriseSsoAttemptStoreFailure
+     */
+    private function deleteOrFail(EnterpriseSsoLoginAttempt $row): void
+    {
+        if ($row->delete() !== true) {
+            throw new EnterpriseSsoAttemptStoreFailure('attempt row delete did not affect a row');
+        }
+    }
+}
diff --git a/app/Services/EnterpriseSso/EnterpriseUserProvisioner.php b/app/Services/EnterpriseSso/EnterpriseUserProvisioner.php
new file mode 100644
index 00000000..4c3dbd27
--- /dev/null
+++ b/app/Services/EnterpriseSso/EnterpriseUserProvisioner.php
@@ -0,0 +1,117 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims;
+use App\Enums\OrganizationRole;
+use App\Models\EnterpriseIdentity;
+use App\Models\Organization;
+use App\Models\OrganizationOidcConnection;
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Webmozart\Assert\Assert;
+
+/**
+ * 初回ログインでの利用者の自動作成 (always-JIT)。
+ *
+ * ★**メールアドレスで利用者を引かない** (正典 v1 / I1)。
+ *   引き当ての鍵は **接続 id と生の subject** だけである
+ *   (列の照合順序が `COLLATE "C"` なので**バイト一致**)。
+ *   ★**指紋 (HMAC) にしない** — 鍵に依存する値を鍵にすると、APP_KEY をローテートした瞬間に
+ *     既存の身元へ到達できなくなり**アカウントが分裂する**。列の照合順序なら鍵に依存しない。
+ *   申告メールは {@see EnterpriseIdentity} に暗号化して持つが、**引き当てには使わない**。
+ *
+ * ## 作る利用者の姿 (A3 と一体)
+ *
+ *  - `email` = **null** (企業 SSO でしか入れない利用者は使えるメールを持たない。
+ *    仮のメール文字列を作らない — 偽のメールは衝突と誤送の温床である)
+ *  - `email_verified_at` = **now()** (「IdP が本人確認した。確認すべきメールが無い」の意味。
+ *    既存の `verified` middleware の意味論を変えずに通す)
+ *  - `password` = **null** (パスワードは持たない。初回設定は既存の settings.password.store が担う)
+ *  - `name` = ID トークンの `name` claim があればそれ、無ければ表示用の既定値
+ *  - 所属は **接続が属する組織のみ**、役割は **{@see OrganizationRole::Member}** (最小)。
+ *    付与のすべてで **組織の team id を明示する** (AGENTS.md セキュリティ不変条件 5)
+ *
+ * ## 並行初回ログインの競合
+ *
+ * ★**競合制御は C2 が張る接続の行ロックが唯一の担い手である**。
+ *   同一接続の callback は行ロックで直列化されるので、事前検索 → 作成の間に
+ *   別の要求が割り込むことがない。
+ *  - 利用者・身元・組織所属の作成は **C2 が開いた 1 トランザクション**の中で行う
+ *  - `enterprise_identities_connection_subject_unique` は**最後の防波堤として残す**が、
+ *    **捕まえない** (握り潰すと「直列化が壊れた」という重大な事実が競合として隠れる)
+ *  - 失敗すればトランザクション全体が巻き戻るので**孤児は残らない**
+ */
+final readonly class EnterpriseUserProvisioner
+{
+    /** `name` claim を持たない IdP のための表示名。 */
+    private const string FALLBACK_NAME = '未設定';
+
+    public function __construct(private OrganizationMembershipService $memberships) {}
+
+    /**
+     * ★本メソッドは **C2 が張った接続の行ロックの中**で呼ばれる (線形化点は C2 が持つ)。
+     *   ここでトランザクションを開き直さない。
+     */
+    public function resolve(OrganizationOidcConnection $connection, VerifiedIdTokenClaims $claims): User
+    {
+        // ★relation 起点で引く。クラス起点で書かない — 組織スコープの出所を型と relation で
+        //   固定する (AGENTS.md セキュリティ不変条件 3)。
+        //   引き当ての鍵は subject の値そのもの (列の照合が COLLATE "C" なので byte 一致)。
+        $existing = $connection->identities()->where('subject', $claims->subject)->first();
+
+        if ($existing !== null) {
+            $existing->forceFill(['last_login_at' => now()])->save();
+
+            $user = $existing->user;
+            Assert::isInstanceOf($user, User::class);
+
+            return $user;   // アーリーリターン
+        }
+
+        // ★一意制約違反を**捕まえない**。理由は 2 つ:
+        //   (1) C2 が接続の行を lockForUpdate() しているので、同一接続の callback は既に
+        //       直列化されており、正規経路でこの競合は起きない
+        //   (2) pgsql は一度 SQL エラーが出るとトランザクション全体が aborted になり、
+        //       **同じトランザクションの中では再検索できない** = 「catch して引き当て直す」は
+        //       そもそも動かない
+        return $this->createUserWithIdentityAndMembership($connection, $claims);
+    }
+
+    private function createUserWithIdentityAndMembership(
+        OrganizationOidcConnection $connection,
+        VerifiedIdTokenClaims $claims,
+    ): User {
+        $organization = $connection->organization;
+        // ★接続は必ず組織に属する (FK が cascade で担保)。null は不変条件の破れなので fail-fast する。
+        Assert::isInstanceOf($organization, Organization::class);
+
+        $user = new User;
+        // ★保護キーは forceFill で明示代入する ($fillable 経由で受けない)。
+        $user->forceFill([
+            'name' => $claims->name ?? self::FALLBACK_NAME,
+            'email' => null,
+            'email_verified_at' => now(),
+            'password' => null,
+        ])->save();
+
+        $identity = new EnterpriseIdentity;
+        $identity->forceFill([
+            'organization_oidc_connection_id' => $connection->id,
+            'user_id' => $user->id,
+            'subject' => $claims->subject,
+            'claimed_email_encrypted' => $claims->claimedEmail,
+            'last_login_at' => now(),
+        ])->save();
+
+        // 所属は接続が属する組織だけ、役割は最小の Member。
+        // ★ロール書き込みは**単一窓口**の OrganizationMembershipService を通す
+        //   (ロール書き込みをロック済みサービス経由に限る直列化の前提を崩さない。
+        //    team id の明示 = AGENTS.md セキュリティ不変条件 5 もそちらが担う)。
+        $this->memberships->attachJustInTimeMember($organization, $user, OrganizationRole::Member);
+
+        return $user;
+    }
+}
diff --git a/app/Services/EnterpriseSso/OidcConnectionTransitionService.php b/app/Services/EnterpriseSso/OidcConnectionTransitionService.php
new file mode 100644
index 00000000..154fdef6
--- /dev/null
+++ b/app/Services/EnterpriseSso/OidcConnectionTransitionService.php
@@ -0,0 +1,423 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\ConnectionCredentialsSnapshot;
+use App\DataTransferObjects\EnterpriseSso\VerifyOutcome;
+use App\Enums\EnterpriseSso\ConnectionTransitionRejection;
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Exceptions\EnterpriseSso\OidcConnectionTransitionException;
+use App\Models\Organization;
+use App\Models\OrganizationOidcConnection;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use App\ValueObjects\EnterpriseSso\OidcIssuerUrl;
+use Closure;
+use Illuminate\Support\Facades\DB;
+use SensitiveParameter;
+
+/**
+ * 接続の状態遷移。
+ *
+ * 許す遷移 (これ以外は例外):
+ *   Draft                    → Verified  (接続先情報の取得に成功した)
+ *   Verified                 → Active    (運営が有効にした)
+ *   Active                   → Disabled  (運営が止めた)
+ *   Disabled                 → Active    (運営が戻した。verified_at が残っている場合のみ)
+ *   Verified/Active/Disabled → Draft     (★**認証材料を更新した**)
+ *
+ * ## 更新の規則は 3 段に分かれる
+ *
+ * | 変えるもの | 規則 | 理由 |
+ * |---|---|---|
+ * | **issuer / client_id** | ★**身元が 1 件でもあれば変更禁止**。新しい接続を作らせる。
+ *   **身元が 0 件なら変更できるが、その場合も必ず `Draft` へ戻し `verified_at` を消す** |
+ *   OIDC の身元は実質 (issuer, subject) であり、pairwise subject では client_id も
+ *   名前空間を変えうる。変えた後に偶然同じ subject が返ると**以前の利用者へ誤ってログインさせる** |
+ * | **client_secret** | **Draft へ差し戻し + verified_at を消す** | 名前空間は変わらないが、
+ *   未検証の構成で直ちにログインできる状態を作らない |
+ * | **表示名** | 状態を変えない | 認証に関与しない |
+ *
+ *  - 更新と状態変更は **同一トランザクション**で行う (片方だけが残る窓を作らない)
+ *  - **身元が 1 件でもある接続は物理削除できない** (削除すると身元だけが消え、
+ *    利用者が残ってアカウントが分裂する。運用は無効化で行う)
+ *
+ * ## 接続を変える操作はすべて接続の行をロックする (C2 との線形化)
+ *
+ * ★対象は **無効化だけではない**。`update` / `activate` / `disable` / `destroy` の**すべて**が
+ * **接続の行を `lockForUpdate()` した同一トランザクション**で、
+ * 「身元の有無の確認 → 検査 → 変更」を行う。
+ * C2 の callback も同じ行をロックして「Active の確認 → JIT」を行うので、両者は直列化される。
+ *
+ * ★ロックしないと次の競合が起きる:
+ *   (1) 管理操作が「身元 0 件」を確認 → (2) callback が行をロックして JIT →
+ *   (3) 管理操作が issuer を更新 / 物理削除
+ *   = **身元があるのに名前空間が変わる / 身元だけが消える**。
+ *
+ * ★**`verify` だけはこの形にしない**。`verify` は外向き HTTP を伴うので、同じ形にすると
+ *   **通信の間ずっと DB のロックを保持する**ことになる。`verify` は下の**三段構成**で線形化する。
+ *
+ * ★**ロック付きの再取得は 5 操作とも relation 起点に統一する**:
+ *
+ *     $organization->oidcConnections()->whereKey($id)->lockForUpdate()->first()
+ *
+ *   クラス起点の主キー同一性クエリで書かない — AGENTS.md セキュリティ不変条件 3 が
+ *   deny-by-default で分類を求める形であり、かつ**再取得の経路そのものが組織スコープを失う**。
+ *   親の `$organization` は route の scoped binding が解決したものだけを受け取り、
+ *   **payload 由来の組織 id を入れない** (不変条件 1)。
+ *   ★入口の binding が済んでいても**再取得の側で改めて relation 起点にする**。
+ *   「入口で確認したから中は自由」は、経路が増えたときに必ず崩れる。
+ *
+ * ★**ロックの取得順を統一する** (接続の行が唯一のロック対象。他の行を先に取らない)。
+ *
+ * ## 取得の失敗で接続を殺さない
+ *
+ * IdP の 5xx・鍵ローテーションの途中・DNS の一時障害を理由に**自動で無効化しない**
+ * (可用性の後退になる)。失敗はすべて「そのログイン試行だけを fail-closed で拒否する」に留め、
+ * 接続の状態を変えるのは**本サービスを通した運営操作だけ**である。
+ */
+final readonly class OidcConnectionTransitionService
+{
+    public function __construct(private OidcDiscoveryService $discovery) {}
+
+    /**
+     * 接続を登録する (常に `Draft` から始まる)。
+     */
+    public function create(
+        Organization $organization,
+        string $loginSlug,
+        string $displayName,
+        OidcIssuerUrl $issuer,
+        string $clientId,
+        #[SensitiveParameter] ConnectionSecret $clientSecret,
+    ): OrganizationOidcConnection {
+        return DB::transaction(function () use (
+            $organization,
+            $loginSlug,
+            $displayName,
+            $issuer,
+            $clientId,
+            $clientSecret,
+        ): OrganizationOidcConnection {
+            $connection = new OrganizationOidcConnection;
+
+            // ★$fillable は空。保護キーは forceFill で明示代入する。
+            $connection->forceFill([
+                'organization_id' => $organization->id,
+                'login_slug' => $loginSlug,
+                'display_name' => $displayName,
+                'issuer' => $issuer->value,
+                'client_id' => $clientId,
+                'client_secret_encrypted' => $clientSecret,
+                'status' => OidcConnectionStatus::Draft,
+                'verified_at' => null,
+                'credentials_revision' => 1,
+            ])->save();
+
+            return $connection;
+        });
+    }
+
+    /**
+     * 接続を更新する。
+     *
+     * @param  string|null  $displayName  null = 変えない
+     * @param  OidcIssuerUrl|null  $issuer  null = 変えない
+     * @param  string|null  $clientId  null = 変えない
+     * @param  ConnectionSecret|null  $clientSecret  null = 変えない (据え置き)
+     *
+     * @throws OidcConnectionTransitionException
+     */
+    public function update(
+        Organization $organization,
+        int $connectionId,
+        ?string $displayName,
+        ?OidcIssuerUrl $issuer,
+        ?string $clientId,
+        #[SensitiveParameter] ?ConnectionSecret $clientSecret,
+    ): OrganizationOidcConnection {
+        return $this->withLockedConnection(
+            $organization,
+            $connectionId,
+            function (OrganizationOidcConnection $locked) use (
+                $displayName,
+                $issuer,
+                $clientId,
+                $clientSecret,
+            ): OrganizationOidcConnection {
+                if ($displayName !== null) {
+                    // ★表示名は認証に関与しない。状態も版も変えない。
+                    $locked->forceFill(['display_name' => $displayName])->save();
+                }
+
+                $changesNamespace = ($issuer !== null && $issuer->value !== $locked->issuer)
+                    || ($clientId !== null && $clientId !== $locked->client_id);
+
+                if ($changesNamespace && $locked->identities()->exists()) {
+                    // ★身元がある接続の名前空間は変えられない (別人へ誤ログインさせるため)。
+                    throw OidcConnectionTransitionException::of(
+                        ConnectionTransitionRejection::IdentitiesExistCannotChangeNamespace,
+                    );
+                }
+
+                $changesSecret = $clientSecret !== null;
+
+                if ($changesNamespace || $changesSecret) {
+                    $this->applyCredentialChange($locked, $issuer, $clientId, $clientSecret);
+                }
+
+                return $locked;
+            },
+        );
+    }
+
+    /**
+     * 接続先情報の取得に成功したことを確認し、Draft → Verified へ進める。
+     *
+     * ★**外向き取得の間、DB のロックを一切保持しない**。段は 3 つに分かれる。
+     *
+     *   第 1 段 (ロックなし): 検証の対象となる**スナップショット**を読む
+     *   第 2 段 (ロックなし・トランザクションの外): 外向き取得と検証
+     *   第 3 段 (トランザクション + 行ロック): 一致の再確認と遷移
+     *
+     * ★**第 2 段をトランザクションの中に入れない**。中に入れると、ロックを取っていなくても
+     *   pgsql のトランザクションが外部 HTTP の往復のあいだ開きっぱなしになる
+     *   (idle in transaction が積み上がる)。開くのは第 3 段だけである。
+     *
+     * ## 比較子は 3 層である
+     *
+     * | 層 | 見るもの | 何を捕まえるか |
+     * |---|---|---|
+     * | **主** | `credentials_revision` | 認証材料の**あらゆる**変更 (書き手が規律を守っている限り) |
+     * | **第 2** | `issuer` / `client_id` の**実値** | ★**`+1` を忘れた書き手** (名前空間を変えた場合) |
+     * | **第 3** | `client_secret_encrypted` の**暗号文の digest** | ★**`+1` を忘れた書き手**
+     *            (secret を変えた場合)。★復号しない |
+     *
+     * 暗号文は保存のたびに変わりうる (同じ平文でも再暗号化で別の暗号文になる) ので、
+     * 第 3 層の比較は**空振りする側 = 拒否する側**へ倒れる。fail-closed であり安全側である
+     * (運営はもう一度押せばよい)。
+     *
+     * ## この形が保証すること / しないこと
+     *
+     * - **保証する**: 外向き取得の開始から完了までの間に認証材料が変わったなら、
+     *   その `verify` の結果は**採用されない** (`Draft` のまま拒否される)
+     * - **保証する**: 外向き取得の**間、接続の行のロックを保持しない**
+     * - **保証する**: `verify` の経路は **client secret を一度も復号しない**
+     * - **保証しない**: 「取得した瞬間に IdP 側が正しかった」こと。IdP は `verify` の**後**に
+     *   いつでも構成を変えられる。`Verified` は**そのときの取得が成功した**という記録に過ぎない
+     * - **保証しない**: 拒否された `verify` の**自動再実行** (運営がもう一度押す)
+     *
+     * @throws EnterpriseSsoAttemptRejectedException 第 2 段の外向き取得に失敗した
+     *                                               (★接続の状態は変えない。可用性の後退を作らない)
+     * @throws OidcConnectionTransitionException 遷移表に無い状態から呼ばれた
+     */
+    public function verify(Organization $organization, OrganizationOidcConnection $connection): VerifyOutcome
+    {
+        // ── 第 1 段: スナップショット (ロックなし)
+        // ★**行を読み直してから撮る**。呼び出し側が渡してきたインスタンスの
+        //   `getRawOriginal()` は、直前に保存した直後だと保存に使った暗号文と食い違うことがある
+        //   (暗号化のたびに別の暗号文になるため)。比べたいのは「**保存されている暗号文**が
+        //   取得の間に変わったか」なので、スナップショットも保存された値から撮る。
+        // ★ここも **relation 起点**である (組織スコープを入口の binding だけに依存させない)。
+        $current = $organization->oidcConnections()->whereKey($connection->id)->first();
+
+        if ($current === null) {
+            return VerifyOutcome::ConnectionGone;   // アーリーリターン
+        }
+
+        $snapshot = ConnectionCredentialsSnapshot::of($current);
+
+        // ── 第 2 段: 外向き取得 (ロックなし・トランザクションの外)
+        //    取得の失敗で接続の状態を変えない (「取得の失敗で接続を殺さない」)。
+        $metadata = $this->discovery->fetchMetadata(OidcIssuerUrl::fromString($snapshot->issuer));
+        $this->discovery->fetchJwks($metadata);
+
+        // ── 第 3 段: 一致の再確認と遷移 (ここで初めてトランザクションと行ロック)
+        return DB::transaction(function () use ($organization, $snapshot): VerifyOutcome {
+            // ★**relation 起点で引く**。親は scoped binding で解決済みの $organization であり、
+            //   ★**payload 由来の組織 id をここへ入れない**。
+            $fresh = $organization->oidcConnections()
+                ->whereKey($snapshot->connectionId)
+                ->lockForUpdate()
+                ->first();
+
+            // 接続が消えていた (または組織の外へ出た) → 結果を捨てる (アーリーリターン)
+            if ($fresh === null) {
+                return VerifyOutcome::ConnectionGone;
+            }
+
+            // ★**主の比較子は credentials_revision** である。
+            if ($fresh->credentials_revision !== $snapshot->credentialsRevision) {
+                return VerifyOutcome::StaleCredentials;   // ★結果を捨てる。Draft のまま
+            }
+
+            // ★**第 2 / 第 3 の比較子**。主の代わりではなく、「+1 を忘れた書き手がいたら落ちる」層。
+            if ($fresh->issuer !== $snapshot->issuer
+                || $fresh->client_id !== $snapshot->clientId
+                || ! hash_equals($snapshot->clientSecretCiphertextDigest, $fresh->clientSecretCiphertextDigest())
+            ) {
+                return VerifyOutcome::StaleCredentials;
+            }
+
+            // ★同じ材料を別の要求が既に Verified にしていた場合は、何もせず成功とする。
+            if ($fresh->status === OidcConnectionStatus::Verified) {
+                return VerifyOutcome::AlreadyVerified;
+            }
+
+            // Draft 以外 (Active / Disabled) からは遷移しない。定義外の遷移は例外。
+            if ($fresh->status !== OidcConnectionStatus::Draft) {
+                throw OidcConnectionTransitionException::of(ConnectionTransitionRejection::UndefinedTransition);
+            }
+
+            $fresh->forceFill([
+                'status' => OidcConnectionStatus::Verified,
+                'verified_at' => now(),
+            ])->save();
+
+            return VerifyOutcome::Verified;
+        });
+    }
+
+    /**
+     * 有効化する (Verified → Active / Disabled → Active)。
+     *
+     * ★`Disabled` から戻せるのは `verified_at` が残っている場合だけである
+     *   (一度も確認できていない構成でログインを開けない)。
+     *
+     * @throws OidcConnectionTransitionException
+     */
+    public function activate(Organization $organization, int $connectionId): OrganizationOidcConnection
+    {
+        return $this->withLockedConnection(
+            $organization,
+            $connectionId,
+            static function (OrganizationOidcConnection $locked): OrganizationOidcConnection {
+                $allowed = $locked->status === OidcConnectionStatus::Verified
+                    || ($locked->status === OidcConnectionStatus::Disabled && $locked->verified_at !== null);
+
+                if (! $allowed) {
+                    throw OidcConnectionTransitionException::of(ConnectionTransitionRejection::UndefinedTransition);
+                }
+
+                $locked->forceFill(['status' => OidcConnectionStatus::Active])->save();
+
+                return $locked;
+            },
+        );
+    }
+
+    /**
+     * 無効化する (Active → Disabled)。
+     *
+     * ★C2 の callback と**同じ行をロックする**ので両者は直列化される。
+     *   無効化が先に線形化したら JIT もログインも起きず、callback が先なら
+     *   無効化はその後に成立する (次回から入れない)。
+     *
+     * @throws OidcConnectionTransitionException
+     */
+    public function disable(Organization $organization, int $connectionId): OrganizationOidcConnection
+    {
+        return $this->withLockedConnection(
+            $organization,
+            $connectionId,
+            static function (OrganizationOidcConnection $locked): OrganizationOidcConnection {
+                if ($locked->status !== OidcConnectionStatus::Active) {
+                    throw OidcConnectionTransitionException::of(ConnectionTransitionRejection::UndefinedTransition);
+                }
+
+                $locked->forceFill(['status' => OidcConnectionStatus::Disabled])->save();
+
+                return $locked;
+            },
+        );
+    }
+
+    /**
+     * 物理削除する。
+     *
+     * ★**身元が 1 件でもある接続は消せない**。消すと身元だけが消えて利用者が残り、
+     *   同じ IdP を再登録したときに同じ subject で**新しい利用者が JIT で作られる**
+     *   (アカウントの分裂)。企業 SSO でしか入れない利用者は元のアカウントへ二度と戻れない。
+     *   運用は**無効化**で行う (無効化なら身元は残り、再び有効にしたときに同じ利用者へ戻る)。
+     *
+     * @throws OidcConnectionTransitionException
+     */
+    public function destroy(Organization $organization, int $connectionId): void
+    {
+        $this->withLockedConnection(
+            $organization,
+            $connectionId,
+            static function (OrganizationOidcConnection $locked): OrganizationOidcConnection {
+                if ($locked->identities()->exists()) {
+                    throw OidcConnectionTransitionException::of(
+                        ConnectionTransitionRejection::IdentitiesExistCannotDelete,
+                    );
+                }
+
+                $locked->delete();
+
+                return $locked;
+            },
+        );
+    }
+
+    /**
+     * ★issuer / client_id / client_secret のいずれかを変える**唯一の書き手**。
+     *
+     * 3 つを 1 か所に閉じ込めるのは、`credentials_revision` の +1 を
+     * 「書き手が思い出す規律」ではなく「経路の性質」にするためである。
+     */
+    private function applyCredentialChange(
+        OrganizationOidcConnection $locked,
+        ?OidcIssuerUrl $issuer,
+        ?string $clientId,
+        #[SensitiveParameter] ?ConnectionSecret $clientSecret,
+    ): void {
+        $changes = [];
+
+        if ($issuer !== null) {
+            $changes['issuer'] = $issuer->value;
+        }
+
+        if ($clientId !== null) {
+            $changes['client_id'] = $clientId;
+        }
+
+        if ($clientSecret !== null) {
+            $changes['client_secret_encrypted'] = $clientSecret;
+        }
+
+        // ★必ず +1 し、必ず Draft へ戻し、verified_at を消す。
+        $changes['credentials_revision'] = $locked->credentials_revision + 1;
+        $changes['status'] = OidcConnectionStatus::Draft;
+        $changes['verified_at'] = null;
+
+        $locked->forceFill($changes)->save();
+    }
+
+    /**
+     * ★ロック付きの再取得を **relation 起点**に統一する 1 本道。
+     *
+     * 接続が組織の外にある / 消えている場合は 404 として扱えるよう `ModelNotFoundException` を
+     * そのまま伝播させる (`firstOrFail`)。層 2 (テナント境界 = 404) は層 3 (認可 = 403) より前で
+     * 閉じており、ここは route の scoped binding が既に通した後の**再取得**である。
+     *
+     * @param  Closure(OrganizationOidcConnection): OrganizationOidcConnection  $callback
+     */
+    private function withLockedConnection(
+        Organization $organization,
+        int $connectionId,
+        Closure $callback,
+    ): OrganizationOidcConnection {
+        return DB::transaction(function () use ($organization, $connectionId, $callback): OrganizationOidcConnection {
+            $locked = $organization->oidcConnections()
+                ->whereKey($connectionId)
+                ->lockForUpdate()
+                ->firstOrFail();
+
+            return $callback($locked);
+        });
+    }
+}
diff --git a/app/Services/EnterpriseSso/OidcDiscoveryService.php b/app/Services/EnterpriseSso/OidcDiscoveryService.php
new file mode 100644
index 00000000..fb86ad8f
--- /dev/null
+++ b/app/Services/EnterpriseSso/OidcDiscoveryService.php
@@ -0,0 +1,243 @@
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
+use Illuminate\Contracts\Cache\Repository as CacheRepository;
+use Illuminate\Support\Facades\Config;
+use Kent013\SsrfPin\Dtos\Deadline;
+use Kent013\SsrfPin\Dtos\PinnedFailure;
+use Kent013\SsrfPin\Dtos\PinnedRequest;
+use Kent013\SsrfPin\PinnedHttpClient;
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
+     *  - 最終再取得時刻を**スカラー**でキャッシュに持ち、最小間隔の内側では再取得しない
+     *    (未知 kid を連打されたときの増幅を防ぐ)
+     *  - 再取得は **1 回だけ**である (呼び出し側が再帰しない)
+     *
+     * @throws EnterpriseSsoAttemptRejectedException 最小間隔の内側 (= 再取得しない)
+     */
+    public function refetchJwks(OidcProviderMetadata $metadata, int $connectionId): OidcJsonWebKeySet
+    {
+        $stampKey = self::JWKS_REFETCHED_AT_CACHE_PREFIX.$connectionId;
+        $minimumInterval = Config::integer('enterprise-sso.discovery.jwks_refetch_min_interval_seconds');
+
+        /** @var mixed $lastRefetchedAt */
+        $lastRefetchedAt = $this->cache->get($stampKey);
+        if (is_int($lastRefetchedAt) && (time() - $lastRefetchedAt) < $minimumInterval) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::JwksRefetchUnavailable);
+        }
+
+        $this->cache->put($stampKey, time(), $minimumInterval);
+        $this->cache->forget(self::JWKS_CACHE_PREFIX.$metadata->issuer->cacheDigest());
+
+        return $this->fetchAndCacheJwks($metadata);
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
diff --git a/app/Services/EnterpriseSso/OidcTokenExchanger.php b/app/Services/EnterpriseSso/OidcTokenExchanger.php
new file mode 100644
index 00000000..622ee45f
--- /dev/null
+++ b/app/Services/EnterpriseSso/OidcTokenExchanger.php
@@ -0,0 +1,125 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use App\DataTransferObjects\EnterpriseSso\OidcProviderMetadata;
+use App\DataTransferObjects\EnterpriseSso\OidcTokenResponse;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Enums\EnterpriseSso\TokenEndpointAuthMethod;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Models\OrganizationOidcConnection;
+use App\Support\EnterpriseSso\BasicCredentials;
+use Illuminate\Support\Facades\Config;
+use Kent013\SsrfPin\Dtos\Deadline;
+use Kent013\SsrfPin\Dtos\PinnedFailure;
+use Kent013\SsrfPin\Dtos\PinnedRequest;
+use Kent013\SsrfPin\PinnedHttpClient;
+use SensitiveParameter;
+
+/**
+ * 認可コードとトークンの交換。
+ *
+ * ★本サービスは `kent013/laravel-ssrf-pin` ^0.4 の「**要求 body を運べる pin 済み取得**」を
+ *   必要とする (v0.2 系では実装そのものが成立しない)。
+ *
+ * ## 秘密を漏らさないための 4 点
+ *
+ *  1. **vendor の例外を外へ連結しない** — previous に載せると、要求 body (認可コード /
+ *     client secret / code_verifier) が例外の連鎖からログへ展開されうる。
+ *     境界で**固定の理由コードの例外**へ変換する。
+ *     `EnterpriseSsoAttemptRejectedException` は **`previous` を受け取れない構築子**を持つので、
+ *     型で連鎖が起きない
+ *  2. 平文を受ける引数に **`#[SensitiveParameter]`** を付ける (スタックトレースに出さない)
+ *  3. client secret は `ConnectionSecret::revealForTokenExchange()` で**ここでだけ**平文化する
+ *     (呼び出し元は gate が exact-fit で pin する)
+ *  4. client 認証は **`client_secret_basic` を優先** (body 漏洩面が小さい)。
+ *     IdP が対応しない場合だけ `client_secret_post` へ落とす
+ *
+ * ★**リダイレクトを追従しない**。追従すると転送先へ資格情報つきの要求が飛びうる
+ *   (^0.4 の client は 2 hop 目以降 body を落とすが、ヘッダの Basic は落ちない)。
+ */
+final readonly class OidcTokenExchanger
+{
+    public function __construct(private PinnedHttpClient $pinned) {}
+
+    /**
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    public function exchange(
+        OrganizationOidcConnection $connection,
+        OidcProviderMetadata $metadata,
+        string $redirectUri,
+        #[SensitiveParameter] string $code,
+        #[SensitiveParameter] string $codeVerifier,
+    ): OidcTokenResponse {
+        $method = $this->chooseAuthMethod($metadata);
+
+        $form = [
+            'grant_type' => 'authorization_code',
+            'code' => $code,
+            'redirect_uri' => $redirectUri,
+            'client_id' => $connection->client_id,
+            'code_verifier' => $codeVerifier,            // ★PKCE の往復の片端
+        ];
+
+        $headers = ['Accept' => 'application/json'];
+        if ($method === TokenEndpointAuthMethod::ClientSecretBasic) {
+            $headers['Authorization'] = BasicCredentials::header(
+                $connection->client_id,
+                $connection->clientSecret()->revealForTokenExchange(),
+            );
+        } else {
+            $form['client_secret'] = $connection->clientSecret()->revealForTokenExchange();
+        }
+
+        $request = new PinnedRequest(
+            method: 'POST',
+            url: $metadata->tokenEndpoint,
+            headers: $headers,
+            connectTimeout: (float) Config::integer('enterprise-sso.token.connect_timeout_seconds'),
+            body: http_build_query($form, '', '&', PHP_QUERY_RFC1738),
+            contentType: 'application/x-www-form-urlencoded',
+        );
+
+        $result = $this->pinned->fetch(
+            $request,
+            Deadline::afterSeconds((float) Config::integer('enterprise-sso.token.request_timeout_seconds')),
+            followRedirects: false,
+        );
+
+        // ★fetch() は PinnedResponse|PinnedFailure を返す。**失敗は値で返る**ので
+        //   catch では捕まらない。明示的に分岐して固定の理由コードへ変換する。
+        if ($result instanceof PinnedFailure) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenExchangeFailed);
+        }
+
+        // ★3xx を成功として扱わない。
+        if ($result->status < 200 || $result->status >= 300) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenExchangeFailed);
+        }
+
+        if (strlen($result->body) > Config::integer('enterprise-sso.token.max_body_bytes')) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::TokenResponseMalformed);
+        }
+
+        return OidcTokenResponse::fromResponseBody($result->body);
+    }
+
+    /**
+     * ★basic を優先する (body 漏洩面が小さい)。どちらも無ければ拒否する。
+     *
+     * @throws EnterpriseSsoAttemptRejectedException
+     */
+    private function chooseAuthMethod(OidcProviderMetadata $metadata): TokenEndpointAuthMethod
+    {
+        foreach ([TokenEndpointAuthMethod::ClientSecretBasic, TokenEndpointAuthMethod::ClientSecretPost] as $method) {
+            if (in_array($method, $metadata->tokenEndpointAuthMethods, true)) {
+                return $method;
+            }
+        }
+
+        throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryNoSupportedAuthMethod);
+    }
+}
diff --git a/app/Services/Organization/OrganizationMembershipService.php b/app/Services/Organization/OrganizationMembershipService.php
index 5b487bb0..af5516b3 100644
--- a/app/Services/Organization/OrganizationMembershipService.php
+++ b/app/Services/Organization/OrganizationMembershipService.php
@@ -19,6 +19,7 @@
 use App\Notifications\Account\AccountDeletionRequestedNotification;
 use App\Notifications\OrganizationInvitationNotification;
 use App\Services\Billing\AccountDeletionBillingGuard;
+use App\Services\EnterpriseSso\EnterpriseUserProvisioner;
 use App\Services\Notification\NotificationCenterService;
 use App\Services\OAuth\OrganizationAccessRevoker;
 use App\Services\Project\DefaultProjectResolver;
@@ -279,7 +280,9 @@ private function pendingInvitationsQuery(?User $user): ?Builder
         }
 
         $email = $user->email; // CipherSweet 復号後
-        if ($email === '') {
+        // ★企業 SSO でしか入れない利用者は使えるメールを持たない (T253 / A3)。
+        //   宛先が無いので招待の引き当ても行わない。
+        if ($email === null || $email === '') {
             return null;
         }
 
@@ -901,6 +904,39 @@ public function organizationsWithoutOwner(): Collection
             ->get();
     }
 
+    /**
+     * 企業 SSO の初回ログインで作られた利用者を、接続が属する組織へ最小権限で所属させる (T253 / C1)。
+     *
+     * ★**ロール書き込みの単一窓口**である本サービスに置く。
+     *   {@see EnterpriseUserProvisioner} から呼ばれ、
+     *   `MembershipWriteLockInventoryTest` の「Laratrust の書き込みはロック済みサービス経由のみ」
+     *   という直列化の前提を崩さない (ロール書き込みを企業 SSO の側へ持ち出さない)。
+     *
+     * ★呼び出し元は既に**接続の行**を `lockForUpdate()` した同一トランザクションの中にいる。
+     *   ここで取るロックの順序は「接続 → users → organizations」であり、
+     *   接続の行より先に他の行をロックする経路は存在しない (D1 は接続の行しかロックしない) ので
+     *   既存のロック順序と循環しない。
+     *
+     * ★利用者は直前に作られた新規行なので、この付与が**既存組織の owner 集合を変えることはない**
+     *   (付与するのは常に最小権限の Member である)。
+     */
+    public function attachJustInTimeMember(Organization $organization, User $user, OrganizationRole $role): void
+    {
+        $this->lockForMembershipWrite([$this->keyOf($user)], [$this->keyOf($organization)]);
+
+        $joined = DB::table('organization_user')->insertOrIgnore([
+            'organization_id' => $organization->id,
+            'user_id' => $user->getKey(),
+            'created_at' => now(),
+            'updated_at' => now(),
+        ]);
+
+        if ($joined === 1) {
+            // ★権限判定は常に laratrust_team_id を明示する (AGENTS.md セキュリティ不変条件 5)。
+            $user->addRole($role->value, $organization->laratrust_team_id);
+        }
+    }
+
     /**
      * メンバーシップ書き込みの共通ロック境界。canonical 順序で行ロックを取り、
      * デッドロックを構造的に排除する: **users(id 昇順) → organizations(id 昇順)**。
diff --git a/app/Support/EnterpriseSso/AttemptFingerprint.php b/app/Support/EnterpriseSso/AttemptFingerprint.php
new file mode 100644
index 00000000..f59cb5d3
--- /dev/null
+++ b/app/Support/EnterpriseSso/AttemptFingerprint.php
@@ -0,0 +1,74 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use Illuminate\Support\Facades\Config;
+use RuntimeException;
+use SensitiveParameter;
+
+/**
+ * **一時値**の指紋の導出。用途ごとに domain separation する。
+ *
+ * 鍵は **APP_KEY から用途別ラベル付きで導出する** (HKDF)。専用の秘密を新設しない —
+ * 運用要件を 1 つ増やす価値が無い (思考原則 2)。判断の根拠:
+ *   APP_KEY をローテートして失効するのは **進行中の試行 (10 分) と未確認の昇格 (60 分) だけ**である。
+ *   ★**身元・接続・利用者はどれも指紋に依存しない** (subject は指紋を使わない) ので、
+ *     ローテートで失われる永続的なものが無い。
+ *   (対比: パスキーの利用者ハンドルは APP_KEY 由来だと**登録済みパスキーが全件無効**になるため
+ *    専用の秘密を要求している。ここはその条件に当たらない。)
+ *
+ * ★**この型に永続する値の用途を足さない**。足すと上の根拠が崩れる。
+ *
+ * ## 鍵の導出の契約 (実装差を残さないために書く)
+ *
+ *  - 入力鍵: `config('app.key')` の **`base64:` 接頭辞を外して base64 復号したバイト列**
+ *    (復号できない設定は例外。黙って文字列のまま使わない)
+ *  - salt:   空 (アプリ内で 1 つの入力鍵しか使わないので salt に載せる情報が無い)
+ *  - info:   **用途の値そのもの** (`FingerprintPurpose::value`)。これが domain separation の実体
+ *  - 出力長: 32 バイト
+ */
+final class AttemptFingerprint
+{
+    /** 指紋の 16 進表記の長さ (DB の `char(64)` と対)。 */
+    public const int HEX_LENGTH = 64;
+
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    /** 用途つきの指紋 (16 進 64 文字)。 */
+    public static function of(FingerprintPurpose $purpose, #[SensitiveParameter] string $value): string
+    {
+        return hash_hmac('sha256', $value, self::key($purpose));
+    }
+
+    /** CSPRNG で一時値を作る (base64url。パディングなし)。 */
+    public static function newSecret(): string
+    {
+        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
+    }
+
+    private static function key(FingerprintPurpose $purpose): string
+    {
+        return hash_hkdf('sha256', self::inputKeyingMaterial(), 32, $purpose->value);
+    }
+
+    private static function inputKeyingMaterial(): string
+    {
+        $key = Config::string('app.key');
+
+        if (! str_starts_with($key, 'base64:')) {
+            throw new RuntimeException('APP_KEY は base64: 接頭辞つきで宣言されている必要があります。');
+        }
+
+        $decoded = base64_decode(substr($key, 7), true);
+
+        if ($decoded === false || $decoded === '') {
+            throw new RuntimeException('APP_KEY を base64 復号できませんでした。');
+        }
+
+        return $decoded;
+    }
+}
diff --git a/app/Support/EnterpriseSso/BasicCredentials.php b/app/Support/EnterpriseSso/BasicCredentials.php
new file mode 100644
index 00000000..dec525ab
--- /dev/null
+++ b/app/Support/EnterpriseSso/BasicCredentials.php
@@ -0,0 +1,29 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\EnterpriseSso;
+
+use SensitiveParameter;
+
+/**
+ * `client_secret_basic` の Authorization ヘッダの組み立て (RFC 6749 §2.3.1)。
+ *
+ * ★仕様は「client_id と client_secret を **application/x-www-form-urlencoded の規則で
+ *   符号化してから** `:` で連結し base64 する」と定めている。
+ *   自前の `rawurlencode` 連結にしない — 空白・`+`・`:`・非 ASCII で壊れる
+ *   (`rawurlencode` は空白を `%20` にするが、この規則では `+` である)。
+ */
+final class BasicCredentials
+{
+    /** インスタンス化しない (純関数の置き場)。 */
+    private function __construct() {}
+
+    public static function header(
+        #[SensitiveParameter] string $clientId,
+        #[SensitiveParameter] string $clientSecret,
+    ): string {
+        // urlencode() が application/x-www-form-urlencoded の規則 (空白 → `+`)。
+        return 'Basic '.base64_encode(urlencode($clientId).':'.urlencode($clientSecret));
+    }
+}
diff --git a/app/ValueObjects/EnterpriseSso/ConnectionSecret.php b/app/ValueObjects/EnterpriseSso/ConnectionSecret.php
new file mode 100644
index 00000000..18a6f935
--- /dev/null
+++ b/app/ValueObjects/EnterpriseSso/ConnectionSecret.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\ValueObjects\EnterpriseSso;
+
+use App\Casts\EncryptedSecretCast;
+use SensitiveParameter;
+
+/**
+ * 接続の秘密 (client secret) の値型。
+ *
+ * ★**暗黙の文字列化を持たない** — `__toString()` を実装しない。
+ *   これにより「うっかり文字列連結・ログ・例外・DTO へ載る」経路が**型で消える**。
+ * ★平文の取り出し口は **用途ごとに分かれた 2 つだけ**である。
+ *   {@see self::revealForTokenExchange()} を呼んでよいのは OidcTokenExchanger だけ、
+ *   {@see self::revealForEncryptionAtRest()} を呼んでよいのは EncryptedSecretCast だけであり、
+ *   tests/Architecture/EnterpriseSsoSecretExposureGateTest が**それぞれ** exact-fit で pin する。
+ *
+ * ## 保証する範囲 (誇張しない)
+ *
+ * `__debugInfo()` が効くのは **`var_dump()` 系だけ**である。
+ * ★**`var_export()` / `serialize()` / Reflection からは平文が見える**。
+ *   任意の PHP の内省に対して隠せるとは**主張しない**。
+ *   したがって守りは 3 層に分ける:
+ *     1. 型 — 暗黙の文字列化を持たない (うっかりの連結・出力を消す)
+ *     2. gate — **この値型をログ・dump・直列化の関数へ渡す記法**を G3 が禁じる
+ *     3. **主たる証明** — 実挙動の漏洩テスト (例外・監査・ログ・要求の記録に出ない)
+ */
+final readonly class ConnectionSecret
+{
+    private function __construct(private string $plaintext) {}
+
+    public static function fromPlaintext(#[SensitiveParameter] string $plaintext): self
+    {
+        return new self($plaintext);
+    }
+
+    /** ★token 交換だけが呼ぶ。他所からの呼び出しは gate が落とす。 */
+    public function revealForTokenExchange(): string
+    {
+        return $this->plaintext;
+    }
+
+    /**
+     * ★**保存のための暗号化だけ**が呼ぶ ({@see EncryptedSecretCast})。
+     *
+     * 用途を `revealForTokenExchange()` と分けているのは、**呼び出し元をそれぞれ
+     * exact-fit で pin できる**ようにするためである。1 つの口にまとめると
+     * 「保存のために要る」という理由で外向きの利用まで通ってしまう。
+     */
+    public function revealForEncryptionAtRest(): string
+    {
+        return $this->plaintext;
+    }
+
+    /** 空でないか (画面が「秘密が在るか」だけを知るための述語。平文を返さない)。 */
+    public function isPresent(): bool
+    {
+        return $this->plaintext !== '';
+    }
+
+    /**
+     * ★`var_dump()` 系にだけ効く。`var_export()` / `serialize()` / Reflection には効かない。
+     *
+     * @return array{client_secret: string}
+     */
+    public function __debugInfo(): array
+    {
+        return ['client_secret' => '********'];
+    }
+}
diff --git a/app/ValueObjects/EnterpriseSso/OidcIssuerUrl.php b/app/ValueObjects/EnterpriseSso/OidcIssuerUrl.php
new file mode 100644
index 00000000..53f3ae9a
--- /dev/null
+++ b/app/ValueObjects/EnterpriseSso/OidcIssuerUrl.php
@@ -0,0 +1,99 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\ValueObjects\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+
+/**
+ * issuer の値オブジェクト。**型で規則を担保する** (呼び出し側の作法に頼らない)。
+ *
+ * 規則: https のみ / userinfo なし / query なし / fragment なし / 絶対 URL / 長さ上限。
+ *
+ * ★**末尾のスラッシュを正規化しない**。OIDC の issuer は**識別子であって URL の
+ *   正規化対象ではない** — `https://idp.example/tenant` と `https://idp.example/tenant/` は
+ *   **別の issuer** になりうる。登録した文字列をそのまま保ち、discovery 文書の issuer と
+ *   仕様どおり完全一致させる。
+ *
+ * ★well-known の URL は「issuer のパスの**後ろに**」付ける
+ *   (`https://idp.example/tenant` → `https://idp.example/tenant/.well-known/openid-configuration`)。
+ *
+ * ★`config/ssrf-pin.php` は http も許している (他用途のため) が、
+ *   **企業 OIDC 自身の入力規則として https を必須化する** — でなければ
+ *   client secret・認可コード・トークンが平文で流れる。
+ */
+final readonly class OidcIssuerUrl
+{
+    /** DB の `issuer` 列 (varchar 255) と対。 */
+    public const int MAX_LENGTH = 255;
+
+    private function __construct(public string $value) {}
+
+    /**
+     * @throws EnterpriseSsoAttemptRejectedException 規則に合わない文字列
+     */
+    public static function fromString(string $value): self
+    {
+        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryInvalidEndpoint);
+        }
+
+        if (! self::isValid($value)) {
+            throw EnterpriseSsoAttemptRejectedException::of(RejectionReason::DiscoveryInvalidEndpoint);
+        }
+
+        return new self($value);
+    }
+
+    /** 規則を満たすか (FormRequest の検査でも使う。例外を投げない述語)。 */
+    public static function isValid(string $value): bool
+    {
+        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
+            return false;
+        }
+
+        $parts = parse_url($value);
+        if (! is_array($parts)) {
+            return false;
+        }
+
+        if (($parts['scheme'] ?? null) !== 'https') {
+            return false;
+        }
+
+        if (($parts['host'] ?? '') === '') {
+            return false;
+        }
+
+        // userinfo (`https://user:pass@host/`) は詐称の温床なので許さない。
+        if (isset($parts['user']) || isset($parts['pass'])) {
+            return false;
+        }
+
+        // issuer は識別子である。query と fragment を持たせない。
+        if (isset($parts['query']) || isset($parts['fragment'])) {
+            return false;
+        }
+
+        return true;
+    }
+
+    /**
+     * discovery 文書の URL。
+     *
+     * ★issuer の**パスの後ろ**に足す (host の直下ではない)。
+     *   末尾のスラッシュは重ねない (issuer 自体は正規化しない)。
+     */
+    public function wellKnownUrl(): string
+    {
+        return rtrim($this->value, '/').'/.well-known/openid-configuration';
+    }
+
+    /** キャッシュキーに使う指紋 (**URL の平文をキーに残さない**)。 */
+    public function cacheDigest(): string
+    {
+        return hash('sha256', $this->value);
+    }
+}
diff --git a/bootstrap/app.php b/bootstrap/app.php
index e22d967d..e3cdfb6b 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -356,6 +356,19 @@
             fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
         );
 
+        /*
+         | 企業 OIDC 接続の client secret を old input へ残さない (T253 / D2)。
+         |
+         | Laravel は validation の失敗時に入力をセッションへ flash する。登録・更新フォームは
+         | **接続の秘密を扱ってよい唯一の前面**なので、この 1 語だけをグローバルに伏せる。
+         |
+         | ★`code` / `state` / `token` のような**一般名はここへ足さない** —
+         |   他のフォームの入力復元まで黙って変えてしまう。これらは**経路側で閉じる**
+         |   (企業 SSO の callback とメール昇格の確認は、失敗時に withInput() を使わない
+         |   = 入力を一切 flash しない)。
+         */
+        $exceptions->dontFlash(['client_secret']);
+
         /*
          | セッション終了を検知した契機で Inertia の履歴暗号鍵を捨てさせる (経路 C の拡張)。
          |
diff --git a/config/enterprise-sso.php b/config/enterprise-sso.php
new file mode 100644
index 00000000..e3f38046
--- /dev/null
+++ b/config/enterprise-sso.php
@@ -0,0 +1,54 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+|--------------------------------------------------------------------------
+| エンタープライズ OIDC SSO
+|--------------------------------------------------------------------------
+| ★外部 URL の安全境界は **ここに書かない**。SSRF の境界の正本は
+|   config/ssrf-pin.php であり、本設計はそれを変更しない (同じ事実を 2 か所に置かない)。
+| ★環境変数を足さない。すべて固定値である (テンプレートの固定値方式に合わせる)。
+|
+| ★`max_body_bytes` は **アプリ側の後段検査の上限**である。
+|   kent013/laravel-ssrf-pin ^0.4 の `PinnedRequest` は要求ごとの本文上限を受け取らず、
+|   上限は transport の構築時 (config/ssrf-pin.php の `max_body_bytes`) に決まる。
+|   したがって用途ごとのより厳しい上限は **応答を受け取った後にアプリが測って拒否する**。
+|   transport 側の上限は「読み切らせない」ための防壁で、こちらは
+|   「期待と違う大きさの応答を DTO へ固定しない」ための検査である (層が違う)。
+*/
+
+return [
+    'discovery' => [
+        'connect_timeout_seconds' => 3,
+        'request_timeout_seconds' => 5,
+        'cache_ttl_seconds' => 300,
+        // 未知 kid での鍵の再取得の最小間隔 (増幅を防ぐ)
+        'jwks_refetch_min_interval_seconds' => 60,
+        'max_body_bytes' => 262144,
+    ],
+
+    'token' => [
+        'connect_timeout_seconds' => 3,
+        'request_timeout_seconds' => 8,
+        'max_body_bytes' => 65536,
+    ],
+
+    'id_token' => [
+        // 許容する時刻ずれ。**顧客の入力では広げられない** (接続の登録項目にしない)。
+        'leeway_seconds' => 60,
+        'max_subject_length' => 255,
+    ],
+
+    'login_attempt' => [
+        'ttl_seconds' => 600,
+        // 掃除の 1 回あたりの上限 (長いトランザクションを作らない)
+        'prune_chunk' => 1000,
+    ],
+
+    // メールアドレスの昇格 (E1)。Auth 名前空間の機能だが、設定は本ファイルに集約する
+    // (企業 SSO でしか入れない利用者のための機構であり、単独では意味を持たない)。
+    'email_promotion' => [
+        'ttl_seconds' => 3600,
+    ],
+];
diff --git a/config/seo.php b/config/seo.php
index c02df8e2..b469d667 100644
--- a/config/seo.php
+++ b/config/seo.php
@@ -84,6 +84,8 @@
         'contact.thanks' => 'お問い合わせ完了',
         // 認証フロー (Fortify)
         'login' => 'ログイン',
+        // 企業アカウントでのログイン (識別名の入力画面。T253)
+        'enterprise-sso.login' => '企業アカウントでログイン',
         'register' => 'アカウント登録',
         'password.request' => 'パスワードリセット',
         'password.reset' => 'パスワードリセット',
@@ -135,6 +137,8 @@
         'manage.users.index' => 'ユーザー管理',
         // API キー (organizations.api-keys.index — ApiKeys/Index.svelte h1「API キー」)
         'organizations.api-keys.index' => 'API キー',
+        // 企業 IdP との OIDC SSO 接続の管理 (T253)
+        'organizations.sso.index' => 'SSO 接続',
         // 接続セッション (organizations.api-keys.sessions.index — ApiKeys/Sessions.svelte h1「接続セッション」)
         'organizations.api-keys.sessions.index' => '接続セッション',
         // CLI 導入ガイド (organizations.onboarding.cli — Onboarding/Cli.svelte h1「CLI 導入ガイド」)
diff --git a/database/factories/EmailPromotionFactory.php b/database/factories/EmailPromotionFactory.php
new file mode 100644
index 00000000..7d72599e
--- /dev/null
+++ b/database/factories/EmailPromotionFactory.php
@@ -0,0 +1,42 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Models\EmailPromotion;
+use App\Models\User;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Facades\Config;
+
+/**
+ * @extends Factory<EmailPromotion>
+ */
+class EmailPromotionFactory extends Factory
+{
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'user_id' => User::factory(),
+            'token_fingerprint' => AttemptFingerprint::of(
+                FingerprintPurpose::EmailPromotionToken,
+                AttemptFingerprint::newSecret(),
+            ),
+            'email_encrypted' => fake()->unique()->safeEmail(),
+            'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.email_promotion.ttl_seconds')),
+        ];
+    }
+
+    /** 期限切れの昇格 (掃除・拒否の検査用)。 */
+    public function expired(): self
+    {
+        return $this->state(fn (): array => [
+            'expires_at' => now()->subMinute(),
+        ]);
+    }
+}
diff --git a/database/factories/EnterpriseIdentityFactory.php b/database/factories/EnterpriseIdentityFactory.php
new file mode 100644
index 00000000..480426c1
--- /dev/null
+++ b/database/factories/EnterpriseIdentityFactory.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Models\EnterpriseIdentity;
+use App\Models\OrganizationOidcConnection;
+use App\Models\User;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * @extends Factory<EnterpriseIdentity>
+ */
+class EnterpriseIdentityFactory extends Factory
+{
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_oidc_connection_id' => OrganizationOidcConnection::factory(),
+            'user_id' => User::factory(),
+            'subject' => 'sub-'.fake()->unique()->bothify('????????########'),
+            'claimed_email_encrypted' => fake()->safeEmail(),
+            'last_login_at' => null,
+        ];
+    }
+}
diff --git a/database/factories/EnterpriseSsoLoginAttemptFactory.php b/database/factories/EnterpriseSsoLoginAttemptFactory.php
new file mode 100644
index 00000000..acae9e4e
--- /dev/null
+++ b/database/factories/EnterpriseSsoLoginAttemptFactory.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Models\EnterpriseSsoLoginAttempt;
+use App\Models\OrganizationOidcConnection;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Illuminate\Database\Eloquent\Factories\Factory;
+use Illuminate\Support\Facades\Config;
+
+/**
+ * @extends Factory<EnterpriseSsoLoginAttempt>
+ */
+class EnterpriseSsoLoginAttemptFactory extends Factory
+{
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_oidc_connection_id' => OrganizationOidcConnection::factory(),
+            'state_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::State, AttemptFingerprint::newSecret()),
+            'nonce_fingerprint' => AttemptFingerprint::of(FingerprintPurpose::Nonce, AttemptFingerprint::newSecret()),
+            'browser_binding_fingerprint' => AttemptFingerprint::of(
+                FingerprintPurpose::BrowserBinding,
+                AttemptFingerprint::newSecret(),
+            ),
+            'pkce_verifier_encrypted' => AttemptFingerprint::newSecret(),
+            'expires_at' => now()->addSeconds(Config::integer('enterprise-sso.login_attempt.ttl_seconds')),
+        ];
+    }
+
+    /** 期限切れの試行 (掃除・拒否の検査用)。 */
+    public function expired(): self
+    {
+        return $this->state(fn (): array => [
+            'expires_at' => now()->subMinute(),
+        ]);
+    }
+}
diff --git a/database/factories/OrganizationOidcConnectionFactory.php b/database/factories/OrganizationOidcConnectionFactory.php
new file mode 100644
index 00000000..5daefbc1
--- /dev/null
+++ b/database/factories/OrganizationOidcConnectionFactory.php
@@ -0,0 +1,64 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories;
+
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use App\Models\Organization;
+use App\Models\OrganizationOidcConnection;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * @extends Factory<OrganizationOidcConnection>
+ */
+class OrganizationOidcConnectionFactory extends Factory
+{
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        $loginSlug = 'idp-'.fake()->unique()->bothify('??????##');
+
+        return [
+            'organization_id' => Organization::factory(),
+            'login_slug' => $loginSlug,
+            'display_name' => fake()->company(),
+            'issuer' => 'https://'.$loginSlug.'.idp.test',
+            'client_id' => 'client-'.fake()->bothify('##########'),
+            'client_secret_encrypted' => ConnectionSecret::fromPlaintext('secret-'.fake()->bothify('????????????')),
+            'status' => OidcConnectionStatus::Draft,
+            'verified_at' => null,
+            'credentials_revision' => 1,
+        ];
+    }
+
+    /** 確認済み (まだログインには使えない)。 */
+    public function verified(): self
+    {
+        return $this->state(fn (): array => [
+            'status' => OidcConnectionStatus::Verified,
+            'verified_at' => now(),
+        ]);
+    }
+
+    /** ログインに使える。 */
+    public function active(): self
+    {
+        return $this->state(fn (): array => [
+            'status' => OidcConnectionStatus::Active,
+            'verified_at' => now(),
+        ]);
+    }
+
+    /** 運営が止めた。 */
+    public function disabled(): self
+    {
+        return $this->state(fn (): array => [
+            'status' => OidcConnectionStatus::Disabled,
+            'verified_at' => now(),
+        ]);
+    }
+}
diff --git a/database/migrations/2026_08_23_001000_make_users_email_nullable.php b/database/migrations/2026_08_23_001000_make_users_email_nullable.php
new file mode 100644
index 00000000..95370e37
--- /dev/null
+++ b/database/migrations/2026_08_23_001000_make_users_email_nullable.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 企業 SSO でしか入れない利用者は**使えるメールを 1 件も持たない** (正典 v1 の always-JIT)。
+ *
+ * ★email の一意性は平文 unique ではなく blind_indexes の **partial unique** が担うため、
+ *   null 化しても一意性の担保は変わらない (null 行は blind index を持たない)。
+ * ★仮のメール文字列 (`sub@example.invalid` 等) は作らない —
+ *   偽のメールは衝突と誤送の温床であり、nOAuth の再現面と衝突する。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::table('users', function (Blueprint $table): void {
+            $table->text('email')->nullable()->change();
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::table('users', function (Blueprint $table): void {
+            $table->text('email')->nullable(false)->change();
+        });
+    }
+};
diff --git a/database/migrations/2026_08_23_001100_create_organization_oidc_connections_table.php b/database/migrations/2026_08_23_001100_create_organization_oidc_connections_table.php
new file mode 100644
index 00000000..5a8996e4
--- /dev/null
+++ b/database/migrations/2026_08_23_001100_create_organization_oidc_connections_table.php
@@ -0,0 +1,60 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\EnterpriseSso\OidcConnectionStatus;
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 組織の OIDC 接続。1 組織に複数の接続を許す (合併・複数 IdP の企業がある)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('organization_oidc_connections', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
+
+            // 公開のログイン導線 (/enterprise/{connection}/redirect) で使う識別名。
+            // ★**全体で一意**であり、**推測されてよい**。推測可能性に依存した防御を持たない —
+            //   防御は接続の状態 (Active か) と state / PKCE / ブラウザ結合が担う。
+            // ★列名を `slug` にしない。`organizations.slug` の書き込み経路を 1 本に絞る gate
+            //   (`OrganizationSlugWritePathTest`) は「`slug` 列を持つ表は organizations だけ」を
+            //   前提にキー名だけで表を特定している。同名の列を別の表に足すとその前提が崩れる。
+            $table->string('login_slug', 64)->unique();
+
+            $table->string('display_name', 100);
+
+            // 顧客が入力する。https 必須・userinfo/query/fragment なし・正規化できる絶対 URL。
+            $table->string('issuer', 255);
+            $table->string('client_id', 255);
+
+            // ★暗号化して保存する。読み出しは ConnectionSecret 値型を経由し、
+            //   平文の取り出しは token 交換だけが呼ぶ 1 メソッドに集約する。索引を持たせない。
+            $table->text('client_secret_encrypted');
+
+            $table->string('status', 16)->default(OidcConnectionStatus::Draft->value);
+            $table->timestamp('verified_at')->nullable();
+
+            // ★**認証材料の版**。issuer / client_id / client_secret のいずれかが変わるたびに +1 する。
+            //   用途は 1 つだけ — D1 の `verify` が「**外向き取得の間に認証材料が変わっていないこと**」を
+            //   ロックの中で確かめるための比較子である。
+            //   ★**`updated_at` で代用しない**: 時刻の精度で同一に見えうるうえ、
+            //     認証に関与しない表示名の更新まで巻き込んで verify を落とす。
+            $table->unsignedBigInteger('credentials_revision')->default(1);
+
+            $table->timestamps();
+
+            // 組織単位の検索用。
+            $table->index('organization_id');
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('organization_oidc_connections');
+    }
+};
diff --git a/database/migrations/2026_08_23_001200_create_enterprise_identities_table.php b/database/migrations/2026_08_23_001200_create_enterprise_identities_table.php
new file mode 100644
index 00000000..11758a2f
--- /dev/null
+++ b/database/migrations/2026_08_23_001200_create_enterprise_identities_table.php
@@ -0,0 +1,80 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * IdP の身元 (接続 × subject) と利用者の対応。
+ *
+ * ★**メールアドレスで利用者を引かない**。引き当ての鍵は
+ *   (organization_oidc_connection_id, 生の subject) だけである。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('enterprise_identities', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();
+            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
+
+            // ★IdP の subject。**これが身元の主キーである**。
+            //   照合を **COLLATE "C" (バイト単位)** に固定する — 既定の照合順序では
+            //   `Alice` と `alice` が同一視されうる環境があり、そうなると
+            //   **別人が同じアカウントに入る**。
+            //   ★指紋 (HMAC) にはしない。指紋は鍵に依存するので、APP_KEY をローテートすると
+            //     既存の身元へ到達できなくなり**アカウントが分裂する**。
+            $table->string('subject', 255)->collation('C');
+
+            // ★申告メール: 暗号化して持つが **索引を意図的に付けない**。
+            //   索引を付けると「メールで引ける」経路が実装として復活する。
+            //   blind index も付けない (configureCipherSweet で addBlindIndex を呼ばない)。
+            $table->text('claimed_email_encrypted')->nullable();
+
+            $table->timestamp('last_login_at')->nullable();
+            $table->timestamps();
+
+            // ★**最後の防波堤**である。競合制御の本体は C2 が張る接続の行ロックであり、
+            //   C1 はこの制約違反を**捕まえない**。
+            $table->unique(
+                ['organization_oidc_connection_id', 'subject'],
+                'enterprise_identities_connection_subject_unique',
+            );
+
+            $table->index('user_id');
+        });
+
+        // ★CHECK 制約は Blueprint に API が無いので**生 SQL で置く**。
+        //   pgsql 固定でよい (phpunit.xml が DB_CONNECTION=pgsql を force しており、テストも本番も pgsql)。
+        //   ★**制約名を明示する** — 違反したときに出所が一目で分かり、
+        //   スキーマ読み取りテストが `pg_constraint.conname` を名前で引ける。
+        //   ★pgsql の `varchar(255)` は 255 **文字**であってバイトではないので、
+        //   バイト長は CHECK で別に閉じる。
+        DB::statement(<<<'SQL'
+            ALTER TABLE enterprise_identities
+                ADD CONSTRAINT enterprise_identities_subject_octet_length_check
+                CHECK (octet_length(subject) BETWEEN 1 AND 255)
+            SQL);
+
+        // ★制御文字の禁止も **DB の不変条件に含める** (DTO だけの保証にしない)。
+        //   ★**名前を分ける** — 長さ違反と文字種違反を、違反の名前だけで切り分けられるようにする。
+        //   対象は C0 制御文字 (U+0001〜U+001F) と DEL (U+007F) **だけ**である
+        //   (U+0000 は pgsql の text/varchar に格納できないので書く必要が無い。
+        //    C1 制御文字と Unicode の書式文字は**対象外**で、これらは許す)。
+        DB::statement(<<<'SQL'
+            ALTER TABLE enterprise_identities
+                ADD CONSTRAINT enterprise_identities_subject_no_control_chars_check
+                CHECK (subject !~ E'[\\x01-\\x1F\\x7F]')
+            SQL);
+    }
+
+    public function down(): void
+    {
+        // 表ごと落とすので CHECK 制約も一緒に消える。
+        Schema::dropIfExists('enterprise_identities');
+    }
+};
diff --git a/database/migrations/2026_08_23_001300_create_enterprise_sso_login_attempts_table.php b/database/migrations/2026_08_23_001300_create_enterprise_sso_login_attempts_table.php
new file mode 100644
index 00000000..79dd334f
--- /dev/null
+++ b/database/migrations/2026_08_23_001300_create_enterprise_sso_login_attempts_table.php
@@ -0,0 +1,45 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * 企業 SSO のログイン試行。**state の使用権の唯一性**を DB の一意制約と行ロックで担保する
+ * (セッションドライバの種別と `->block()` の書き忘れに依存させない)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('enterprise_sso_login_attempts', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('organization_oidc_connection_id')->constrained()->cascadeOnDelete();
+
+            // state の **指紋だけ**を持つ (原文を保存しない)。一意制約が使用権の唯一性の根拠。
+            $table->char('state_fingerprint', 64)->unique();
+
+            // nonce も **指紋だけ**。ID トークンの nonce を同じ用途ラベルで指紋化して定時間比較する。
+            $table->char('nonce_fingerprint', 64);
+
+            // 開始したブラウザとの結合 (login CSRF を塞ぐ本体)。
+            // セッションへ置いた「結び付けの秘密」の指紋。**session ID は保存しない**。
+            $table->char('browser_binding_fingerprint', 64);
+
+            // PKCE の検証子だけは token 交換でそのまま送るので原文が要る → 暗号化して保存。
+            $table->text('pkce_verifier_encrypted');
+
+            $table->timestamp('expires_at');
+            $table->timestamps();
+
+            $table->index('expires_at');   // 期限切れ掃除の走査用
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('enterprise_sso_login_attempts');
+    }
+};
diff --git a/database/migrations/2026_08_23_001400_create_email_promotions_table.php b/database/migrations/2026_08_23_001400_create_email_promotions_table.php
new file mode 100644
index 00000000..9cf99c98
--- /dev/null
+++ b/database/migrations/2026_08_23_001400_create_email_promotions_table.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Database\Migrations\Migration;
+use Illuminate\Database\Schema\Blueprint;
+use Illuminate\Support\Facades\Schema;
+
+/**
+ * メールアドレスの昇格 (企業 SSO でしか入れない利用者が自分のメールを持つための確認待ち)。
+ */
+return new class extends Migration
+{
+    public function up(): void
+    {
+        Schema::create('email_promotions', function (Blueprint $table): void {
+            $table->id();
+            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
+
+            // ★トークンは **原文を保存せず指紋だけ** (用途ラベル EmailPromotionToken)。
+            //   一意制約が「一回だけ consume できる」の根拠。
+            $table->char('token_fingerprint', 64)->unique();
+
+            // 昇格しようとしているメール。**CipherSweet で暗号化する** (PII)。
+            // ★ここにも blind index を付けない — 確定するまでは users のメールではないので、
+            //   引き当てに使う理由が無い。
+            $table->text('email_encrypted');
+
+            $table->timestamp('expires_at');
+            $table->timestamps();
+
+            // ★利用者ごとの未消費は **1 件だけ**にする (再送で旧トークンが失効することの DB 側の担保)。
+            //   消費は行の削除なので、未消費 = 行が在ることである。
+            $table->unique('user_id', 'email_promotions_user_unique');
+
+            $table->index('expires_at');   // 期限切れ掃除の走査用
+        });
+    }
+
+    public function down(): void
+    {
+        Schema::dropIfExists('email_promotions');
+    }
+};
diff --git a/lang/ja/validation.php b/lang/ja/validation.php
index 298dc67e..5a0ce3f9 100644
--- a/lang/ja/validation.php
+++ b/lang/ja/validation.php
@@ -209,6 +209,16 @@
         'user_id' => '対象ユーザー',
         'abilities' => '権限',
         'abilities.*' => '権限',
+        // --- 企業 SSO 接続 (T253) ---
+        // 'slug' は組織の識別名と同名キーのため StoreSsoConnectionRequest::attributes() で個別に上書きする
+        'login_slug' => 'ログイン用の識別名',
+        'display_name' => '表示名',
+        'issuer' => '発行者 URL',
+        'client_id' => 'クライアント ID',
+        'client_secret' => 'クライアントシークレット',
+        'state' => '状態値',
+        'code' => '認可コード',
+        'error' => 'エラー',
         // --- 課金 ---
         'plan_code' => 'プラン',
         'declaration' => '個人利用の確認',
diff --git a/resources/js/components/features/sso/oidc-connection.ts b/resources/js/components/features/sso/oidc-connection.ts
new file mode 100644
index 00000000..a08e4cba
--- /dev/null
+++ b/resources/js/components/features/sso/oidc-connection.ts
@@ -0,0 +1,56 @@
+/**
+ * 企業 OIDC 接続の状態の値域と表示語彙。
+ *
+ * PHP 側 `App\Enums\EnterpriseSso\OidcConnectionStatus` と対で保守する
+ * (値集合の一致は `tests/js/architecture/enum-ts-sync.test.ts` が機械で固定する)。
+ *
+ * ★画面が値で分岐するのは**この 4 値だけ**である。`credentials_revision` は
+ *   D1 の内部の比較子なので props にも本ファイルにも現れない。
+ */
+
+/** 接続の状態 (App\Enums\EnterpriseSso\OidcConnectionStatus と対)。 */
+export type OidcConnectionStatus = "draft" | "verified" | "active" | "disabled";
+
+/** 画面に出す日本語ラベル。 */
+export const OIDC_CONNECTION_STATUS_LABELS: Record<OidcConnectionStatus, string> = {
+    draft: "未確認",
+    verified: "確認済み",
+    active: "有効",
+    disabled: "無効",
+};
+
+/** 状態バッジの色調 (Badge atom の tone 語彙)。 */
+export const OIDC_CONNECTION_STATUS_TONES: Record<
+    OidcConnectionStatus,
+    "neutral" | "info" | "success" | "warning"
+> = {
+    draft: "neutral",
+    verified: "info",
+    active: "success",
+    disabled: "warning",
+};
+
+/** 画面に出す 1 行の説明 (次に何をすればよいかを述べる)。 */
+export const OIDC_CONNECTION_STATUS_HINTS: Record<OidcConnectionStatus, string> = {
+    draft: "接続先情報をまだ確認していません。「確認」を押してください。",
+    verified: "確認済みです。「有効化」を押すとこの IdP でログインできるようになります。",
+    active: "この IdP でログインできます。",
+    disabled: "停止中です。「有効化」で再開できます (登録済みの利用者はそのまま戻ります)。",
+};
+
+/** 企業 SSO の接続 1 件分 (PHP 側 SsoConnectionSummary と対で保守する)。 */
+export interface SsoConnectionSummary {
+    id: number;
+    /** 公開のログイン導線で使う識別名。推測されてよい。 */
+    loginSlug: string;
+    displayName: string;
+    issuer: string;
+    clientId: string;
+    status: OidcConnectionStatus;
+    /** 秘密が保存されているか。★平文も伏字も渡らない (一覧の経路は復号しない)。 */
+    hasClientSecret: boolean;
+    /** ISO8601 (オフセット付き) / 一度も確認できていなければ null。 */
+    verifiedAt: string | null;
+    /** 身元が 1 件でもあるか (削除と issuer / client_id の変更ができるかの判断に使う)。 */
+    hasIdentities: boolean;
+}
diff --git a/resources/js/pages/Admin/Users.svelte b/resources/js/pages/Admin/Users.svelte
index 96e0a7d8..38f0c58c 100644
--- a/resources/js/pages/Admin/Users.svelte
+++ b/resources/js/pages/Admin/Users.svelte
@@ -312,8 +312,10 @@
                                             </Badge>
                                         {/if}
                                     </div>
+                                    <!-- 企業 SSO でしか入れない利用者は使えるメールを持たない (T253 / A3)。
+                                         空文字へ畳まず「メールなし」と明示する -->
                                     <p class="truncate text-caption text-text-secondary">
-                                        {member.email}
+                                        {member.email ?? "メールなし"}
                                     </p>
                                     <!-- 最終ログイン。値の無い行は「記録なし」(「未ログイン」と断定しない —
                                          導出元の security_audit_events は保持期間が未確定で、将来 purge されうるため)。
diff --git a/resources/js/pages/Auth/EnterpriseLogin.svelte b/resources/js/pages/Auth/EnterpriseLogin.svelte
new file mode 100644
index 00000000..cd279c70
--- /dev/null
+++ b/resources/js/pages/Auth/EnterpriseLogin.svelte
@@ -0,0 +1,98 @@
+<script lang="ts">
+    /**
+     * 企業アカウントでのログインの入口。
+     *
+     * ★組織から配られた**識別名**を入れて開始するだけの画面である。
+     *   外向き通信も DB の変更もここでは起きない (開始は次の GET 導線が行う)。
+     * ★開始導線は **GET の anchor リンク**である (form POST にしない —
+     *   CSP form-action がリダイレクト先の IdP に適用されてブロックされる)。
+     * ★識別名が空でもボタンを押せる。押した時にエラーを表示する (禁止事項 8)。
+     */
+    import { page } from "@inertiajs/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import { Building2 } from "@lucide/svelte";
+    import type { SharedProps } from "@/lib/shared-props";
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    let connectionSlug = $state("");
+    let localError = $state<string | null>(null);
+
+    /** 識別名の書式 (サーバ側の登録規則と同じ形。ここでの判定は入力の手当てである)。 */
+    const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]*[a-z0-9]$/;
+
+    function start(event: MouseEvent): void {
+        const value = connectionSlug.trim();
+
+        if (value === "") {
+            event.preventDefault();
+            localError = "組織から配られた識別名を入力してください。";
+            return;
+        }
+
+        if (!SLUG_PATTERN.test(value)) {
+            event.preventDefault();
+            localError = "識別名は英小文字・数字・ハイフンで入力してください。";
+            return;
+        }
+
+        localError = null;
+    }
+
+    const href = $derived(
+        connectionSlug.trim() === ""
+            ? "#"
+            : `/enterprise/${encodeURIComponent(connectionSlug.trim())}/redirect`,
+    );
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="企業アカウントでログイン"
+            description="組織から配られた識別名を入力すると、勤務先の ID プロバイダへ移動します。"
+            icon={Building2}
+            testId="enterprise-login-heading"
+        />
+        <PageContent>
+            <Card padding="lg">
+                <FormField label="識別名" id="enterprise-connection-slug" error={localError}>
+                    {#snippet children({ id, describedBy, invalid })}
+                        <Input
+                            {id}
+                            type="text"
+                            bind:value={connectionSlug}
+                            error={invalid}
+                            aria-describedby={describedBy}
+                            autocomplete="off"
+                            testId="enterprise-connection-slug"
+                        />
+                    {/snippet}
+                </FormField>
+
+                <p class="mt-2 text-caption text-text-secondary">
+                    識別名が分からない場合は、組織の管理者にお問い合わせください。
+                </p>
+
+                <div class="mt-4 flex items-center justify-between gap-4">
+                    <TextLink href="/login" testId="enterprise-login-back">
+                        通常のログインに戻る
+                    </TextLink>
+                    <!-- 開始は GET の anchor リンク (form POST にしない) -->
+                    <Button {href} onclick={start} testId="enterprise-login-start">
+                        次へ進む
+                    </Button>
+                </div>
+            </Card>
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/pages/Organizations/Sso/Index.svelte b/resources/js/pages/Organizations/Sso/Index.svelte
new file mode 100644
index 00000000..1edae563
--- /dev/null
+++ b/resources/js/pages/Organizations/Sso/Index.svelte
@@ -0,0 +1,410 @@
+<script lang="ts">
+    /**
+     * 企業 IdP との OIDC SSO 接続の管理 (一覧 + 登録・更新フォーム)。
+     *
+     * ★画面は **1 枚**である。**接続の秘密を扱う前面を 2 枚に割らない** (正典 v1 / I4)。
+     * ★サーバから渡ってくるのは `hasClientSecret` の真偽だけで、
+     *   **平文も伏字も渡らない** (一覧の経路はサーバ側でも復号しない)。
+     * ★**必須条件が未充足でもボタンを disabled にしない**。押した時にエラーを表示する
+     *   (禁止事項 8)。身元がある接続の削除・発行者 URL の変更も「押せるが拒否される」形にし、
+     *   拒否の理由はサーバの応答としてエラー表示に出す。
+     */
+    import { page, router, useForm } from "@inertiajs/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import EmptyState from "@/components/molecules/EmptyState.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import PageHeader from "@/components/molecules/PageHeader.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import Modal from "@/components/organisms/Modal.svelte";
+    import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
+    import AppLayout from "@/components/templates/AppLayout.svelte";
+    import PageContainer from "@/components/templates/PageContainer.svelte";
+    import PageContent from "@/components/templates/PageContent.svelte";
+    import {
+        OIDC_CONNECTION_STATUS_HINTS,
+        OIDC_CONNECTION_STATUS_LABELS,
+        OIDC_CONNECTION_STATUS_TONES,
+        type SsoConnectionSummary,
+    } from "@/components/features/sso/oidc-connection";
+    import { withRecentAuth, type RecentAuthStatus } from "@/lib/recent-auth";
+    import type { SharedProps } from "@/lib/shared-props";
+    import { orgUrl } from "@/lib/org-url";
+    import { ShieldCheck } from "@lucide/svelte";
+
+    interface Props {
+        organization: { id: number; name: string; slug: string };
+        connections: SsoConnectionSummary[];
+        callbackUrl: string;
+    }
+
+    let { organization, connections, callbackUrl }: Props = $props();
+
+    const shared = $derived(page.props as unknown as SharedProps);
+    const appName = $derived(shared.appName ?? "");
+
+    /* ---- recent-auth (step-up) precheck ---- */
+    let recentAuthOpen = $state(false);
+    let recentAuthStatus = $state<RecentAuthStatus | null>(null);
+    let pendingAction: (() => void) | null = null;
+
+    function guardWithRecentAuth(action: () => void): void {
+        void withRecentAuth({
+            onFresh: action,
+            onStale: (status) => {
+                recentAuthStatus = status;
+                pendingAction = action;
+                recentAuthOpen = true;
+            },
+        });
+    }
+
+    function resumePendingAction(): void {
+        const action = pendingAction;
+        pendingAction = null;
+        action?.();
+    }
+
+    /* ---- 登録 ---- */
+    let registerModalOpen = $state(false);
+    const registerForm = useForm({
+        login_slug: "",
+        display_name: "",
+        issuer: "",
+        client_id: "",
+        client_secret: "",
+    });
+
+    function submitRegister(event: SubmitEvent): void {
+        event.preventDefault();
+        guardWithRecentAuth(() => {
+            registerForm.post(orgUrl(organization.slug, "/sso"), {
+                preserveScroll: true,
+                onSuccess: () => {
+                    registerForm.reset();
+                    registerModalOpen = false;
+                },
+            });
+        });
+    }
+
+    /* ---- 更新 ---- */
+    let editTarget = $state<SsoConnectionSummary | null>(null);
+    let editModalOpen = $state(false);
+    const editForm = useForm({
+        display_name: "",
+        issuer: "",
+        client_id: "",
+        client_secret: "",
+    });
+
+    function openEdit(connection: SsoConnectionSummary): void {
+        editTarget = connection;
+        editForm.display_name = connection.displayName;
+        editForm.issuer = connection.issuer;
+        editForm.client_id = connection.clientId;
+        // ★秘密は**空**で開く。空のまま送れば据え置きである (伏字を送らない)。
+        editForm.client_secret = "";
+        editModalOpen = true;
+    }
+
+    function submitEdit(event: SubmitEvent): void {
+        event.preventDefault();
+        const target = editTarget;
+        if (target === null) return;
+        guardWithRecentAuth(() => {
+            editForm.patch(orgUrl(organization.slug, `/sso/${target.id}`), {
+                preserveScroll: true,
+                onSuccess: () => {
+                    editModalOpen = false;
+                },
+            });
+        });
+    }
+
+    /* ---- 状態を変える操作 ---- */
+    let busyConnectionId = $state<number | null>(null);
+
+    function postAction(connection: SsoConnectionSummary, action: string): void {
+        guardWithRecentAuth(() => {
+            router.post(
+                orgUrl(organization.slug, `/sso/${connection.id}/${action}`),
+                {},
+                {
+                    preserveScroll: true,
+                    onStart: () => {
+                        busyConnectionId = connection.id;
+                    },
+                    onFinish: () => {
+                        busyConnectionId = null;
+                    },
+                },
+            );
+        });
+    }
+
+    /* ---- 削除 ---- */
+    let deleteTarget = $state<SsoConnectionSummary | null>(null);
+    let deleteDialogOpen = $state(false);
+    let deleting = $state(false);
+
+    function openDelete(connection: SsoConnectionSummary): void {
+        deleteTarget = connection;
+        deleteDialogOpen = true;
+    }
+
+    function deleteConnection(): void {
+        const target = deleteTarget;
+        if (target === null) return;
+        guardWithRecentAuth(() => {
+            router.delete(orgUrl(organization.slug, `/sso/${target.id}`), {
+                preserveScroll: true,
+                onStart: () => {
+                    deleting = true;
+                },
+                onFinish: () => {
+                    deleting = false;
+                    deleteDialogOpen = false;
+                },
+            });
+        });
+    }
+
+    const errors = $derived(shared.errors ?? {});
+    const connectionError = $derived(
+        typeof errors.sso_connection === "string" ? errors.sso_connection : null,
+    );
+</script>
+
+<AppLayout {appName}>
+    <PageContainer>
+        <PageHeader
+            title="SSO 接続"
+            description={`${organization.name} のメンバーが勤務先の ID プロバイダ (IdP) でログインできるようにします。`}
+            icon={ShieldCheck}
+            testId="sso-connections-heading"
+        />
+        <PageContent>
+            <div class="flex flex-col gap-6">
+                {#if connectionError}
+                    <div
+                        class="rounded-md border border-danger bg-danger/10 p-4 text-body text-text"
+                        role="alert"
+                        data-testid="sso-connection-error"
+                    >
+                        {connectionError}
+                    </div>
+                {/if}
+
+                <Card padding="lg">
+                    <div class="flex items-start justify-between gap-4">
+                        <div class="min-w-0">
+                            <h2 class="text-h3">登録済みの接続</h2>
+                            <p class="mt-1 text-caption text-text-secondary">
+                                IdP 側には戻り先として <code class="font-mono">{callbackUrl}</code> を登録してください。
+                            </p>
+                        </div>
+                        <Button size="sm" onclick={() => (registerModalOpen = true)} testId="register-sso-button">
+                            接続を登録
+                        </Button>
+                    </div>
+
+                    {#if connections.length === 0}
+                        <EmptyState
+                            title="SSO 接続はありません"
+                            description="登録した接続はここに表示されます。"
+                        />
+                    {:else}
+                        <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="sso-connection-list">
+                            {#each connections as connection (connection.id)}
+                                <li class="flex flex-col gap-3 py-4">
+                                    <div class="flex flex-wrap items-center gap-2">
+                                        <p class="truncate text-body">{connection.displayName}</p>
+                                        <Badge tone={OIDC_CONNECTION_STATUS_TONES[connection.status]}>
+                                            {OIDC_CONNECTION_STATUS_LABELS[connection.status]}
+                                        </Badge>
+                                        {#if connection.hasIdentities}
+                                            <Badge tone="neutral">利用者あり</Badge>
+                                        {/if}
+                                    </div>
+
+                                    <p class="text-caption text-text-secondary">
+                                        {OIDC_CONNECTION_STATUS_HINTS[connection.status]}
+                                    </p>
+
+                                    <dl class="grid grid-cols-1 gap-1 text-caption text-text-secondary sm:grid-cols-2">
+                                        <div class="flex min-w-0 gap-2">
+                                            <dt class="shrink-0">ログイン用の識別名</dt>
+                                            <dd class="truncate font-mono">{connection.loginSlug}</dd>
+                                        </div>
+                                        <div class="flex min-w-0 gap-2">
+                                            <dt class="shrink-0">発行者 URL</dt>
+                                            <dd class="truncate font-mono">{connection.issuer}</dd>
+                                        </div>
+                                        <div class="flex min-w-0 gap-2">
+                                            <dt class="shrink-0">クライアント ID</dt>
+                                            <dd class="truncate font-mono">{connection.clientId}</dd>
+                                        </div>
+                                        <div class="flex min-w-0 gap-2">
+                                            <dt class="shrink-0">シークレット</dt>
+                                            <dd>{connection.hasClientSecret ? "登録済み" : "未登録"}</dd>
+                                        </div>
+                                    </dl>
+
+                                    <div class="flex flex-wrap items-center gap-2">
+                                        <Button
+                                            variant="ghost"
+                                            size="sm"
+                                            onclick={() => openEdit(connection)}
+                                            testId={`edit-sso-${connection.id}`}
+                                        >
+                                            編集
+                                        </Button>
+                                        <Button
+                                            variant="ghost"
+                                            size="sm"
+                                            loading={busyConnectionId === connection.id}
+                                            onclick={() => postAction(connection, "verify")}
+                                            testId={`verify-sso-${connection.id}`}
+                                        >
+                                            確認
+                                        </Button>
+                                        <Button
+                                            variant="ghost"
+                                            size="sm"
+                                            loading={busyConnectionId === connection.id}
+                                            onclick={() => postAction(connection, "activate")}
+                                            testId={`activate-sso-${connection.id}`}
+                                        >
+                                            有効化
+                                        </Button>
+                                        <Button
+                                            variant="ghost"
+                                            size="sm"
+                                            loading={busyConnectionId === connection.id}
+                                            onclick={() => postAction(connection, "disable")}
+                                            testId={`disable-sso-${connection.id}`}
+                                        >
+                                            無効化
+                                        </Button>
+                                        <Button
+                                            variant="danger-ghost"
+                                            size="sm"
+                                            onclick={() => openDelete(connection)}
+                                            testId={`delete-sso-${connection.id}`}
+                                        >
+                                            削除
+                                        </Button>
+                                    </div>
+                                </li>
+                            {/each}
+                        </ul>
+                    {/if}
+                </Card>
+            </div>
+
+            <Modal bind:open={registerModalOpen} title="SSO 接続を登録" testId="register-sso-modal">
+                <form novalidate onsubmit={submitRegister} class="flex flex-col gap-4">
+                    <FormField label="識別名" id="sso-slug" error={registerForm.errors.login_slug}
+                        help="ログイン導線の URL に使う名前です。英小文字・数字・ハイフンで入力してください。">
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={registerForm.login_slug} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-slug" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="表示名" id="sso-display-name" error={registerForm.errors.display_name}>
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={registerForm.display_name} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-display-name" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="発行者 URL" id="sso-issuer" error={registerForm.errors.issuer}
+                        help="IdP が示す issuer をそのまま入力してください (末尾のスラッシュも区別されます)。">
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={registerForm.issuer} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-issuer" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="クライアント ID" id="sso-client-id" error={registerForm.errors.client_id}>
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={registerForm.client_id} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-client-id" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="クライアントシークレット" id="sso-client-secret"
+                        error={registerForm.errors.client_secret}>
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="password" bind:value={registerForm.client_secret} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-client-secret" />
+                        {/snippet}
+                    </FormField>
+                    <div class="flex justify-end">
+                        <Button type="submit" loading={registerForm.processing} testId="register-sso-submit">
+                            登録する
+                        </Button>
+                    </div>
+                </form>
+            </Modal>
+
+            <Modal bind:open={editModalOpen} title="SSO 接続を編集" testId="edit-sso-modal">
+                <form novalidate onsubmit={submitEdit} class="flex flex-col gap-4">
+                    <FormField label="表示名" id="sso-edit-display-name" error={editForm.errors.display_name}>
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={editForm.display_name} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-display-name" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="発行者 URL" id="sso-edit-issuer" error={editForm.errors.issuer}
+                        help="利用者が 1 人でもログインした接続では変更できません (新しい接続を作成してください)。">
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={editForm.issuer} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-issuer" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="クライアント ID" id="sso-edit-client-id" error={editForm.errors.client_id}>
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="text" bind:value={editForm.client_id} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-client-id" />
+                        {/snippet}
+                    </FormField>
+                    <FormField label="クライアントシークレット" id="sso-edit-client-secret"
+                        error={editForm.errors.client_secret}
+                        help="変更するときだけ入力してください。空のままなら現在の値を保ちます。">
+                        {#snippet children({ id, describedBy, invalid })}
+                            <Input {id} type="password" bind:value={editForm.client_secret} error={invalid}
+                                aria-describedby={describedBy} autocomplete="off" testId="sso-edit-client-secret" />
+                        {/snippet}
+                    </FormField>
+                    <p class="text-caption text-text-secondary">
+                        発行者 URL・クライアント ID・シークレットのいずれかを変えると、接続は「未確認」に戻ります。
+                        もう一度「確認」と「有効化」を行ってください。
+                    </p>
+                    <div class="flex justify-end">
+                        <Button type="submit" loading={editForm.processing} testId="edit-sso-submit">
+                            更新する
+                        </Button>
+                    </div>
+                </form>
+            </Modal>
+
+            <ConfirmDialog
+                bind:open={deleteDialogOpen}
+                title="SSO 接続の削除"
+                message={`${deleteTarget?.displayName ?? ""} を削除しますか？ この IdP でログインした利用者がいる場合は削除できません (「無効化」を使ってください)。`}
+                confirmLabel="削除する"
+                confirmVariant="danger"
+                processing={deleting}
+                onConfirm={deleteConnection}
+                testId="delete-sso-dialog"
+            />
+
+            <RecentAuthModal
+                bind:open={recentAuthOpen}
+                status={recentAuthStatus}
+                onConfirmed={resumePendingAction}
+            />
+        </PageContent>
+    </PageContainer>
+</AppLayout>
diff --git a/resources/js/types/admin.ts b/resources/js/types/admin.ts
index 61425cd0..93b14a14 100644
--- a/resources/js/types/admin.ts
+++ b/resources/js/types/admin.ts
@@ -12,7 +12,11 @@ export type MemberRoleState = ConsoleRole | "owner" | "unassigned";
 export interface MemberRow {
     id: number;
     name: string;
-    email: string;
+    /**
+     * CipherSweet 復号後のメールアドレス。
+     * 企業 SSO でしか入れない利用者は使えるメールを持たないため null になりうる (T253 / A3)。
+     */
+    email: string | null;
     roleState: MemberRoleState;
     roleLabel: string;
     twoFactorStatus: "disabled" | "pending" | "enabled";
diff --git a/resources/views/auth/email-promotion/confirm.blade.php b/resources/views/auth/email-promotion/confirm.blade.php
new file mode 100644
index 00000000..3a923b3e
--- /dev/null
+++ b/resources/views/auth/email-promotion/confirm.blade.php
@@ -0,0 +1,53 @@
+{{-- メール昇格の確認画面。**standalone Blade** (Inertia / Vite に依存しない)。
+   形の先例は resources/views/mcp/authorize.blade.php。
+
+   ★Inertia を使わないのは、Inertia が page object を history.state へ載せるためである。
+     prop へ置いた瞬間に**トークンがブラウザの履歴に残る**。encryptHistory() で緩和はできるが、
+     それは「履歴の暗号化に依存する」ことになる。Blade なら**そもそも履歴に載らない**。
+
+   ★DESIGN.md の「生 CSS / inline style 禁止」は Vite/Tailwind パイプラインに乗る Svelte
+     コンポーネントへの規約であり、本 blade はそのパイプラインに依存できないため inline CSS が
+     正当な例外である (errors/layout.blade.php / legal/layout.blade.php / mcp/authorize.blade.php
+     と同じ扱い)。色は DS token を参照できないため**ニュートラルなプレースホルダを hex 直書き**で
+     固定する (新しいパレットを作らない)。
+
+   ★トークンの有効・無効で画面を変えない (一様。存在の探り当てを作らない)。
+   ★外部リソースを一切読み込まない (@vite なし・外部 CSS / フォント / 画像 / リンクなし)。
+--}}
+<!DOCTYPE html>
+<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
+<head>
+    <meta charset="utf-8">
+    <meta name="viewport" content="width=device-width, initial-scale=1">
+    {{-- ★この document からの Referer を止める。
+         ヘッダで上書きしない理由: SecurityHeaders は web group の middleware で、
+         route middleware より外側から Referrer-Policy を無条件に set するため、
+         route 側で立てても後から上書きされる。document 側で閉じる。 --}}
+    <meta name="referrer" content="no-referrer">
+    <meta name="robots" content="noindex">
+    <title>メールアドレスの確認 | {{ config('app.name') }}</title>
+    <style>
+        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; background: #f9fafb; margin: 0; padding: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
+        .card { max-width: 32rem; width: 100%; background: #fff; padding: 2rem; border-radius: 0.5rem; box-shadow: 0 1px 3px rgba(0,0,0,0.12); box-sizing: border-box; }
+        h1 { font-size: 1.25rem; font-weight: 700; margin: 0 0 0.5rem; color: #111827; }
+        p { font-size: 0.875rem; color: #374151; margin: 0.5rem 0 0; }
+        .muted { color: #6b7280; }
+        button { margin-top: 1.5rem; width: 100%; padding: 0.625rem 1rem; border-radius: 0.375rem; font-size: 0.875rem; font-weight: 600; border: none; cursor: pointer; background: #2563eb; color: #fff; }
+        button:hover { background: #1d4ed8; }
+    </style>
+</head>
+<body>
+<div class="card">
+    <h1>メールアドレスの確認</h1>
+    <p>下のボタンを押すと、このメールアドレスがアカウントに登録されます。</p>
+    <p class="muted">心当たりがない場合は、このまま画面を閉じてください (押さなければ何も起きません)。</p>
+
+    <form method="POST" action="{{ route('settings.email-promotion.confirm') }}">
+        @csrf
+        {{-- ★サーバが描画した hidden。Inertia の props にも history.state にも載らない --}}
+        <input type="hidden" name="token" value="{{ $token }}">
+        <button type="submit" data-testid="email-promotion-confirm-submit">このメールアドレスを確定する</button>
+    </form>
+</div>
+</body>
+</html>
diff --git a/resources/views/emails/auth/email-promotion.blade.php b/resources/views/emails/auth/email-promotion.blade.php
new file mode 100644
index 00000000..107a78fc
--- /dev/null
+++ b/resources/views/emails/auth/email-promotion.blade.php
@@ -0,0 +1,10 @@
+{{-- メールアドレス昇格の確認メール (テキスト)。
+     ★載せるのは確認画面の URL だけである (宛先のアドレスも利用者の名前も載せない)。 --}}
+{{ config('app.name') }} でメールアドレスの登録手続きが行われました。
+
+次のリンクを開き、表示された画面で「確定する」を押すと登録が完了します。
+
+{{ $confirmUrl }}
+
+このリンクは 60 分で無効になり、一度しか使えません。
+心当たりがない場合は、このメールを破棄してください (リンクを開かなければ何も起きません)。
diff --git a/routes/console.php b/routes/console.php
index 3fd1ba40..d88254ba 100644
--- a/routes/console.php
+++ b/routes/console.php
@@ -215,3 +215,20 @@
 | 「初回を能動的に完走させて結果を確認する」ためのもので、schedule の抑止ではない)。
 */
 Schedule::command('billing:purge-retention-expired --apply')->daily()->onOneServer();
+
+/*
+|--------------------------------------------------------------------------
+| 企業 SSO の一時状態の掃除 (T253)
+|--------------------------------------------------------------------------
+| どちらも**期限の決着**であって滞留の前進ではない (回収の入口 work:recover-stuck には載せない)。
+|
+| - enterprise-sso:prune-login-attempts: 期限切れのログイン試行。callback の
+|   オンアクセス掃除と二段構えで、こちらが「二度と戻ってこなかった試行」の受け皿である。
+| - auth:prune-email-promotions: 期限切れのメール昇格の確認待ち。利用者ごとに 1 行しか
+|   持てないため、消さないとその利用者は**二度と昇格を始められない**。
+|
+| **監視対象**: 両コマンドの終了コード。削除件数が上限 (prune_chunk) に張り付き続けるなら
+| 発行の側が想定より多い (掃除が追いついていない)。
+*/
+Schedule::command('enterprise-sso:prune-login-attempts')->daily()->onOneServer();
+Schedule::command('auth:prune-email-promotions')->daily()->onOneServer();
diff --git a/routes/web.php b/routes/web.php
index 00d705e0..310dd400 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -4,6 +4,8 @@
 
 use App\Http\Controllers\Admin\UserManagementController;
 use App\Http\Controllers\Auth\ConfirmRecentAuthController;
+use App\Http\Controllers\Auth\EmailPromotionController;
+use App\Http\Controllers\Auth\EnterpriseSsoLoginController;
 use App\Http\Controllers\Auth\SessionStatusController;
 use App\Http\Controllers\Auth\SocialAuthController;
 use App\Http\Controllers\Billing\BillingController;
@@ -33,6 +35,7 @@
 use App\Http\Controllers\Organizations\OrganizationOnboardingController;
 use App\Http\Controllers\Organizations\OrganizationOwnershipController;
 use App\Http\Controllers\Organizations\OrganizationSlugController;
+use App\Http\Controllers\Organizations\OrganizationSsoConnectionController;
 use App\Http\Controllers\Projects\CategoryController;
 use App\Http\Controllers\Projects\CutTakeController;
 use App\Http\Controllers\Projects\ItemController;
@@ -177,6 +180,38 @@
     ->middleware('throttle:social-callback')
     ->name('social.callback');
 
+/*
+|--------------------------------------------------------------------------
+| エンタープライズ OIDC SSO (組織 OIDC 接続 + always-JIT)
+|--------------------------------------------------------------------------
+| 開始導線は GET の anchor リンク (form POST にしない。CSP form-action が
+| リダイレクト先 IdP に適用されるため。social.redirect と同じ理由)。
+|
+| ★**この経路にアプリ側の 2 要素認証を挟まない** (家系の裁定 AG-200)。
+|   確認できた時点でログインを確定させる。組織義務づけの強制は別関門
+|   (RequireTwoFactorForEnforcedOrganizations) が**ログイン確定後**に
+|   アカウント全体のゲートとして担い、転送先は 2 要素の**設定ページ**である。
+*/
+Route::get('/enterprise/login', [EnterpriseSsoLoginController::class, 'show'])
+    ->name('enterprise-sso.login');
+
+// ★GET だが **DB に試行の行を作る変更操作**である (OAuth の開始)。
+//   CSRF トークンの代わりに state・ブラウザ結合・流量制限・no-store が守る。
+//   {connection} は**全体で一意な公開の識別名**であり組織に属さない。
+//   PublicOidcConnectionBinder が「不在の識別名」と「使えない接続」を同じ 404 に畳み、
+//   missing() がそれを**利用者向けの一様な案内**へ変える (実在オラクルを作らない)。
+Route::get('/enterprise/{connection}/redirect', [EnterpriseSsoLoginController::class, 'redirect'])
+    ->middleware(['throttle:enterprise-sso-start', 'no-store'])
+    ->missing(fn () => redirect()->route('enterprise-sso.login')->withErrors([
+        'enterprise_sso' => '企業アカウントでのログインを開始できませんでした。識別名を確認するか、組織の管理者にお問い合わせください。',
+    ]))
+    ->name('enterprise-sso.redirect');
+
+// 戻り口。**未認証で外部へ HTTP を発射する経路**である (discovery + token 交換 + JWKS)。
+Route::get('/enterprise/callback', [EnterpriseSsoLoginController::class, 'callback'])
+    ->middleware(['throttle:enterprise-sso-callback', 'no-store'])
+    ->name('enterprise-sso.callback');
+
 /*
 |--------------------------------------------------------------------------
 | 認証済み
@@ -232,6 +267,37 @@
     // 2FA / ソーシャル連携 / パスキーの管理面 (passkey 一覧の組み立てに DI が要るため Controller)
     Route::get('/settings/security', SecurityController::class)->name('settings.security');
 
+    /*
+    | メールアドレスの昇格 (T253 / E1)。企業 SSO でしか入れない利用者が自分のメールを持つための救済。
+    |
+    | ★**すべて認証済み group の内側**に置く (auth を書き忘れると未認証で叩ける経路になる)。
+    | ★認可は「自分の資源」なので Gate を通さない (controller が Auth::id() だけを使う)。
+    |   ControllerAuthorizationGateTest の exemption へ理由付きで登録する。
+    | ★発行・再送は **認証手段を増やす操作**なので step-up (recent-auth) 必須
+    |   (settings.password.store と同水準)。
+    | ★**確認 (confirm) には recent-auth を足さない** — 救済の性格であり、
+    |   関門を足すと確定できず詰む (退会予約の取消を allowlist に入れないのと同じ判断)。
+    */
+    Route::post('/settings/email-promotion', [EmailPromotionController::class, 'store'])
+        ->middleware(['recent-auth', 'throttle:email-promotion'])
+        ->name('settings.email-promotion.store');
+
+    Route::post('/settings/email-promotion/resend', [EmailPromotionController::class, 'resend'])
+        ->middleware(['recent-auth', 'throttle:email-promotion'])
+        ->name('settings.email-promotion.resend');
+
+    // ★メールのリンクが開く**確認画面** (GET)。**状態を変えない**。
+    //   トークンを画面へ渡し、利用者が明示のボタンで POST する。
+    //   メールクライアントの先読み・プレビューでは**この画面が開くだけ**で確定しない。
+    Route::get('/settings/email-promotion/confirm', [EmailPromotionController::class, 'showConfirm'])
+        ->middleware(['throttle:email-promotion-confirm', 'no-store'])
+        ->name('settings.email-promotion.confirm.show');
+
+    // 確定 (POST のみ)。
+    Route::post('/settings/email-promotion/confirm', [EmailPromotionController::class, 'confirm'])
+        ->middleware(['throttle:email-promotion-confirm', 'no-store'])
+        ->name('settings.email-promotion.confirm');
+
     // アカウント削除 (即時・取り消せない) は step-up (recent-auth) 必須。
     // 猶予期間つきの予約 (下記) が UI の主導線で、こちらは**副導線として併存**させる
     // (標準形 v1 は「猶予つき予約と即時削除の両方」を必須にしている)。
@@ -303,6 +369,45 @@
             ->middleware('recent-auth')
             ->name('organizations.members.two-factor.reset');
     });
+    /*
+    | 企業 IdP との OIDC SSO 接続 (T253)。境界は OrganizationPolicy::update と同じ owner / admin で、
+    | **閲覧も含めて**管理者に限る (issuer と client_id が見えるため一覧だけ緩めない)。
+    |
+    | ★登録・更新は**接続の秘密を扱う唯一の前面**である (正典 v1 / I4)。
+    | ★{oidcConnection} は Organization::oidcConnections() 経由で scopeBindings が解決する。
+    |   親に属さない id は **binding 段で 404** (認可より前。不変条件 2 / 10)。
+    | ★確認 (verify) は**外向きの取得を伴う唯一の管理操作**なので専用の流量制限を持つ
+    |   (他の管理操作と bucket を共有しない = IdP が遅いときの連打で一覧や有効化を止めない)。
+    */
+    Route::get('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'index'])
+        ->name('organizations.sso.index');
+
+    Route::scopeBindings()->group(function (): void {
+        Route::post('/organizations/{organization:slug}/sso', [OrganizationSsoConnectionController::class, 'store'])
+            ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
+            ->name('organizations.sso.store');
+
+        Route::patch('/organizations/{organization:slug}/sso/{oidcConnection}', [OrganizationSsoConnectionController::class, 'update'])
+            ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
+            ->name('organizations.sso.update');
+
+        Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/verify', [OrganizationSsoConnectionController::class, 'verify'])
+            ->middleware(['recent-auth', 'throttle:enterprise-sso-verify'])
+            ->name('organizations.sso.verify');
+
+        Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/activate', [OrganizationSsoConnectionController::class, 'activate'])
+            ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
+            ->name('organizations.sso.activate');
+
+        Route::post('/organizations/{organization:slug}/sso/{oidcConnection}/disable', [OrganizationSsoConnectionController::class, 'disable'])
+            ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
+            ->name('organizations.sso.disable');
+
+        Route::delete('/organizations/{organization:slug}/sso/{oidcConnection}', [OrganizationSsoConnectionController::class, 'destroy'])
+            ->middleware(['recent-auth', 'throttle:enterprise-sso-manage'])
+            ->name('organizations.sso.destroy');
+    });
+
     // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
     Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
         ->middleware('recent-auth')
```

## 実装差分 2/3: 静的検査 (tests/Architecture, tests/Support)

```diff
diff --git a/tests/Architecture/AccountDeletionPathGateTest.php b/tests/Architecture/AccountDeletionPathGateTest.php
index 14d4cb22..86b54347 100644
--- a/tests/Architecture/AccountDeletionPathGateTest.php
+++ b/tests/Architecture/AccountDeletionPathGateTest.php
@@ -126,6 +126,27 @@
     'App\DataTransferObjects\Security\OrgAccessRevocationResult',
     'App\Enums\Security\OrgAccessRevocationReason',
     'App\Enums\AccountDeletionBlockReason',
+    // ↓ T253 (企業 IdP との OIDC SSO) で閉包に入った 14 クラス。閉包はクラス粒度なので、
+    //   退会そのものが企業 SSO を触らなくても、**組織と利用者の relation を辿った時点で入る**
+    //   (Organization::oidcConnections() / User::emailPromotions() / 接続の状態遷移サービス)。
+    //   いずれも接続の登録・状態・身元と ID トークンの検証結果を扱うだけで、
+    //   決済事業者 SDK への到達辺を持たない (検査 2 が機械的に固定する)。
+    //   退会時に消えるのは組織の接続とその身元で、cascade は FK が担う。
+    'App\Casts\EncryptedSecretCast',
+    'App\DataTransferObjects\EnterpriseSso\ConnectionCredentialsSnapshot',
+    'App\DataTransferObjects\EnterpriseSso\VerifiedIdTokenClaims',
+    'App\DataTransferObjects\EnterpriseSso\VerifyOutcome',
+    'App\Enums\EnterpriseSso\ConnectionTransitionRejection',
+    'App\Enums\EnterpriseSso\OidcConnectionStatus',
+    'App\Enums\EnterpriseSso\RejectionReason',
+    'App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException',
+    'App\Exceptions\EnterpriseSso\OidcConnectionTransitionException',
+    'App\Models\EnterpriseIdentity',
+    'App\Models\OrganizationOidcConnection',
+    'App\Services\EnterpriseSso\EnterpriseUserProvisioner',
+    'App\Services\EnterpriseSso\OidcConnectionTransitionService',
+    'App\ValueObjects\EnterpriseSso\ConnectionSecret',
+    'App\ValueObjects\EnterpriseSso\OidcIssuerUrl',
     'App\Enums\AccountDeletionBlockerAction',
     'App\Enums\AdminConsoleRole',
     'App\Enums\Billing\PlanPriceKind',
diff --git a/tests/Architecture/CachePayloadPlainDataGateTest.php b/tests/Architecture/CachePayloadPlainDataGateTest.php
index a5b77956..c37b6ca7 100644
--- a/tests/Architecture/CachePayloadPlainDataGateTest.php
+++ b/tests/Architecture/CachePayloadPlainDataGateTest.php
@@ -317,6 +317,10 @@
         'count' => 1,
         'rationale' => 'route binding が宣言した型を生成して Eloquent Model かどうかと主キーの型区分を確かめる。生成するのはモデルであって保管先ではない',
     ],
+    'tests/Feature/EnterpriseSso/EnterpriseSsoModelHidingTest.php' => [
+        'count' => 1,
+        'rationale' => '4 モデルの $fillable が空であることを 1 本のデータ提供で確かめるため、モデルのクラス名から実体を作る。キャッシュの保管先とは無関係である',
+    ],
     'tests/Feature/InitialState/NullInitialStateColumnClassificationTest.php' => [
         'count' => 1,
         'rationale' => '実スキーマと突き合わせるため Eloquent モデルを生成して cast 宣言を読む。生成するのはモデルであって保管先ではない',
@@ -389,6 +393,20 @@ function cachePayloadIsStoreType(string $fqcn): bool
         'proof' => 'tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php',
         'rationale' => '当日の USD/JPY レートを 1 日 cache する。読み戻しは is_array 検査 + FxSnapshotDto::fromArray() + 失敗時 Cache::forget() で標準形どおり',
     ],
+    'tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php::put' => [
+        'kind' => 'guard-selftest',
+        'count' => 1,
+        'payload' => '壊れた / 空 / 未知の値のキャッシュ (読み戻しの検査を撃つための入力)',
+        'proof' => 'tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php',
+        'rationale' => '読み戻しで DTO を組み立て直せない値を意図的に置き、Cache::forget して取り直すことを固定する。壊れた値を直接置けないとこの分岐を撃てない',
+    ],
+    'app/Services/EnterpriseSso/OidcDiscoveryService.php::put' => [
+        'kind' => 'plain',
+        'count' => 3,
+        'payload' => '接続先情報 (文字列 4 + 文字列の list 2) / 公開鍵集合 (文字列だけの二重配列) / 最終再取得時刻 (int)。オブジェクトは渡さない',
+        'proof' => 'tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php',
+        'rationale' => '企業 IdP の接続先情報と公開鍵を issuer の sha256 をキーにして寿命つきで保存する。読み戻しは is_array 検査 + DTO の fromCachePayload() で組み立て直し、破損 / 空配列 / 未知の値のいずれでも Cache::forget して miss 扱いにする',
+    ],
     'app/Services/Mail/Sns/SnsCertificateFetcher.php::put' => [
         'kind' => 'plain',
         'count' => 1,
@@ -536,6 +554,14 @@ function cachePayloadIsStoreType(string $fqcn): bool
         'role' => 'lock-only',
         'rationale' => 'チケット checkout の二重発行を Cache::lock で抑止するのみ。payload は書かない',
     ],
+    'tests/Feature/EnterpriseSso/OidcDiscoveryServiceTest.php' => [
+        'role' => 'write',
+        'rationale' => '接続先情報と公開鍵の取得口の振る舞い検査。キャッシュ命中と読み戻し不能の分岐を撃つため素の配列の put を直接使う',
+    ],
+    'app/Services/EnterpriseSso/OidcDiscoveryService.php' => [
+        'role' => 'write',
+        'rationale' => '企業 IdP の接続先情報と公開鍵の取得口。get / put / forget を持つ唯一のファイルで、payload は素の配列とスカラーだけである',
+    ],
     'app/Services/FxRateService.php' => [
         'role' => 'write',
         'rationale' => 'FX レートの当日 cache。素の配列を put し、読み戻しで DTO へ組み立て直す唯一の経路',
diff --git a/tests/Architecture/ControllerAuthorizationGateTest.php b/tests/Architecture/ControllerAuthorizationGateTest.php
index 89e55f51..6a1c1ad5 100644
--- a/tests/Architecture/ControllerAuthorizationGateTest.php
+++ b/tests/Architecture/ControllerAuthorizationGateTest.php
@@ -112,6 +112,23 @@ function controllerAuthorizationExemptions(): array
             .'別軸の防御として recent-auth (step-up) middleware を必須にし、password 設定済みの'
             .'迂回は PasswordCredentialService が lock 下で fail-closed 拒否する。総当り防御は throttle:password-set。'],
 
+        'settings.email-promotion.store' => [$selfScoped,
+            '対象は $request->user() 自身のメールアドレスの昇格の発行のみ (T253)。route に他者を'
+            .'指せる parameter が無く、Service は relation 起点 ($user->emailPromotions()) しか'
+            .'引かないため他人の行へ到達する経路がコード上存在しない。'
+            .'別軸の防御として recent-auth (step-up) を必須にし、総当り防御は throttle:email-promotion。'],
+
+        'settings.email-promotion.resend' => [$selfScoped,
+            '発行と対称の再送 (T253)。対象は $request->user() 自身だけで、route に他者を指せる'
+            .'parameter が無い。旧トークンは発行のたびに自分の行を消すことで失効する。'
+            .'別軸の防御として recent-auth (step-up) を必須にし、総当り防御は throttle:email-promotion。'],
+
+        'settings.email-promotion.confirm' => [$selfScoped,
+            'メールアドレスの昇格の確定 (T253)。トークンは relation 起点 ($user->emailPromotions())'
+            .'で引くため、他人のトークンでは 1 件も当たらない (user_id の結合が認可そのものである)。'
+            .'★救済の性格なので recent-auth を課さない (関門を足すと確定できず詰む)。'
+            .'総当り防御は throttle:email-promotion-confirm。'],
+
         'recent-auth.password' => [$selfScoped,
             '自分の再認証鮮度 (RecentAuthState) の更新。route に他者を指せる parameter が無く、'
             .'認証そのものが主体判定であるため Policy による再判定に意味がない。'
diff --git a/tests/Architecture/EmailPromotionIdentityGateTest.php b/tests/Architecture/EmailPromotionIdentityGateTest.php
new file mode 100644
index 00000000..064b03b0
--- /dev/null
+++ b/tests/Architecture/EmailPromotionIdentityGateTest.php
@@ -0,0 +1,71 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;
+
+/*
+ * G5: メールアドレスの昇格フローが**メールから利用者を引かない**、
+ *     かつ**既存アカウントとの併合をしない**。
+ *
+ * ## なぜ Auth 名前空間に置いてあるか
+ *
+ * 昇格は**メール文字列を正当に扱う唯一の場所**である。企業 SSO の走査根
+ * (`App\Services\EnterpriseSso`) へ入れると、G1 の「メールで引く記法の禁止」に
+ * 巻き込まれてしまう。**検査の回避ではない** — 引き当ての鍵は常に自分自身であることを
+ * 本 gate が別に固定する。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - 走査根の外から `users.email` を書く経路は見ない (A3 の規約は実挙動テストが担う)
+ * - 文字列で組み立てた列名は見ない
+ */
+
+function emailPromotionRoots(): array
+{
+    return [
+        'app/Services/Auth/EmailPromotionService.php',
+        'app/Http/Controllers/Auth/EmailPromotionController.php',
+    ];
+}
+
+test('G5-1: 昇格フローがメールから利用者を引かない', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(emailPromotionRoots());
+
+    // ★`whereBlind` は「暗号化列で引く」唯一の記法である。昇格フローは**自分自身**しか引かない。
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['whereBlind', 'orWhereBlind']))
+        ->toBe([], '昇格フローはメールから利用者を引かないこと');
+});
+
+test('G5-2: 昇格フローが既存アカウントとの併合をしない', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(emailPromotionRoots());
+
+    // ★併合は「他人の行を触る」ことである。移譲・付け替え・削除の記法を禁じる。
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, [
+        'merge', 'transferOwnership', 'forceDelete',
+    ]))->toBe([], '昇格は既存利用者を一切変更しないこと');
+});
+
+test('G5-3: 昇格の引き当てが relation 起点である (自分自身しか見ない)', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(['app/Services/Auth/EmailPromotionService.php']);
+    $source = $sources['app/Services/Auth/EmailPromotionService.php'];
+
+    // ★`$user->emailPromotions()` を通る (クラス起点で `EmailPromotion::query()` を書かない)。
+    expect(str_contains($source, '$user->emailPromotions()'))->toBeTrue();
+    expect(str_contains($source, 'EmailPromotion::query()'))->toBeFalse();
+});
+
+test('G5-4: 昇格フローが企業 SSO の走査根の中に無い (配置の宣言)', function (): void {
+    // ★配置そのものを固定する (移動すると G1 の走査根に入ってしまう)。
+    // ★パスは**区切り文字で組み立てる**。先頭にスラッシュを持つ文字列リテラルを書くと、
+    //   旧 URL の不在を見張る `LegacyOrganizationlessUrlAbsenceTest` が URL として拾ってしまう。
+    $separator = DIRECTORY_SEPARATOR;
+    $base = dirname(__DIR__, 2).$separator.'app'.$separator.'Services'.$separator;
+
+    expect(is_file($base.'Auth'.$separator.'EmailPromotionService.php'))->toBeTrue();
+    expect(is_file($base.'EnterpriseSso'.$separator.'EmailPromotionService.php'))->toBeFalse();
+});
+
+test('G5-5: 走査が空振りしていない (走査根がそれぞれ生きている)', function (string $root): void {
+    expect(EnterpriseSsoSourceScanner::sources([$root]))->not->toBe([]);
+})->with(emailPromotionRoots());
diff --git a/tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest.php b/tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest.php
new file mode 100644
index 00000000..60edc29b
--- /dev/null
+++ b/tests/Architecture/EnterpriseSsoEmailIdentityIsolationTest.php
@@ -0,0 +1,61 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;
+
+/*
+ * G1: 企業 SSO は**メールアドレスで利用者を引かない**。
+ *
+ * 引き当ての鍵は **接続 × `COLLATE "C"` の subject** だけである。
+ *
+ * ## 二層で固定する
+ *
+ *  1. **記法の走査 (本 gate)** — 走査根に「メールで引く」記法が無い
+ *  2. **スキーマの検査** (`tests/Feature/EnterpriseSso/EnterpriseIdentityIsolationTest.php`) —
+ *     申告メールの列を含む索引が 0 本であること (**スキーマの読み取りだけ**。
+ *     `migrate:fresh` 等の破壊操作を伴わない = 禁止事項 3)
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - 走査根の外 (`App\Services\Auth\EmailPromotionService` = メール昇格) は母集団に入らない。
+ *   そちらは G5 が別に固定する (**メール文字列を正当に扱う唯一の場所**を
+ *   禁止語の走査へ巻き込まないための意図的な配置である)
+ * - 文字列で組み立てた列名 (`where($column, …)`) は見ない
+ */
+
+function enterpriseSsoIdentityRoots(): array
+{
+    return [
+        'app/Services/EnterpriseSso',
+        'app/Http/Controllers/Auth/EnterpriseSsoLoginController.php',
+        'app/Models/EnterpriseIdentity.php',
+        'app/Models/OrganizationOidcConnection.php',
+    ];
+}
+
+test('G1-1: メールで利用者を引く記法が無い', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoIdentityRoots());
+
+    // ★`whereBlind` は CipherSweet の「暗号化列で引く」唯一の記法である。
+    //   企業 SSO の経路にこれが現れたら、メールでの引き当てが復活している。
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['whereBlind', 'orWhereBlind']))
+        ->toBe([], '企業 SSO の経路でメールから利用者を引かないこと');
+});
+
+test('G1-2: 身元モデルが申告メールへ blind index を張らない', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(['app/Models/EnterpriseIdentity.php']);
+
+    // ★`addBlindIndex` を**呼ばない**ことが不変条件の実体である。
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['addBlindIndex']))->toBe([]);
+});
+
+test('G1-3: 昇格の確認待ちモデルも blind index を張らない', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(['app/Models/EmailPromotion.php']);
+
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, ['addBlindIndex']))->toBe([]);
+});
+
+test('G1-4: 走査が空振りしていない (走査根がそれぞれ生きている)', function (string $root): void {
+    expect(EnterpriseSsoSourceScanner::sources([$root]))->not->toBe([]);
+})->with(enterpriseSsoIdentityRoots());
diff --git a/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php b/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php
new file mode 100644
index 00000000..514181c0
--- /dev/null
+++ b/tests/Architecture/EnterpriseSsoOutboundHttpGateTest.php
@@ -0,0 +1,87 @@
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
+test('G2-4: リダイレクトを自動追従する記法が無い', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots());
+
+    foreach ($sources as $path => $source) {
+        // ★`fetch()` の第 3 引数は既定が true なので、**明示的に false を渡していること**を要求する。
+        if (! str_contains($source, '->fetch(')) {
+            continue;
+        }
+        expect(str_contains($source, 'followRedirects: false'))
+            ->toBeTrue("{$path} は追従を明示的に切ること");
+    }
+});
+
+test('G2-5: 走査が空振りしていない (母集団が空でない)', function (): void {
+    expect(EnterpriseSsoSourceScanner::sources(enterpriseSsoOutboundRoots()))->not->toBe([]);
+});
diff --git a/tests/Architecture/EnterpriseSsoPruneScheduleTest.php b/tests/Architecture/EnterpriseSsoPruneScheduleTest.php
new file mode 100644
index 00000000..82394aa4
--- /dev/null
+++ b/tests/Architecture/EnterpriseSsoPruneScheduleTest.php
@@ -0,0 +1,73 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Console\Scheduling\Event;
+use Illuminate\Console\Scheduling\Schedule;
+
+/*
+ * 一時状態の掃除が **scheduler へ日次で登録されている** ことの固定 (F3)。
+ *
+ * ★コマンドが在るだけでは日次の掃除は成立しない。**登録そのもの**を固定する
+ *   (登録を消しても掃除コマンドのテストは緑のままなので、それだけでは気付けない)。
+ */
+
+/** @return list<string> scheduler に登録された全コマンドの式 */
+function enterpriseSsoScheduledCommands(): array
+{
+    /** @var Schedule $schedule */
+    $schedule = app(Schedule::class);
+
+    return array_map(
+        static fn (Event $event): string => (string) $event->command,
+        $schedule->events(),
+    );
+}
+
+test('掃除コマンド 2 本が scheduler へ登録されている', function (string $command): void {
+    $registered = array_values(array_filter(
+        enterpriseSsoScheduledCommands(),
+        static fn (string $expression): bool => str_contains($expression, $command),
+    ));
+
+    expect($registered)->toHaveCount(1, "{$command} が scheduler に 1 本だけ登録されていること");
+})->with([
+    'enterprise-sso:prune-login-attempts',
+    'auth:prune-email-promotions',
+]);
+
+test('掃除コマンド 2 本が日次で走る', function (string $command): void {
+    /** @var Schedule $schedule */
+    $schedule = app(Schedule::class);
+
+    $events = array_values(array_filter(
+        $schedule->events(),
+        static fn (Event $event): bool => str_contains((string) $event->command, $command),
+    ));
+
+    expect($events)->toHaveCount(1);
+    // 日次 (`->daily()`) は 0 0 * * * である
+    expect($events[0]->expression)->toBe('0 0 * * *');
+})->with([
+    'enterprise-sso:prune-login-attempts',
+    'auth:prune-email-promotions',
+]);
+
+test('掃除コマンド 2 本が 1 台だけで走る (多重起動で二重に消さない)', function (string $command): void {
+    /** @var Schedule $schedule */
+    $schedule = app(Schedule::class);
+
+    $events = array_values(array_filter(
+        $schedule->events(),
+        static fn (Event $event): bool => str_contains((string) $event->command, $command),
+    ));
+
+    expect($events[0]->onOneServer)->toBeTrue();
+})->with([
+    'enterprise-sso:prune-login-attempts',
+    'auth:prune-email-promotions',
+]);
+
+test('走査が空振りしていない (scheduler に 1 件以上の登録がある)', function (): void {
+    expect(count(enterpriseSsoScheduledCommands()))->toBeGreaterThan(0);
+});
diff --git a/tests/Architecture/EnterpriseSsoSecretExposureGateTest.php b/tests/Architecture/EnterpriseSsoSecretExposureGateTest.php
new file mode 100644
index 00000000..bbdde22a
--- /dev/null
+++ b/tests/Architecture/EnterpriseSsoSecretExposureGateTest.php
@@ -0,0 +1,119 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Organizations\SsoConnectionSummary;
+use App\Enums\EnterpriseSso\RejectionReason;
+use App\Exceptions\EnterpriseSso\EnterpriseSsoAttemptRejectedException;
+use App\Services\EnterpriseSso\EnterpriseLoginAttemptStore;
+use App\Services\EnterpriseSso\OidcTokenExchanger;
+use App\Support\EnterpriseSso\BasicCredentials;
+use App\ValueObjects\EnterpriseSso\ConnectionSecret;
+use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;
+
+/*
+ * G3: 接続の秘密が受け渡しの型・例外・記録に存在しない。
+ *
+ * ## 三層で守る (本 gate は 2 層目である)
+ *
+ *  1. **型** — {@see ConnectionSecret} が暗黙の文字列化を持たない (うっかりの連結を消す)
+ *  2. **gate (ここ)** — 値型をログ・dump・直列化の関数へ渡す記法を禁じ、
+ *     平文化の呼び出し元を exact-fit で pin し、例外の構築子の形を固定する
+ *  3. **主たる証明** — 実挙動の漏洩テスト
+ *     (`tests/Feature/EnterpriseSso/OidcTokenExchangerTest.php` の
+ *      「ログに秘密・認可コード・検証子が残らない」「例外の中身に出ない」/
+ *      `tests/Feature/Organizations/OrganizationSsoConnectionTest.php` の
+ *      「一覧の生成が秘密を一度も復号しない」「dontFlash」)
+ *
+ * ★**主たる証明は 3 にある**。本 gate は「うっかり書けてしまう形」を消すだけで、
+ *   秘密が出ないことそのものを静的に証明はしない。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - `var_export` / `serialize` / Reflection からは平文が見える (値型の docblock が明言している)
+ * - 走査根の外から値型を受け取って出力する形は見ない (値型を渡せる経路が
+ *   `revealForTokenExchange()` の exact-fit で閉じていることが根拠である)
+ */
+
+function enterpriseSsoSecretRoots(): array
+{
+    return [
+        'app/Services/EnterpriseSso',
+        'app/Http/Controllers/Organizations/OrganizationSsoConnectionController.php',
+        'app/Http/Requests/Organizations/StoreSsoConnectionRequest.php',
+        'app/Http/Requests/Organizations/UpdateSsoConnectionRequest.php',
+        'app/DataTransferObjects/Organizations/SsoConnectionSummary.php',
+        'app/ValueObjects/EnterpriseSso',
+    ];
+}
+
+test('G3-1: 秘密を出力・直列化する関数の記法が走査根に無い', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(enterpriseSsoSecretRoots());
+
+    expect(EnterpriseSsoSourceScanner::forbiddenTokens($sources, [
+        'var_dump', 'var_export', 'print_r', 'serialize', 'dump', 'dd', 'ray',
+    ]))->toBe([], '秘密を扱う経路で出力・直列化の関数を使わないこと');
+});
+
+test('G3-2: 平文化の呼び出し元が用途ごとに 1 本ずつである (exact-fit)', function (string $method, string $caller): void {
+    // ★走査根は `app/` 全数である (**どこからでも呼べてはいけない**ため)。
+    $sources = EnterpriseSsoSourceScanner::sources(['app']);
+
+    expect(EnterpriseSsoSourceScanner::filesCalling($sources, $method))->toBe([$caller]);
+})->with([
+    // 外向きへ出す平文は token 交換だけが取り出す
+    ['revealForTokenExchange', 'app/Services/EnterpriseSso/OidcTokenExchanger.php'],
+    // 保存のための暗号化だけが取り出す (用途を分けているので相互に流用できない)
+    ['revealForEncryptionAtRest', 'app/Casts/EncryptedSecretCast.php'],
+]);
+
+test('G3-3: 値型が暗黙の文字列化を持たない', function (): void {
+    expect(method_exists(ConnectionSecret::class, '__toString'))->toBeFalse();
+});
+
+test('G3-4: 拒否の例外が理由の enum だけを受け取り、previous を受け取れない', function (): void {
+    $constructor = (new ReflectionClass(EnterpriseSsoAttemptRejectedException::class))->getConstructor();
+
+    expect($constructor)->not->toBeNull();
+    expect($constructor?->isPrivate())->toBeTrue('外から任意の値で作れないこと');
+    expect($constructor?->getNumberOfParameters())->toBe(1, '理由の enum だけを受け取ること');
+    expect((string) $constructor?->getParameters()[0]->getType())->toBe(RejectionReason::class);
+});
+
+test('G3-5: 画面へ返す要約が秘密の項目を持たない (伏字すら持たない)', function (): void {
+    $properties = array_map(
+        static fn (ReflectionProperty $property): string => $property->getName(),
+        (new ReflectionClass(SsoConnectionSummary::class))
+            ->getProperties(ReflectionProperty::IS_PUBLIC),
+    );
+
+    expect($properties)->not->toContain('clientSecret');
+    expect($properties)->not->toContain('clientSecretMasked');
+    // ★版番号も出さない (D1 の内部の比較子であって画面が使う値ではない)
+    expect($properties)->not->toContain('credentialsRevision');
+});
+
+test('G3-6: 秘密を平文で受ける引数に SensitiveParameter が付いている', function (string $class, string $method, string $parameter): void {
+    $reflection = (new ReflectionClass($class))->getMethod($method);
+
+    $target = null;
+    foreach ($reflection->getParameters() as $candidate) {
+        if ($candidate->getName() === $parameter) {
+            $target = $candidate;
+        }
+    }
+
+    expect($target)->not->toBeNull();
+    expect($target?->getAttributes(SensitiveParameter::class))->not->toBe([]);
+})->with([
+    [OidcTokenExchanger::class, 'exchange', 'code'],
+    [OidcTokenExchanger::class, 'exchange', 'codeVerifier'],
+    [BasicCredentials::class, 'header', 'clientSecret'],
+    [ConnectionSecret::class, 'fromPlaintext', 'plaintext'],
+    [EnterpriseLoginAttemptStore::class, 'consume', 'browserBindingSecret'],
+]);
+
+test('G3-7: 走査が空振りしていない', function (): void {
+    expect(EnterpriseSsoSourceScanner::sources(enterpriseSsoSecretRoots()))->not->toBe([]);
+    expect(EnterpriseSsoSourceScanner::sources(['app']))->not->toBe([]);
+});
diff --git a/tests/Architecture/JobDeferralTerminationGateTest.php b/tests/Architecture/JobDeferralTerminationGateTest.php
index 1180d699..ad74f611 100644
--- a/tests/Architecture/JobDeferralTerminationGateTest.php
+++ b/tests/Architecture/JobDeferralTerminationGateTest.php
@@ -13,6 +13,7 @@
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Jobs\Manual\RunManualAnalysis;
 use App\Jobs\Manual\RunManualRender;
+use App\Mail\EmailPromotionMail;
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
 use App\Notifications\Account\AccountDeletionRequestedNotification;
@@ -224,6 +225,12 @@ function jobDeferralTerminationInventory(): array
                 .'取れなければ退避ではなくその場で終了する。再実行は書き出しの再要求だけが入口である。',
             'coveredBy' => [],
         ],
+        [
+            'class' => EmailPromotionMail::class,
+            'mode' => 'NO_DEFERRAL',
+            'reason' => $common.'メールアドレス確認の案内を 1 通送るだけで、他の仕事と順番を争わない。',
+            'coveredBy' => [],
+        ],
         [
             'class' => InquiryAcknowledgementMail::class,
             'mode' => 'NO_DEFERRAL',
diff --git a/tests/Architecture/JobExecutionDedupInventoryTest.php b/tests/Architecture/JobExecutionDedupInventoryTest.php
index 1e0c28a0..03ac37b7 100644
--- a/tests/Architecture/JobExecutionDedupInventoryTest.php
+++ b/tests/Architecture/JobExecutionDedupInventoryTest.php
@@ -16,6 +16,7 @@
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Jobs\Manual\RunManualAnalysis;
 use App\Jobs\Manual\RunManualRender;
+use App\Mail\EmailPromotionMail;
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
 use App\Notifications\Account\AccountDeletionRequestedNotification;
@@ -215,6 +216,12 @@ function jobDedupExemptions(): array
             .'S3 削除は存在しないキーに対して冪等。NULL 化は検証時の値と一致する行のみを'
             .'更新する CAS なので、再実行で最新世代を誤って壊さない。',
         ),
+        EmailPromotionMail::class => new ExemptionEntry(
+            JobDedupExemption::DuplicateDeliveryAccepted,
+            'メールアドレス昇格の確認メール。ドメイン状態を一切書かず、重複受信しても'
+            .'同じ確認リンクが 2 通届くだけである (確定は POST 1 回きりで、トークンの'
+            .'consume は行ロック下の削除なので二重確定は構造的に起きない)。',
+        ),
         InquiryAcknowledgementMail::class => new ExemptionEntry(
             JobDedupExemption::DuplicateDeliveryAccepted,
             'お問い合わせ受付の自動返信メール。ドメイン状態を一切書かず、重複受信しても'
@@ -281,7 +288,7 @@ function jobDedupExemptions(): array
  */
 function jobDedupExemptionCap(): int
 {
-    return 16;
+    return 17;
 }
 
 /**
@@ -293,7 +300,7 @@ function jobDedupExemptionCap(): int
 function jobDedupExemptionCapByCase(): array
 {
     return [
-        JobDedupExemption::DuplicateDeliveryAccepted->value => 10,
+        JobDedupExemption::DuplicateDeliveryAccepted->value => 11,
         JobDedupExemption::IdempotentDeletion->value => 2,
         JobDedupExemption::ConvergentStateSync->value => 3,
         JobDedupExemption::GuardedByDownstreamConstraint->value => 1,
diff --git a/tests/Architecture/MembershipWriteLockInventoryTest.php b/tests/Architecture/MembershipWriteLockInventoryTest.php
index 56c5527c..42956b7a 100644
--- a/tests/Architecture/MembershipWriteLockInventoryTest.php
+++ b/tests/Architecture/MembershipWriteLockInventoryTest.php
@@ -27,6 +27,10 @@
         // deleteAccount と同じ canonical 順序 (users 昇順 → organizations 昇順) の起点に乗せ、
         // 新しいロック順序を作らない (順序の SoT を 2 クラスに分けない)
         'requestAccountDeletion', 'cancelAccountDeletion',
+        // 企業 SSO の初回ログインで作られた利用者の所属付与 (T253 / C1)。
+        // 呼び出し元は接続の行をロックした tx の中にいるが、users → organizations の
+        // canonical 順序はここでも自分で取る (順序の SoT を 2 クラスに分けない)。
+        'attachJustInTimeMember',
     ];
     // ロック済み内部メソッド経由で間接的にロックされる経路 (メソッド名 => 必須の委譲先呼び出し)。
     // ★ハードコードの 'joinOrganization(' を map へ一般化した (既存 3 本の判定は等価のまま)。
diff --git a/tests/Architecture/OrganizationAccessRevocationChokePointTest.php b/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
index 93c654a1..2fcd330d 100644
--- a/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
+++ b/tests/Architecture/OrganizationAccessRevocationChokePointTest.php
@@ -61,7 +61,7 @@ function orgRevocationReasonMinLength(): int
 /** 免除の**件数** (完全一致。増えても減っても赤くなる)。 */
 function orgRevocationExemptionCount(): int
 {
-    return 1;
+    return 2;
 }
 
 /**
@@ -77,6 +77,7 @@ function orgRevocationClassification(): array
         'transferOwnership' => 'revokes',
         'normalizeOrganizationRole' => 'revokes',
         'joinOrganization' => 'exempt',
+        'attachJustInTimeMember' => 'exempt',
     ];
 }
 
@@ -103,6 +104,7 @@ function orgRevocationExemptions(): array
 {
     return [
         'joinOrganization' => OrgAccessRevocationExemption::JoinOrganization,
+        'attachJustInTimeMember' => OrgAccessRevocationExemption::AttachJustInTimeMember,
     ];
 }
 
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index d7ea73b1..4ca45ffd 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -13,6 +13,7 @@
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Jobs\Manual\RunManualAnalysis;
 use App\Jobs\Manual\RunManualRender;
+use App\Mail\EmailPromotionMail;
 use App\Mail\InquiryAcknowledgementMail;
 use App\Mail\InquiryReceivedMail;
 use App\Notifications\Account\AccountDeletionRequestedNotification;
@@ -82,6 +83,7 @@
     DeleteRenderOutputsJob::class => 'database-media',
     RunManualAnalysis::class => 'database-analysis',
     RunManualRender::class => 'database-render',
+    EmailPromotionMail::class => null,
     InquiryAcknowledgementMail::class => null,
     InquiryReceivedMail::class => null,
     AutoRechargeActionRequiredNotification::class => null,
diff --git a/tests/Architecture/RateLimiterKeyConventionTest.php b/tests/Architecture/RateLimiterKeyConventionTest.php
index e79e895f..ce53a724 100644
--- a/tests/Architecture/RateLimiterKeyConventionTest.php
+++ b/tests/Architecture/RateLimiterKeyConventionTest.php
@@ -230,6 +230,18 @@ function rateLimiterKeyInventory(): array
             'expectedKeyPrefixes' => ['social-callback:ip'],
             'emailScenarios' => [],
         ],
+        // T253: 企業 SSO の開始と戻り口。どちらも未認証面なので IP レーン
+        // (開始と戻り口を分けるのは、開始の連打で戻り口が 429 になると詰むため)。
+        'enterprise-sso-start' => [
+            'scenarios' => ['guest' => $noEmail],
+            'expectedKeyPrefixes' => ['enterprise-sso-start:ip'],
+            'emailScenarios' => [],
+        ],
+        'enterprise-sso-callback' => [
+            'scenarios' => ['guest' => $noEmail],
+            'expectedKeyPrefixes' => ['enterprise-sso-callback:ip'],
+            'emailScenarios' => [],
+        ],
         'invitation-accept' => [
             'scenarios' => ['guest' => $noEmail],
             'expectedKeyPrefixes' => ['invitation-accept:ip'],
@@ -269,6 +281,11 @@ function rateLimiterKeyInventory(): array
         'two-factor-manage',
         'invitation-accept-submit',
         'plan-activate',
+        // T253: 認証済み actor の業務操作 (接続管理 / 確認 / メール昇格の発行と確認)。
+        'enterprise-sso-manage',
+        'enterprise-sso-verify',
+        'email-promotion',
+        'email-promotion-confirm',
     ] as $lane) {
         $inventory[$lane] = [
             'scenarios' => [
@@ -503,6 +520,11 @@ function rateLimiterActorOrIpFullKeys(): array
         'plan-activate',
         // T134 で新設。helper 経由なので同じ full key 契約に載る
         'invitation-accept-in-app',
+        // T253 で新設。helper 経由なので同じ full key 契約に載る
+        'enterprise-sso-manage',
+        'enterprise-sso-verify',
+        'email-promotion',
+        'email-promotion-confirm',
     ];
 
     $expected = [];
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index 61cdc14b..d198090a 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -34,6 +34,19 @@ function recentAuthRequiredRouteNames(): array
         'settings.account.deletion-request.store',
         // パスワード初回設定 (認証手段を増やす操作。セッション奪取からの永続化を防ぐため step-up 必須)
         'settings.password.store',
+        // メールアドレスの昇格の発行・再送 (T253)。認証手段を増やす操作なので同水準。
+        // ★**確認 (settings.email-promotion.confirm) は追加しない** — 救済の性格であり、
+        //   関門を足すと確定できず詰む (退会予約の取消と同じ判断)。
+        'settings.email-promotion.store',
+        'settings.email-promotion.resend',
+        // 企業 SSO 接続の管理 (T253)。接続の秘密と組織のログイン経路を変える操作であり、
+        // API キーの発行・失効と同水準。
+        'organizations.sso.store',
+        'organizations.sso.update',
+        'organizations.sso.verify',
+        'organizations.sso.activate',
+        'organizations.sso.disable',
+        'organizations.sso.destroy',
         // オーナー移譲
         'organizations.transfer-ownership',
         // 組織の 2FA 必須方針トグル (Owner 専権のセキュリティ方針変更)
diff --git a/tests/Architecture/SsoTwoFactorInterpositionGateTest.php b/tests/Architecture/SsoTwoFactorInterpositionGateTest.php
new file mode 100644
index 00000000..e1fcbc29
--- /dev/null
+++ b/tests/Architecture/SsoTwoFactorInterpositionGateTest.php
@@ -0,0 +1,66 @@
+<?php
+
+declare(strict_types=1);
+
+use Tests\Support\EnterpriseSso\EnterpriseSsoSourceScanner;
+
+/*
+ * G4: **共通ログイン経路に 2 要素認証を挟まない** (家系の裁定 AG-200)。
+ *
+ * 企業・ソーシャルの**両方**の戻り口に、待機ログインを作る記述と
+ * 2 要素の入力画面への転送が無いことを固定する。
+ *
+ * ★**主たる証明は実挙動側にある** —
+ *   `tests/Feature/Auth/EnterpriseSsoLoginTest.php` の
+ *   「2 要素認証が有効な利用者もそのままログインが確定する」
+ *   「組織が義務づけていても確定したうえで設定ページへ導かれる」の 2 本である。
+ *   本 gate はその形を**静的に裏当てする**だけで、挙動そのものを証明しない。
+ *
+ * ## 保証しないもの (誇張しない)
+ *
+ * - 別名の route 名や別の controller で第二要素を挟む形には沈黙する
+ * - middleware で挟む形は見ない (route の宣言は本 gate の母集団に入らない)
+ */
+
+function ssoCallbackRoots(): array
+{
+    return [
+        'app/Http/Controllers/Auth/EnterpriseSsoLoginController.php',
+        'app/Http/Controllers/Auth/SocialAuthController.php',
+        'app/Services/EnterpriseSso/EnterpriseCallbackAuthenticator.php',
+    ];
+}
+
+test('G4-1: 戻り口に 2 要素の待機ログインを作る記述が無い', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(ssoCallbackRoots());
+
+    // ★Fortify の待機ログインの実体 (`login.id` のセッションへの退避) と、
+    //   2 要素の入力画面への転送の記法をトークン完全一致で禁じる。
+    foreach ($sources as $path => $source) {
+        expect(str_contains($source, 'two-factor.login'))
+            ->toBeFalse("{$path} は 2 要素の入力画面へ転送しないこと");
+        expect(str_contains($source, 'two_factor_login'))
+            ->toBeFalse("{$path} は待機ログインを作らないこと");
+    }
+});
+
+test('G4-2: 戻り口が確定のログイン (Auth::login) を持つ', function (): void {
+    // ★「挟まない」が「そもそもログインしない」で満たされてしまう形を排除する
+    //   (空振りの否定側だけを固定しない)。
+    foreach (['app/Http/Controllers/Auth/EnterpriseSsoLoginController.php'] as $path) {
+        $sources = EnterpriseSsoSourceScanner::sources([$path]);
+        expect(str_contains($sources[$path], 'Auth::login('))->toBeTrue();
+    }
+});
+
+test('G4-3: 企業ログインが remember cookie を使わない', function (): void {
+    $sources = EnterpriseSsoSourceScanner::sources(['app/Http/Controllers/Auth/EnterpriseSsoLoginController.php']);
+    $source = $sources['app/Http/Controllers/Auth/EnterpriseSsoLoginController.php'];
+
+    // ★接続を無効化したあとに cookie から新しいセッションを開始できてしまう形にしない。
+    expect(str_contains($source, 'remember: false'))->toBeTrue();
+});
+
+test('G4-4: 走査が空振りしていない (走査根がそれぞれ生きている)', function (string $root): void {
+    expect(EnterpriseSsoSourceScanner::sources([$root]))->not->toBe([]);
+})->with(ssoCallbackRoots());
diff --git a/tests/Architecture/fixtures/enterprise-sso/CleanSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/CleanSample.php.txt
new file mode 100644
index 00000000..9c19a7b8
--- /dev/null
+++ b/tests/Architecture/fixtures/enterprise-sso/CleanSample.php.txt
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use Kent013\SsrfPin\PinnedHttpClient;
+
+/**
+ * ★正例の見本。規定どおりの書き方を**誤検出しない**ことを固定する。
+ *
+ * `hasSecretIn` のような**接尾辞つきの識別子**は別のトークンなので、
+ * 語彙一致 (トークン完全一致) では拾われない。
+ */
+final class CleanSample
+{
+    public function __construct(private PinnedHttpClient $pinned) {}
+
+    public function run(): void
+    {
+        $this->pinned->fetch();
+    }
+
+    public function hasSecretIn(): bool
+    {
+        return false;
+    }
+}
diff --git a/tests/Architecture/fixtures/enterprise-sso/DynamicCallSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/DynamicCallSample.php.txt
new file mode 100644
index 00000000..40982ba5
--- /dev/null
+++ b/tests/Architecture/fixtures/enterprise-sso/DynamicCallSample.php.txt
@@ -0,0 +1,21 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+/**
+ * ★負例の見本 (拡張子を .txt にしてあるので実行も autoload もされない)。
+ *   走査器が「動的な呼び出しの形」を 3 つとも検出することを固定する。
+ */
+final class DynamicCallSample
+{
+    public function run(object $client, string $method, string $class): void
+    {
+        $client->$method();
+
+        $instance = new $class;
+
+        call_user_func([$client, 'fetch']);
+    }
+}
diff --git a/tests/Architecture/fixtures/enterprise-sso/ForbiddenHttpSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/ForbiddenHttpSample.php.txt
new file mode 100644
index 00000000..a715ce7a
--- /dev/null
+++ b/tests/Architecture/fixtures/enterprise-sso/ForbiddenHttpSample.php.txt
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+use Illuminate\Support\Facades\Http;
+
+/**
+ * ★負例の見本。pin されていない外向き経路 (Http ファサード) を検出することを固定する。
+ */
+final class ForbiddenHttpSample
+{
+    public function run(): void
+    {
+        Http::get('https://idp.example.test/.well-known/openid-configuration');
+    }
+}
diff --git a/tests/Architecture/fixtures/enterprise-sso/UnresolvedReceiverSample.php.txt b/tests/Architecture/fixtures/enterprise-sso/UnresolvedReceiverSample.php.txt
new file mode 100644
index 00000000..d8cff1d9
--- /dev/null
+++ b/tests/Architecture/fixtures/enterprise-sso/UnresolvedReceiverSample.php.txt
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\EnterpriseSso;
+
+/**
+ * ★負例の見本。受け手の型が解決できない保護対象語彙の呼び出しを検出することを固定する。
+ */
+final class UnresolvedReceiverSample
+{
+    public function __construct(private ResolvedClient $resolved) {}
+
+    public function run(): void
+    {
+        // 解決できる (構築子の promoted プロパティの型から引ける)
+        $this->resolved->fetch();
+
+        // 解決できない (局所変数の受け手)
+        $client = $this->make();
+        $client->fetch();
+    }
+
+    private function make(): object
+    {
+        return new ResolvedClient;
+    }
+}
diff --git a/tests/Support/EnterpriseSso/CommittedConnectionHarness.php b/tests/Support/EnterpriseSso/CommittedConnectionHarness.php
new file mode 100644
index 00000000..7f923d71
--- /dev/null
+++ b/tests/Support/EnterpriseSso/CommittedConnectionHarness.php
@@ -0,0 +1,233 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\EnterpriseSso;
+
+use Closure;
+use Illuminate\Database\ConnectionInterface;
+use Illuminate\Support\Facades\Config;
+use Illuminate\Support\Facades\DB;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * 行ロックの排他を**実際に競合させて**確かめるための土台。
+ *
+ * グローバル `RefreshDatabase` はテスト全体を未コミットのトランザクションで包むので、
+ * 既定の接続で作った検体は**別の接続から見えない**。本ハーネスは
+ * `Tests\Support\Concurrency\OutOfTransactionFixtures` と同じ手口で
+ * **別名接続の明示トランザクションで commit** し、2 本の接続から同じ行を触れるようにする。
+ *
+ * ## 何を証明できるか (誇張しない)
+ *
+ * - **証明する**: 1 本目が `SELECT … FOR UPDATE` で行を掴んでいる間、
+ *   2 本目の同じ行のロック取得は**進めない** (`lock_timeout` で観測する)。
+ *   1 本目がコミットして行を消したあと、2 本目は**行が無い**ものとして扱う
+ *   = 使用権を得るのはちょうど 1 つである
+ * - **証明しない**: 実 OS プロセスを 2 本立てた場合の挙動。本ハーネスは 1 プロセス内の
+ *   **2 本の DB 接続**であり、排他の主体である pgsql の行ロックは同じだが、
+ *   PHP 側の同時実行 (worker の競合) までは再現しない
+ *
+ * ## 後片付け
+ *
+ * 作った行は `RefreshDatabase` の巻き戻しでは消えない。呼び出し側は必ず
+ * {@see self::cleanup()} を finally で呼ぶこと。
+ */
+final class CommittedConnectionHarness
+{
+    /** 検体の生成と「1 本目」に使う接続。 */
+    public const string PRIMARY = 'enterprise_sso_committed_a';
+
+    /** 「2 本目」に使う接続。 */
+    public const string SECONDARY = 'enterprise_sso_committed_b';
+
+    /**
+     * 接続 id で絞って片付ける表 (FK 安全な順序。表名 => 絞り込む列)。
+     *
+     * ★検体の生成経路が別の表へ行を足すようになったら、この一覧を**同じ変更で増やす**。
+     */
+    private const array CONNECTION_SCOPED_TABLES = [
+        'enterprise_sso_login_attempts' => 'organization_oidc_connection_id',
+        'enterprise_identities' => 'organization_oidc_connection_id',
+        'organization_oidc_connections' => 'id',
+    ];
+
+    /**
+     * 組織 id で絞って片付ける表 (FK 安全な順序)。
+     *
+     * ★`organizations.laratrust_team_id` は **restrictOnDelete** なので
+     *   「組織を消せば全部消える」は成り立たない。**組織 → teams の順**でなければ削除できない
+     *   (`OutOfTransactionFixtures` と同じ理由)。
+     */
+    private const array ORGANIZATION_SCOPED_TABLES = [
+        'organization_user' => 'organization_id',
+        'custom_teams' => 'organization_id',
+        'organizations' => 'id',
+    ];
+
+    /** インスタンス化しない。 */
+    private function __construct() {}
+
+    /**
+     * 検体を**コミット済み**で作る (別接続から見える)。
+     *
+     * @template T
+     *
+     * @param  Closure(): T  $callback
+     * @return T
+     */
+    public static function create(Closure $callback): mixed
+    {
+        $original = Config::string('database.default');
+        Assert::same($original, 'pgsql', 'このハーネスは pgsql レーンを前提にする');
+
+        self::register($original, self::PRIMARY);
+
+        $succeeded = false;
+        try {
+            config(['database.default' => self::PRIMARY]);
+            $result = DB::connection(self::PRIMARY)->transaction($callback);
+            $succeeded = true;
+
+            return $result;
+        } finally {
+            config(['database.default' => $original]);
+
+            if (! $succeeded) {
+                self::forget(self::PRIMARY);
+            }
+        }
+    }
+
+    /**
+     * 既定の接続を別名接続へ差し替えて実行する (アプリのコードをそのまま走らせるため)。
+     *
+     * @template T
+     *
+     * @param  Closure(): T  $callback
+     * @return T
+     */
+    public static function onConnection(string $name, Closure $callback): mixed
+    {
+        $original = Config::string('database.default');
+        self::register($original, $name);
+
+        try {
+            config(['database.default' => $name]);
+
+            return $callback();
+        } finally {
+            config(['database.default' => $original]);
+        }
+    }
+
+    public static function connection(string $name): ConnectionInterface
+    {
+        $original = Config::string('database.default');
+        self::register($original, $name);
+
+        return DB::connection($name);
+    }
+
+    /**
+     * ロックの待ち時間に上限を置く (待ち続けて 1 プロセスが固まらないようにする)。
+     *
+     * ★上限を超えたら pgsql は `55P03 lock_not_available` を投げる。
+     *   「待たされたこと」を**例外として観測できる**のが要点である。
+     */
+    public static function limitLockWait(string $name, int $milliseconds): void
+    {
+        self::connection($name)->statement("SET lock_timeout = '{$milliseconds}ms'");
+    }
+
+    /**
+     * 作った行を消す (呼び出し側が finally で呼ぶ。冪等)。
+     *
+     * ★**削除したあと、自分で残留ゼロを検査する**。呼び出し側だけに任せると、
+     *   後片付けの完全性が「別のテストが緑であること」に依存してしまう。
+     *
+     * @param  list<int>  $userIds  JIT で作られた利用者 (組織の所属と役割を先に消してから消す)
+     */
+    public static function cleanup(int $connectionId, ?int $organizationId = null, array $userIds = []): void
+    {
+        $original = Config::string('database.default');
+        self::register($original, self::PRIMARY);
+        $connection = DB::connection(self::PRIMARY);
+
+        try {
+            foreach (self::CONNECTION_SCOPED_TABLES as $table => $column) {
+                $connection->table($table)->where($column, $connectionId)->delete();
+            }
+
+            if ($organizationId !== null) {
+                /** @var object{laratrust_team_id: int}|null $organization */
+                $organization = $connection->table('organizations')->where('id', $organizationId)->first();
+
+                foreach (self::ORGANIZATION_SCOPED_TABLES as $table => $column) {
+                    $connection->table($table)->where($column, $organizationId)->delete();
+                }
+
+                if ($organization !== null) {
+                    $connection->table('role_user')->where('team_id', $organization->laratrust_team_id)->delete();
+                    $connection->table('teams')->where('id', $organization->laratrust_team_id)->delete();
+                }
+            }
+
+            if ($userIds !== []) {
+                $connection->table('users')->whereIn('id', $userIds)->delete();
+            }
+
+            foreach (self::CONNECTION_SCOPED_TABLES as $table => $column) {
+                Assert::same(
+                    $connection->table($table)->where($column, $connectionId)->count(),
+                    0,
+                    "検体の残留がある: {$table}",
+                );
+            }
+
+            if ($organizationId !== null) {
+                foreach (self::ORGANIZATION_SCOPED_TABLES as $table => $column) {
+                    Assert::same(
+                        $connection->table($table)->where($column, $organizationId)->count(),
+                        0,
+                        "検体の残留がある: {$table}",
+                    );
+                }
+            }
+        } finally {
+            self::forget(self::PRIMARY);
+            self::forget(self::SECONDARY);
+        }
+    }
+
+    /** 開きっぱなしのトランザクションを best-effort で閉じる。 */
+    public static function rollbackQuietly(string $name): void
+    {
+        try {
+            $connection = self::connection($name);
+            while ($connection->transactionLevel() > 0) {
+                $connection->rollBack();
+            }
+        } catch (Throwable) {
+            // 片付けの失敗で本体の失敗を隠さない
+        }
+    }
+
+    private static function register(string $original, string $name): void
+    {
+        if (Config::array("database.connections.{$name}", []) !== []) {
+            return;
+        }
+
+        /** @var array<string, mixed> $base */
+        $base = Config::array("database.connections.{$original}");
+        config(["database.connections.{$name}" => $base]);
+    }
+
+    private static function forget(string $name): void
+    {
+        DB::disconnect($name);
+        DB::purge($name);
+    }
+}
diff --git a/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
new file mode 100644
index 00000000..4c0e721c
--- /dev/null
+++ b/tests/Support/EnterpriseSso/EnterpriseSsoSourceScanner.php
@@ -0,0 +1,440 @@
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
diff --git a/tests/Support/EnterpriseSso/FakeIdentityProvider.php b/tests/Support/EnterpriseSso/FakeIdentityProvider.php
new file mode 100644
index 00000000..ba52dfeb
--- /dev/null
+++ b/tests/Support/EnterpriseSso/FakeIdentityProvider.php
@@ -0,0 +1,363 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\EnterpriseSso;
+
+use App\Enums\EnterpriseSso\FingerprintPurpose;
+use App\Support\EnterpriseSso\AttemptFingerprint;
+use Closure;
+use Firebase\JWT\JWT;
+use Kent013\SsrfPin\Contracts\DnsResolverInterface;
+use Kent013\SsrfPin\Contracts\PinnedCurlTransportInterface;
+use Kent013\SsrfPin\Dtos\CurlResolveEntry;
+use Kent013\SsrfPin\Dtos\PinnedFailure;
+use Kent013\SsrfPin\Dtos\PinnedRequest;
+use Kent013\SsrfPin\Dtos\PinnedResponse;
+use Kent013\SsrfPin\Testing\FakeDnsResolver;
+use Kent013\SsrfPin\Testing\FakePinnedTransport;
+use Kent013\SsrfPin\UrlSafetyInspector;
+use RuntimeException;
+use Webmozart\Assert\Assert;
+
+/**
+ * 試験用の偽 IdP。
+ *
+ * ★**アプリ側 (`app/`) に偽の実装を置かない**。差し替えるのは
+ *   ssrf-pin が出荷している transport の seam ({@see FakePinnedTransport}) だけである。
+ *   したがって:
+ *     - 本番の container 束縛を 1 つも変えない (`ExternalFakeDeclaration` の母集団に入らない)
+ *     - 未認証で任意の subject を名乗れる route を**作らない**
+ *     - それでも**実装が `PinnedHttpClient` を通ることの検査**になる —
+ *       通らなければ本 fake には 1 件も要求が届かないからである
+ *
+ * ★discovery / JWKS / token の 3 経路を URL で振り分ける。
+ * ★`beforeRespond` は「**待つ**ものではなく**やって戻る**もの」である。
+ *   同一プロセスで callback に待たせると呼び出し元が戻らずデッドロックするので、
+ *   sleep も ready / go も締切も持たせない (順序は呼び出しの構造が保証する)。
+ */
+final class FakeIdentityProvider
+{
+    /** 署名鍵の id。 */
+    public const string KEY_ID = 'fake-key-1';
+
+    /**
+     * 偽 IdP の名前解決結果 (**公開到達可能と分類される IP**)。
+     *
+     * ★private レンジを返すと `UrlSafetyInspector` (本物) が拒否して transport まで届かない。
+     *   ここで公開 IP を返すことで、SSRF 判定を通ったうえで偽の transport が応答する形になる。
+     */
+    public const string PUBLIC_IP = '93.184.216.34';
+
+    /** @var list<PinnedRequest> 受領した要求 (到達の検証に使う) */
+    public array $requests = [];
+
+    /** @var array<string, mixed> discovery 文書の上書き */
+    private array $metadataOverrides = [];
+
+    /** @var list<array<string, string>> JWKS が返す鍵 (既定は自分の公開鍵 1 本) */
+    private ?array $keys = null;
+
+    /** @var Closure(string): void|null 応答を返す直前に呼ぶ割り込み点 */
+    private ?Closure $beforeRespond = null;
+
+    /** @var array<string, mixed> ID トークンの claim の上書き */
+    private array $claimOverrides = [];
+
+    /** @var list<string> 上書きで削る claim */
+    private array $removedClaims = [];
+
+    private string $idTokenAlgorithm = 'RS256';
+
+    private ?string $idTokenOverride = null;
+
+    private ?PinnedFailure $failureOverride = null;
+
+    private int $statusOverride = 200;
+
+    private ?string $bodyOverride = null;
+
+    private static ?string $privateKey = null;
+
+    /** @var array<string, string>|null */
+    private static ?array $publicJwk = null;
+
+    public function __construct(public readonly string $issuer = 'https://idp.example.test') {}
+
+    /**
+     * transport と名前解決を差し替える。
+     *
+     * ★`UrlSafetyInspector` そのものは偽物にしない (差し替え禁止)。
+     *   差し替えるのは**その依存**である `DnsResolverInterface` だけなので、
+     *   **SSRF の判定層は本物が動く** (pin 済み経路を通ることの検査になる)。
+     */
+    public function install(): self
+    {
+        app()->instance(PinnedCurlTransportInterface::class, new FakePinnedTransport(
+            fn (PinnedRequest $request, CurlResolveEntry $entry): PinnedResponse|PinnedFailure => $this->respond($request),
+        ));
+
+        $host = parse_url($this->issuer, PHP_URL_HOST);
+        Assert::stringNotEmpty($host);
+
+        app()->bind(
+            DnsResolverInterface::class,
+            fn (): DnsResolverInterface => new FakeDnsResolver([$host => [self::PUBLIC_IP]]),
+        );
+        app()->forgetInstance(UrlSafetyInspector::class);
+
+        return $this;
+    }
+
+    /** @param  array<string, mixed>  $overrides */
+    public function withMetadata(array $overrides): self
+    {
+        $this->metadataOverrides = [...$this->metadataOverrides, ...$overrides];
+
+        return $this;
+    }
+
+    /** @param  list<array<string, string>>  $keys */
+    public function withKeys(array $keys): self
+    {
+        $this->keys = $keys;
+
+        return $this;
+    }
+
+    /** @param  array<string, mixed>  $claims */
+    public function withClaims(array $claims): self
+    {
+        $this->claimOverrides = [...$this->claimOverrides, ...$claims];
+
+        return $this;
+    }
+
+    /** @param  list<string>  $claims */
+    public function withoutClaims(array $claims): self
+    {
+        $this->removedClaims = [...$this->removedClaims, ...$claims];
+
+        return $this;
+    }
+
+    public function withIdTokenAlgorithm(string $algorithm): self
+    {
+        $this->idTokenAlgorithm = $algorithm;
+
+        return $this;
+    }
+
+    public function withRawIdToken(string $idToken): self
+    {
+        $this->idTokenOverride = $idToken;
+
+        return $this;
+    }
+
+    public function withStatus(int $status): self
+    {
+        $this->statusOverride = $status;
+
+        return $this;
+    }
+
+    public function withBody(string $body): self
+    {
+        $this->bodyOverride = $body;
+
+        return $this;
+    }
+
+    public function withTransportFailure(PinnedFailure $failure): self
+    {
+        $this->failureOverride = $failure;
+
+        return $this;
+    }
+
+    /**
+     * 応答を返す**直前**に呼ぶ割り込み点 (D1 の `verify` の三段構成の検査に使う)。
+     *
+     * ★**1 回だけ**発火する (1 回の `verify` は discovery と JWKS の 2 経路を取りに行くため)。
+     *
+     * @param  Closure(string): void  $callback  引数は要求 URL
+     */
+    public function beforeRespond(Closure $callback): self
+    {
+        $this->beforeRespond = $callback;
+
+        return $this;
+    }
+
+    /** discovery 文書 (上書きを反映したもの)。 */
+    public function metadata(): array
+    {
+        return [
+            'issuer' => $this->issuer,
+            'authorization_endpoint' => $this->issuer.'/authorize',
+            'token_endpoint' => $this->issuer.'/token',
+            'jwks_uri' => $this->issuer.'/jwks',
+            'token_endpoint_auth_methods_supported' => ['client_secret_basic', 'client_secret_post'],
+            'id_token_signing_alg_values_supported' => ['RS256'],
+            ...$this->metadataOverrides,
+        ];
+    }
+
+    /** 署名済みの ID トークン。 */
+    public function idToken(string $clientId, string $nonce, string $subject = 'sub-abc'): string
+    {
+        $claims = [
+            'iss' => $this->issuer,
+            'sub' => $subject,
+            'aud' => $clientId,
+            'exp' => time() + 300,
+            'iat' => time(),
+            'nonce' => $nonce,
+            'email' => 'worker@corp.example',
+            'name' => '現場 太郎',
+            ...$this->claimOverrides,
+        ];
+
+        foreach ($this->removedClaims as $claim) {
+            unset($claims[$claim]);
+        }
+
+        return JWT::encode($claims, self::privateKey(), $this->idTokenAlgorithm, self::KEY_ID);
+    }
+
+    /** 直近の token 交換の要求 (資格情報の載り方の検証に使う)。 */
+    public function lastTokenRequest(): ?PinnedRequest
+    {
+        foreach (array_reverse($this->requests) as $request) {
+            if (str_contains($request->url, '/token')) {
+                return $request;
+            }
+        }
+
+        return null;
+    }
+
+    /** JWKS が返す鍵 (既定は自分の公開鍵 1 本)。 */
+    public function jwks(): array
+    {
+        return ['keys' => $this->keys ?? [self::publicJwk()]];
+    }
+
+    /** 本 fake の公開鍵 (JWK 形式)。 */
+    public static function publicJwk(): array
+    {
+        self::ensureKeyPair();
+        Assert::isArray(self::$publicJwk);
+
+        return self::$publicJwk;
+    }
+
+    private function respond(PinnedRequest $request): PinnedResponse|PinnedFailure
+    {
+        $this->requests[] = $request;
+
+        // ★「やって戻る」割り込み点。待たない (待つとデッドロックする)。
+        // ★**1 回だけ**発火する。1 回の verify は discovery と JWKS の 2 経路を取りに行くので、
+        //   毎回発火すると「割り込みは 1 回」という筋書きにならない (2 回目で前提が崩れる)。
+        if ($this->beforeRespond !== null) {
+            $callback = $this->beforeRespond;
+            $this->beforeRespond = null;
+            $callback($request->url);
+        }
+
+        if ($this->failureOverride !== null) {
+            return $this->failureOverride;
+        }
+
+        $body = $this->bodyOverride ?? $this->bodyFor($request);
+
+        return new PinnedResponse($this->statusOverride, [], $request->url, [$request->url], $body);
+    }
+
+    private function bodyFor(PinnedRequest $request): string
+    {
+        if (str_contains($request->url, '.well-known/openid-configuration')) {
+            return json_encode($this->metadata(), JSON_THROW_ON_ERROR);
+        }
+
+        if (str_contains($request->url, '/jwks')) {
+            return json_encode($this->jwks(), JSON_THROW_ON_ERROR);
+        }
+
+        if (str_contains($request->url, '/token')) {
+            return json_encode([
+                'token_type' => 'Bearer',
+                'id_token' => $this->idTokenOverride ?? $this->idTokenForRequest($request),
+            ], JSON_THROW_ON_ERROR);
+        }
+
+        throw new RuntimeException('偽 IdP が知らない URL を受け取りました: '.$request->url);
+    }
+
+    /**
+     * 要求 body の `client_id` と、テストが記録した nonce から ID トークンを組み立てる。
+     *
+     * ★nonce は**要求からは取れない** (認可要求にしか載らない) ので、
+     *   テストが `withClaims(['nonce' => …])` で渡した値を使う。
+     */
+    private function idTokenForRequest(PinnedRequest $request): string
+    {
+        parse_str($request->body ?? '', $form);
+        $clientId = is_string($form['client_id'] ?? null) ? $form['client_id'] : 'client-unknown';
+
+        $nonce = $this->claimOverrides['nonce'] ?? null;
+        Assert::string($nonce, 'テストは withClaims([\'nonce\' => …]) で nonce を渡すこと');
+
+        return $this->idToken($clientId, $nonce);
+    }
+
+    private static function privateKey(): string
+    {
+        self::ensureKeyPair();
+        Assert::string(self::$privateKey);
+
+        return self::$privateKey;
+    }
+
+    private static function ensureKeyPair(): void
+    {
+        if (self::$privateKey !== null) {
+            return;
+        }
+
+        $resource = openssl_pkey_new([
+            'private_key_bits' => 2048,
+            'private_key_type' => OPENSSL_KEYTYPE_RSA,
+        ]);
+        Assert::notFalse($resource, 'RSA 鍵の生成に失敗した');
+
+        openssl_pkey_export($resource, $privateKey);
+        Assert::string($privateKey);
+        self::$privateKey = $privateKey;
+
+        $details = openssl_pkey_get_details($resource);
+        Assert::isArray($details);
+        Assert::isArray($details['rsa'] ?? null);
+
+        self::$publicJwk = [
+            'kty' => 'RSA',
+            'kid' => self::KEY_ID,
+            'alg' => 'RS256',
+            'use' => 'sig',
+            'n' => self::base64Url($details['rsa']['n']),
+            'e' => self::base64Url($details['rsa']['e']),
+        ];
+    }
+
+    private static function base64Url(string $binary): string
+    {
+        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
+    }
+
+    /** 試行が保持する nonce の指紋 (テストが nonce を組み立てるための補助)。 */
+    public static function nonceFingerprint(string $nonce): string
+    {
+        return AttemptFingerprint::of(FingerprintPurpose::Nonce, $nonce);
+    }
+}
diff --git a/tests/Support/ExternalSeam/ExternalSeamInventory.php b/tests/Support/ExternalSeam/ExternalSeamInventory.php
index 009c9c80..511e3947 100644
--- a/tests/Support/ExternalSeam/ExternalSeamInventory.php
+++ b/tests/Support/ExternalSeam/ExternalSeamInventory.php
@@ -11,6 +11,7 @@
 use App\Enums\Security\ExternalSeamDimension;
 use App\Enums\Security\ExternalSeamKind;
 use App\Providers\AppServiceProvider;
+use App\Services\Auth\EmailPromotionService;
 use App\Services\Auth\SocialiteDriverResolver;
 use App\Services\Billing\CashierAutoRechargeGateway;
 use App\Services\Billing\CashierStripeGateway;
@@ -131,7 +132,7 @@ classification: ExternalSeamClassification::Guarded,
                 rationale: 'Google siteverify の到達点。非本番は RecaptchaVerifierTestFake へ container bind で差し替わる',
             ),
 
-            // --- mail (3 クラス) ---
+            // --- mail (4 クラス) ---
             new ExternalSeamEntry(
                 class: CreateInquiryAction::class,
                 kind: ExternalSeamKind::Mail,
@@ -144,6 +145,12 @@ class: OrganizationMembershipService::class,
                 classification: ExternalSeamClassification::Guarded,
                 rationale: '組織招待メールの on-demand 送信点。外部到達の有無は mailer driver 設定が決める',
             ),
+            new ExternalSeamEntry(
+                class: EmailPromotionService::class,
+                kind: ExternalSeamKind::Mail,
+                classification: ExternalSeamClassification::Guarded,
+                rationale: 'メールアドレス昇格の確認メールの送信点 (T253)。外部到達の有無は mailer driver 設定が決める',
+            ),
             new ExternalSeamEntry(
                 class: UpdateUserProfileInformation::class,
                 kind: ExternalSeamKind::Mail,
diff --git a/tests/Support/InitialState/NullableStateColumnRegistry.php b/tests/Support/InitialState/NullableStateColumnRegistry.php
index 9c52cf95..2a000648 100644
--- a/tests/Support/InitialState/NullableStateColumnRegistry.php
+++ b/tests/Support/InitialState/NullableStateColumnRegistry.php
@@ -409,6 +409,21 @@ public static function entries(): array
                 '撮影端末が申告した撮影時刻を、テイクの登録時にそのまま書き込む。'
                 .'NULL は端末が時刻を申告しなかったことを意味し、進行段階ではない',
             ),
+            // --- 企業 SSO (T253) ---
+            NullableStateColumnEntry::initialStateMarker(
+                'organization_oidc_connections',
+                'verified_at',
+                '接続先情報の取得に成功したときだけロック下の既存行へ打刻し、認証材料を'
+                .'更新したら消す。NULL = まだ一度も確認できていない (既定値が付くと'
+                .'登録した瞬間に確認済みの接続ができる)',
+            ),
+            NullableStateColumnEntry::initialStateMarker(
+                'enterprise_identities',
+                'last_login_at',
+                '企業ログインが確定するたびに既存行へ打刻する。NULL = 身元は作られたが'
+                .'まだ一度もログインが確定していない',
+            ),
+
             NullableStateColumnEntry::setAtCreation(
                 'cuts',
                 'material_type',
diff --git a/tests/Support/Recovery/StuckWorkRecoveryInventory.php b/tests/Support/Recovery/StuckWorkRecoveryInventory.php
index b38107fb..b2ac5a03 100644
--- a/tests/Support/Recovery/StuckWorkRecoveryInventory.php
+++ b/tests/Support/Recovery/StuckWorkRecoveryInventory.php
@@ -155,6 +155,18 @@ public static function nonRecoverySchedules(): array
                 NonRecoveryScheduleReasonKind::RetentionSettlement,
                 '保持期限 (7 年) を過ぎた課金記録の削除と畳み込み。期限の決着であって滞留の前進ではない',
             ),
+            new NonRecoveryScheduleEntry(
+                'enterprise-sso:prune-login-attempts',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '期限切れの企業 SSO ログイン試行の物理削除。期限の決着であって滞留の前進ではない'
+                .'(戻ってこなかった試行を消すだけで、止まった処理を進めるわけではない)',
+            ),
+            new NonRecoveryScheduleEntry(
+                'auth:prune-email-promotions',
+                NonRecoveryScheduleReasonKind::RetentionSettlement,
+                '期限切れのメール昇格の確認待ちの物理削除。期限の決着であって滞留の前進ではない'
+                .'(消さないと利用者ごとの 1 件の枠が空かない)',
+            ),
             new NonRecoveryScheduleEntry(
                 'capture:purge-upload-reservations',
                 NonRecoveryScheduleReasonKind::RetentionSettlement,
diff --git a/tests/Support/Retention/RetentionTableRegistry.php b/tests/Support/Retention/RetentionTableRegistry.php
index 1426fb57..fc872834 100644
--- a/tests/Support/Retention/RetentionTableRegistry.php
+++ b/tests/Support/Retention/RetentionTableRegistry.php
@@ -4,7 +4,9 @@
 
 namespace Tests\Support\Retention;
 
+use App\Console\Commands\Auth\PruneEmailPromotionsCommand;
 use App\Console\Commands\Capture\PurgeUploadReservationsCommand;
+use App\Console\Commands\EnterpriseSso\PruneLoginAttemptsCommand;
 use App\Console\Commands\PurgeInquiriesCommand;
 use App\Support\Account\AccountDeletionGrace;
 use App\Support\Idempotency\IdempotencyRetention;
@@ -322,6 +324,31 @@ public static function entries(): array
                 'oauth_clients',
                 '接続してくる機械側の登録。廃止した登録をいつ消すかが未決である',
             ),
+            // --- 企業 SSO (T253) ---
+            RetentionTableEntry::deletedWithParent(
+                'organization_oidc_connections',
+                '組織と企業 IdP を結び付ける登録。組織を消せば連鎖して消える。'
+                .'身元が 1 件でもある接続は物理削除できず、運用は無効化で行う',
+            ),
+            RetentionTableEntry::deletedWithParent(
+                'enterprise_identities',
+                'IdP の身元と利用者の対応。接続と利用者のどちらを消しても連鎖して消える',
+            ),
+            RetentionTableEntry::scheduledDeletion(
+                'enterprise_sso_login_attempts',
+                '進行中の企業 SSO ログイン試行。期限切れの行を日次で物理削除する'
+                .'(callback のオンアクセス掃除と二段構え)',
+                PruneLoginAttemptsCommand::class,
+                'enterprise-sso:prune-login-attempts',
+            ),
+            RetentionTableEntry::scheduledDeletion(
+                'email_promotions',
+                'メールアドレス昇格の確認待ち。期限切れの行を日次で物理削除する'
+                .'(利用者ごとに 1 行しか持てないので消さないと次の昇格を始められない)',
+                PruneEmailPromotionsCommand::class,
+                'auth:prune-email-promotions',
+            ),
+
             RetentionTableEntry::undecided(
                 'oauth_sessions',
                 '機械向け接続の許諾の記録。利用者と組織の削除には連鎖するが、'
diff --git a/tests/Support/Routing/NestedRouteDefenseInventory.php b/tests/Support/Routing/NestedRouteDefenseInventory.php
index df11f712..471422cc 100644
--- a/tests/Support/Routing/NestedRouteDefenseInventory.php
+++ b/tests/Support/Routing/NestedRouteDefenseInventory.php
@@ -41,6 +41,7 @@ public static function inventory(): array
         $tenant = NestedRouteDefenseMode::TenantGuardMiddleware;
         $manual = NestedRouteDefenseMode::ManualOwnerScopedResolution;
         $nonRes = NestedRouteDefenseMode::NonResourceParameter;
+        $publicGlobal = NestedRouteDefenseMode::PublicGlobalResource;
 
         // {project} は web/API とも テナント guard middleware が binding 直後に走る (T108 S2)
         $project = ['project' => $tenant];
@@ -132,6 +133,19 @@ public static function inventory(): array
             'organizations.members.destroy' => ['organization' => $binder, 'user' => $scoped],
             'organizations.members.two-factor.reset' => ['organization' => $binder, 'user' => $scoped],
 
+            // --- 企業 OIDC SSO 接続 (T253) ---
+            // {oidcConnection} は $organization->oidcConnections() 経由 (scopeBindings)
+            'organizations.sso.index' => ['organization' => $binder],
+            'organizations.sso.store' => ['organization' => $binder],
+            'organizations.sso.update' => ['organization' => $binder, 'oidcConnection' => $scoped],
+            'organizations.sso.verify' => ['organization' => $binder, 'oidcConnection' => $scoped],
+            'organizations.sso.activate' => ['organization' => $binder, 'oidcConnection' => $scoped],
+            'organizations.sso.disable' => ['organization' => $binder, 'oidcConnection' => $scoped],
+            'organizations.sso.destroy' => ['organization' => $binder, 'oidcConnection' => $scoped],
+            // 公開のログイン導線。{connection} は**組織に属さない全体一意の識別名**であり、
+            // テナント親子関係の対象にならない (理由は nonTenantReasons)
+            'enterprise-sso.redirect' => ['connection' => $publicGlobal],
+
             // --- 通知センター (組織 URL 配下。一覧は全 org 横断だが URL は組織を持つ) ---
             'notifications.index' => $org,
             'notifications.read-all' => $org,
@@ -207,6 +221,9 @@ public static function nonTenantReasons(): array
             'mcp.oauth.protected-resource.nested#path' => 'vendor (laravel/mcp) の OAuth discovery。任意の後続セグメントでリソース id ではない',
             'storage.local#path' => 'Laravel の local disk 配信 route。署名付き URL でファイルパスを受ける (リソース id ではない)',
             'storage.local.upload#path' => 'Laravel の local disk アップロード route。署名付き URL でファイルパスを受ける',
+            'enterprise-sso.redirect#connection' => '企業ログインの公開導線の識別名。組織に属さない全体一意の値で、'
+                .'推測されてよい (防御は接続の状態と state / PKCE / ブラウザ結合が担う)。'
+                .'不在の識別名と使えない接続は PublicOidcConnectionBinder が同じ 404 に畳む',
         ];
     }
 
diff --git a/tests/Support/Routing/OrganizationlessWebRouteInventory.php b/tests/Support/Routing/OrganizationlessWebRouteInventory.php
index b7261e08..df6f40e6 100644
--- a/tests/Support/Routing/OrganizationlessWebRouteInventory.php
+++ b/tests/Support/Routing/OrganizationlessWebRouteInventory.php
@@ -42,6 +42,14 @@ public static function exactNames(): array
                 .'利用者に属する情報であり組織に属さない。',
             'settings.password.store' => '個人のパスワード初回設定。利用者の認証手段であり'
                 .'組織には属さない (組織ごとにパスワードが違うことはない)。',
+            'settings.email-promotion.store' => 'メールアドレスの昇格の発行 (T253)。'
+                .'企業 SSO でしか入れない利用者が自分の認証手段を持つための操作で、利用者に属し組織に属さない。',
+            'settings.email-promotion.resend' => 'メールアドレスの昇格の再送 (T253)。'
+                .'発行と対称の利用者単位の操作であり、組織文脈を持たない。',
+            'settings.email-promotion.confirm.show' => 'メールアドレスの昇格の確認画面 (T253)。'
+                .'メールのリンクから開く 1 枚で、利用者の認証手段の確認であり組織に属さない。',
+            'settings.email-promotion.confirm' => 'メールアドレスの昇格の確定 (T253)。'
+                .'利用者の認証手段を増やす操作であり組織に属さない。',
             'settings.account.destroy' => '退会 (即時)。利用者そのものを消す操作であり、'
                 .'特定の組織の文脈では実行しない。',
             'settings.account.deletion-request.store' => '退会予約。利用者そのものに対する操作で'
diff --git a/tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php b/tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php
index 83dfeeac..1f5b873f 100644
--- a/tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php
+++ b/tests/Support/Security/MachinePlaneOrganizationReferenceInventory.php
@@ -157,6 +157,12 @@ public static function all(): array
             'console:cli:client' => new NotOrganizationScoped(
                 'CLI 用の OAuth クライアントを作る。クライアントは組織ではなくアプリ全体に属する。'
             ),
+            'console:auth:prune-email-promotions' => new NotOrganizationScoped(
+                '期限切れのメール昇格の確認待ちを期限だけで削除する定期実行。組織を選ぶ外部入力を持たない。'
+            ),
+            'console:enterprise-sso:prune-login-attempts' => new NotOrganizationScoped(
+                '期限切れの企業 SSO ログイン試行を期限だけで削除する定期実行。組織を選ぶ外部入力を持たない。'
+            ),
             'console:idempotency:prune' => new NotOrganizationScoped(
                 '期限切れの冪等キーを全件走査で削除する定期実行。組織を選ぶ外部入力を持たない。'
             ),
diff --git a/tests/Support/TemplateDivergence/LedgerPins.php b/tests/Support/TemplateDivergence/LedgerPins.php
index 5998e511..11788ffb 100644
--- a/tests/Support/TemplateDivergence/LedgerPins.php
+++ b/tests/Support/TemplateDivergence/LedgerPins.php
@@ -19,7 +19,7 @@ final class LedgerPins
     private function __construct() {}
 
     /** 逸脱の登録件数 (宣言行 / 見出しの実数 / 本定数の 3 点一致)。 */
-    public const int DIVERGENCE_ENTRY_COUNT = 41;
+    public const int DIVERGENCE_ENTRY_COUNT = 45;
 
     /** 指紋台帳の登録パス件数 (「以下」ではない完全一致)。 */
     public const int FINGERPRINT_POPULATION_COUNT = 281;
@@ -31,7 +31,7 @@ private function __construct() {}
      *   増やせば通る)。増加を許さないのは生成器のガードとレビュー規約であり、
      *   検査は「一覧と定数と実測が食い違ったら赤」を担う。
      */
-    public const int ADOPTION_DEBT_COUNT = 151;
+    public const int ADOPTION_DEBT_COUNT = 148;
 
     /**
      * 採用時債務一覧を説明する逸脱の登録番号 (D34)。
diff --git a/tests/Support/TemplateDivergence/adoption-debt.tsv b/tests/Support/TemplateDivergence/adoption-debt.tsv
index b61cc766..b1d78ac7 100644
--- a/tests/Support/TemplateDivergence/adoption-debt.tsv
+++ b/tests/Support/TemplateDivergence/adoption-debt.tsv
@@ -31,7 +31,6 @@ app/Http/Middleware/ResolveApiActor.php	2d9a2f872155f34d47ababc32bd0a74e8253a565
 app/Http/Middleware/SecurityHeaders.php	7687386d9d5c3885c224a3b9c544fd4257d85721cef87fc39a81b23ff3b812f0
 app/Http/Routing/MembershipScopedOrganizationBinder.php	cdc18cce292638eac59c2e4768e4f259034952747f81e1d0608f2c6b520ae578
 app/Http/Routing/NormalizesRouteBindingInput.php	a8c1d829f4d3c3cda1477043216942972bbba094dda4030f5a10663a308adca1
-app/Http/Routing/RouteBindingTypes.php	46559984d899c9aa408219836c63485520a443e0ad216807ec712a8354079559
 app/Support/EmailNormalizer.php	bc10e294cc0190806741999099379ec500ee0731a9c92b7e06ccda0444093500
 config/audit.php	b944df99bd203a86c967f9237f8d77371394577ee6e82b45df9404f2cfd38860
 config/cache.php	6c38c4eea0562afd19663cf5cd7d65a477266e023bb2094d6a2f58db2485d040
@@ -77,7 +76,6 @@ tests/Architecture/BillingRetentionConfigSingleSourceTest.php	d03eb1ed368cb00545
 tests/Architecture/BillingRetentionTargetInventoryTest.php	338da106bfe063adb4f23285933c59c76bb044c44cf802404eab605211b4719b
 tests/Architecture/BugHuntSkillInvariantTest.php	7ac57d13113b5bb97c6aa252d30f825f8438f3c275281fedabc5e8fd41a837b4
 tests/Architecture/BughuntOrchestratorGateInvariantTest.php	d6c12c7a5faba29643a98f3b8bcabb31b10d957ea59845c4d6b34f0dfa2cc299
-tests/Architecture/CachePayloadPlainDataGateTest.php	c92f8a4b364fcad254869f43327bc5c99a2fa55b618c05428f7e90cbabd87508
 tests/Architecture/CarbonOverflowArithmeticGateTest.php	30dbbf0af932e1aba992d7ba61379bdc002d30da68f3f23a0fae1f0200e1d9d1
 tests/Architecture/ClaudeHooksWiringTest.php	04c6385e626e87c1c073dc4efcd3e93c9fda2b95034792ee0e8a30d861e2a9ce
 tests/Architecture/DefensiveInstructionsPresenceTest.php	10ee98844f033287a78052d8b31b79c29de0014f0f94324da2f3904068847be0
@@ -105,7 +103,6 @@ tests/Architecture/PromptUntrustedInputContractTest.php	7c63bbd7bbde9e3aaa99965d
 tests/Architecture/PromptYamlContractTest.php	65b420e54bccd41618f10d41f46213a207b6fca9844e91fd162344ded23b6416
 tests/Architecture/QueueDispatchAtomicityInventoryTest.php	4175168181d08e5f9d24d45ba4e9378c56d9885170338a124517247f16e166d8
 tests/Architecture/QueueWorkerLeaseInvariantTest.php	4504f2928cc9b96de7c9bcf901d9e3a9b48cd186293e3a1d0f9b0947f66042e0
-tests/Architecture/RecentAuthRouteTest.php	06dfa019ca22c9c8bb0bdf07d880dd6aabd61b07684fab030fe46d54e1b3d865
 tests/Architecture/RescueRouteGateInventoryTest.php	03bb831a621f7d8dec0d35e677d88755894d96044ba31a201589d96a79fbf2f9
 tests/Architecture/RestWriteScopeRevalidationInvariantTest.php	6c86a2df15ddd20bd03e4e6926a64578541a7b2a2f0c69c55b6b0da8aae11821
 tests/Architecture/RetiredRecoveryReferenceGateTest.php	23feae580f4bb7b967b04c0c06046fe79e64136b55a82a892fd3e468e20ae66d
```

## 実装差分 3/3: 振る舞い検査のファイル一覧 (tests/Feature, tests/Unit, tests/js)

★本文は分量の都合で省く。**必要ならリポジトリを読んでよい** (worktree は /workspace/.claude/worktrees/tasks/T253 )。

```
 tests/Feature/Auth/EmailPromotionTest.php          | 335 +++++++++++++++++++
 tests/Feature/Auth/EnterpriseOnlyUserEmailTest.php | 133 ++++++++
 tests/Feature/Auth/EnterpriseSsoLoginTest.php      | 297 +++++++++++++++++
 .../EnterpriseIdTokenVerifierTest.php              | 234 ++++++++++++++
 .../EnterpriseIdentityCipherSweetTest.php          |  46 +++
 .../EnterpriseIdentityIsolationTest.php            | 142 ++++++++
 .../EnterpriseLoginAttemptStoreTest.php            | 227 +++++++++++++
 .../EnterpriseSso/EnterpriseSsoConfigTest.php      |  63 ++++
 .../EnterpriseSso/EnterpriseSsoHttpWiringTest.php  |  59 ++++
 .../EnterpriseSso/EnterpriseSsoModelHidingTest.php |  90 ++++++
 .../EnterpriseUserProvisionerTest.php              | 191 +++++++++++
 .../OidcConnectionTransitionServiceTest.php        | 275 ++++++++++++++++
 .../OidcConnectionVerifyLinearizationTest.php      | 267 +++++++++++++++
 .../EnterpriseSso/OidcDiscoveryServiceTest.php     | 238 ++++++++++++++
 .../EnterpriseSso/OidcTokenExchangerTest.php       | 188 +++++++++++
 .../EnterpriseSso/PruneLoginAttemptsTest.php       |  64 ++++
 .../NullInitialStateColumnClassificationTest.php   |  20 +-
 .../OrganizationSsoConnectionTest.php              | 357 +++++++++++++++++++++
 .../Retention/RetentionTableClassificationTest.php |   2 +-
 .../EnterpriseSsoSourceScannerTest.php             | 108 +++++++
 tests/Unit/Enums/OidcSigningAlgorithmTest.php      |  31 ++
 tests/Unit/Support/AttemptFingerprintTest.php      |  56 ++++
 tests/Unit/Support/BasicCredentialsTest.php        |  36 +++
 tests/Unit/ValueObjects/ConnectionSecretTest.php   |  66 ++++
 tests/Unit/ValueObjects/OidcIssuerUrlTest.php      |  65 ++++
 .../js/architecture/enum-ts-sync-discovery.test.ts |   8 +-
 .../recent-auth-modal-call-site-inventory.test.ts  |   2 +
 .../features/sso/oidc-connection.test.ts           |  46 +++
 tests/js/support/enum-ts-sync/mirror-inventory.ts  |   8 +-
 29 files changed, 3650 insertions(+), 4 deletions(-)
```
