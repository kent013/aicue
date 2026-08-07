# 対応マトリクス: conceptual-review Round 1

## [Warning] 観点2: `response()->json()` 直書き回避方針を明記せよ
- 判断: 対応する
- 根拠: 実際に新規レスポンスは 1 本も作らない。409 body は既存の
  `RecentAuthRequiredResource` (JsonResource) + `RecentAuthRequiredDto` が返すため、
  禁止事項 4 に触れない。明記していなかったのは記述漏れ。
- 対応内容: 概念設計「制約・前提」に「Fortify の controller / レスポンス契約は 1 行も変更しない。
  遮断応答は既存 RecentAuthRequiredResource が返す既存契約 (409 / 302) をそのまま使う」を追記。

## [Warning] 観点3 (最重要): 不変条件名が広いのに gate の母集団が `two-factor.` だけ
- 判断: 対応する (**広げる**方を選択)
- 根拠: 指摘は正しい。設計名「2FA の秘密と第二要素の状態に触る route」に対し母集団を
  Fortify 名前空間に限ると、`organizations.members.two-factor.reset` /
  `organizations.two-factor-requirement.update` が機械保証の外に残る。
  **`php artisan route:list --json` で実測**したところ、route 名に `two-factor` を含む route は
  ちょうど **11 本**で、Fortify の 9 本 + アプリ側の 2 本だった。アプリ側 2 本は
  どちらも既に recent-auth 済み (`RecentAuthRouteTest` allowlist) のため、
  **母集団を広げても新たな配線変更は 1 本も発生しない**。
  「広げるコストがゼロで、設計名と機械保証が一致する」なら広げる一択。
- 対応内容: 母集団セレクタを「route 名が `two-factor` を含む全 route」に変更 (実測 11 本)。
  母集団件数は **exact-fit** で固定し、増減したら必ず fail させる。

## [Warning] 観点5-a: 直接 POST / 非 XHR 時の見え方を固定せよ
- 判断: 対応する
- 根拠: 遮断の見え方 (XHR = 409 + `recent_auth_required` / 通常遷移 = 302 confirm) は
  `RequireRecentAuth` の既存契約だが、`two-factor.enable` について behavioral に
  固定していなければ「vendor が Inertia visit を返すよう変わった」等で沈黙して壊れる。
- 対応内容: Feature テストに「非 XHR の直 POST は `recent-auth.confirm` へ 302」ケースを追加する
  (`TwoFactorRecoveryCodesStepUpTest` と同じ 4 ケース構成に揃える)。

## [Warning] 観点5-b: 素材 fetch の 409 を step-up 再開へ接続せよ (precheck 順序だけでは不十分)
- 判断: 対応する
- 根拠: 当初案は「precheck を前段に置き、race で 409 になったら『取得失敗』表示 →
  再試行ボタンが precheck を通る」だったが、指摘のとおり 409 を「取得失敗」に畳むのは
  **原因と対処が一致しない表示**であり、ユーザーに 1 手の無駄と誤解を強いる。
  素の `fetch` は Inertia の `httpException` ハンドラに乗らないので、ここだけは
  自前で 409 を見る必要がある。追加コストは「fetch の戻り値に
  `recentAuthRequired` を 1 bit 足す」だけで、新しい状態機械は増えない。
- 対応内容: enrollment 素材 fetch を「値 + recent-auth 要求フラグ」を返す形にし、
  409 を検出したら `guardWithRecentAuth(() => void loadEnrollmentAssets())` で
  **既存の**モーダル + `pendingAction` 再開機構に載せる。

## [Warning] 観点5-c: satisfier が 1 つも無いユーザーの着地
- 判断: 対応する (ただし新規実装は足さない)
- 根拠: `canSatisfy=false` の着地は既に `RecentAuthModal` +
  `RecentAuthRecoveryNotice` が担当しており、`tests/js/components/organisms/RecentAuthModal.test.ts`
  が固定している。本設計で新しい詰みを作らないことを示せばよく、
  新しい復旧 UI を作るのは過剰 (今必要なものだけ作る)。
- 対応内容: 制約・前提に「satisfier ゼロの着地は既存 RecentAuthModal /
  RecentAuthRecoveryNotice の担当で、本設計は経路を増やさない」ことと、
  `TwoFactorEnforcementTest` に **passkey-only ユーザーが 2FA 必須ゲート下で
  passkey satisfier に到達できる**ケースを足すことを明記。

## [Warning] 観点7: inventory は「未分類が増えたら fail」と「存在しない entry も fail」の両方が必要
- 判断: 対応する (当初から意図していたが概念設計に書いていなかった)
- 対応内容: gate の検査項目を概念設計に列挙する (母集団 exact-fit / 未分類 fail /
  stale entry fail / 死んだ exemption fail / 秘密開示 3 本の名指し固定 / 理由 30 文字 / cap exact-fit)。

## [Suggestion] 観点1・4・6
- 判断: 反映不要 (肯定的評価)
