# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Critical 0 / Warning 2 / Suggestion 6)。
APPROVED だが Warning 2 件は設計に反映してから Phase 2 へ進む。

## [Warning] Architecture gate の母集団 (resolved middleware 全体の exact-fit) が過剰・壊れやすい

- 判断: **対応する** (母集団を絞る)
- 根拠: 正当な指摘。`EncryptCookies` / `StartSession` / `ValidateCsrfToken` / `SubstituteBindings` /
  `AuthenticateSession` などは「救済 route を通すか」を判断する対象ではなく、Laravel 側の構成変更で
  母集団が動く = 偽赤の温床になる。思考原則 2 (今必要なものだけ作る) にも反する。
  一方で Codex 案の「実質ゲートだけを人手で列挙」は deny-by-default の穴になる (列挙漏れに沈黙する)ので採らない。
- 対応内容: 母集団を**機械的に絞れる形**へ変更した。
  `U = (取消 route の resolved middleware ∩ 名前空間 App\Http\Middleware\*) ∪ {Illuminate\Auth\Middleware\Authenticate,
  Illuminate\Auth\Middleware\EnsureEmailIsVerified}`。
  - 自前 middleware は**全部**母集団に入る (新しい自前ゲートを web group に足したら必ず分類が要る = 再発経路を閉じる)
  - vendor は「実際にこの route を短絡させる 2 本」だけを**名指し**で入れる
    (名指しなので vendor 側に新しい短絡 middleware が増えると沈黙する → 保証しないものへ明記)
  - 母集団は 8 前後に収まり、framework の構成変更 (cookie / session / CSRF / binding) では動かない
  - disposition は 3 値 (`PassesRescueRoute` / `ShortCircuitsButEscapable` / `NeverShortCircuits`)

## [Warning] 「直前の操作は実行されていません」は厳密には session 書き込み等の副作用がありうる

- 判断: **対応する** (保証範囲の記述を限定。ユーザー向け文言は維持)
- 根拠: 正当。middleware が短絡しても session / throttle / CSRF 検証の副作用は起きうる。
  ただしユーザーが「操作」と認識するのは controller が行う業務処理であり、文言としては誤解を生まない。
  誇張しないために**設計文書とテスト名の側**を限定する。
- 対応内容: 保証の記述を「**controller に到達しておらず、ドメイン状態 (退会予約列) は変化しない**」に限定。
  「副作用が一切ない」とは書かない。保証しないものへも 1 行追加。

## [Suggestion] 使命への貢献 / 禁止事項 / 型安全性 / 期待効果 / 一般化しない判断 (5 件)

- 判断: **同意 (変更なし)**
- 根拠: いずれも本設計の主張を追認する内容。allowlist 追加が妥当という固有争点の判定も一致した。

## [Suggestion] 一般化しない代わりに gate の検知面を十分にせよ

- 判断: **対応済み** (上の Warning 1 の対応に含まれる)
- 根拠: 自前 middleware を全件母集団に入れることで、「他 middleware を今回変えない」判断の代償
  (別ゲートで同じ見落としが起きる) を、少なくとも**取消 route については**機械で検知できる。
