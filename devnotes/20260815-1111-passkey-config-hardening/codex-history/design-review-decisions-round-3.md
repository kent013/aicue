# 対応マトリクス: design-review Round 3

## [Warning] 実効値 = 宣言値の一致比較は偽陰性（通常環境ではどちらも APP_URL / APP_KEY 由来）
- 判断: 対応する
- 根拠: 指摘のとおり。Fortify が `fortify.passkeys.*` を読まなくなって fallback に戻っても、
  fallback の値と宣言値が同じなので一致してしまい、**本設計で最も重要な検査が空振りする**。
- 対応内容: fallback では絶対に生まれない sentinel
  （`sentinel.example.com` / `str_repeat('s', 32)`）を `fortify.passkeys.*` に置いてから、
  `FortifyServiceProvider::configurePasskeys()` を **Reflection で直接呼んで**写像を実行し、
  `passkeys.*` に sentinel が現れることを検査する形に書き換えた。
  - `register()` 全体の再実行は採らない。Response contract のアプリ実装への差し替えまで
    Fortify 既定へ戻してしまい、検査対象より広い副作用が出るため（対象を最小に絞る）。
  - protected メソッドへの Reflection は vendor 実装への結合だが、
    **名前が変われば落ちる = 版を上げたときに写像を再確認する契機になる**ので、
    この検査の目的（写像が生きていることの固定）と方向が一致している。

## [Warning] allowed origins の `strtolower()` と「大文字 scheme を reject」の仕様矛盾
- 判断: 対応する（**正規化を仕様として明文化**し、validator の厳格拒否も残す）
- 根拠: 指摘を受けて webauthn-lib を実測した結果、**小文字化は load-bearing** だと分かった。
  照合は `in_array($normalizedOrigin, $this->fullOrigins, true)` の **strict 比較**で
  (`CheckAllowedOrigins::process()`)、ブラウザは常に小文字の origin を申告する。
  つまり `HTTPS://App.Example.com` と書かれた設定は**一致せず全手続きが無言で失敗する**。
  したがって「config では trim だけにして validator に原値を渡す」案は採らない
  （運用者が大文字で書いた時点で本番が壊れる）。
- 対応内容:
  - config 側の小文字化を**仕様として明記**し、理由（strict 比較 + RFC 3986 で
    scheme / host は case-insensitive なので意味を変えない）をコメントに書いた。
  - validator は**大文字を受理しない**ままにする。宣言側が正規化するので、
    validator に大文字が届くのは「別経路が正規化せずに設定した」場合だけであり、
    その値は本番で無言に壊れる。黙って受理せず落とすのが正しい。理由をコメントに書いた。
  - テストを両側に足した:
    config = `HTTPS://App.Example.com` → `https://app.example.com` に正規化される /
    validator = `HTTPS://app.example.com` と `https://APP.example.com` はどちらも reject。

## [Warning] リスク欄の `mergeConfigFrom` 説明が旧設計のまま
- 判断: 対応する
- 対応内容: リスクを「Fortify の `configurePasskeys()` のキー写像・組み立て規則への依存」に置き換え、
  テスト名も「vendor 既定キーが残る」→「**Fortify 結線後の実効キーが揃っている**」に変更した。
  あわせて「`management_middleware` / `throttle` は vendor 既定値ではなく
  Fortify がアプリ設定から組み立てた実効値である」と明記した（誇張しない）。

## [Warning] ProductionEnvGuardTest の baseline のキー位置が新設計に追従していない
- 判断: 対応する
- 対応内容: baseline を具体コードで示し、**実効値は `passkeys.*` / 検査専用は `fortify.passkeys.*`** と
  読み出し元を明示した。非 string の検査も「2 系統をそれぞれ個別に壊す」形に増やし、
  読み出し元の取り違えを検出できるようにした。

## [Suggestion] 版 pin の担当範囲（Fortify は semver 管理なので minor pin 不要）
- 判断: 対応する
- 対応内容: docs の記述を
  「版 pin の対象は `laravel/passkeys` だけ。`laravel/fortify` は 1.x の semver 管理なので pin を足さず、
  写像は実効値の契約テストが守る」に書き換えた。
