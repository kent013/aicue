# アプリの使命（North Star）

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割

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

---

# 追加の文脈（この設計の位置づけ）

本設計は aicue 単独の思いつきではなく、**家系（6 リポジトリ）の機能台帳 lctl** の
feature `ssrf-pin-boundary`（canonical_version t0）が aicue セルへ明示指定した
`target_version` への追従である。target_version の原文:

> kent013/laravel-ssrf-pin を完全区間分類の版 (^0.4) へ改版し回帰テストで受ける (手本 spirux@a41aabbd)

- 割り当ての性格: キュレーターによる**安全上の追従**（裁定 = settle を経ずに割り当て済み）。
  AG-003b が settle へ委ねた「正典の版を 1 つ上げるか」の論点は**別件**であり、本追従とは独立に処理してよい。
- 家系の版の現状: spirux `^0.4` / laravel-claude-template・aigenba・metamovics `^0.3` / aicue・motivation `^0.2`。
- 配布経路の裁定 AG-003（2026-08-14 で言い直し済み）: 「取得元をバージョン管理システムの参照に統一し、
  版を明示して固定する。版は正典リポジトリの前進に追従する」（版番号は裁定文に焼き込まない）。
- 手本 spirux@a41aabbd は、回帰テストを先に書いて 0.2 に対し 9 failed を確認してから版を上げた。

## レビューにあたって特に見てほしい点

1. **スコープの絞り方が妥当か**。第二層の契約検査 / `config/ssrf-pin.php` への
   `registry_version` 追加 / `PinnedHttpClient` への一本化 を、いずれも「スコープ外」に
   置いた判断（理由は本文の表に記載）。過小になっていないか。
2. **既存 fixture（TEST-NET-3 = `203.0.113.10`）の差し替えが「検査を緩める」ことになっていないか**。
   実測で「版を上げると既存の 3 テストが赤くなる」ことが判明しており、
   その直し方として fixture を公開到達可能なアドレスへ移す方針を採っている。
3. **`deny_ip_literals: true` により、手本（spirux）の回帰テストの書き方をそのまま写すと
   偽グリーンになる**という指摘の妥当性。
4. **採用時債務パス（`config/ssrf-pin.php` / `SsrfPinBoundaryTest.php` / `SnsTestData.php`）に
   触らない設計にしたこと**が、逆に不自然な回り道になっていないか。

---

## 概念設計

# 概念設計: ssrf-pin-v04-upgrade

家系の機能台帳 lctl の feature `ssrf-pin-boundary` (canonical_version t0) の
`projects.aicue.target_version` —
**「kent013/laravel-ssrf-pin を完全区間分類の版 (^0.4) へ改版し回帰テストで受ける (手本 spirux@a41aabbd)」**
への安全追従。裁定 (settle) 待ちではなく、キュレーターが家系全体へ割り当てた
**安全上の追従**である (AG-003b の settle 論点とは独立)。

## 背景・課題

### いま入っている版が持つ穴 (実測で確定)

aicue の `composer.json` は `kent013/laravel-ssrf-pin: "^0.2"`、
`composer.lock` の解決版は `v0.2.0` (reference `eeff6189…`) である。

v0.2 の `UrlSafetyInspector::classifyIp()` は**列挙型の拒否**である
(`DENY_CIDRS_V4` 12 件 / `DENY_CIDRS_V6` 8 件を順に当て、
どれにも当たらなければ「拒否規則に該当しない = 許可」)。この形は
**IANA Special-Purpose Address Registry の特殊用途アドレス 8 区間**を列挙に持たないため、
そこへ解決される host が素通りする:

| 区間 | 用途 |
|---|---|
| `192.0.2.0/24` | TEST-NET-1 (ドキュメント用) |
| `198.51.100.0/24` | TEST-NET-2 |
| `203.0.113.0/24` | TEST-NET-3 |
| `192.88.99.0/24` | 6to4 relay anycast (廃止済み) |
| `2001:db8::/32` | IPv6 ドキュメント用 |
| `2002::/16` | 6to4 |
| `3fff::/20` | IPv6 ドキュメント用 (新) |
| `5f00::/16` | SRv6 SID |

**本リポジトリの現物で実測した** (`vendor/kent013/laravel-ssrf-pin` v0.2.0 を直接叩き、
aicue の pin 値 — schemes `http|https` / ports `80,443` /
`additional_deny_cidrs` 空 / `deny_ip_literals: true` — を与えた):

