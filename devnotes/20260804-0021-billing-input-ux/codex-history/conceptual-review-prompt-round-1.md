【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
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
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【補足: 本件はフロントエンド (Svelte 5 runes + Tailwind v4 + Inertia) のみの変更で、PHP 側の変更を含まない】
リポジトリ (/workspace) のファイルは読んでよい。特に以下は判断に直結する:
- `AGENTS.md` / `DESIGN.md`
- `resources/js/components/features/billing/AutoRechargeCard.svelte`
- `resources/js/components/features/billing/BillingContactForm.svelte`
- `resources/js/components/atoms/Input.svelte` / `Textarea.svelte` / `Select.svelte` / `input-state.ts`
- `resources/js/components/molecules/FormField.svelte`
- `resources/js/pages/Billing/PurchaseTickets.svelte` (T041 の確立パターン)
- `resources/js/pages/Organizations/Settings.svelte` (T044 の確立パターン)
- `resources/js/pages/Auth/Register.svelte`
- `tests/js/architecture/ds-purity.test.ts` / `tests/js/support/ds-purity.ts`

---

## 概念設計

# 概念設計: billing-input-ux (課金 UI の入力 UX 整合 — F-3-05 / F-3-02 / F-3-03)

出典: `devnotes/20260803-203721-bug-hunt/report.md` (Medium 3 件) /
shard レポート `devnotes/20260803-203721-bug-hunt/shard-3/shard-report.md` (F-2 / F-3 / F-5 相当)

## 背景・課題

bug-hunt run 20260803-203721 が課金画面 (`billing.index`) の入力 UX で 3 件の Medium を検出した。
3 件とも「個別の画面バグ」に見えるが、**いずれも DS/規約側に穴があり、call site が
その穴を踏んだ**という同じ構造をしている。場当たりの 3 ファイル修正で閉じると同じ species が再発する。

### F-3-05: 押下時エラーが値を直しても消えない (stale invalid)

- `resources/js/components/features/billing/AutoRechargeCard.svelte:46` の `inputError` は `$state`。
  更新されるのは `ensureValidRange()` (161-163 行) の中だけで、これは **ボタン押下ハンドラからしか
  呼ばれない** (`openConsent` / `confirmEnable` / `handleUpdate` / `handleSaveDraft`)。
- 一方、判定の実体 `rangeError` は 84 行の `$derived.by` でリアクティブに再計算されている。
  したがって値を有効な組み合わせに直しても、表示中の文言 (`auto-recharge-range-error`) は残り続ける。
- **同じ問題は T041 (チケット購入枚数) と T044 (オーナー移譲 select) で既に解決済み**:
  - `resources/js/pages/Billing/PurchaseTickets.svelte:59-63` — `clientError !== null && isValidCount` で
    `$effect` クリア。「押下時に出す」契約は維持し、有効へ復帰した時だけ消す。
  - `resources/js/pages/Organizations/Settings.svelte:112-115` — 同型 (`transferClientError`)。
  - 回帰テストも `tests/js/pages/PurchaseTickets.test.ts:147-195` に 3 本 (消える / 別の無効値では
    消えない / server error は消えない) 揃っている。
- つまり **確立済みパターンから新規実装 (P8a) の本カードだけが外れている**。規約が
  「T041/T044 の実装を読めば分かる」形でしか存在せず、DESIGN.md に書かれていないことが根因。

### F-3-02: 請求先メールが native (英語) constraint validation に奪われる

- `BillingContactForm.svelte:87` の `Input` が `type="email"`。`<form onsubmit=…>` (69-76 行) は
  `novalidate` を持たないため、submit ボタン押下時にブラウザの interactive constraint validation が
  **submit イベントより前に**発火し、`router.patch` に到達しない (shard-3 F-2 で実測、英語ツールチップ)。
- アプリ全体が日本語 UI・サーバ検証文言も日本語 (`lang/ja/validation.php:216` に
  `billing_contact_email` の属性名あり) なのに、ここだけブラウザロケール依存の英語表示になる。
- **既存の vitest はこの穴を検出できない**: `tests/js/pages/Billing/BillingContactForm.test.ts:36` は
  `fireEvent` で submit イベントを直接発火するため native validation を素通りする。
  behavior テストでは原理的に守れない不変条件であり、**属性レベルで機械的に固定する必要がある**。
- 横断確認 (grep 済み): `<form>` は `resources/js` 配下に 33 個 / 26 ファイル。うち native
  constraint validation を持つ入力を含み、かつ submit する form は
  `Auth/Login` `Auth/Register` `Auth/ForgotPassword` `Auth/ResetPassword` `Settings/Index`
  `Contact/Index` `Admin/Users` `BillingContactForm` の **8 箇所** (すべて `type="email"` 由来)。
  → **本件は billing 固有ではなく、認証系を含む全フォームに開いている穴**。
