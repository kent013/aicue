# 対応マトリクス: design-review Round 1

## [Critical] `retentionSchemaTableNames()` が PHPStan level 10 で不安定
- 判断: 対応する
- 根拠: `Schema::` ファサードの docblock は `array getTables(...)` としか書いておらず、
  level 10 では要素が mixed になる。`array_column()` も `list<string>` に推論されない。
- 対応内容: `DB::connection()->getSchemaBuilder()` で**具体の `Illuminate\Database\Schema\Builder`**
  を取る形に変更した (実体側の `@return list<array{name: string, …}>` がそのまま効く)。
  `array_map()` で明示的に `list<string>` を作り、`sort()` で比較順も固定した。

## [Critical] RC-6 / RC-7 が DB 照会に直結していて合成入力で点灯できない
- 判断: 対応する
- 根拠: そのとおり。`retentionClassify()` は RC-1〜RC-3 しか返さず、RC-6 / RC-7 の
  負のコントロールを書く手段が設計に無かった。
- 対応内容: 外部キーの一覧を引数で受け取る純関数を 2 本に分けた
  (`retentionDeletedWithParentViolations()` / `retentionHorizonParentViolations()`)。
  実 DB 側は `retentionForeignKeyMap()` が 1 度だけ組み立てて渡す。
  負のコントロール 4 本 (NC-1〜NC-4) を具体的な点灯条件つきでテスト計画に書いた。

## [Warning] `entries()` の戻り値が設計内で矛盾している
- 判断: 対応する
- 根拠: 骨格が連想配列、注記が list になっており、読んだ人がどちらを実装するか決められない。
  連想配列にすると二重宣言が上書きで消えるので list が正しい。
- 対応内容: 骨格を `@return list<RetentionTableEntry>` に統一し、
  「連想配列にしない理由」を docblock に書いた。キー化と二重宣言の検出は gate 側で行う。

## [Warning] `Schema::getForeignKeys()` がスキーマを絞っていない
- 判断: 対応する
- 根拠: 表一覧を現在のスキーマに絞りながら外部キーの照会だけ `search_path` 任せにするのは
  一貫していない。`Builder::getForeignKeys()` は `parseSchemaAndTable()` を通すので
  `schema.table` を受け取れることを vendor 実物で確認した (Builder.php 492 行 / 758 行)。
- 対応内容: スキーマ修飾名で問い合わせる形にした。併せて
  「表と外部キーの読み取りは現在のスキーマに限る (`search_path` の健全性は前提であって保証ではない)」
  を保証しないものへ追記した。

## [Warning] `users` を「定期実行が消す」に置くと意味が広すぎる (行ごとに寿命が違う)
- 判断: 対応する
- 根拠: そのとおり。退会予約が入った行だけが消える表を、表単位の区分に丸めている。
- 対応内容: 根拠欄に「**表の中で行ごとに寿命が違う**」と明記する指示を追加し、
  保証しないものに「行ごとの寿命の違いは表現しない」を追加した (gate docblock / docs の両方)。

## [Warning] `oauth_*` の未確定理由と RC-5 の検査内容がずれる
- 判断: 対応する
- 根拠: 「Schedule に無い」を理由に書くと、gate が Schedule を見ていないことと食い違う。
- 対応内容: 理由を「本リポジトリの保持期限の責任者が決まっていない (掃除の配線を含む決着が未決)」に
  書き換え、「gate は Schedule への登録有無を見ない」を保証しないものへ明記した。

## [Warning] RC-7 に `FrameworkManaged` を含めるのは責務としてやや強い
- 判断: 対応する (含めたまま、理由を明記)
- 根拠: 基盤の表がアプリの寿命を持つ表を親に持つなら、それは「フレームワークが寿命を決めている」
  とは言えず `DeletedWithParent` である。つまり RC-7 は区分の定義そのものの検査になっている。
- 対応内容: その理由を設計に書き足した (対象は `ReferenceData` と `FrameworkManaged` の 2 区分のまま)。

## [Warning] RC-5 の `Artisan::all()` の副作用と実行時間
- 判断: 対応する
- 対応内容: `array_key_exists($entry->ownerCommand, Artisan::all())` に限定し、
  **コマンドは実行しない**ことをコメントで固定する旨を設計へ書いた。
  `ownerCommand` を必須にするのは `scheduledDeletion()` の名前付き生成子だけなので、
  対象は既に「定期実行が消す」区分に絞られている。

## [Suggestion] RC-8 の区分ごとの件数 pin は過剰摩擦になり得る
- 判断: 対応する (**区分ごとの件数 pin を落とす**)
- 根拠: 全体件数と未確定の表名一覧があれば「台帳が空になった」「未確定が無音で増えた」の 2 つは
  捕まる。区分ごとの件数は書き換えの手間が増えるだけで、新しく捕まえられるものが無い
  (思考原則 2 — 今必要なものだけ作る)。
- 対応内容: RC-8 から区分ごとの件数 pin を削除し、落とした理由を設計に明記した。

## [Suggestion] 施策 4 の文言「同じ事実を 2 か所に書かない」が不正確
- 判断: 対応する
- 根拠: 表名自体は両方に現れる。正確には「年数・起算点・purger を写さない。表集合の重なりは
  RC-4 の結線だけで管理する」である。
- 対応内容: 追記文言をその表現に直した。

## [Suggestion] `RetentionClass::Undecided::hasHorizon()` のコメント
- 判断: 対応する
- 対応内容: 「期限が要ると決まった」ではなく「期限の連鎖に入りうるので保守的に horizon 側へ寄せる」
  という書き方に直した。

## [Suggestion] AGENTS.md に「ドメイン固有規約」節が無いかもしれない
- 判断: 確認済み (対応不要)
- 根拠: `AGENTS.md` に「## ドメイン固有規約」節は実在し、現在 1〜14 の 14 項が並んでいる。
  施策 6 はその 15 項めとして追加する。