```
v0.2.0  DNS 応答 203.0.113.10  → allowed=true
v0.2.0  DNS 応答 192.0.2.1     → allowed=true
v0.2.0  DNS 応答 93.184.216.34 → allowed=true   (これは正しい)
```

### 穴が実際に届く経路が aicue には既にある

台帳の aicue セルは古い観測 (`aicue@a5553b5`) では「package を使う app 側の経路は 0 件」と
書いているが、**その後 aicue:T229 で経路ができた** (台帳も差分巡回 2026-08-19 で追記済み):

`app/Services/Mail/Sns/SnsCertificateFetcher.php::inspect()` が
`UrlSafetyInspector::inspect()` を掛けている。これは**無認証の SNS 受け口が誘発する外部取得**で、
攻撃者が提示した URL を検証のために取りに行く経路である。
取得先の host は値オブジェクト `SnsCertificateUrl` が `sns.<region>.amazonaws.com` の
厳格な書式に固定しているので、悪用には DNS を握る (split-horizon / rebinding) 必要があるが、
**判定層の穴は「その host が 203.0.113.x へ解決されたら通す」という形で現に開いている**。
`SnsCertificateFetcher` の docblock 自身が「DNS rebinding は解消しない」と明記しており、
つまり**判定層の網の細かさがそのまま防御の実体**である。

### 上流 v0.4.1 が何を変えたか (上流を clone して実読)

`kent013/laravel-ssrf-pin` を実際に clone し、`v0.2.0..v0.4.1` の差分を読んだ。

- **v0.3** (`46f16c1`): 要求 / 応答の body 対応・`followRedirects`・大きさ制限つき読み出し。
  **判定層 (`src/UrlSafetyInspector.php`) は無変更**。config へ `max_body_bytes` が 1 件増えた。
- **v0.4** (`03fd3b9`) / **v0.4.1** (`93ba837`): 判定層の反転。
  - `resources/ip-classification.json` (registry_version `2025-10-09` / **IPv4 28 区間 / IPv6 22 区間**)
    を単一ソースに、`src/Ip/IpClassificationTable.php` がアドレス空間を**完全に分割**する。
  - load 時に「隙間なし / 重複なし / `globally_reachable` 欠落なし /
    false の区間に `deny_reason` があること」を検査し、崩れていたら例外
    (**表が壊れたまま静かに fail-open しない**)。
  - `Reachability` enum を新設。**`PublicUnicast` だけが許可**で、
    `NotGloballyReachable` と `Unclassified` はどちらも拒否へ倒す。
    「一致しなければ Public」をやめた。
  - 判定経路から IP の文字列比較を排除し `inet_pton` のバイナリ二分探索だけにした。
  - `SsrfDenyReason` に `NotGloballyReachable` を**追加のみ**。
  - `UrlSafetyInspector::classificationRegistryVersion()` を公開 (判定に使った登録簿の版が読める)。

**後方互換**: 上流の `tests/Unit/BackwardCompatibilityTest.php` が
「新規フィールド・新規引数はすべて既定値つきで末尾に追加」を pin している。
`UrlSafetyDecision` は無変更、`UrlSafetyInspector::__construct` は第 6 引数
(`?IpClassificationTable`、既定 null) が末尾に増えただけ。
`SsrfDenyReason` は case の**追加のみ**で削除・改名なし。
**aicue の呼び出し側 (`SnsCertificateFetcher`) は無改修で通る形**である。

**依存**: `composer.json` に `guzzlehttp/psr7: ^2.4` と `psr/http-message: ^1.1 || ^2.0` が
増えている。aicue の `composer.lock` は既に `guzzlehttp/psr7 2.13.0` /
`psr/http-message 2.0` を持つので**新規取得は発生しない**見込みである。

### aicue の現状で v0.4.1 に上げると何が起きるか (実測)

上流 v0.4.1 を clone して aicue の pin 値を与え、DNS 応答を差し替えて測った:

```
v0.4.1  DNS 応答 203.0.113.10 → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 192.0.2.1    → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 198.51.100.7 → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 192.88.99.1  → allowed=false / not_globally_reachable
v0.4.1  DNS 応答 2001:db8::1 / 2002::1 / 3fff::1 / 5f00::1 → すべて false / not_globally_reachable
v0.4.1  DNS 応答 10.0.0.5     → allowed=false / private_range   (従来どおり)
v0.4.1  DNS 応答 93.184.216.34 → allowed=true                    (従来どおり)
v0.4.1  DNS 応答 2606:2800:220:1:248:1893:25c8:1946 → allowed=true
v0.4.1  classificationRegistryVersion() = "2025-10-09"
```

