# 対応マトリクス: impl-review Round 1

## [Warning] `assertHasErrors(['key' => 値])` をメッセージの完全一致検査として使っている

- 判断: 対応する
- 根拠: vendor 実装 (`Livewire\Features\SupportValidation\TestsValidation::assertErrorMatchesRuleOrMessage()`) を
  実読すると、連想配列の値は**失敗した規則名と照合し、一致しなければメッセージの部分照合へ落ちる**
  二段構えである。したがって現状も「メッセージを見ている」こと自体は成立しているが、
  (a) 規則名と同名の値を渡すと黙って規則側で緑になる、
  (b) 値に `:` が含まれると `Str::before($value, ':')` で**前半だけの照合に弱まる**
  (現在の ja / en の文言にコロンは無いが、文言の変更や locale の追加で無音のまま弱まる)。
  台帳の保証 (2) 「残り秒数を含む案内」を固定したい検査でこの曖昧さを持つのは不適当である。
- 対応内容: 両ケースとも「key の存在」と「メッセージ」を分け、メッセージは error bag の
  完全一致 (`expect($errors->get($key))->toBe([adminLoginThrottleMessage()])`) で固定した。
  これで「前の試行の理由が残っていない」「案内が 1 本だけ (積み増しでない)」
  「残り秒数を含む正しい文言」が 1 本の期待値になる。
  併せて、なぜ `assertHasErrors(['key' => 値])` を使わないのかをテスト内のコメントに残した。

## [Suggestion] 宣言元検査が固定するのは `authenticate()` だけである

- 判断: 対応する (説明の是正として)
- 根拠: 指摘のとおりで、独自クラス側が `rateLimit()` / `getRateLimitKey()` を上書きして
  上限を骨抜きにする形は、本文走査と宣言元検査では拾えない。AGENTS.md の文化
  (「保証範囲を誇張しない」) に照らして、検査の説明が実力を超えている状態は残さない。
- 対応内容: `ThrottleExemptionPremiseTest` のコメントへ保証範囲の限界と、
  その分を担う振る舞いテスト (`AdminLoginThrottleDisplayTest`) を明記した。
  検査そのものは増やさない (今回の差分に該当する上書きは無く、
  振る舞いテストが上限到達を実際に踏んでいるため。思考原則 2)。

## その他 (実装本体・panel 配線・免除前提の起点変更)

- 判定は「妥当」。変更なし。
