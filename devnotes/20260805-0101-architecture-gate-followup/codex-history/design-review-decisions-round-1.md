# 対応マトリクス: design-review Round 1

指摘された技術的事実はすべて **実測 / プロトタイプ実走で裏を取ってから**対応した。

## 施策 4 [Critical] `use \RuntimeException;` (先頭 `\` 付き単一名) を取りこぼす

- 判断: **対応する**（指摘は正しい。silent hole だった）
- 根拠: 実測で確認。PHP は先頭 `\` 付きでも**まったく同じ warning** を出す:

  ```
  use \RuntimeException;    → Warning ...non-compound name 'RuntimeException'...
  use function \strlen;     → Warning ...non-compound name 'strlen'...
  use const \PHP_VERSION;   → Warning ...non-compound name 'PHP_VERSION'...
  ```

  しかも tokenizer 上は **T_STRING ではなく T_NAME_FULLY_QUALIFIED** になる:

  ```
  use \RuntimeException;  → T_USE, T_NAME_FULLY_QUALIFIED('\RuntimeException'), ';'
  use RuntimeException;   → T_USE, T_STRING('RuntimeException'), ';'
  ```

  元設計の `is(T_STRING)` 判定では**先頭 `\` 付きを丸ごと取りこぼす**。
- 対応内容: 判定を token 種別から**名前の中身**へ変更した（Codex の提案どおり）:
  - import 要素を `T_STRING` / `T_NAME_QUALIFIED` / `T_NAME_FULLY_QUALIFIED` /
    `T_NS_SEPARATOR` を連結して **1 つの文字列に正規化**
  - `ltrim($name, '\\')` 後に区切り `\` を含まなければ非複合 = 違反
  - `,` / `as` / `;` / `{` で flush する制御に書き換え
  - 負のコントロール **「先頭バックスラッシュ付きの非複合 use も検出する」を追加**
  - 正のコントロールに `use \Illuminate\Support\Str;`（先頭 `\` 付き複合名）と
    カンマ区切りの複合名を追加
- 検証: プロトタイプ実走で全 fixture PASS + 実リポジトリ走査
  （total=1124 / namespaceless=459 / violations=**2** = 施策 5 の 2 件と完全一致）

## 施策 4 [Warning] `T_NAME_*` 非依存の実装は tokenizer 差分に弱い

- 判断: **対応する**（上の Critical 対応に統合）
- 根拠: 同じ問題の別表現。「名前を 1 要素に集約 → セグメント数判定」に統一せよ、が本質。
- 対応内容: まさにその形に書き換えた。`T_NS_SEPARATOR` 分割型の tokenizer 表現でも
  同じ結論になる（連結してから判定するため）。

## 施策 1 [Warning] `safeCalls > 0` は「違反ゼロかつ許可形ゼロ」の健全状態で誤落ちする

- 判断: **対応する（ただし提案より強い形で）**
- 根拠: 指摘は正しい。実コードの Carbon 使用有無に依存する指標は脆い。
  ただし「fixture だけで担保して実コード側の空振り検知を捨てる」のも惜しい —
  走査基盤が実ファイルに対して生きているかは見たい。
- 対応内容: 指標を `safeCalls`（Carbon 許可形の件数）から
  **`methodCalls`（`->name(` 形のメソッド呼び出しを何件見たか）**へ変更した。
  - 実コードの Carbon 使用有無に**依存しない**（Codex の懸念を解消）
  - かつ実ファイル走査が生きていることを証明する（元の意図も維持）
  - プロトタイプ実測で `methodCalls=24486`。ゼロになる現実的経路が無く頑健
  fixture の負/正コントロールによる担保はそのまま維持している。

## 施策 1 [Warning] `$date->{$method}()` の動的呼び出しが完全スルーで規約回避経路が残る

- 判断: **部分的に対応する（deny-by-default 全面適用は反論）**
- 根拠: 実測でスコープを測った。走査対象（app/ database/ tests/）の動的メソッド呼び出しは
  **全 5 件（コメント 1 件を除くと実質 4 件）**で、内訳は
  - `->{$method}($uri)` … HTTP verb を回す 2FA テスト
  - `->{$state}()` … factory state を回す課金テスト 3 件
  いずれも**日付と無関係**。ここで全 dynamic dispatch を deny-by-default にすると、
  Carbon gate が無関係な 4 件を人質に取り、今後 factory state テストを書くたびに
  Carbon gate の allowlist を触らせることになる。**gate の責務外への越境**であり、
  「やたらに複雑な案を提案する」（禁止事項）にも触れる。
- 対応内容: 回避経路のうち**静的に決定できるものだけ**を塞いだ:
  - `->{'addMonth'}()` / `->{"subYears"}()` （**literal 文字列**の動的呼び出し）を
    deny 対象に追加。実測 0 件なので allowlist コストはゼロ
  - 変数形 `->{$method}()` は**本 gate の明示的な限界**としてテスト冒頭コメントに
    理由付きで明記（「変数に 'addMonth' を入れて日付を進める」は実測 0 件かつ
    通常のコードレビューで自明に不自然 = 現実的な脅威ではない）
  - 負のコントロール「literal 文字列の動的メソッド呼び出しも検出する」を追加
- 検証: プロトタイプ実走で literal 動的形 2/3 検出（NoOverflow は正しく許可）PASS

## 施策 6 [Warning] `setPrivateTitle` 判定が「識別子の存在」だけで偽陰性リスク

- 判断: **対応する**
- 根拠: 指摘は正しい。しかもこの偽陰性は**取りこぼす方向**（タイトル未供給を
  「供給済み」と誤判定）に倒れるため、gate の失敗として最悪。
  callable 参照 `[$seo, 'setPrivateTitle']` や変数名でも通ってしまう。
- 対応内容: `documentTitleBodyHasIdentifier()` を
  **`documentTitleBodyCallsMethod()`** に置き換え、
  `->setPrivateTitle(` / `?->setPrivateTitle(` の**呼び出しトークン列**に限定した。
  既存 `ScenarioWritePathInventoryTest::containsMethodCall()` と同じ判定形であり、
  既存作法とも整合する。呼び出し側 3 箇所と fixture テストも追随させた。

## 施策 6 [Warning] `Inertia::render` 判定で `(` を確認していない

- 判断: **対応する**
- 根拠: 指摘のとおり。`[Inertia::class, 'render']` のような callable 参照や
  `Inertia::render` 単独参照を誤検出しうる（Inertia を描画しない route を
  候補に入れてしまう = 偽陽性）。
- 対応内容: `render` の直後に `(` があることを必須にした。
  コメントに「callable 参照を誤検出しない」理由も明記。

## 施策 6 [Suggestion] メソッド名解決を小文字正規化して PHP の case-insensitive 仕様に揃える

- 判断: **対応する**
- 根拠: route action 文字列（`Class@Index`）と宣言（`function index`）の case が
  揃っていなくても PHP は解決する。case 一致前提だと
  「メソッドを解決できない」→ 誤って unresolvable 扱いになる。
- 対応内容: `documentTitleMethodRanges()` の**キーを小文字化**し、
  参照側も `strtolower($method)`（`$methodKey`）で引くよう統一。
  1 hop 追跡の callee 解決も同様に小文字化。fixture テストのキーも追随させた。

## 施策 9 [Warning] `meta[name="description"]` の regex が限定的で抜け道が残る

- 判断: **対応する（AST 切替は反論）**
- 根拠: 無引用属性値（`name=description`）は HTML として有効なので抜け道になる —
  指摘は正しい。一方 **Svelte AST 解析への切替は過大**:
  この gate は「ページに第二 SoT を作らせない」ための deny-by-default で、
  対象は `resources/js/pages/**/*.svelte` の `<svelte:head>` ブロックのみ。
  AST パーサ（`svelte/compiler`）を architecture テストに持ち込むと
  Svelte のバージョン更新で gate が壊れる依存を増やす。
  「今必要なものだけ作る」原則に照らして regex 拡張で足りる。
- 対応内容:
  - regex を `/<meta\b[^>]*\bname\s*=\s*(?:"description"|'description'|description\b)/i` に拡張
    （ダブル・シングル・**無引用**の 3 形態をカバー。`\b` で `descriptionfoo` を弾く）
  - 式・スプレッド属性（`<meta name={x}>` / `<meta {...attrs}>`）は
    「description でないと静的に証明できない」ので **deny-by-default で fail**
    （`meta[dynamic-attr]`）。実測 0 件なのでコストはゼロで、
    「静的に判定できないものを黙って通さない」という他 gate と同じ規律に揃う
  - 負のコントロールに無引用 1 件・式/スプレッド 2 件を追加（計 8 ケース）
  - 正のコントロールに `name=descriptionfoo`（無引用の紛らわしい語）を追加（計 7 ケース）
- 検証: **全 15 ケースを Node で実走して ALL OK を確認済み**

## 施策 8 [Suggestion] 文言を h1 と完全一致にするか、差異理由をコメントで固定する

- 判断: **対応する（差異理由をコメントで固定する側を採用）**
- 根拠: h1 は「**この**招待リンクは使用できません」で、指示語「この」はタブ title には不要。
  一方 `config/seo.php` には「h1 見出しと一致させる」規約があるため、
  理由を残さないと後から「不一致だから直そう」と揺り戻される。
- 対応内容: controller のコードコメントに
  「h1 から指示語を落とした形」「意図的な短縮」「文言変更時は h1 も追随させる」を明記。
  詳細設計の該当節にもコメント例を掲載した。

## 施策 2 / 3 / 5 / 7 / 10 (APPROVE)

- 判断: 変更なし
