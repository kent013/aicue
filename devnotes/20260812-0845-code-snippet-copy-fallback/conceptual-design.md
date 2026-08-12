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

**コピーが失敗したとき、利用者の手に手段を残す。** 4 段で受ける:

1. `navigator.clipboard.writeText` を試す (現行どおり)。
2. 失敗したら**コード文字列を DOM 上で選択状態にする**。これが本命である
   (手動コピーへ進める状態を残すことが目的)。
3. その選択状態を使って `document.execCommand("copy")` を**ついでに試す** (legacy fallback)。
   通れば成功表示にする。**これは補助であり主役ではない**。
4. それも通らなければ、**選択を残したまま**「選択したので手動でコピーしてください」と示す。
   このメッセージは**自動で消さない** (手動コピーには時間がかかるため。2 秒で消える案内は
   案内として機能しない)。ただし**次のコピー試行・成功・component 破棄では必ず解除する**
   (古い失敗案内が成功後に残ってはならない)。

2〜4 は別機構ではなく **1 つの流れ**である (execCommand は選択を前提とするため、
選択は 3 のための手順であり、同時に 4 の受け皿にもなる)。

### 判断の分かれ目 (詳細設計で確定させる)

- **`document.execCommand("copy")` は非推奨 API である。** 採用条件を狭く固定する:
  `typeof document.execCommand === 'function'` のときだけ呼ぶ / 例外は握って次段へ落とす /
  **成功しても「Clipboard API の代替が保証された」とは書かない**。
  単独で見れば過剰だが、**3 段目のためにどのみち選択状態を作る**ので、その選択を使って
  ついでに試すだけの追加コストである。主役は「選択を残して手動コピーに進めること」。
- **失敗メッセージの置き場所**。現在の `role=status` はボタン横の
  `absolute top-2 right-2` にあり、長文を置くと確実に破綻する。案内文は
  **ブロック下部の通常フロー**へ出す (レイアウトを壊さず、読み上げ順も自然)。

## 期待効果

- コピー API が失敗しても、**手動コピーへ移れる状態が手元に残る**。特に 2FA セットアップキーと
  MCP 設定 JSON という「転記できないと先に進めない」2 箇所で効く。
- 原因特定を待たずに前へ進める。**ただし「原因が何であっても効く」とは言わない** —
  フォーカス喪失が原因なら `execCommand` も同じく失敗する。確実に効くのは
  「選択を残して案内する」最終段だけである。
- docblock の主張「手動コピーを促す」が、実装として本当になる。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `resources/js/components/molecules/CodeSnippet.svelte` | 失敗経路を段階化 (選択 → execCommand → 案内)。案内はブロック下部・自動消滅なし (次試行/成功/破棄で解除) |
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
  フォーカスを要求するため、フォーカス起因なら同じく失敗する。その場合に効くのは最終段 (選択 + 案内) である。
- **Vitest は DOM 契約と分岐だけを見る。** 実ブラウザで実際にクリップボードへ入ること・
  iOS Safari で選択範囲から「コピー」メニューが出ることは確認しない
  (Browser lane は追加しない。追加するなら Chromium + WebKit の 2 レーン契約に従う必要がある)。
- **モバイルの手動コピー体験を保証しない。** 選択状態は作るが、iOS では選択だけでは
  コピーメニューが自動表示されない。案内文はそれを踏まえた書き方にするが、
  「ワンタップでコピーできる」ようにはならない。
- **完了条件は Vitest による DOM 契約までとする。** 実機 / Browser lane で見るなら観点は
  「(a) 実ブラウザで失敗を起こしたとき選択範囲が実際に張られるか」「(b) iOS Safari で
  その選択からコピーメニューへ到達できるか」「(c) `execCommand` が通る環境がどれだけあるか」。
  本 TODO ではこれらを**確認しない**。

## スコープ外（今回やらないこと）

- **失敗原因の特定** (bughunt 環境の provision と実ページ再走)。別作業。
- **呼び出し側 7 箇所の変更**。component 内で閉じる。
- **`CodeSnippet` 以外のコピー UI**。現状ほかに `navigator.clipboard` を直接呼ぶ箇所があるかは
  詳細設計で確認し、あっても本 TODO では触らない (あることが分かったら記録だけ残す)。
- **Browser lane の追加**。上記のとおり。
