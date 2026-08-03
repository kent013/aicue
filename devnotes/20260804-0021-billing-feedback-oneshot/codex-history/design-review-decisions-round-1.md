# 対応マトリクス: design-review Round 1

## [Warning] (施策3) `?session_id=` (空) / `?session_id[]=` (配列) が canonical 化されない
- 判断: **対応する**
- 根拠: 指摘が正しい。設計本文は「着地 query を認識したら必ず畳む」と書いているのに、
  実装案の着地判定が `is_string($sessionId) && $sessionId !== ''` (値の妥当性) になっていた。
  この不一致は「バナーは出ないが URL に session_id が残る」= 次のリロードでまた DB を引く
  状態を残し、one-shot 契約の穴になる。
- 対応内容: 着地判定を **キーの存在** (`$request->query->has('session_id')` /
  `$request->query->has('portal')`) に変更。kind 解決は別段の `match (true)` で
  「string かつ非空のときだけ DB を引く、それ以外は無言」に分離した (fail-closed は維持)。
  docblock にも「着地判定はキーの存在で行う」と明記。

## [Warning] (施策3) `error` 継続条件が `is_string()` 固定
- 判断: **対応する**
- 根拠: keep の目的は「hop で error を落とさない」ことなので、値の型に依存させる理由がない。
  型が変わったときに黙って取りこぼす (= 失敗を伝えない) 方向の劣化になる。
- 対応内容: `$request->session()->has('error')` で判定し、成立時は feedback を出さず
  `keep(['error'])` して canonical へ 303 する。
  (`Session::has()` は null を false 扱いするため「キーはあるが null」で誤発火しない)

## [Warning] (施策7) 空 / 不正型 `session_id` の canonical 303 を固定するテストが未明記
- 判断: **対応する**
- 対応内容: T6 (着地 query は必ず畳まれる契約テスト) の dataset に
  空 `?session_id=` / 配列 `?session_id[]=` / 値なし `?portal` を追加し、
  `assertStatus(303)` + `assertRedirect('/billing')` + (不正値ケースは) `assertSessionMissing(FLASH_KEY)`
  を固定した。

## [Suggestion] (施策7) `?portal` + error は追従先の props まで確認する
- 判断: **対応する**
- 対応内容: T5 に「追従先の Inertia props で `flash.error` が届く」ことの確認を追加。
  `keep()` の実効 (= toast が実際に出る) までを固定する。

## [Suggestion] (施策1) `@phpstan-type` を `value-of<BillingFeedbackKind>` に
- 判断: **対応する**
- 根拠: 手書き literal union は enum 追加時に drift する。今回 enum を触る施策なので
  ついでに直すのが自然 (別施策を増やさない)。
- 対応内容: `SimpleBillingFeedbackKind` の手書き union を削除し
  `array{kind: value-of<BillingFeedbackKind>, message: string}` に置換。
  `toArray()` の `/** @var */` も不要になるため削除。
  万一 PHPStan が `value-of<>` を解決できない場合のみ現行の手書き union を維持する旨を fallback として明記。

## [Suggestion] (施策2) `PRESERVED_LANDING_QUERY` の意図を docblock に
- 判断: **対応する**
- 対応内容: 「状態を主張しない anchor は保持 / 状態を主張する query は保持しない」という
  振り分け基準を docblock に明記した (query を足す人が迷わないようにする)。

## その他 (施策 4 / 5 / 6): 指摘なし
- 現状維持。
