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
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

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
1. 使命との整合性 2. 禁止事項違反 3. 実現可能性 (Laravel + Svelte 5 + Inertia)
4. 期待効果の妥当性 5. リスク 6. スコープの適切さ 7. 型安全性

【この設計に固有の、特に厳しく見てほしい点】
- **非推奨 API (`document.execCommand("copy")`) を使う判断は妥当か、それとも過剰か。**
  「Clipboard API が失敗する原因が未特定」という状況で、この保険は正当化されるか。
  それとも 3 段目 (選択 + 案内) だけで足り、2 段目は思考原則 2 (今必要なものだけ作る) に反するか。
- 失敗メッセージを「自動で消さない」判断は妥当か。既存の成功表示 (2 秒で消える) との
  非対称を作ることになるが、それは正しい非対称か。
- 原因未特定のまま受け皿だけ作るのは、対症療法として批判されるべきか。
  それとも原因が何であれ効く正しい層への手当てか。
- スコープ (component 内で閉じる / 呼び出し 7 箇所は無変更 / Browser lane 追加なし) は適切か。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（リポジトリ `/workspace` の実コードを読んで検証してよい。特に
`resources/js/components/molecules/CodeSnippet.svelte` /
`tests/js/components/molecules/CodeSnippet.test.ts` /
`config/security.php` / `app/Http/Middleware/SecurityHeaders.php`）

# 概念設計: code-snippet-copy-fallback (コピー失敗時に手段が残らない問題)

> bug-hunt run 20260811-003230 の **F-3-01** (severity 未確定・`needs_spec`) 起点。
> ただし**当時の原因説明は本設計の実測で反証されている** (下記)。

## 背景・課題

### bug-hunt が観測したこと

`/organizations/{org}/onboarding/mcp` のコピーボタン (`mcp-endpoint-url-copy`) を押すと、
`role=status` に「コピー失敗」が出た (console error は 0 件)。当時の探索エージェントは
`CodeSnippet.svelte` を読み「意図的なフォールバック設計であり、**headless Chromium が
clipboard-write を既定で許可しない検査環境側の制約**の可能性が高い」と判断し、
severity を付けず「要確認」に分類した。

### その説明は反証された (2026-08-12 実測)

`CodeSnippet.svelte` の `copy()` と同一ロジックを持つページを `http://127.0.0.1` に置き、
bug-hunt と同じ `@playwright/cli` の headless Chromium で叩いた結果:

| 観測項目 | 結果 |
|---|---|
| `navigator.permissions.query({name:'clipboard-write'})` | **granted** |
| `navigator.clipboard.writeText` の有無 | あり |
| `window.isSecureContext` | **true** |
| ボタン押下の結果 | **「コピー完了」= 成功** |
| `navigator.clipboard.readText()` | `NotAllowedError` (**read だけ**拒否) |

**headless が write を拒否するという前提は成り立たない。** 拒否されるのは read だけである。
アプリ側も `Permissions-Policy` に `clipboard-write` を列挙していない
(`config/security.php` の baseline は `geolocation=(), microphone=(), camera=(), payment=(...)`。
未列挙 directive の既定 allowlist は `self` なのでブロックしていない)。

**したがって bug-hunt 実行時に失敗した原因は現時点で未特定である。** 有力な候補は
「`writeText` は document がフォーカスされていないと `NotAllowedError` になる」機序だが、
使用した CLI は `tab-select` が必ずフォーカスを戻すため非フォーカス状態を再現できず、
**確認できていない**。確定させるには bughunt 環境 (`:8010`) を provision して実ページを
叩き直す必要があり、**本設計はそれを行わない** (下記スコープ外)。

### 直すべき対象は「原因」ではなく「失敗したときに手段が残らないこと」

原因が未特定であることは、むしろ本件を直す理由を強くする。**素の Chromium でも失敗しうる**と
分かった以上、失敗経路の作りが問題になる。現行 `resources/js/components/molecules/CodeSnippet.svelte`:

```ts
} catch {
    copied = false;
    failed = true;
}
timeoutId = setTimeout(() => { copied = false; failed = false; }, 2000);
```

- 失敗時に出るのは **「コピー失敗」の 5 文字だけ**で、**2 秒で消える**。
- 次に何をすればよいかを一言も示さない。docblock は「手動コピーを促す」と書いているが、
  **促していない** (名前と実装の乖離)。
- 手動コピーの対象は `overflow-x-auto` の `<pre>` で、
  `mcpConfigJson` のような複数行 JSON をスマートフォンで正確に範囲選択するのは実質困難である。

### 影響範囲は MCP ガイドだけではない

`CodeSnippet` は 3 画面 7 箇所で使われている:

| 画面 | 用途 |
|---|---|
| `Organizations/Onboarding/Mcp.svelte` | エンドポイント URL / Claude Code 登録コマンド / 設定 JSON |
| `Organizations/Onboarding/Cli.svelte` | インストールコマンド ほか 2 箇所 |
| `Settings/Security.svelte` | **2FA のセットアップキー** |

