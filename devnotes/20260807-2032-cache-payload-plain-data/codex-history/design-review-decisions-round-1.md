# 対応マトリクス: design-review Round 1

全体判定は **CHANGES_REQUESTED**（S1 に 3 件の Critical）。全件を詳細設計へ反映した。反論はゼロ。

## [Critical] S1-1: `cache($values, $ttl)`（変数の第 1 引数）を見逃す

- 判断: **対応する**
- 根拠: 指摘のとおり。`cache()` ヘルパは `is_array($key)` で `put` に分岐するため、第 1 引数が
  変数のとき静的には読み書きを決定できない。「literal 配列だけ write」は bypass になる。
- 対応内容: ヘルパ呼び出しの分類を引数の形で 4 分岐にした。
  引数 0 個 = 連鎖の起点 / `[` または `array(` = WRITE / 文字列リテラル = 読み出し /
  **それ以外は `unclassified`（fail）**。fail message で「`Cache::put(...)` 等の明示形に書き換えよ」と促す。
  負のコントロール fixture「静的に判定できない形は fail させる」と mutation M11 を追加。

## [Critical] S1-2: `app(Repository::class)->put(...)` が表にあるのに未実装

- 判断: **対応する**
- 根拠: 設計表と実装の不一致は gate の信頼性を直接損なう（表を読んで安心した人が bypass を作る）。
- 対応内容: `app` / `resolve` / `make` の第 1 引数解析に `::class` 形を追加した。
  `T_DOUBLE_COLON` + `class` であることを確認したうえで名前を FQCN へ解決し、
  `CACHE_PAYLOAD_RECEIVER_TYPES` に含まれるときだけ受け手として `followChain()` に渡す。
  負のコントロール fixture「コンテナ解決・getStore・literal 動的呼び出し」に
  `app(Repository::class)->put(...)` / `resolve('cache')->forever(...)` / `app('cache.store')->add(...)` を追加。

## [Critical] S1-3: `Cache::getStore()->put(...)` を見逃す

- 判断: **対応する**
- 根拠: `getStore()` は `Illuminate\Contracts\Cache\Store` を返し、`put` / `forever` を持つ
  **書き込み可能な受け手**である。NON_WRITE に置くと探索がそこで終わる = bypass。
- 対応内容: `getstore` を NON_WRITE から **CHAIN** へ移した（語彙表と設計表の両方）。
  負のコントロール fixture に `Cache::getStore()->put(...)` を追加。
  mutation M12（getStore 経由の書き込みが赤くなる）と M13（CHAIN から外すと緑に戻る =
  この分類が効いていることの確認）を追加。

## [Warning] S1-4: 動的 literal メソッドがコメントと実装で不一致

- 判断: **対応する**（コメントを消すのではなく実装を合わせる）
- 根拠: `CarbonOverflowArithmeticGateTest` が同じ判断（literal 形は静的に決定できるので検出する）を
  既に採っており、作法を揃えるほうが読み手の学習コストが低い。加えて本 gate では
  **受け手が cache であると既に分かっている**ため、変数動的ディスパッチを素通りさせる理由が無い
  （Carbon gate が変数形を対象外にしたのは、走査対象に日付と無関係な dynamic dispatch が実在したから）。
- 対応内容: `cachePayloadFollowChain()` に `->{'put'}(...)` の literal 分岐（`cachePayloadLiteralValue()`）を
  実装し、`->{$m}(...)` / `->$m(...)` の変数形は `unclassified` として fail させる（実測 0 件）。
  冒頭コメントの「保証しないもの」も、変数動的ディスパッチではなく
  「受け手そのものが動的に得られる形（bind 名が変数）」に書き換えた。

## [Warning] S1-5: `use Cache;` だと facade に解決されない

- 判断: **対応する**
- 根拠: 指摘のとおり `useMap['Cache'] = 'Cache'` が先に当たり、裸名の facade 正規化に到達しない。
- 対応内容: `cachePayloadResolveName()` を「use 表を引いた**あとで**名前空間なしの `Cache` を
  facade へ正規化する」順序に組み替えた。負のコントロール fixture「use Cache; 形でも facade として解決する」を追加。

## [Warning] S1-6: `lock-only` は lock 呼び出し 0 件でも通る

- 判断: **対応する**
- 根拠: import だけ残った残骸が `lock-only` として居座ると、面の目録が実態から乖離する
  （思考原則 3「後方互換の並走を残さない」の機械化）。
- 対応内容: 検査 5 に「role=lock-only なら `lock` / `restoreLock` が 1 件以上ある」を追加。

## [Warning] S2-1: `fetched_at` の解釈不能値を固定していない

- 判断: **対応する**
- 根拠: 壊れた cache payload の代表ケース。`Assert::stringNotEmpty` は通過し
  `CarbonImmutable::parse()` 側で落ちるため、Assert のテストとは**別の失敗経路**である。
- 対応内容: `php -r` で実挙動を確認（`CarbonImmutable::parse('not-a-date')` は
  `Carbon\Exceptions\InvalidFormatException`）。dataset に混ぜると期待例外型が変わってしまうため、
  独立したテスト「fromArray は解釈できない fetched_at を例外にする」として追加した。

## [Suggestion] S2-2: 「database store の JSON 経路」というコメントが不正確

- 判断: **対応する**
- 根拠: 指摘のとおり database store は `serialize()` 系であり、JSON 経路という記述は誤り。
  誤情報の訂正が本件の主目的なのに、自分が新しい誤情報を書いては本末転倒。
- 対応内容: コメントを「永続化済みの古い payload や外部入力由来で rate が文字列になっていても」に修正。

## [Suggestion] S5-1: 不変条件番号の同期

- 判断: **対応する**
- 根拠: 5 件並列設計中で、同じ節の末尾に追記する他タスクがありうる。
- 対応内容: S5 のリスク欄を「番号が変わったら同期する 3 箇所」の具体的なチェックリストに書き換えた
  （AGENTS.md 本体 / gate 冒頭コメント / ConfigHardeningTest のメッセージ。guide §7 の番号は動かさないので対象外）。

## S3 / S4: APPROVE（変更なし）
