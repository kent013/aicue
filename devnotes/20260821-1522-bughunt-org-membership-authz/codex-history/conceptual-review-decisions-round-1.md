# 対応マトリクス: conceptual-review Round 1

## [Critical] リスク: T055 register 誘導 / AG-113 アプリ内受諾の退行を「変えない」宣言に留めている
- 判断: 対応する
- 根拠: 「変えない」だけでは退行検知できない。禁止事項 1 (不変条件はテスト登録まで含めて実装済み) に照らし、明示テストで固定すべき。
- 対応内容: 概念設計に「テスト計画」節を追加し、guest register 誘導 + prefill / 招待先 email の POST 成功 / 別 email の show 非提示 + 直 POST 失敗 / email 規則の register 一致 / AG-113 宛先本人限定、の 5 本を列挙。詳細は detailed-design.md へ引き継ぐ。

## [Warning] show は補助 UX、POST の Service 検証が唯一の権威であるべき
- 判断: 対応する
- 根拠: 表示だけでは直 POST を防げない。元々 Service を権威と考えていたが明示が弱かった。
- 対応内容: 「Service = 唯一の権威的 gate、画面は補助 UX」と明記し、直 POST が membership/pivot/role を変えないテストを追加。

## [Warning] email 比較の正規化 (大文字小文字等) で正規受諾者を拒否しうる
- 判断: 対応する (ただし新規正規化は導入しない)
- 根拠: 既存の安全 2 経路 (acceptInvitationIfValid / MatchesInvitationEmail) はいずれも復号後平文の素の `!==`。ここで独自正規化を足すと email 同一性規則が経路ごとに分岐し「別物を似ているからで統合しない」「後方互換並走を残さない」に反する。正しい対応は規則の統一とテストによる固定。
- 対応内容: 「token POST 経路は register 経路と同一の email 同一性規則 (復号後平文の `!==`) を使う」と明記し、両経路が同一入力で同一判定になることをテストで固定 (テスト計画 4)。

## [Warning] mismatch 画面のログアウト導線と token 継続リスク
- 判断: 対応する
- 根拠: token が logout を跨いで生存する前提の UX は脆く後退の恐れ。
- 対応内容: ログアウト後は「招待リンクを再オープン」する導線 (guest 経路が token を session へ保存し直す) とし、token が logout を跨いで生存することに依存しない設計に変更。招待 email は画面に出さない。

## [Warning] F-2-03「全経路 403」の表現が過大
- 判断: 対応する
- 根拠: 検証したのは代表 route のみ。全数保証はしていない。
- 対応内容: 「主要な組織保護 route (dashboard/projects/billing/manage-users)」に表現を狭め、テスト docblock にも検証経路を正確に書くと明記。

## [Warning] 型安全性: mismatch prop を任意 props/生配列にしない
- 判断: 対応する
- 根拠: PHPStan L10 + Inertia props の型付け規約。
- 対応内容: `canAccept: boolean` のみ追加。招待 email 等は渡さない。Svelte Props interface もこの boolean を受ける。ValidationException は標準 error bag 経路で response()->json() を増やさない。

## [Suggestion] 効果表現「往復を完全になくす」ではなく「減らす可能性」
- 判断: 対応する (表現調整)
- 根拠: F-2-01 は option を選べる以上、送信時エラーは残りうる。
- 対応内容: 期待効果は「手戻りを減らす」表現に留める (既にその趣旨。過大表現を避ける)。

## [Suggestion] 使命整合・並行受諾レースを混ぜない判断は妥当
- 判断: 見送る (指摘に同意、追加対応不要)