**★ここが本設計で最も重要な発見である。**
aicue の既存テストは SNS 証明書 host の DNS 応答を **`203.0.113.10` (TEST-NET-3)** に
固定している。これは塞がる 8 区間のうちの 1 つなので、版を上げると
**既存テストが「意図せず」赤くなる**。該当は 3 か所:

| ファイル | 行 | 内容 |
|---|---|---|
| `tests/Feature/Mail/SnsCertificateFetcherTest.php` | 38 | `beforeEach` の `bindSnsDnsResolver(['203.0.113.10'])` |
| `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` | 16 | 同 |
| `tests/Feature/Mail/SesSignatureMiddlewareTest.php` | 25 | 同 |

とくに `SnsCertificateFetcherTest` の
「F0 (正のコントロール): 正常系 fixture は SSRF 検査を通る」は
**「境界が変わったらここが最初に赤くなる」ことを目的に置かれた検査**であり、
設計どおりに機能している。**fixture を「本当に公開到達可能なアドレス」へ差し替える**のが
正しい直し方であって、検査を緩めるのではない。

なお repo 内の他の `203.0.113.x` / `192.0.2.x` / `2001:db8::` の出現
(rate limiter のキー・passkey の origin・trusted proxy の CIDR 表記・監査ログの ip_address)
は `UrlSafetyInspector` を通らないので**影響しない**ことを全数確認した。

### `deny_ip_literals: true` が回帰テストの書き方を決める

aicue の pin 値は `deny_ip_literals => true` である。`inspect()` は
**IP literal を分類より前に short-circuit する**ので:

```
v0.4.1  URL http://192.0.2.1/ (deny_ip_literals=true) → false / ip_literal_not_allowed
```

つまり **spirux の手本 (`http://192.0.2.1/` のような IP literal URL を並べる形) を
aicue にそのまま写すと、8 区間を 1 つも検査しないまま緑になる** (`ip_literal_not_allowed` で
落ちるだけ)。aicue の回帰テストは**必ず「host → DNS 応答」経由**で書かなければならない。
これは偽グリーンの罠であり、設計に明記して実装へ渡す。

## 改善アイデア

「共有パッケージの版を上げ、塞がった区間を回帰テストで受け、既存 fixture の
前提崩れを直す」— これだけを行う。判定規則を aicue 側で再実装しない
(判定の正本は共有パッケージにある)。

1. **版制約を上げる** — `composer.json` の `^0.2` → `^0.4`。
2. **lock を再解決する** — 当該 1 パッケージだけを更新し、
   「動いたのはその 1 件だけ」を機械照合で確認する。
3. **塞がった 8 区間を回帰テストで固定する** — 新規 Architecture テスト 1 本。
   `app(UrlSafetyInspector::class)` を通し、**DNS 応答経由**で 8 区間が
   `NotGloballyReachable` で拒否されることを pin する。
   併せて (a) 従来から拒否していた古典区分が**緩んでいない**こと、
   (b) 公開到達可能なアドレスは通ること (正のコントロール)、
   (c) 混在応答 (public + 特殊用途) が拒否されること、
   (d) **判定に使われた登録簿の版が `2025-10-09` であること**を固定する。
4. **既存 fixture の前提崩れを直す** — TEST-NET-3 を使っている 3 か所を
   公開到達可能なアドレスへ移し、その値の出所を 1 か所にする。
5. **AGENTS.md の不変条件 8 を実態へ揃える** — 拒否の形が
   「列挙型 deny」から「完全区間分類 + 既定拒否」へ変わり、
   安全境界の一部が**登録簿の版**になった。gate の名前と、
   登録簿が古くなると fail-open しうる性質を 1 段落で足す。

### なぜ「回帰テスト」が追従の本体なのか

版を上げるだけなら composer の 2 ファイルで済む。だが本 feature の boundary は
「`config/ssrf-pin.php` + `SsrfPinBoundaryTest` + パッケージ配布形」であり、
**「導入した版が実際に何を備えているか」は composer 制約とは独立に固定されなければ、
次に誰かが `composer update` の巻き添えで版を戻したときに黙って穴が開く**。
target_version が「改版し**回帰テストで受ける**」と書いているのはこの意味である。

## 期待効果

