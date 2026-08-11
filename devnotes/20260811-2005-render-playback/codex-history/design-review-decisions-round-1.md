# 対応マトリクス: design-review Round 1

## [Warning] 施策 2: kind→ability 写像が実質未テスト (M7 が赤にならない)
- 判断: **対応する**(trip-wire を廃し、写像を直接固定する形へ差し替え)
- 根拠: 指摘が正しい。本施策の中核契約が回帰テストで固定されていないのは
  「テストなしの実装完了」に近い。Gate spy より policy 差し替えの方が
  「写像が実際の認可経路で効く」ことを end-to-end で観測できる。
- 対応内容: `tests/Feature/Manual/RenderPlaybackAbilityMappingTest.php` +
  `tests/Support/Policies/DivergentVideoManualPolicy.php` を新設し、
  `Gate::policy()` で render / download を非対称にした 4 ケース + 層 2 先行の 1 ケースを固定。
  mutation 表の M7 を「赤になる」へ更新し、M7'(逆写像)も追加。
  `RenderPlaybackAbilityParityTest`(同値監視の trip-wire)は**作らない**
  (写像を直接固定できる以上、同値監視は冗長 = 思考原則 2)。
  「保証しないもの」も「behavioral に固定できない」→「本番 policy の差は今は存在しない」へ書き換え。

## [Warning] 施策 5: `finishedJob !== null && canManage` が設計文と矛盾
- 判断: **対応する**(推奨案 = `canManage` を積まない)
- 根拠: 指摘が正しい。props が既に `download` ability を評価しているのに UI で
  `update` ability を再度積むと、「判断は props で 1 回」という主張が嘘になる。
- 対応内容: 完成動画ブロックの条件を `{#if finishedJob !== null}` に変更。
  vitest に「canManage=false でも完成動画ブロックは出る」を追加し、M11(条件に canManage を足す)を
  mutation 表へ追加。現行 props ではこの組合せが発生しないことも設計に明記した。

## [Warning] 施策 6: gate の不変条件表現が検出条件より強い
- 判断: **対応する**(両方 = 検出強化 + 表現の弱化)
- 根拠: 「守る不変条件」と「保証しないもの」の粒度がずれているという指摘は妥当。
  文字列リテラル経路は実際に安価に塞げるので塞ぎ、塞げない部分は表現を弱める。
- 対応内容:
  - 母集団マーカーを拡張: status 群 = `JobStatus::Succeeded` **または** `'succeeded'`、
    query 根群 = `renderJobs(` / `RenderJob::`(静的呼び出し全般) / `'render_jobs'`。
    `git grep` で実測し、拡張しても母集団は 5 ファイルのまま(偽陽性 0)であることを確認済み。
  - mutation に M1'(文字列リテラル版の書き戻し)を追加。
  - 不変条件の文言を「inventory 登録ファイルだけが直接クエリを書ける。Canonical は 1 ファイル」へ
    修正し、ファイル粒度・同一ファイル内メソッド追加・動的経路に沈黙することを明記。

## [Warning] 施策 7: ドキュメントの保証文を修正後に合わせよ
- 判断: **対応する**
- 対応内容: `docs/architecture.md` / `AGENTS.md` に書く文言を
  「テスト専用 policy で写像を固定。本番 policy は現在同値」に統一すると設計へ明記。

## [Suggestion] 施策 1: 別 manual に引っ張られないケースを Unit に足す
- 判断: **対応する**(テストケースを 1 本追加)

## [Suggestion] 施策 4: props に output_path / URL 系キーが無いことを exact に確認する
- 判断: **対応する**(キー集合の exact 一致 + 本文の文字列非出現を確認するケースへ書き換え)
