# 対応マトリクス: conceptual-review Round 1

## [Critical] i9 の件数申告が台帳の実構造を表さない (値の固定側の「分類」が未定義)
- 判断: 対応する
- 根拠: 指摘どおり。値の固定側は現状 `AG007_CORE` / `AICUE` の 2 定数に割れており、これは必須キー側の
  `SETUP` 等と同じ「分類」概念である (由来ごとの束)。分類を定義せず種別の合計だけを申告すると、
  「AG007 の 1 件を消して aicue 固有を 1 件足す」差分が合計 5 のまま緑になり、由来の入れ替えが無音になる。
  正典 i9 も「種別ごと**・分類ごと**」を要求している。
- 対応内容: 概念設計を書き換え、合成後の entry を
  `{key, kind, classification, origin, value}` の**単一 shape へ正規化**する形を明記した。
  値の固定の分類を 3 つ (`ag007_core` / `canonical_t2` / `aicue`) と定義し、
  `APP_ENV` は新設の `canonical_t2` に置く。申告は「種別ごと 2 件 + 分類ごと 7 件」の 2 段で持ち、
  正規化後の実件数と**完全一致** (ksort 後の map 比較) を要求する。

## [Warning] 解析器の純粋関数に `expect()` が入っており実行環境に依存する
- 判断: 対応する
- 根拠: 妥当。`expect()` は Pest の実行文脈に依存し、「入力文字列だけから戻り値が決まる」という
  i2 の主張を弱める。また `preg_split()` / `preg_match()` の `false` を「違反なし」へ畳むと
  AGENTS.md の走査器規約 (b) fail-closed に反する。
- 対応内容: 解析器の中の `expect()` を `Webmozart\Assert\Assert` へ置き換え、
  `preg_split()` の失敗と `preg_match()` の `false` を**例外で落とす**方針を概念設計に明記した。
  制御文字の判定は `!== 1` ではなく `=== 1` を違反とし、`false` は例外にする
  (「制御文字なし」へ畳まない)。

## [Warning] i12 は basename 比較だけでは「見本そのもの」を指せない
- 判断: 対応する (両方を見る形にする)
- 根拠: 指摘の精度は正しい。ただし basename 比較を捨てるのは検出力を落とす方向なので、
  走査器規約 (b) の「拾いすぎる方向へ倒すのは可、見逃す方向へ倒すのは不可」に従い**両方**を見る。
- 対応内容: (1) 解決済み絶対パスが `realpath(base_path('.env.example'))` と一致しないこと、
  (2) basename が `.env.example` でないこと、の 2 段にした。見本の `realpath()` が解決できない
  ことは合格にせず不合格にする (fail-closed)。symlink の扱いを docblock に明記する。

## [Warning] 「制御文字」の定義が未固定
- 判断: 対応する
- 根拠: 妥当。範囲を書かずに「制御文字」と言うと C1 域・DEL・TAB の去就が実装者依存になる。
- 対応内容: 判定を単一の定数 (`/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/`) として明文化し、
  TAB (`\x09`) は許容、`\n` / `\r` は行分割で除去済み、**C1 域 (`\x80`-`\x9F`) は対象外**
  (UTF-8 の継続バイトと衝突するため。保証しない範囲として明記) と決めた。
  反証の境界値に NUL / SOH / VT / FF / DEL / 許容される TAB / コメント内の制御文字を置く。

## [Warning] APP_ENV の移送理由が entry から追えるようにする
- 判断: 対応する
- 根拠: i7 の由来の機械検査の目的そのもの。
- 対応内容: `APP_ENV` の entry の `origin` に「見本の用途宣言。`APP_DEBUG=true` を許す論拠が
  `APP_ENV=local` であり、論拠側が固定されていないと黙って失効する (正典 s4)」を書くことを明記した。
  種別を跨ぐ重複禁止 (旧 `array_intersect` の役割) は新形式の「キーは台帳全体で一意」規則が
  引き継ぐことも明記した。

## [Warning] entry の配列 shape を明示せよ (型安全性)
- 判断: 対応する
- 根拠: 妥当。分類ごとに shape が違うと将来 tests/ を PHPStan へ入れたときに壊れる。
- 対応内容: 正規化後の shape を
  `list<array{key: non-empty-string, kind: 'value_pin'|'required_key', classification: non-empty-string, origin: non-empty-string, value: ?string}>`
  に固定した (`value` は**常に存在する**キーとし、必須キーは `null`。optional key にしない)。
  種別と `value` の有無の整合は誠実性の検査の規則 4 が見る。

## [Warning] red-first-evidence.md の保存先が実装方針に無い
- 判断: 対応する
- 根拠: 妥当。T213 も同名の証跡を devnotes に残しており、規約 (AGENTS.md の設計・devnotes 運用) の
  通り置き場は決まっているが、明記が無いのは不備。
- 対応内容: 実装方針のファイル表に `devnotes/{dir}/red-first-evidence.md` を追加した。
  devnotes は指紋台帳の母集合外なので乖離台帳への影響は無い旨も明記した。

## [Suggestion] 「制御文字を混ぜて値を差し替える」の表現が強い
- 判断: 対応する
- 根拠: 誇張を避ける規約 (AGENTS.md の「保証範囲を誇張しない」) と整合する。
- 対応内容: 期待効果の記述を「dotenv・OS の環境変数・配備経路で同じ値として扱われる保証が無い
  不正形式を拒否する」へ書き換えた。

## [Suggestion] 使命への貢献 / A 形維持 / 解析器 2 本の非統合は妥当
- 判断: 反映不要 (肯定的指摘)
- 根拠: 現行の設計判断がそのまま支持された。
