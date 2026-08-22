# 概念設計: svelte-raw-html-sink-ban (家系正典 t1 への追従)

## 背景・課題

家系の機能台帳 lctl の feature `svelte-raw-html-sink-ban` (canonical_version: **t1**、area: security、
feature_revision `3-dc59a9928099`) は、**画面テンプレートで文字列を HTML として差し込む構文
(`{@html}`) を deny-by-default で全面禁止し、同時に唯一の正当な用途に置き換え先の部品を配る**
という家系標準形である。

正典の boundary は「含む」を **4 点そろい**と明示している:

1. lint 設定で当該規則を **error** にすること
2. **ファイル内のコメントで規則を無効化できない**こと
   (無効化の 3 形式を負例として**実際に lint を走らせて**確かめる)
3. 対象ディレクトリ配下の**実ファイルに当該構文が 0 件**であることの直接固定
4. 唯一の正当な用途 (サーバ生成の QR を描く) に対する**置き換え先の部品**と、
   その部品が依存する**応答ヘッダの指示**の固定

正典側 (laravel-claude-template@1dda94e) は、この 4 点を
`eslint.config.js` / `tests/js/architecture/svelte-raw-html-gate.test.ts` /
`resources/js/components/atoms/QrCodeImage.svelte` /
`tests/Feature/Security/SecurityHeadersTest.php` の一式で実装している。
台帳は **許可一覧の口を持たない**方針まで明記しており、
「例外を設けるなら別のセキュリティ設計としてレビューを通せ」と書いてある。

### aicue の現状 (実測。本設計の起点)

台帳の aicue エントリは `status: pending` / 観測点 `aicue@03a69350`。
今回あらためて実読した結果は次のとおり:

| 正典の要求 | aicue の現状 | 差分 |
|---|---|---|
| (1) lint 規則 error | `eslint.config.js` の `.svelte` rules に `svelte/no-at-html-tags` が**無い** | **欠落** |
| (2) コメントで無効化できない | `{ linterOptions: { noInlineConfig: true } }` が lint 対象全体に**既にある** (D11 が config 静的検査で固定) | 前提は充足。ただし**「実際に lint を走らせた振る舞いの裏取り」は無い** |
| (3) 実ファイル 0 件 | `resources/js/pages/Settings/Security.svelte:640` に `{@html qrSvg}` が **1 件**残存 (`rg` 全数走査で他に 0 件) | **欠落 (残存 1 件)** |
| (4) 置き換え部品 + 応答ヘッダ | `resources/js/components/atoms/QrCodeImage.svelte` は**不在**。CSP は `config/security.php` に `img-src 'self' data:` (既定) と gtm overlay 側 `img-src 'self' data: …` の **2 構成とも既に data: を許可**しているが、**`data:` を pin する検査が無い** (`GtmCspTest` は GTM ホストのみ検査) | 部品が**欠落**、ヘッダは値はあるが**pin が欠落** |

つまり aicue は **4 点のうち 1 点も機械で固定していない**。
残存 1 件はテンプレート由来の同一箇所 (2 要素認証の QR 表示) で、
台帳が「置き換え先の部品を移植すればそのまま消せる同型の追従」と判定しているものである。

### なぜ今やるか (実害の形)

`{@html}` は「便利な逃げ道」であり、**禁止だけを配ると現場は使い続ける**。
現状 aicue では lint も検査も何も無いため、**レビューの見落とし 1 回**で
外部由来の文字列が HTML として画面へ流し込まれ、閲覧者の browser で script が走る
(セッション乗っ取り・撮影データの持ち出し) 事故が成立する。
本アプリは撮影 PWA が同一オリジン・セッション認証で動くため、
XSS の成立は撮影導線の資格情報にそのまま届く。

現在の 1 件 (`qrSvg`) は**自サーバの Fortify が生成した SVG** なので今すぐ悪用される値ではないが、
- 「ここでは使ってよい」という**前例**が resources/js に見えている状態そのものが穴であり、
- `{@html}` を通す限り、**サーバ側の QR 生成器が将来別実装に差し替わった瞬間**に
  無検査の HTML 注入点へ戻る。

