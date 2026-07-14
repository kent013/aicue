# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

# 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。想定外のパターンも判断材料になる。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ(Laravel/Svelte エコシステムの既存解)。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。方向性が正しいと確認してから微調整せよ。

# ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# システム: レビュアー役割

あなたはWebアプリケーション(Laravel + Svelte)の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命(North Star)に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か(Laravel 12 + Svelte 5 + Inertia.js)
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか(本件はフロントのみだが該当あれば指摘)

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下、devnotes/20260714-1338-purchase-tickets-stale-validation/conceptual-design.md の内容）

# 概念設計: purchase-tickets-stale-validation

## 背景・課題

bug-hunt(real-llm run) F-3-01(Medium, ヒューリスティクス H10 一貫性 + H12 エラーからの回復)。

`/purchase-tickets`(チケット購入画面)で以下の UX 破綻が観測された:

1. 枚数入力に範囲外(例: `1001`、上限 `maxCount=1000` 超)を入力し「購入手続きへ」を押下
2. client-side validation がブロックし、フィールドが invalid 表示 + エラー文言
   「購入枚数は 1〜1000 の整数で入力してください」を表示(ここまでは設計どおり正しい)
3. 送信し直さずに枚数を有効値(例: `20`)へ修正すると、合計金額は正しく再計算されるが、
   invalid 表示とエラー文言が消えず残留する

結果、実際には購入可能な有効入力であるにもかかわらず「エラー中」と誤認させ、
ユーザーが有効な入力のまま操作を諦めうる。エラーからの回復(H12)が阻害されている。

### 根本原因

`resources/js/pages/Billing/PurchaseTickets.svelte`:

- `clientError`(`let clientError = $state<string | null>(null)`)は独立した state で、
  `submit()` 内でのみ null リセット/セットされる。
- 入力値は `countText`($state) → `parsedCount`($derived) → `isValidCount`($derived) と
  正しく再計算されるが、`clientError` はこの派生チェーンに追従しない独立 state のため残留する。
- FormField の error prop は `clientError ?? serverErrors.count ?? serverErrors.attempt_token ?? null`
  で解決され、`clientError` が非 null の限り invalid + 文言が表示され続ける。

## 改善アイデア

入力が有効値へ回復した時点で client-side validation エラーを自動的に解消する。
`isValidCount`(既存 $derived)が true に復帰した時点で `clientError = null` にする反応的
dismissal を `$effect` で追加する最小変更。

- クリア条件は「値が有効に戻ったら消える」のみ(isValidCount === true の時だけクリア)。
- 無効値のまま別の無効値へ変えてもエラーは残す(過剰クリア回避、「押下時にエラー表示」契約維持)。
- サーバ由来エラー(serverErrors、full POST 往復由来)は対象外。clientError のみ扱う。

### なぜ $effect か(代替案の検討)

- 代替案 A(oninput で毎回クリア): 無効値タイプ中でもエラーが消え「押下時にエラー表示」意図と乖離。不採用。
- 代替案 B(submitAttempted フラグ + $derived で純粋導出): $effect を避けられ idiomatic だが、
  一度送信を試みたら無効入力タイプ中に即エラー再表示する挙動になり「押下時にのみエラー表示」契約から逸脱。不採用。
- 採用(条件付き $effect dismissal): clientError は「押下」という imperative イベントで設定される
  transient UI state であり、その有効化時の解除は純粋な派生ではなく反応的副作用。$effect の正当な用途。
  最小差分で既存契約を保てる。$effect はコードベースで 11 ファイル確立済み。

## 期待効果

- 使命への貢献: 課金導線での「有効入力なのにエラー中に見える」誤認を除去し、専門外ユーザーを
  つまずかせない「思考ゼロ」の導線に近づける。
- 具体的改善: 有効値へ修正した時点でエラー表示が即消え invalid が外れる。合計金額の再計算と表示状態が一致。

## 実装方針(概要)

```ts
$effect(() => {
    if (isValidCount) clientError = null;
});
```

- submit() 既存ロジックはそのまま。
- 無限ループの懸念なし: effect は isValidCount を読むが clientError を読まないため書き込みが再起動しない。

## 制約・前提

- Svelte 5 runes。DESIGN.md / 禁止事項#8(disabled にしない、押下時にエラー表示)を維持。
- DS token / atomic import 階層は変更しない。サーバ権威・attempt_token フロー不変更。

## スコープ外

- サーバ側 validation・課金ロジック・傾斜単価計算・serverErrors 表示ライフサイクル・他フォーム横展開・UI/token 変更。

## 補足質問

この最小変更(条件付き $effect による clientError dismissal)で、症状(有効値修正後の stale invalid/エラー残留)を
過不足なく解消できるか。既存の「押下時にエラー表示」契約・a11y(aria-invalid / aria-describedby)への悪影響、
および見落としているエッジケース(例: サーバエラー表示中に有効値へ修正した場合、空入力→有効、等)があれば指摘してほしい。