- なお `required` / `pattern` / `minlength` を native 属性として入力に渡している箇所は存在しない
  (`FormField` の `required` prop はラベルの `*` 表示のみ、`Checkbox` の `required` prop は未使用)。
  つまり native validation に依存している機能は現状ゼロで、無効化しても失われる検証はない。

### F-3-03: 編集不可 (readonly) の入力欄が通常の入力欄と同じ見た目

- **原因を特定した** (bug-hunt では未特定だった):
  `AutoRechargeCard.svelte:336,356` が `readonly={!autoRecharge.canManage}` を `Input` に渡している。
  `Input` は `readonly` を restProps としてそのまま native 属性に流すが、
  `resources/js/components/atoms/input-state.ts` の `INPUT_BASE_CLASSES` は
  `disabled:` バリアントしか持たず (`disabled:cursor-not-allowed disabled:bg-neutral
  disabled:text-text-secondary`)、**readonly に対応する視覚状態が DS に存在しない**。
  結果、白背景・`border-border-strong` のまま = 編集可能に見えるが編集できない (H12)。
- 同じ穴を踏んでいる箇所がもう 1 つある: `resources/js/pages/Auth/Register.svelte:113`
  (招待経由の email prefill `readonly={isInvited}`)。**bug-hunt では未検出だが同一 species**。
- `readonly` を渡している call site はこの 2 ファイル 3 箇所のみ (grep 済み) で、
  DS 側を 1 回直せば全数が閉じる。

## 改善アイデア

**3 件を「call site の修正」ではなく「規約 + その規約を強制する層」で閉じる。**

### 規約 1: 押下時 client エラーは値の有効化に追随して消える (stale-invalid を残さない)

- AGENTS.md 禁止事項 8 / DESIGN.md Do's and Don'ts 「必須条件未充足でボタンを disabled にしない」の
  **対**として成立する規約。disabled を捨てた代償として「押下時にエラーを出す」を採ったのだから、
  そのエラーは「今の入力の状態」を表し続けなければならない。**古い判定が残るのは、
  disabled でブロックするより悪い**(ユーザーは何を直せばよいか分からなくなる)。
- 実装形: T041/T044 と同じ `$effect` による連動クリア。**過剰クリアはしない**
  (無効のままなら消さない / server 由来 error は対象外)。
- DESIGN.md §FormField に規約として明記する (これまで実装にしか無かった知識を canonical へ昇格)。

### 規約 2: 検証 UX は日本語に一本化する — native constraint validation に依存しない

- `<form>` には **`novalidate` を付ける**。検証の正本はサーバ (日本語) + 明示的な client エラー。
- `Input` の `type` union は **変更しない**。`type="email"` 等は「入力補助 (モバイルキーボード /
  autofill / スクリーンリーダーの型アナウンス)」のための意味付けであって検証手段ではない、と
  責務を分離する。撮影 PWA を iOS Safari で回すアプリで `type="email"` を `type="text"` に
  落とす (shard の改善案) と、キーボード最適化という実利を捨てることになる。`novalidate` は
  まさにこの用途の標準属性で、**フレームワーク/HTML のレンジ内**の解 (思考原則 1)。
- `inputmode` は現行どおり restProps 透過で扱う (`Settings/Security.svelte:375` /
  `Auth/TwoFactorChallenge.svelte:96` に既存利用あり)。新しい prop は作らない。
- 全 `<form>` に一律で付ける。「constraint 付き入力を含む form だけ」という条件付き規約は
  人間にもレビューにも判定不能で、必ず抜ける。

### 規約 3: 編集不可状態は面で示す (readonly の視覚状態を DS に持たせる)

- `input-state.ts` に readonly の視覚状態を追加し、`Input` / `Textarea` が明示 prop として受ける。
  見た目は既存 `disabled:` と同じトーン (`bg-neutral` + `text-text-secondary`) に揃え、
  カーソルだけ `cursor-default` (disabled は `cursor-not-allowed`) とする。**新規トークンは足さない**。
- `Select` には readonly を持たせない (HTML 仕様上 `<select>` に `readonly` 属性は無い)。
- 「編集させたくない値」の表現手段は 2 つあり、使い分けを DESIGN.md に書く:
  - **readonly input**: そのフォームの送信対象に含まれる / 値を選択・コピーさせたい
    (例: 招待 email の prefill、権限が無いユーザーへの設定値提示)
  - **読み取り表示 (`<dl>` 等)**: 編集手段そのものを出さない (例: `BillingContactForm` の
    非 manage 表示 113-131 行)
  どちらを選んでもよいが、**readonly input を選んだ場合は必ず「編集できない見た目」になる**ことを
  DS が保証する、というのが本規約の要点。