いずれも「**正確に転記できないと先へ進めない**」文字列であり、コピーが落ちた地点が
そのまま作業の停止点になる。2FA セットアップキーに至っては、転記に失敗すると
認証アプリの登録そのものが進まない。

## 改善アイデア

**コピーが失敗したとき、利用者の手に手段を残す。** 3 段で受ける:

1. `navigator.clipboard.writeText` を試す (現行どおり)。
2. 失敗したら**コード文字列を DOM 上で選択状態にし**、`document.execCommand("copy")` を試す。
   成功したら通常の成功表示にする (Clipboard API が使えない環境でも実際にコピーが完了する)。
3. それも失敗したら、**選択を残したまま**「選択したので手動でコピーしてください」と示す。
   このメッセージは**自動で消さない** (手動コピーには時間がかかるため。2 秒で消える案内は
   案内として機能しない)。

2 と 3 は別機構ではなく **1 つの流れ**である (execCommand は選択を前提とするため、
選択は 2 のための手順であり、同時に 3 の受け皿にもなる)。

### 判断の分かれ目 (詳細設計で確定させる)

- **`document.execCommand("copy")` は非推奨 API である。** それでも採るのは、
  Permissions API の管轄外で、Clipboard API が塞がれた状況でも通る経路だからである。
  「非推奨だから使わない」を優先すると、**原因不明のまま失敗し続ける利用者に何も残らない**。
- **失敗メッセージの置き場所**。現在の `role=status` はボタン横の
  `absolute top-2 right-2` にあり、長文を置くと確実に破綻する。案内文は
  **ブロック下部の通常フロー**へ出す (レイアウトを壊さず、読み上げ順も自然)。

## 期待効果

- コピー系 UI の失敗が**停止点でなくなる**。特に 2FA セットアップキーと MCP 設定 JSON という
  「転記できないと先に進めない」2 箇所で効く。
- 原因 (フォーカス / 権限 / 環境) が何であっても効く。**原因特定を待たずに前へ進める**。
- docblock の主張「手動コピーを促す」が、実装として本当になる。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `resources/js/components/molecules/CodeSnippet.svelte` | 失敗経路を 3 段化 (選択 → execCommand → 案内)。案内はブロック下部・自動消滅なし |
| `tests/js/components/molecules/CodeSnippet.test.ts` | 既存 5 本のうち失敗系 2 本を新しい契約へ更新 + 新規ケース追加 |

- 既存の**成功時の挙動は変えない** (「コピー完了」+ 2 秒で消える)。
- component の階層 (molecule) も props インターフェースも変えない
  (`code` / `language` / `testId` / `class`)。呼び出し 7 箇所は**無変更**。
- DS token のみ。色は既存の `text-danger` / `text-text-secondary` を使い hex 直書きをしない。

## 制約・前提

- **jsdom は `document.execCommand` を実装しない** (未定義)。テストでは stub して
  「呼ばれたか」「戻り値で分岐するか」を固定する。実ブラウザでの実際のコピー成否は
  Vitest では保証しない (下記)。
- 既存テスト 2 本 (`コピー失敗で「コピー失敗」を表示する` /
  `clipboard API 非対応環境では「コピー失敗」を表示する`) は**意図的な挙動変更**に伴い
  期待値を更新する。**削除はしない** (禁止事項 3)。

## 保証しないもの（誇張しない）

- **bug-hunt が観測した失敗の原因は特定していない。** 本設計は失敗時の受け皿を作るだけで、
  「もう失敗しなくなる」とは言わない。原因特定には bughunt 環境での再走が要る。
- **`document.execCommand("copy")` が成功することを保証しない。** これも document の
  フォーカスを要求するため、フォーカス起因なら同じく失敗する。その場合に効くのは 3 段目である。
- **Vitest は DOM 契約と分岐だけを見る。** 実ブラウザで実際にクリップボードへ入ること・
  iOS Safari で選択範囲から「コピー」メニューが出ることは確認しない
  (Browser lane は追加しない。追加するなら Chromium + WebKit の 2 レーン契約に従う必要がある)。
- **モバイルの手動コピー体験を保証しない。** 選択状態は作るが、iOS では選択だけでは
  コピーメニューが自動表示されない。案内文はそれを踏まえた書き方にするが、
  「ワンタップでコピーできる」ようにはならない。

## スコープ外（今回やらないこと）

- **失敗原因の特定** (bughunt 環境の provision と実ページ再走)。別作業。
- **呼び出し側 7 箇所の変更**。component 内で閉じる。
- **`CodeSnippet` 以外のコピー UI**。現状ほかに `navigator.clipboard` を直接呼ぶ箇所があるかは
  詳細設計で確認し、あっても本 TODO では触らない (あることが分かったら記録だけ残す)。
- **Browser lane の追加**。上記のとおり。
