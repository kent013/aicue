# 対応マトリクス: conceptual-review Round 1（CHANGES_REQUESTED, 全 Warning）

## [Warning] F-0-2 の責務分担が曖昧（shell + page の二重中央寄せ→nested max-w）
- 判断: 対応する
- 対応: aigenba のページ単位 primitive 方式に責務を一本化する。
  - **`PageContent`**（templates layout primitive）が**幅制御 + 中央寄せ**を一元所有。
    `<div class="mx-auto max-w-{prop}">`。`maxWidth` は union prop（下記）。
  - **`AppLayout` の `<main>`** は **padding のみ**（`px-4 py-6 lg:px-8`）を担い、**max-width / 中央寄せを
    持たない**。→ 幅の責務が PageContent に一本化され nested max-w が起きない。
  - 各認証ページは本文ルートを `<PageContent maxWidth="…">` で包む（narrow フォームは狭いまま中央寄せ）。

## [Warning] 対象範囲（~17 か offender のみか）が未確定
- 判断: 対応する（概念設計で inventory 確定）
- 対応: 修正対象を「監査済みの AppLayout 配下・左寄せ narrow max-width ページ**全 17 枚**」と概念設計に
  明記・列挙する（段階導入せず一括。ドリフト残件を作らない）。列挙:
  Organizations/{ApiKeys/Index, ApiKeys/Sessions, Create, Settings, Onboarding/Cli, Onboarding/Mcp},
  Settings/{Index, Security}, Projects/{Index, Show, Create, Edit}, Manuals/{Index相当=Show, Create, Edit},
  Billing/{Index, PurchaseTickets}。Dashboard（max-w 無し=全幅）は幅統一のため PageContent(広め)で
  包むか全幅維持かを詳細設計で確定（本問題=左寄せ dead space は Dashboard には無い）。

## [Warning] テスト方針が F-0-2 回帰防止として弱い
- 判断: 対応する
- 対応: (a) `PageContent` 単体テスト（children 描画 + `mx-auto` + `maxWidth` prop→対応 max-w class）。
  (b) **幅バリエーションの異なる代表認証ページ複数**（Settings max-w-2xl / Billing max-w-3xl /
  Manuals max-w-4xl）について「本文ルートが `PageContent`（中央寄せラッパ）を使う」ことを検証する回帰テスト。
  1 ページでなく複数代表を押さえる。

## [Suggestion] 成功条件を「中央寄せ」でなく「幅制御の共通規約統一」まで引き上げ
- 判断: 対応する
- 対応: 成功条件を「認証ページの本文横幅制御が共通 primitive `PageContent` に統一され、右余白の不揃いが
  解消」と定義。

## [Suggestion] maxWidth を文字列 class 直渡しにしない（union prop）
- 判断: 対応する
- 対応: `maxWidth: 'xl' | '2xl' | '3xl' | '4xl' | '5xl' | '6xl' | '7xl'`（既定は現行踏襲で '2xl'）の
  union prop にし、内部で Record で対応 Tailwind class へ引く（任意 class 拡散防止・ds-purity 適合）。

## [Suggestion] F-0-1 は妥当（変更なし）
- F-0-1（設定二重掲載の解消）は使命整合・スコープとも妥当。設計変更なし。
