# 対応マトリクス: design-review Round 1

## [Warning] S1: cookie 属性が Laravel の session cookie と完全一致しない余地がある
- 判断: 対応する (指摘より踏み込んで、自前で属性を組み立てるのをやめる)
- 根拠: vendor を実読したところ `Illuminate\Cookie\CookieServiceProvider` が
  `CookieJar` の既定を **session config の path / domain / secure / same_site から**
  設定している (`setDefaultPathAndDomain`)。`CookieJar::make()` に path / domain /
  secure / sameSite を渡さなければ、その既定がそのまま入る。
  自前で `config()` を読んで cast すると、まさに指摘のとおり framework の規則と
  ずれる余地が残る (先人の知恵を探せ = 既存の cookie factory に寄せる)。
- 対応内容: `Cookie::make(name, value, 0, httpOnly: false)` 相当へ書き換え、
  path / domain / secure / sameSite は**渡さない** (framework の既定に委ねる)。
  テストも「期待値の直書き」ではなく **session cookie と同じ属性であること**の比較に変えた。

## [Suggestion] S1: bootstrap/app.php の import 追加が明記されていない
- 判断: 対応する
- 対応内容: 変更箇所に `use App\Http\Middleware\IssueSessionEpochCookie;` と
  `use App\Support\Auth\SessionEpoch;` の追加を明記した。

## [Warning] S2: `Inertia::always()` の即時評価で cookie と印がずれうる
- 判断: 対応する
- 根拠: vendor 実読で確認。`HandleInertiaRequests::handle()` は `$next()` の**前**に
  `Inertia::share($this->share($request))` を呼ぶため、値を即時に作ると
  「要求前のセッション ID」で固定される。`AlwaysProp` は `ResolvesCallables` を持ち、
  closure を渡すと応答構築時 (= `$next` の内側を通った後) に解決されるので、
  cookie 側 (`$next` の後) と同じ時点のセッション ID になる。
- 対応内容: `Inertia::always(fn (): ?string => SessionEpoch::current($request))` に変更し、
  「同じ応答の cookie と prop が同値」であることを Feature テストで固定する旨を明記した。
  併せて `share()` が `$next` の前に評価される事実を設計へ書いた (後から読む人が同じ罠を踏まないため)。

## [Suggestion] S3: Resource の docblock を更新対象に含める
- 判断: 対応する
- 対応内容: 変更箇所に docblock (`{ authenticated }` → `{ authenticated, sessionEpochMatches }`) を明記した。

## [Warning] S4: `decodeURIComponent` が壊れた値で例外を投げる
- 判断: 対応する
- 根拠: 復元直後の同期判定で例外が出ると、秘匿属性は `pending` のまま誰も進めない
  = 「隠したまま誰も解除しない画面」になる。これは本設計が避けると宣言した形そのもの。
- 対応内容: decode を try/catch で包み、失敗は `null` (= 読み直し) に倒す。
  壊れた百分率エンコードの vitest を追加した。

## [Warning] S4: `probeSessionStatus` のシグネチャ変更が明示されていない
- 判断: 対応する
- 対応内容: 変更後シグネチャ
  `probeSessionStatus(fetchImpl: ProbeFetch, renderedEpoch: string | null, url: string = SESSION_STATUS_PATH)`
  を設計へ明記した。

## [Warning] S4 と S7 で `readCurrentEpoch` の扱いが矛盾している
- 判断: 対応する (明示配線へ統一)
- 根拠: 既定値に任せると、呼び出し側を読んだだけでは「描画世代と現世代がどこから来るか」が
  分からない。2 つの出所が違うことが本設計の要なので、**呼び出し側で両方を名前付きで渡す**方が
  読み手にとって安全である。
- 対応内容: `app.ts` で `readRenderedEpoch` / `readCurrentEpoch` の**両方を明示配線**し、
  S7 の契約検査も両方を対象にする、と統一した。ガード側の既定 (描画世代 = null /
  現世代 = cookie) はテスト用に残すが、**描画世代の既定を cookie にしない**という
  禁止事項は設計に残した。

## [Warning] S5: `reloading` は未認証とは限らないのに `unauthenticated-redirected` へ写している
- 判断: 一部対応する (判定は維持し、意味の定義を明文化する)
- 根拠: `redirect-observed` は T161 で「**利用者が `/login` 到達を確認して記録する**手入力イベント」と
  定義済みで、単なる「何か起きた」の目視ではない。したがって
  「読み直しに倒れた + `/login` 到達を目視確認した」= 未認証で `/login` へ着いた、は意味的に正しい。
  別利用者としてアプリへ着地した場合は `/login` に着かないので利用者は記録できず、
  判定は `stale-session-reloaded` (= `undetermined`) に留まる = 合格にならない。
  これは望ましい安全側の挙動である。
- 対応内容: S5 に「`redirect-observed` は `/login` 到達の目視確認であり、
  別利用者としてアプリへ着地した試行はこのイベントを記録できないため合格にならない」を明記し、
  検証ページの目視確認の文言 (現行の `/login` 到達を問う形) は変えない、とした。
  終端名を分けない理由も併記した (`expectedGuardVerdict` を変えないことで T085 の完了条件を動かさない)。

## [Warning] S7: 応答キーの抽出手順が曖昧で文字列直書きに戻りやすい
- 判断: 対応する
- 対応内容: 「`SessionStatusResource::make(new SessionStatusDto(authenticated: true,
  sessionEpochMatches: true))->toArray($request)` の**キー**を取り、
  そのキー文字列が画面側ファイルに現れることを検査する」と手順を明記した。

## [Suggestion] S6: `/admin` 非対象のリスク受容理由を添える
- 判断: 対応する
- 対応内容: 文書更新の項目に「`/admin` は独自 middleware stack で web グループを通らず
  Inertia でも描画されないため、印もガードも届かない。管理面はサーバ側の保存禁止ヘッダのみで
  受容する」という理由を書く、と明記した。
