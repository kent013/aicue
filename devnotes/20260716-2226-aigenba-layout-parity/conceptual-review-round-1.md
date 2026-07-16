**全体判定**

`CHANGES_REQUESTED`

parity の主方針自体は妥当です。`aigenba` の primitive 移植、`PageContent` の独自拡張撤去、認証後ページの外枠統一、`AdminMenuNav` 廃止は、North Star と「無駄な独自実装をしない」に強く整合しています。  
ただし現状の概念設計は、スコープ境界と実装契約が数点あいまいで、そのまま入ると「完全一致」の定義ぶれとフロント規約テストの後退が起きえます。

**1. 使命整合**

- [Warning] `P2` の `{appName} -> BrandLogo` 置換が `Guest/Auth` まで含んでおり、「ログイン後レイアウト parity」という今回の使命からはみ出しています。認証後 parity の達成に不要な変更を混ぜると、レビュー面積だけ増えて目的達成が遅れます。  
  修正案: 今回は `AppLayout` 配下の認証後シェルだけを対象に固定し、`Guest/Auth` は明示的に別タスクへ切り出してください。
- [Suggestion] 「完全一致」の完了条件を DOM 構造レベルで 3 点に絞るとぶれません。`PageContainer` 採用、`PageHeader(Section)` 採用、`PageContent` の `max-w-7xl` 固定、の 3 契約を DoD に明文化するとよいです。

**2. 禁止事項整合**

- [Suggestion] テストファースト原則は概ね満たしていますが、「先に fail を作る」がやや弱いです。  
  実装前に `PageContent` 独自 prop 禁止、`AppLayout` ページの外枠統一、`AdminMenuNav` 不使用を検出する失敗テストを先に追加する前提を明記してください。

**3. 実現可能性（Svelte 5 + Inertia）**

- [Warning] `P4` の「top-level nav 項目化」は、見た目の移植だけではなくナビ生成契約の変更です。`既存 shared prop の認可フラグで出し分け` とありますが、active 判定・権限別表示・モバイルナビ反映まで含めて本当にフロントのみで閉じるかが未確定です。  
  修正案: `AppLayout`/shared data のどの prop を使って `Users`/`Categories` を表示するか、active 判定キーを何にするか、Inertia shared data 追加が不要かを設計本文に明記してください。不要と言い切れないなら「軽微な shared prop 調整あり」に直した方が安全です。
- [Warning] `Capture/Show` を `PageContent` 非適用の例外にするなら、構造テストの契約と衝突します。現状の本文では「全 AppLayout ページで統一」と「allowlist 例外」が併記されており、規約が二重化しています。  
  修正案: `Capture/Show` を parity 対象外の明示例外として 1 行で固定し、Architecture テストにも同じ allowlist 名称で反映する前提を書いてください。

**4. 期待効果**

- [Suggestion] 効果の見積もりは妥当です。加えて「新規認証ページ追加時に aigenba パターン逸脱を自動検出できる」ことを主要効果として前面に出すと、単なる見た目合わせではなく保守コスト削減として説明しやすくなります。

**5. リスク（後退）**

- [Warning] `PageContent` の `testId` prop 撤去と「既存 testid 不変」が両立するか不明です。現在のテストや E2E が `PageContent` ルートの `data-testid` に依存している場合、レイアウト parity とは無関係な回帰を生みます。  
  修正案: `testId` 依存はページ固有要素へ移す、もしくは「`PageContent` の test hook は今回削除対象であり、必要なテストはページ側へ再配置する」と設計で明示してください。
- [Warning] `PageHeaderSection` の負マージン契約は強いですが、`AppLayout` padding 移譲の途中状態で全ページが一時崩れるリスクがあります。  
  修正案: 実装順を「`AppLayout` 調整 -> primitive 導入 -> 各ページ移行 -> 古い外枠撤去」の 1 PR 内シーケンスとして固定し、混在期間を作らない前提を追記してください。

**6. スコープ適切さ**

- [Warning] 今回の主目的は parity ですが、`P4` の情報設計変更と `Guest/Auth` ロゴ変更が混ざることで、外枠 parity から逸れています。24 ページ移行自体は妥当でも、周辺変更を同梱するとレビュー焦点がぼけます。  
  修正案: スコープを `primitive移植 / AppLayout責務移譲 / 24認証ページ外枠統一 / AdminMenuNav撤去` に閉じ、`Guest/Auth` とそれ以外の見た目改善は完全に別建てにしてください。
- [Suggestion] `Onboarding/Cli・Mcp` は「aigenba 相当へ寄せる範囲」を先に 1 文で限定した方がよいです。ここが曖昧だと独自 UI 整形が再発します。

**7. 型安全性**

- [Warning] `BrandLogo` の配置方針が、`svg-inline-allowlist` と atomic import 規約に対してまだ曖昧です。`molecules/BrandLogo.svelte` に SVG を直書きすると規約違反になりえます。  
  修正案: 生 SVG は `components/atoms/icons/` 配下に置き、`BrandLogo` はその atom を組み合わせる薄いコンポーネントとして定義する、と明記してください。`BrandLogo` 自体を atom に寄せる方がより安全です。
- [Suggestion] `BreadcrumbItem` は `href` 必須/任意、現在位置判定、`icon` の nullable 可否を型で先に固定しておくと、24 ページ移行時の分岐増殖を防げます。

設計の芯は良いです。修正すべきは「何を今回やるか」と「どの例外を許すか」の境界です。そこを固めれば `APPROVED` にできます。