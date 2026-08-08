# 対応マトリクス: conceptual-review Round 2

Round 2 は Critical 0 件 / Warning 4 件 / Suggestion 5 件。Warning は全件対応した。

## [Warning] (観点 3/10) 委譲の結線が「ファイル存在 + 識別子の文字列検索」では弱い

- 判断: **対応する** (Codex が提示した 2 択のうち「強化 + 保証範囲の明記」の両方を採る)
- 根拠: 正しい。`socialProvidersConfig` という文字列だけ残して空振り防止や列挙検査を消しても結線テストは緑になる。ただし Codex 案の「委譲先に型付き policy descriptor / 専用 inventory API を新設する」は、**既に緑で動いている gate 3 本を書き換えて意味を再宣言させる**もので、得るものに対して侵襲が大きい (思考原則 2)。
- 対応内容 (2 層):
  1. **母集団の生存確認を behavioral にする**。委譲先が見ている母集団の導出を**本 gate 側で実行**し空でないことを assert する (`config('template.social_providers')` / `ExternalClientBoundaryScanner` の app/ 走査 / `PrismDirectDispatchScanner` の走査根)。文字列検索ではなく実行なので、走査条件の破壊は検出できる。
  2. **委譲先 gate の同定を test 名で行う** (ファイル実在 + test 名の固定)。テストごと消える / 名前が変わると赤くなる。
  3. Codex が明示した条件 (「単なるソース文字列検索を結線保証と呼ぶ場合は保証範囲を下げる」) に従い、**§6 に「委譲先の assert の中身を弱める改変は検出できない」を明記**した。

## [Warning] (観点 4) SSO 委譲の効果表現が「宛先の許可制」に読める

- 判断: **対応する**
- 根拠: 正しい。`SocialProviderTrustPolicyTest` は宣言の**有無と型**を見るだけで、任意の新 IdP に既存 enum 値を付ければ通る。「宛先を許可制にする」ではない。
- 対応内容: §3 を「provider 集合の増加と信頼属性の宣言漏れを検知する。**宛先の許可制でも bug-hunt での遷移可否の審査でもない**」に限定した。

## [Warning] (観点 6) 「目録登録」と「正規経路への集約」を同一視している (標準形 (1))

- 判断: **対応する** (Codex 提示の 2 択のうち **1 番目** を採る)
- 根拠: 正しい。「登録すれば通る目録」では、別クラスからの `Socialite::driver()` を新 entry として登録できてしまい、集約にも直呼び禁止にもならない。
- 対応内容: **`SocialLogin` 種別は `SocialAuthController` 1 クラスを名指しで固定し、他クラスは `guarded` でも `exempt` でも登録不能にする**。repo 内に同型の作法がある (`TwoFactorStepUpInventoryTest` の「exemption にできない 6 本」)。これで「正規経路は `SocialAuthController` 唯一・他クラスの直呼びは登録手段が無く必ず赤」となり、標準形 (1) が実際に機械化される。§2-3 と §4 S3 に反映。

## [Warning] (観点 8) 「種類の数え落としを検出可能」は過大

- 判断: **対応する**
- 根拠: 正しい。「次元」の定義は人手であり、第 3 の次元が生まれても gate は沈黙する。
- 対応内容: §3 を「**登録済みの種別 × 次元の対応に対する欠落**が検出可能」に修正し、§6 に「次元そのものの数え落としは検出できない」を追加。

## [Suggestion] (観点 2) 実装完了条件は追跡対象ファイルに限定せよ

- 判断: **対応する**。§6 に「`.env.bughunt.local` は git 管理外のため本 PR の完了条件に含めない。是正できるのは `.env.bughunt.local.example` と `config/testing.php` の docblock まで」を明記。

## [Suggestion] (観点 5) scanner は採用結果と抑制結果を別の型付きコレクションで返せ

- 判断: **対応する**。§4 S3 の型方針に明記 (抑制後に情報を復元しない構造にする)。

## [Suggestion] (観点 7) classification / 次元も enum に、委譲表も array shape を残さない

- 判断: **対応する**。§4 S3 の型方針に明記。

## [Suggestion] (観点 1 / 9) 使命整合・二重管理は解消済み

- 判断: **対応不要** (指摘なし)
