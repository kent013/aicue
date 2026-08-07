# 対応マトリクス: design-review Round 2

Codex 全体判定: CHANGES_REQUESTED
(S1 APPROVE / S2 APPROVE / S3 APPROVE / S4 REQUEST_CHANGES / S5 APPROVE / S6 REQUEST_CHANGES)
[Critical] 0 件・[Warning] 4 件・[Suggestion] 2 件。

## [Warning] S4: 419 でも `$request->user()` が先に評価される (引数評価順の罠)

- 判断: **対応する**
- 根拠: 完全に正当。PHP は引数を呼び出し前に評価するため、
  `ErrorScreenDestinations::for($status, $request->user() !== null)` と書くと
  `forcesGuestDestinations()` が真でも user resolver が走る。
  セッションが壊れている 419 で resolver が throw すると、
  **本来最も救いたい画面** (セッション切れ) が `report()` + Blade fallback に落ちる。
  リスク表の「419 は D1 で認証状態を見ない」という記述もコードと一致していなかった。
- 対応内容:
  - `render()` を `$authenticated = $status->forcesGuestDestinations() ? false : $request->user() !== null;`
    に変更し、419 では短絡することをコメントで明示
  - リスク表 7 番を実態 (短絡済み) に書き換え
  - テスト追加: `it('419 は user resolver が例外を投げても Error 画面になる (認証状態を評価しない)')`
    — guard の `user()` が throw する状態で 419 を叩き、`component('Error')` +
    戻り先が login route + `Exceptions::assertNothingReported()` (fail-safe に落ちていない)
  - mutation 表に M13 (短絡を戻す) を追加

## [Warning] S4: `report($e)` がテスト契約になっていない

- 判断: **対応する**
- 根拠: 正当。fail-safe テストが原応答の一致しか見ていないため、
  将来 `report($e)` が消えても green のままになる = Round 1 の [Critical] 対応が骨抜きになる。
- 対応内容: version resolver throw のテストを
  「原応答が完全一致 **かつ** `Exceptions::fake()` + `Exceptions::assertReported(...)` で
  例外が報告された」に強化。`Illuminate\Support\Testing\Fakes\ExceptionHandlerFake::render()` が
  実ハンドラへ委譲する (vendor で確認済み) ため、fake しても respond callback の検証は成立する。
  mutation 表に M14 (report を削除) を追加。

## [Warning] S6: 設計本文に「文字列走査」の旧説明が残っている

- 判断: **対応する** (Round 1 の反映漏れ)
- 対応内容: gate の docblock とリスク欄を token 走査ベースへ書き換え。
  保証範囲の限界を「動的呼び出し / 別名ラッパー / **同名の無関係メソッド** /
  将来追加される API は検出範囲外」に更新し、静的 + 振る舞いの二重化を明記。
  リスク欄の「増えたら FQCN 前提へ絞る」も「レシーバが `$exceptions` /
  ExceptionHandler 相当のときだけに絞る (絞る前に本当に別物かをレビューで確認する)」に具体化。

## [Warning] S6: 恒久テストの期待件数が 2 件 / fixture が 3 呼び出しで不一致

- 判断: **対応する** (単純な不整合)
- 対応内容: 負のコントロールの期待件数を **3 件**に統一し、内訳
  (`->respond(` / `handleExceptionsUsing(` / `->respondUsing(`) を明記。
  併せて「コメントだけの fixture で 0 件」「壊れた目録 fixture で違反が返る」も
  負のコントロールのチェックリストへ明示的に列挙した。

## [Suggestion] S2: `RetryAfterSeconds` の docblock の「利用点は 3 つ」が古い

- 判断: **対応する**
- 対応内容: 4 点 (API details / API ヘッダ / Inertia prop / Inertia ヘッダ) に更新し、
  それぞれの呼び出し元メソッド名まで書いた (SoT の監査対象が一意になる)。
  mutation 表に M15 (`extraHeaders()` の正規化を外す) も追加。

## [Suggestion] S6: mutation 表のヘッダが二重

- 判断: **対応する**
- 対応内容: 重複行を削除。