## 改善アイデア

**正典 t1 を 4 点そろいでそのまま採る (同型の追従)。許可一覧の口は作らない。**

1. **lint で落とす**: `eslint.config.js` の `.svelte` ブロックに
   `"svelte/no-at-html-tags": "error"` を足す。
   併せて「例外は許可一覧ではなく別のセキュリティ設計としてレビューを通す」方針をコメントで宣言する。
2. **無効化できないことを振る舞いで裏取りする**: 新設 gate
   `tests/js/architecture/svelte-raw-html-gate.test.ts` が **ESLint を実際に走らせて**、
   `{@html}` を含む合成入力が error になること、
   および**無効化コメントを付けてもなお error のまま**であることを固定する。

   無効化の **3 形式**は次のとおり具体に確定する (いずれも `.svelte` の markup 区間に置く。
   Svelte のテンプレートでは HTML コメントが ESLint の inline configuration として解釈される):

   | # | 形式 | 置き方 |
   |---|---|---|
   | (i) | ファイル全体の一括無効化 | 先頭に `<!-- eslint-disable -->` |
   | (ii) | 対象ルールを名指しした一括無効化 | 先頭に `<!-- eslint-disable svelte/no-at-html-tags -->` |
   | (iii) | 次行だけの無効化 | 違反行の直前に `<!-- eslint-disable-next-line svelte/no-at-html-tags -->` |

   **さらに「負例が負例として効いていること」自体を裏取りする**。
   3 形式それぞれについて、`noInlineConfig` を **false にした対照条件**では
   実際に error が消えることを同じ gate で確かめる。
   これが無いと「その 3 形式が元から ESLint に解釈されていない文字列だった」場合と
   「`noInlineConfig` が効いて抑制を跳ね返した」場合を区別できず、
   検出力の裏取りが空振りする (AGENTS.md 走査器共通規約 (c) の趣旨)。
3. **実ファイル 0 件を直接固定する**: 同 gate が `resources/js` 配下の `.svelte` 全数を走査し、
   `{@html}` が 0 件であることを固定する (母集団が空なら fail = 空振り防止)。
4. **置き換え先の部品を配り、唯一の実在サイトを置換する**:
   - 新設 atom `resources/js/components/atoms/QrCodeImage.svelte` が、
     サーバ生成の SVG 文字列を **data URI の `<img>`** として描く。
     狙いは **DOM への HTML 挿入をやめ、SVG を「画像リソース」として扱う経路へ移すこと**である
     (`{@html}` は文字列を DOM 木として解釈させるが、`<img>` は画像として読ませる)。
   - **props 契約**: `svg: string` (必須) と `alt: string` (必須) の 2 つだけ。
     `src` は `data:image/svg+xml,${encodeURIComponent(svg)}` で生成する。
     **base64 は採らない** — `btoa()` は非 ASCII を含む SVG で例外を投げ、
     `TextEncoder` 経由で base64 化しても安全性は同じで手数だけ増えるためである。
     素朴な文字列連結は `#` (fragment 開始) で切れ、`%` が不正な percent escape になり、
     非 ASCII で壊れるので**採らない**。
   - **nullable は atom に吸収させない**。`qrSvg: string | null` の分岐は既存の状態機械
     (取得失敗 Alert / step-up / 世代管理) が持っており、
     置換後も**既存の `{#if qrSvg}` 分岐の内側でだけ**部品を描く (分岐の正本を 2 か所にしない)。
   - `Settings/Security.svelte` の `{@html qrSvg}` をこの部品に置換する。
   - 部品が `data:` に依存するので、**CSP の `img-src` が `data:` を含むことを
     既定構成と GTM 有効構成の 2 通りで pin する**。

## 期待効果

