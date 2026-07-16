# 対応マトリクス: conceptual-review Round 2（CHANGES_REQUESTED, 全 Warning）

## [Warning] テストファースト順序が実装後に読める
- 判断: 対応する
- 対応: 各施策 S1〜S3 を「失敗するテスト追加 → 実装 → green」の順で明記する。特に S2(primitive) と
  S3(移行) は「Architecture テスト(PageContent 利用強制)を先に書き fail 確認 → 移行して green」。

## [Warning] 「今後の新規ページも自動統一」は任意利用では成立しない
- 判断: 対応する（Architecture テストで強制 → 主張を真にする）
- 対応: **Architecture テスト**を新設し「`AppLayout` を import する全ページは本文を `PageContent` で
  包む(= PageContent を import・使用する)」を強制する。ただし意図的 full-bleed ページ(PWA 撮影
  レコーダー等)は**明示 allowlist**で除外する(理由付き)。これにより「新規ページも PageContent 必須 =
  自動的に幅統一」が構造的に成立する。

## [Warning] 代表3ページのテストでは残り移行漏れを検出できない
- 判断: 対応する（上記 Architecture テストで全件保証）
- 対応: 上記 Architecture テストが AppLayout 全ページ(allowlist 除く)の PageContent 利用を走査保証する。
  加えて PageContent 単体テスト(children 描画 + mx-auto + maxWidth→class)で primitive 自体を固定。
  代表ページの表示テストは補助(構造保証は Architecture テストが担う)。

## [Warning] 対象境界の未確定（17 vs 17+Dashboard vs 全認証）
- 判断: 対応する（境界を「AppLayout を使う全ページ − full-bleed allowlist」に確定）
- 対応: 監査で AppLayout を import するページは **24 枚**。対象境界を「この 24 枚のうち full-bleed
  allowlist を除く全ページ」に確定する。Dashboard は `maxWidth="7xl"`(全幅相当・中央寄せ)で包む
  = 対象に含める。full-bleed allowlist は詳細設計で確定(候補: Capture/Index, Capture/Show の PWA
  撮影レコーダー面)。曖昧さを残さない。

## [Suggestion] maxWidth の既定値 '2xl' が意図しない狭幅化を隠す
- 判断: 対応する（必須 prop 化）
- 対応: `maxWidth` を**必須 prop**にする(既定値なし)。全ページが幅を明示宣言する方針と整合し、
  指定漏れは型エラーで露見する。

## [Suggestion] 使命整合・実現可能性・型安全性
- F-0-1/F-0-2 の使命整合、union→Record の実現可能性は妥当(変更なし)。