## 期待効果

- 使命への貢献: 直接の機能追加ではないが、**課金は「専門知識ゼロの現場」がアプリを使い続ける
  ための土台**であり、入力欄が嘘をつく (直したのにエラーが残る / 押せる見た目で押せない /
  英語で拒否される) 状態は、現場管理者の運用信頼を最初に削る箇所。
- 3 件の Medium が閉じるのに加えて、**bug-hunt が見つけていない同一 species が同時に閉じる**:
  - native validation: 認証系 7 フォーム (Login / Register / ForgotPassword / ResetPassword /
    Settings / Contact / Admin invite) — 新規登録・パスワード再設定という最も重要な導線を含む
  - readonly 見た目: `Auth/Register.svelte:113` (招待経由の登録)
- 規約を DESIGN.md + 機械テストに落とすので、以降の新規実装 (P10 以降) は同じ穴を踏まない。

## 実装方針(概要)

| 層 | 変更 |
|---|---|
| DS canonical | `DESIGN.md` §Input/Textarea/Select に readonly 状態と type の責務、§FormField に stale-invalid 規約、§Do's and Don'ts に `novalidate` 規約を追記 |
| atom | `components/atoms/input-state.ts` に readonly 状態を追加、`Input.svelte` / `Textarea.svelte` が `readonly` を明示 prop 化 |
| call site (billing) | `AutoRechargeCard.svelte`: `$effect` で `inputError` を `rangeError` に追随させる (F-3-05)。readonly は DS 修正で自動的に解決 (F-3-03) |
| call site (全 form) | `resources/js` の全 `<form>` に `novalidate` を付与 (F-3-02、33 箇所 / 26 ファイル) |
| test | atom 単体 (readonly)、AutoRechargeCard 単体 (stale-invalid / readonly)、architecture テスト (全 form の `novalidate`) |

## 制約・前提

- フロントのみの変更。**サーバ側 (FormRequest / Controller / DTO) は一切変更しない**。
  `UpdateBillingContactRequest` は既に `required|email:rfc|max:255` + ja 属性名を持つので、
  native validation を外せば日本語文言がそのまま届く。
- `AutoRechargeCard` の readonly 入力は `canManage=false` の UI 都合であり、**認可の境界ではない**
  (サーバ側 `Gate::authorize('manageBilling')` が正本。member の直 POST 403 は bug-hunt S5 で確認済み)。
  見た目を変えても認可の強度は変わらない、という前提を設計に明記する。
- DS token は追加しない (`bg-neutral` / `text-text-secondary` / `border-border-strong` は既存)。
  よって `tests/js/styles/canonical-source-parity.test.ts` (色/radius/typography の集合一致) には影響しない。
- `ds-purity` の禁止パターン (raw palette / hex / arbitrary値) に触れない範囲で実装する。

## スコープ外

- `type="number"` 固有の落とし穴 (ホイールで値が変わる、不正入力で `value` が空文字になる) —
  今回の 3 findings とは別 species。触らない。
- 同 bug-hunt の他 finding (F-4-01 / F-3-01 / F-3-04 / F-1-01 / F-2-01 / F-2-02 / F-1-02) —
  別タスクの担当。
- `Form` component の新設 (`<form>` を包む molecule) — 現状 `<form>` 手書きは DESIGN.md が
  禁じておらず、33 箇所を包み直す利得が無い。オーバーエンジニアリング (思考原則 2)。
- `AutoRechargeCard` の member 表示を `<dl>` 読み取り表示へ作り替えること — 規約 3 の判断に従い
  readonly input を維持する (DS 側の穴を塞ぐ方が汎用で、`Register.svelte` も同時に救う)。

---

## 特に判断してほしい論点

1. **`novalidate` を全 `<form>` に一律付与する**判断は妥当か。条件付き (constraint を持つ入力を
   含む form のみ) にすべきという反論があるなら、その運用可能性も含めて指摘してほしい。
2. **`type="email"` を維持する**判断は妥当か。shard の改善案 (`type="text"` + `inputmode="email"`)
   と比べて、iOS Safari 主戦場の PWA でどちらが正しいか。
3. **readonly の視覚状態を DS に持たせる vs. call site を読み取り表示 (`<dl>`) に作り替える**の
   選択は妥当か。過剰でないか / 逆に不足していないか。
4. Tailwind v4 の `read-only:` バリアント (CSS `:read-only` 疑似クラス) を使わず、明示 prop で
   class を計算する方針にしている。理由は `:read-only` が **disabled な input と `<select>` 全般にも
   マッチする** (HTML 仕様上 `<select>` は `:read-write` にならない) ため、共通 `INPUT_BASE_CLASSES`
   に `read-only:` を混ぜると Select が常時 muted になるから。この理解と判断は正しいか。