- **使命への貢献**: 撮影 PWA は同一オリジン・セッション認証で現場の撮影データを扱う。
  XSS が 1 回成立すると、その資格情報とアップロード導線がそのまま奪われる。
  本設計は「レビューの見落とし 1 回で成立する経路」を**言語構文のレベルで閉じる**ことで、
  現場作業者が触る撮影導線の信頼性を守る。
- **家系との整合**: 台帳の pending 4 本のうち aicue 分を implemented へ上げられる。
  正典と**同型**の追従なので、逸脱登録を増やさずに済む。
- **具体的な改善見込み**:
  - `resources/js` 配下の raw HTML sink が **1 件 → 0 件**。
  - 新規の `{@html}` は **`pnpm lint` で即座に落ちる** (書いた瞬間に分かる)。
  - lint を黙らせる手段 (コメント無効化) が**振る舞いで**塞がれていることが機械で分かる。
  - QR 表示が **DOM への HTML 挿入から画像リソースの読み込みへ**移り、
    サーバ側 QR 生成器が将来差し替わっても「HTML として解釈させる経路」が残らない。

### 保証範囲 (誇張しない)

本設計が**機械で固定するのは正典 t1 の 4 点そろいだけ**である。すなわち —
規則が error であること / 無効化コメントが効かないこと /
`resources/js` 配下の `.svelte` に `{@html}` が 0 件であること /
置き換え部品が実在し `img-src` に `data:` が居ること、の 4 つである。

以下は**保証しない**:

- **browser が画像文脈の SVG をどう扱うかの細部**。
  「`<img>` なら絶対に何も起きない」とは本設計は主張しない
  (それは browser の実装に依存し、本設計の検査対象ではない)。
  主張するのは「HTML として DOM へ挿す経路をやめた」ことまでである。
- **`{@html}` 以外の raw HTML sink** (`innerHTML` 直代入等)。スコープ外に明記する。
- **`resources/js` の外**にある `.svelte` (存在しないが、走査根はここに限る)。

## 実装方針（概要）

| # | 施策 | 変更/新設 |
|---|---|---|
| 1 | lint 規則の有効化 | `eslint.config.js` (既存。`.svelte` rules に 1 行 + 方針コメント) |
| 2 | 置き換え部品の新設 | `resources/js/components/atoms/QrCodeImage.svelte` (新規) |
| 3 | 唯一の実在サイトの置換 | `resources/js/pages/Settings/Security.svelte` (`{@html}` 除去) |
| 4 | gate の新設 | `tests/js/architecture/svelte-raw-html-gate.test.ts` (新規) |
| 5 | 部品テスト | `tests/js/components/atoms/QrCodeImage.test.ts` (新規) |
| 6 | 画面テストの追随 | `tests/js/pages/SettingsSecurityTwoFactorConfirm.test.ts` 等 (既存。QR の描画形が変わる) |
| 7 | 応答ヘッダの pin | `tests/Feature/Security/SecurityHeadersTest.php` (既存。`img-src` の `data:` を 2 構成で固定) |
| 8 | 設計規約への追記 | `DESIGN.md` (正典の `files_touched` に含まれる。`{@html}` 禁止と代替部品の宣言) |

**gate の作り方は AGENTS.md「静的検査 (gate) と走査器の共通規約」の (b)〜(e) に従う**:
- (b) 解決できない形は落とす: ESLint の実行に失敗したら**未解決として fail** させる。
  走査対象の読み取り不能も同様。
- (c) 検出力は**負例と正例の両方向**で裏取りする
  (違反入力が error / 規定どおりの入力を誤検出しない)。
- (d) 集めた結果を判定に使わない形を作らない。
- (e) 語彙一致ではなく構文 (`{@html`) の検出なので (e) は適用対象外だが、
  **検出の区切りは docblock に宣言する**。
- 母集団が空なら fail (走査根の改名・移動で無音化しない)。

## 制約・前提

