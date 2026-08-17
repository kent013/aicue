# 対応マトリクス: design-review Round 2

全体判定 CHANGES_REQUESTED (Critical なし / 必須修正 3 点)。**全件対応**した。

## [Warning] 施策 1: 呼び出し名だけを数えており、中継クラスへの委譲を証明していない
- 判断: 対応する
- 根拠: 正当。`SHARED_PROP_KEY => OtherFlashBuilder::payload($request)` が素通りする。
- 対応内容: 検査をクラス名込みの**字句列の完全一致**に変えた。
  コメント・空白を落として 1 個の空白で繋いだ正規化文字列に対し、
  `FlashNotificationRelay :: SHARED_PROP_KEY => FlashNotificationRelay :: payload ( $request )` が
  **ちょうど 1 回**、かつ `SHARED_PROP_KEY` の出現も `payload (` の出現もそれぞれ 1 回であることを見る。
  これで key と value が同じ配列 entry に並んでいることまで固定される。
  保証範囲 (「その 1 entry がその形で書かれていること」まで) も設計に明記した。

## [Warning] 施策 1: `session` / `uuid` の全面禁止は制約範囲と保証範囲がずれている
- 判断: **一部反論のうえ残す** (根拠を設計へ明記)
- 根拠: 正確な委譲検査を入れても**残る抜け道が 1 つある** — PHP の配列リテラルは同じキーが
  2 度現れると後勝ちになるため、正しい委譲 entry の**後ろに**別 entry で同じ prop 名を
  書けば上書きできる。その形を「文字列リテラルを使わず `KINDS` を回して `session` から
  組み立てる」書き方で作ると、委譲検査にも種別直書き検査にも当たらない。
  `session` / `uuid` の呼び出しが 0 件であることは、この残りをちょうど塞ぐ。
- 対応内容: 検査は残し、テストのコメントと設計の「リスク」節に
  **何を塞ぐために置いているのか**と**将来 session が要る共有 prop を足すときの手順**
  (支援クラスへ寄せる / それが不自然なら検査を意図して直す) を書いた。
  指摘のあった「別名 helper 経由の組み立て」は、上の委譲検査 (クラス名込み) の方で塞がる。

## [Warning] 施策 2: 負のコントロールに TypeScript の構文エラー (非 async 内の await)
- 判断: 対応する
- 対応内容: `const source = await readRelay();` を先に済ませ、`expect(() => …)` の中には
  await を置かない形へ直した。

## [Warning] 施策 5: `.flash` 文字列走査は不完全かつ過剰
- 判断: 対応する
- 根拠: 正当。`props["flash"]` / 分割代入を見逃し、`camera.flash` を巻き込む。
  「共有 prop の直読み禁止」ではなく「`.flash` という文字列の禁止」になっていた。
- 対応内容: 走査対象を**プロパティの書き方から消費の入口へ**移した。
  (a) `consumeFlash(` の呼び出し元が目録 (3 レイアウト) と完全一致すること /
  (b) 各呼び出しが `consumeFlash(readFlash(` の形であること。
  読み出しがどの記法でも値は最後に `consumeFlash` へ渡るため、迂回は必ずここに現れる。
  保証範囲 (整形後の固定書式に対する文字列一致であり、間に変数を挟む書き方は赤になる) も明記した。

## [Suggestion] 施策 3: Location が取れないときに黙って /login へ倒さない
- 判断: 対応する
- 対応内容: `expect($location)->toBeString();` を挟み、フォールバックを外した (fail-closed)。
