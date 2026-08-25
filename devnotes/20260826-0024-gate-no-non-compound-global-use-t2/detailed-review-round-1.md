# 全体判定: CHANGES_REQUESTED

中核となる実装方針は妥当です。特に `T_NAMESPACE` の文脈判定、nullable の明示分岐、Process の配列引数、既存の unresolved 経路維持には大きな問題はありません。

ただし、負例が個別に発火していることを保証できない点と、追跡対象ファイルの読み込み失敗を黙って除外する既存経路が、AGENTS.md の fail-closed 要件に抵触します。

## 施策1: LC_ALL=C 固定 + 配線検査

判定: REQUEST_CHANGES

- [Warning] 記載された fail-first の失敗内容を再現できません。

  現行コードには `buildProcess()` が存在しないため、提示されたテストを先に追加すると `getEnv() === []` の期待値違反ではなく、未定義メソッド呼び出しで失敗します。これでは「env 未配線を検査が検出した」という分岐の裏取りになりません。

  修正案: 次の順序を設計へ明記してください。

  1. 既存テストが緑の状態で、env を設定しない `buildProcess()` を振る舞い変更なしで抽出
  2. 配線検査を追加し、`[]` と `['LC_ALL' => 'C']` の不一致で赤を確認
  3. `LC_ALL=C` を追加して緑化

- [Suggestion] 検査が直接保証するのは `buildProcess()` の設定だけです。

  `inspect()` が将来 builder を迂回して `new Process()` を直接生成しても、提示された検査は緑のままです。現在の実装では `inspect()` が builder を呼ぶためコードとしては正しいものの、「inspect の実行時配線を機械保証する」という表現はやや強すぎます。

  過剰なテスト用 DI を追加しないなら、テスト名と D54 を「php-l Process builder の明示 env を pin する」程度に限定するのが適切です。

Process の引数配列、`null` cwd、明示 env の指定方法、`PHP_BINARY` の利用には、提示コード上のコマンドインジェクション上の問題はありません。PHPStan level 10 上も問題になりにくい形です。

## 施策2: 識別子位置の T_NAMESPACE ガード

判定: REQUEST_CHANGES

- [Critical] `detects=true` の各検体が、本当に警告を1件以上出したことを保証していません。

  現在の空振り検査は全検出検体の警告合計が `> 0` であることしか確認しません。そのため、新しい `detects-namespace-identifier` の oracle と scanner が両方0件になっても、他の検体から警告が取れていれば緑になり得ます。`GLOBAL_USE_FIXTURES` の `true` が「検出側」という契約になっているのに、その契約が個別には検査されていません。

  修正案:

  - 各 `detects=true` 検体について、oracle の `warnings` が非空であることを個別に検査する
  - 新規検体は期待が明確なので、可能なら警告数をちょうど1件で pin する
  - より堅牢にするなら、一覧を bool ではなく期待警告数にする

  例:

  ```php
  foreach (GLOBAL_USE_FIXTURES as $name => $detects) {
      if (! $detects) {
          continue;
      }

      expect($globalUseOracle[$name]['warnings'])
          ->not->toBeEmpty('検出側の見本 '.$name.' から真値が取れていません');
  }
  ```

- [Warning] 追跡下ファイルの読み込み失敗が fail-open です。

  `globalUseScanTrackedTree()` の次の経路は、対象ファイルを無言で母集団から除外します。

  ```php
  if (! is_string($source)) {
      continue;
  }
  ```

  他のファイルが読めていれば `totalFiles > 0` も各 root の包含検査も通るため、AGENTS.md の「解決できない形を黙って候補から外さない」に反します。今回 gate 本体と走査器を変更し、fail-closed 維持を実装済み条件として主張する以上、設計へ含めるべきです。

  修正案: 読み込み失敗時は `RuntimeException` を投げるか、対象パスを `unresolved` に積んで既存の空配列検査で落としてください。可能なら読み込み失敗分岐の自己検査も追加してください。

- [Warning] 「旧実装で偽の赤と検出漏れの両方を確認する」という fail-first の説明は、現在のアサーション順では完全には成立しません。

  検出側テストは最初に `unresolved === []` を検査するため、そこで失敗すると真値との不一致比較まで実行されません。

  修正案: 新規検体について unresolved と violations の期待を別テストに分けるか、両方を一つの配列へまとめて比較し、初回の赤で双方の差が観測できるようにしてください。

`atStatementStart()` 自体の候補集合は妥当です。PHP 8.4 の構文として有効な namespace 宣言について、開始タグ、`;`、`}` の三形を既存検体が押さえており、非候補位置を識別子として読み飛ばす判断も構文妥当な入力という保証範囲と整合します。`previousSignificant(): ?int` の null 分岐も型安全です。

## 施策3: D54・採用時債務・pin 更新

判定: REQUEST_CHANGES

- [Warning] D54 の「検査群が保証する」という記述が、現状のテスト強度より広くなっています。

  特に以下は現案のままでは完全には保証されません。

  - LC_ALL が `inspect()` の実行経路へ常に使われること
  - 各検出側検体が個別に負例として発火し続けること
  - 読めない追跡下 PHP が無言で走査対象外にならないこと

  修正案: 施策1・2の検査を補強したうえで記載を維持するか、「builder の明示 env」「検体で固定した文脈」など、実際に機械保証している範囲へ文言を限定してください。

- [Warning] 「業務要件起因の説明」が、現在は主に技術規約の説明になっています。

  `TrackedPhpSourceFiles` と AGENTS.md の単一出典規約は強い技術的根拠ですが、9行メタ表の項目名どおり業務要件起因を必須とする運用なら、その要件との接続が不足しています。

  修正案: 6 root へ縮小すると、migration や Architecture コードなど追跡下 PHP の一部でコンパイル警告を見逃し、テスト基盤が環境依存で停止し得るため、リポジトリ全体の変更安全性を維持する必要がある、という運用上の必要性まで対象行へ含めてください。技術的逸脱だけでも登録可能な台帳規約なら、その適用根拠を明記してください。

D54の9行順序、対象パスの一意性、件数の `53→54`、債務の `143→142` という更新方針自体は整合しています。

## 横断的な指摘

- [Warning] 最終検証コマンドが AGENTS.md の必須一覧を満たしていません。

  手順5では次が省略されています。

  - `pnpm build`
  - `pnpm typecheck:packages`
  - `pnpm build:packages`
  - `pnpm test:packages`

  修正案: AGENTS.md の `VERIFICATION_COMMANDS` にある全コマンドを、そのまま最終検証へ列挙してください。フォーマット確認は `vendor/bin/pint --test` を正本とし、`composer fix` は実装中の整形操作と最終検証を区別してください。

- [Suggestion] 変更対象は「7ファイル程度」ではなく、列挙上は新規検体を含めて8ファイルです。実装スコープ表記を合わせるとレビュー時の取りこぼしを防げます。

- DESIGN.md / Atomic Design は、UI・Svelte・コンポーネント変更がないため該当なしです。

Critical 1件と Warning の修正後であれば、t2追従の方向性そのものは承認可能です。