- **`noInlineConfig: true` は既存**であり、D11 (`docs/template-divergence.md`) が
  「lint 対象の全ファイルで inline の抑制が効かない」を config 静的検査で既に固定している。
  本設計はこれを**壊さず**、正典が求める「**振る舞いでの**裏取り」を新 gate 側で足す。
  二重管理にはしない — D11 の gate は**設定値**を、新 gate は**実際の lint 結果**を見る (層が違う)。
- **`eslint.config.js` は指紋台帳 (`docs/template-fingerprints.json`) のキーに在る共有ファイル**で、
  かつ**既に D11 の対象パスとして逸脱登録済み**である (実測: ローカル sha256 が台帳値と不一致)。
  よって本設計の変更で `LedgerPins` の件数 pin を動かす必要は無い。
  ただし D11 の記述が「no-undef の gate」に閉じているため、**登録の追補が要るかを Phase 3 の
  乖離台帳確認段で判定する** (本設計は正典への**接近**であり新たな逸脱ではない、が既定の読み)。
- 他の変更対象 (`Settings/Security.svelte` / `config/security.php` /
  `tests/Feature/Security/SecurityHeadersTest.php` / `DESIGN.md` /
  新設 3 ファイル) は**指紋台帳のキーに無い** (実測)。
- **CSP の値そのものは変えない** (`img-src 'self' data:` は既定・GTM overlay の両方に既にある)。
  変えるのは**それを pin する検査を足すこと**だけである。
  正典の boundary も「本 feature が固定するのは、置き換え先の部品が依存する画像取得元の指示が
  緩まないことだけ」と明記している (ヘッダを配る仕組み自体は `security-headers-csp` の範囲)。
- Atomic Design: `QrCodeImage` は**単機能・無状態**の atom であり、階層規約
  (`atoms → molecules → …` の単方向) に適合する。`components/atoms/icons/` ではないので
  `svg-inline-allowlist.test.ts` の inline SVG 許可ディレクトリには入れない —
  部品は `<svg>` 要素を**書かない** (`<img>` に data URI を渡すだけ) ので同 gate に抵触しない。
- アクセシビリティ: 現行は wrapper を `role="img"` + `aria-label` にして SVG に属性注入しない形。
  `<img>` へ移ると **`alt` 属性が正規の手段**になるので、wrapper の `role="img"` は不要になる。
- 現行 `Security.svelte` の QR 周りの状態機械 (取得失敗 Alert / 再認証 step-up / 世代管理 /
  `qrSvg = null` によるリセット) は**触らない**。置換するのは**描画の 1 箇所だけ**である。

## スコープ外

- **`{@html}` 以外の sink** (`svelte:element` の動的タグ / `innerHTML` 直代入 /
  `document.write` 等) は扱わない。正典 t1 の対象は
  「画面テンプレートで文字列を HTML として差し込む構文」であり、
  語彙を勝手に増やさない (AGENTS.md「語彙を勝手に増やさない」に同旨)。
- **lint / 型 / 整形の基礎設定そのもの**は `eslint-svelte-ts-baseline` の範囲 (正典 boundary の除外)。
- **応答ヘッダを配る仕組み自体** (`SecurityHeaders` middleware / CSP の組み立て) は
  `security-headers-csp` の範囲。本設計は `img-src` に `data:` が居ることを pin するだけ。
- **部品の粒度と設計体系の純度**は `atomic-design-gates` の範囲。
- **雛形へ外部由来の文字列を渡すときの防御**は `prompt-injection-defense` の範囲。
- **許可一覧 (exemption inventory) の新設**はしない。正典が「許可一覧の口を持たない」と
  明記しており、口を作ること自体が正典からの逸脱になる。
- サーバ側の QR 生成 (Fortify の `two-factor-qr-code` endpoint) の実装変更はしない。
  受け取った SVG 文字列の**描き方**だけを変える。
- **他画面の一括監査**は不要 (実測で `{@html}` は resources/js 全体で 1 件のみ)。
  0 件固定は gate が恒久的に担う。