- **使命への貢献**: 直接の貢献は「SOP → シナリオ → ナビ撮影」の機能面ではなく、
  その土台の**安全**である。aicue は SES/SNS 経由のメール受信という
  **無認証の入口**を持ち、そこが外部 URL 取得を誘発する。ここで内部宛て・
  非公開到達アドレスへの誘導が通ると、現場の SOP と撮影データを預かる基盤の
  信頼が崩れる。使命の前提条件を守る施策である。
- **具体的な改善見込み**:
  - 素通りしていた IANA 特殊用途 8 区間 (IPv4 4 / IPv6 4) が拒否される。
  - 「拒否規則に当たらない = 許可」という **fail-open の既定が消える**
    (未分類・登録簿破損は拒否 / load 時例外)。
  - 判定経路から IP の文字列比較が消え、表記揺れ由来の取りこぼしが減る。
  - 家系の版の割れ (spirux `^0.4` / template・aigenba・metamovics `^0.3` /
    aicue・motivation `^0.2`) のうち aicue 分が解消し、**家系で最も進んだ版に並ぶ**。

## 実装方針（概要）

| # | 変更 | ファイル |
|---|---|---|
| A | 版制約 `^0.2` → `^0.4` | `composer.json` |
| B | 当該 1 パッケージのみ再解決 | `composer.lock` |
| C | 塞がった区間の回帰 gate を新設 | `tests/Architecture/SsrfPinSpecialPurposeRangeRegressionTest.php` (新規) |
| D | TEST-NET-3 fixture を公開到達可能なアドレスへ移す (出所は 1 か所) | `tests/Pest.php` / `tests/Feature/Mail/SnsCertificateFetcherTest.php` / `tests/Unit/Mail/AwsSnsSignatureVerifierTest.php` / `tests/Feature/Mail/SesSignatureMiddlewareTest.php` |
| E | 不変条件 8 の記述を実態へ揃える | `AGENTS.md` |

### C の書き方 (核心)

- `bindSnsDnsResolver()` (`tests/Pest.php`) と同じ作法で
  **`DnsResolverInterface` を差し替える**。`UrlSafetyInspector` 自身は
  `ExternalFakeDeclaration::neverSwapped()` で偽物にできないので、
  差し替えるのは**その依存**である (既存の作法をそのまま使う)。
- 検査対象は `app(UrlSafetyInspector::class)` — **アプリの config pin 値 →
  provider の結線 → v0.4 の分類表**を通した実物である
  (テスト内で inspector を直接 `new` すると config pin の結線を検査しない)。
- **IP literal URL を使わない** (`deny_ip_literals: true` で short-circuit する = 偽グリーン)。
- DB 不要なので `tests/Architecture/` に置く (`SsrfPinBoundaryTest` と同じレーン)。

### 触らないもの (意図的)

- **`config/ssrf-pin.php` は 1 文字も変えない**。pin 値 5 つ
  (`allowed_schemes` / `allowed_ports` / `max_redirect_hops` /
  `additional_deny_cidrs` / `deny_ip_literals`) を維持する。
  v0.4.1 の package 側 config が持つ `max_body_bytes` は
  `mergeConfigFrom` で package 既定 (1 MiB) が入る。
  aicue は `PinnedHttpClient` を 1 か所も使っていないので、
  この値は aicue の判定にも取得にも影響しない。
- **`tests/Architecture/SsrfPinBoundaryTest.php` も変えない**。
  pin 値の固定と「境界で拒否できる」ことの検査は v0.4.1 でもそのまま通る
  (IP literal 拒否 / スキーム / ポートはいずれも分類層より前で決まる)。
  回帰は**別ファイル**で足す。

## 制約・前提

- **判定規則を aicue 側で再実装しない**。判定の正本は共有パッケージ
  `kent013/laravel-ssrf-pin` にあり、aicue は「版で追随 + 回帰テストで受ける」形をとる
  (spirux の手本と同じ)。
- **配布経路は AG-003 のとおり**: `composer.json` の `repositories` は
  VCS 参照 (`https://github.com/kent013/laravel-ssrf-pin.git`)、版は明示指定。
  この形は変えない (版番号だけを上げる)。
- **不変条件 (正典由来。詳細設計で全数を列挙する)**:
  1. 判定は `UrlSafetyInspector` に一元化されたまま (aicue 側に deny 規則の実装を作らない)。
  2. `config/ssrf-pin.php` の pin 値 5 つを維持。
  3. 境界 gate が緩まない (既存の `SsrfPinBoundaryTest` を弱めない・削らない)。
  4. 外部 URL 取得は SSRF 検査経由 (AGENTS.md セキュリティ不変条件 8)。
  5. 配布経路は VCS 参照 + 版指定 (AG-003)。
