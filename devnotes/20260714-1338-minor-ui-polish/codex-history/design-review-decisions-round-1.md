# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED。S1 に Critical 1 + Warning 2、S2 は APPROVE + 改善。

## [Critical/S1] `sm:min-w-40` が Tailwind 既定で未定義の可能性
- 判断: 反論する (証拠を添えて) + 設計に根拠を明記
- 根拠: 本プロジェクトは **Tailwind v4.3.0** (`package.json` 実測)。v4 は min-width が
  spacing スケール (`--spacing` 連動) を共有し、`min-w-<n>` = `n*0.25rem` が有効。
  さらに **既存コードで `min-w-40` / `max-w-40` が現用**
  (`grep -oE '(min|max)-w-[0-9]+'` で確認済み) = ビルドが通っている実績あり。
  よって `sm:min-w-40` (= 10rem) は有効。v3 前提の Critical は本環境に不適合。
- 対応内容: 詳細設計に「Tailwind v4.3.0 / min-w-40 既存使用実績」を明記して確定。

## [Warning/S1] `sm:flex-wrap` + `sm:justify-between` は 2 行目の見え方が不安定
- 判断: 対応する (設計変更)
- 根拠: wrap 時に justify-between が各行で効き、回り込んだ操作ブロックの整列が曖昧になる。
  Codex 提案の「justify-between を外し操作側 `sm:ml-auto`」の方が 1 行時右寄せ・
  折返し時も右寄せで安定。
- 対応内容: `<li>` から `sm:justify-between` を除去し、操作ブロックに `sm:ml-auto` を付与
  (メンバー行・招待行の両方)。1 行時の見た目 (名前左 / 操作右) は不変、wrap 時は
  操作ブロックが右寄せで 2 行目に落ちる。既存テストは justify-between を assert していない
  ため破壊なし。

## [Warning/S1] jsdom クラス検証のみでは受け入れ条件 (768折返し/834維持) を保証できない
- 判断: 対応する
- 根拠: レイアウト非計算のため本丸は手動/E2E 確認。回帰防止を強化する。
- 対応内容: PR 成果物要件に「768px/834px の viewport スクリーンショット 2 点」を必須化。
  vitest はクラス不変条件のプロキシに限定 (既存方針踏襲) と明記。

## [Suggestion/S1] min-w-0 と床付与の要素対称性
- 判断: 対応する (明記で解消)
- 根拠: メンバー行は wrapper div、招待行は `<p>` 直下。いずれも「flex 直下の子 (名前/メール列)」
  に床を付与しており構造的に対称。招待行は wrapper が無く `<p>` 自体が flex 子のため直指定が正。
- 対応内容: 設計にこの対称性の説明を追記 (両者とも flex 直下子に `min-w-0 sm:min-w-40`)。

## [Warning/S2] useForm fake の password form 捕捉が曖昧だと put 検証が脆い
- 判断: 対応する
- 根拠: 既存 fake は `email` キーで profileForm を holder 捕捉。password form
  (`current_password`/`password` キー) 用に独立 holder + spy が必要。
- 対応内容: テスト計画を具体化 — fake を初期キーで二分岐
  (`email` 含む→profile / `current_password` 含む→password)、各 form に独立 put spy。

## [Suggestion/S2] autocomplete / aria-describedby 透過の回帰ケース追加
- 判断: 対応する
- 対応内容: テスト計画に「2 入力が autocomplete(current-password/new-password) と
  aria-describedby を保持」ケースを追加。

## [Suggestion/S2] 送信配線で errorBag:'updatePassword' まで assert
- 判断: 対応する
- 対応内容: put 検証で第2引数 options に `errorBag:'updatePassword'` を含むことまで固定。
