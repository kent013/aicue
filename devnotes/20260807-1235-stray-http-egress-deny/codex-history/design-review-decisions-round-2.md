# 対応マトリクス: design-review Round 2

全体判定 **CHANGES_REQUESTED**。[Critical] ゼロ。必須修正 2 点 (+ Suggestion 1 件)。
**すべて対応した** (反論・見送りゼロ)。

## [Warning] S1: `localhost` の説明が許可機構の保証を実態より強く書いている
- 判断: **対応する**
- 根拠: 指摘は完全に正しい。`PendingRequest::isAllowedRequestUrl()` は
  `Str::is($pattern, $url)` = **名前解決前の URL 文字列**照合であり、
  DNS/hosts で `localhost` が外部 IP に解決される環境では、許可判定を通過したうえで
  実際に外部へ送信されうる。「解決先が loopback でなければそもそも到達しない」は
  **事実として誤り**であり、設計文書に嘘を残すことは裁定 AG-105 が最も嫌う
  「保証範囲を誇張する」に該当する。
- 対応内容:
  1. `ALLOWED_URL_PATTERNS` の docblock から誤った 1 文を削除し、
     「判定は名前解決前の URL 文字列照合である」「本 guard は
     『localhost はテスト実行環境で loopback に解決される』を**前提として置いている**だけで、
     hosts / DNS の健全性は保証しない」に書き換えた。
  2. クラス docblock の「保証範囲」に「hosts / DNS の健全性は保証しない — それは前提である」を追記。
  3. S5 の `AGENTS.md` 追記案の「保証範囲 (誇張しない)」にも同じ限定を 1 行追加。
  4. `localhost` / `[::1]` を残す判断自体は維持 (表記揺れによる偽赤コストの方が大きい)。
     一方で「前提を置けないホスト名 (`aicue.test` 等) は入れない」理由をこの文脈に接続した。

## [Warning] S4: closure 本体の抽出を生文字列の `{` / `}` カウントで行うのは安全でない
- 判断: **対応する** (指摘どおり `PhpToken` ベースへ変更)
- 根拠: 指摘のとおり。コメントを落としても
  `'{"enabled":true}'` / `"value={$value}"` / heredoc 本文の裸の `{` が残り、
  現行 `tests/Pest.php` で偶然成立しても**将来無関係な文字列を足しただけで gate が壊れる**。
  deny-by-default gate が「たまたま動いている」状態は、gate が無いのと同程度に危険。
- 対応内容: `strayHttpEgressCode()` の契約を「コメント除去」から
  **「構文的に安全な解析入力への正規化」**へ変更した:
  - `T_COMMENT` / `T_DOC_COMMENT` → text を空にする
  - `T_CONSTANT_ENCAPSED_STRING` / `T_ENCAPSED_AND_WHITESPACE` (補間文字列と
    heredoc/nowdoc の本文) / `T_INLINE_HTML` → text 中の `{` `}` `(` `)` を `_` に**置換**
    (消さずに置換するのは、レーン名 `'Feature'` などの中身を残しつつ構造だけ無害化し、
     オフセットを 1:1 に保つため)
  - それ以外はそのまま連結
  これにより残る括弧は**すべて構文上の括弧**になる。補間の `{$x}` は
  `T_CURLY_OPEN` + `}` という構文トークンの対なので深度が必ず戻り、誤認しない。
  深度カウント自体は純関数
  `strayHttpEgressBalancedInner(string $code, int $openOffset, string $open, string $close): ?string`
  に切り出し、closure 本体抽出と引数抽出の両方から使う。
  負のコントロール「closure 内の JSON 文字列 / 補間 / heredoc で終端を誤認しない」
  (JSON literal / 不均衡 literal `'} ) { ('` / 補間 / nowdoc を全部含む fixture が
   違反ゼロになること) を追加した。

## [Warning] S4: `preventStrayRequests()` の引数判定も対応括弧を生文字列で探索するなら同じ問題
- 判断: **対応する**
- 根拠: 同上。`Http::preventStrayRequests(str_contains($s, ')'))` のように引数中に `)` が
  あると「次の `)`」実装は空引数と誤認し、**opt-out を見逃す** (deny-by-default の穴)。
- 対応内容: `strayHttpEgressIsOptOutSource()` の判定を
  「`preventStrayRequests` 直後の `(` から `strayHttpEgressBalancedInner()` で求めた
  **対応する** `)` までが空白のみか」に変更 (`allowStrayRequests(` は引数を問わず全件対象のまま)。
  負のコントロール「引数中の文字列に `)` を含む opt-out を誤判定しない」を追加
  (誤検出側「無引数呼び出しの後ろに別の括弧があっても false」も併せて固定)。

## [Suggestion] S4: 無引数 `preventStrayRequests()` を許可する判断は妥当。ただし将来 framework の既定引数の意味が変わるリスクを契約テストで固定せよ
- 判断: **対応する**
- 根拠: guard 自身が `preventStrayRequests()` を無引数で呼ぶため、既定値が反転したら
  レーン既定が**静かに無効化される**。まさに本設計が防ごうとしている「静かに緑」。
- 対応内容: S2 case A のコメントに「本ケースは**無引数** `preventStrayRequests()` が
  拒否を有効化するという vendor の既定引数の意味に対する契約テストでもある」を明記した
  (case A は guard 経由で install した状態を検査するため、追加のテストは不要)。

## [Suggestion] S2: 骨格一覧の case D 名が旧名のまま / `beforeEach(...)` が擬似コードと分かりにくい
- 判断: **対応する**
- 根拠: 実装者が骨格一覧をそのまま貼る事故を防ぐ。コストゼロ。
- 対応内容: 骨格一覧の case D を「(stray 判定を通過して送信段まで進む)」へ同期し、
  一覧の直前に「↓ 以下は擬似コード (テスト名の一覧)。実体は本節の後半に示す。」を追記した。

## Round 2 で APPROVE 済みの施策 (S2 / S3 / S5 / S6)
- 判断: **見送る** (対応不要)
- 根拠: Round 1 の指摘が解消したことを確認済みとの評価。追加変更は入れない。