- **乖離台帳との関係** (Phase 3-0 で正式に確認する。ここでは前提として押さえる):
  - `composer.json` / `composer.lock` / `AGENTS.md` / `tests/Pest.php` は
    `docs/template-fingerprints.json` の `entries` に**無い** = 突合の母集合外。
  - `config/ssrf-pin.php` と `tests/Architecture/SsrfPinBoundaryTest.php` と
    `tests/Support/SnsTestData.php` は**指紋台帳にあり、かつ採用時債務一覧にもある**。
    債務パスは採用時ハッシュとの一致まで見られるので、**変更すると
    「変更したまま債務に残す」が選べなくなる**。3 件はいずれも**触らない**設計にする
    (`SnsTestData.php` に定数を足したくなるが、これがまさに債務パスなので避ける)。
- PHPStan level 10 / Pest / `RefreshDatabase` はグローバル適用 (Architecture レーンは DB なし) /
  テストデータは Factory / DTO + JsonResource。
- 新規テストファイルの作法上の制約 (既存 gate 由来):
  - `declare(strict_types=1);` 必須 (`StrictTypesDeclarationGateTest`)。
  - グローバル名前空間で**非複合名の `use` を書かない**
    (`NoNonCompoundGlobalUseTest`。`use ReflectionMethod;` の類は違反になる)。
  - キャッシュに触らない (触ると `CachePayloadPlainDataGateTest` の面目録への登録が要る)。
  - 外向き HTTP を出さない (`StrayHttpRequestGuard` は Architecture レーンでも既定 ON)。

## スコープ外

| 項目 | 理由 |
|---|---|
| 第二層 (`SsrfPinPackageContractTest` — 導入した版が実際に何を備えるかの契約検査) の新設 | 正典が「**第二層は t0 の必須要素ではない**」と明示している (台帳の 2026-08-18 夕 第 2 ラウンド)。保有は laravel-claude-template と aigenba の 2 本の先行分。本追従の target_version にも含まれない。過大化させない |
| `config/ssrf-pin.php` への `registry_version` の pin (spirux はやっている) | pin 値 5 つ維持の不変条件に反し、かつ同ファイルは**採用時債務パス**なので触ると債務の整理が連鎖する。登録簿の版は `classificationRegistryVersion()` で読めるので、**新設 gate の中で pin する**ことで同じ目的 (陳腐化の検知) を達成する |
| `config/ssrf-pin.php` への `max_body_bytes` の明示 | aicue は `PinnedHttpClient` を 1 か所も使っていない。`mergeConfigFrom` で package 既定 1 MiB が入るので実効挙動に差が無く、上と同じ債務パスの問題がある |
| `PinnedHttpClient` への取得の一本化 | 本 feature の boundary が「呼び出し側は各機能側」と切っている。加えて `PinnedResponse` が本文を返せる形になったのは v0.3 以降で、`SnsCertificateFetcher` を書き換える判断は `mail-ses-suppression` 側の管轄 (aicue:T229 の裁定 AG-199 で「inspect → fetch」を採る判断が既に済んでいる) |
| `docs/ses-mail-runbook.md` の 403 切り分け表の文言更新 (「private IP へ解決されていないか」→ 拒否区分の広がり) | 同ファイルは**指紋台帳 + 採用時債務パス**である。現行の記述は誤りではなく (private IP は依然その一例) 網羅的でないだけなので、債務の整理を伴う変更に見合わない。再判定の条件: 同ファイルが別の理由で債務から外れたとき |
| `docs/architecture.md` の SNS 節 | 「DNS 解決失敗のみ 503・他は 403」という記述は v0.4.1 でもそのまま正しい (`NotGloballyReachable` は 403 側に落ちる)。更新すべき事実が無い |
| 家系全体の版の扱い / 正典の版を 1 つ上げるかの判断 (AG-003b の settle 論点) | aicue 1 リポジトリ分の安全追従であり、settle の代行はしない。他リポジトリの追従も本設計の対象外 |
| aigenba の gate 名の割れ (`SsrfPinBoundaryTest` 名の統一) | aicue には既に正典と同名の gate がある。他リポジトリの話 |
| TypeScript 側の URL 安全性判定 | 本 feature の boundary が明示的に除外 (`capture-core-package` の管轄)。aicue に該当実装は無い |

