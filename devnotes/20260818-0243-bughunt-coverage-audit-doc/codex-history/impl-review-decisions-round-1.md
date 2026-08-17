# 対応マトリクス: impl-review Round 1

## [Warning] 自己言及検査に祖先パスによる迂回がある (`out_of_scope.py`)

- 判断: 対応する
- 根拠: 指摘のとおり。`verification_refs: ["app"]` や
  `[".claude/skills/app-bug-hunt/coverage"]` は、対象外の面や宣言自身を**内包する祖先**であり、
  子方向の判定だけでは通ってしまう。D27 に「代替検証が自己言及でない」と書いた以上、
  実装がその保証に届いていないのは記述の誇張になる。
- 対応内容: `_overlaps()` を新設し、重なりを**両方向**で判定するようにした
  (対象パスとの重なり・宣言自身・監査文書の 3 つすべてに適用)。
  負の対照を実測で確認済み — 子方向だけの判定へ戻すと `["app"]` が通ってしまう。

## [Warning] JSON の重複キーを検出できない (`out_of_scope.py`)

- 判断: 対応する
- 根拠: `json.loads()` は同一 object 内の重複キーを黙って後勝ちで畳むため、
  必須キー・未知キーの検査を通っても、レビューで見えている値と実際に採用される値が
  食い違いうる。deny-by-default の宣言形式としては穴である。
- 対応内容: `object_pairs_hook=_reject_duplicate_keys` を渡し、トップレベルと entry の
  両方で重複キーを拒否するようにした。負の対照も実測で確認済み。

## [Warning] パス解決の失敗が DeclarationError へ収束しない (`out_of_scope.py`)

- 判断: 対応する
- 根拠: 「終了コード 2 / traceback を出さない」と文書に書いた契約が、
  symlink の輪や入出力エラーで破れうる。fail-closed の実体に関わる。
- 対応内容: `_resolve_or_fail()` を新設し、`resolve()` と `is_symlink()` / `exists()` の
  失敗をすべて `DeclarationError` へ収束させた。基点 (`repo_root`) の解決も同じ関数を通す。

## [Warning] 実装上の迂回に対応する負のテストが無い (`test_out_of_scope.py`)

- 判断: 対応する
- 根拠: 検査と同じ穴を共有しているテストは空振りする。
- 対応内容: 次の 4 本を足した。
  - 祖先による自己言及 (`app` / `.claude/skills/app-bug-hunt/coverage` / `.claude`) の拒否
  - トップレベルと entry の JSON 重複キーの拒否
  - symlink の輪でも 終了コード 2 / stdout 空 / traceback なし になること
  - 値に混ぜられた Unicode の行区切りでも stderr が 1 行に保たれること

## [Warning] `queued-job` の代替検証が狭い (`out-of-scope.json`)

- 判断: 対応する
- 根拠: `app/Jobs` 全体を対象外にしながら、代替検証が「待ち時間の扱いと重複実行の目録」しか
  指していないと、各ジョブの業務挙動を誰も見ていないように読める。
  実際にはドメイン側の Feature テストが各ジョブを実走させている。
- 対応内容: 実際にジョブを実走させている Feature テスト 3 本
  (合成の一連の流れ / テイクの実体削除 / 自動チャージ) を `verification_refs` に足し、
  説明文も「各ジョブの業務挙動はドメイン側の Feature テストが検査する」と書き直した。

## [Suggestion] `_single_line()` が CR / LF しか畳まない

- 判断: 対応する
- 根拠: 指摘のとおり、行区切り 1 文字で「1 行」という契約を壊せる。
  `str.splitlines()` を使えば標準ライブラリの作法のまま塞げる (安い)。
- 対応内容: `" ".join(message.splitlines())` へ変更し、負の対照テストを足した。

## [Suggestion] `ID_PATTERN` が末尾ハイフン・連続ハイフンを許す

- 判断: 対応する
- 根拠: id は参照される語彙なので、表記ゆれを許さないほうがよい。安い。
- 対応内容: `^[a-z0-9]+(?:-[a-z0-9]+)*$` へ変更した。

## [Suggestion] middleware の docblock が env と config を混ぜている

- 判断: 対応する
- 根拠: 実装の判定窓口は `config('bughunt.pcov.enabled')` であり、env は値の出所にすぎない。
  説明が実装とずれていると、guard を外すときの判断材料を誤らせる。
- 対応内容: docblock を「設定 config('bughunt.pcov.enabled') (値の出所は env の BUGHUNT_PCOV)」
  と書き直した。実装 (コード) には触れていない。
