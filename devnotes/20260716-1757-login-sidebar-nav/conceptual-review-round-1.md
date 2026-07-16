**全体判定: CHANGES_REQUESTED**

**1. 使命との整合性**
- [Warning] 参照アプリへの UI 統一方針自体は妥当ですが、提案された nav 項目だけを見ると、North Star の中核である「マニュアル作成」「撮影導線」への直接的な到達改善は弱いです。`/projects` を入口にする設計なら、その先で manual/capture に最短到達できることまで含めて説明しないと、「使命への本質的貢献」はやや誇大です。  
  修正提案: 効果表現を「姉妹アプリとの UI 一貫性・保守性向上」にいったん限定するか、もしくは AI-CUE の主要業務導線が `Projects` 起点で十分短いことを明記してください。
- [Suggestion] 成功条件を「ログイン後、主要業務開始までのタップ数/迷いの減少」のように具体化すると、使命整合の説明が強くなります。

**2. 禁止事項違反**
- [Warning] テスト方針が `AppLayout.test.ts` 更新中心で、shared prop 追加に対するサーバ側検証が明記されていません。このままだと「テストなしの実装完了報告」に近い運用になりやすいです。  
  修正提案: JS テストに加えて、少なくとも「認証時のみ nav が出る」「権限なしでは Billing/API Keys/組織設定系リンクが shared prop に出ない」ことを確認する Feature テストを設計に含めてください。
- [Suggestion] `headerActions` 廃止を同一 PR で行う方針は、後方互換の並走を残さないという規約に合っています。その意図は明示してよいです。

**3. 実現可能性**
- [Critical] org スコープのリンク定義が不十分です。`Billing`、`API キー`、`/organizations/{slug}/settings`、`/onboarding/cli|mcp` は、`currentOrganization` の有無と `canManageApiKeys` だけでは安全に出し分けできません。設計文のままだと、null org・権限不足・route 条件不一致で 403/404 導線を作る可能性があります。  
  修正提案: `currentOrganization` を UI で組み立て材料にするのではなく、サーバ側で `authNavigation` のような型付き shared prop を作り、各項目について `visible` と `href` を解決済みで渡してください。少なくとも Billing、Org Settings、CLI、MCP、API Keys は個別 capability を持たせるべきです。
- [Suggestion] 「基本コピー、無い依存だけ差し替え」という移植方針は、Laravel + Svelte + Inertia の範囲で十分実現可能です。

**4. 期待効果の妥当性**
- [Warning] 「保守性向上」「姉妹アプリとの同期取り込み容易化」は合理的ですが、「manual/capture 導線改善」は現時点の nav 定義だけでは未立証です。  
  修正提案: 期待効果を二段に分けてください。第1段は UI 一貫性・保守性、第2段は業務導線改善で、後者は追加の IA 検討または計測前提とするのが妥当です。
- [Suggestion] 「desktop/mobile の重複描画を helper に集約」は効果が明確なので、ここはそのまま主張してよいです。

**5. リスク**
- [Warning] `NotificationBell` を残しつつ `通知` nav 項目も追加すると、通知導線が二重化し、未読表示やモバイル時の期待動作がぶれる恐れがあります。  
  修正提案: 通知は「ベルを主」「nav を主」のどちらかに寄せてください。両方残すなら役割分担を明記すべきです。
- [Warning] `AppLayout` の全面置換は、全ログイン後ページに横断影響します。特に `EmailVerificationBanner`、toast、mobile drawer、collapsed sidebar、org switcher、logout POST の回帰が起きやすいです。  
  修正提案: 設計段階で回帰観点を列挙してください。最低でも desktop 展開/折りたたみ、mobile drawer、banner/toast の積み上がり、org switch、logout の 5 点は必須です。
- [Suggestion] 参照アプリ準拠なら、popover/drawer の focus 管理とキーボード操作も合わせて確認対象に入れると安全です。

**6. スコープの適切さ**
- [Warning] 「シェルのみ」としつつ、shared prop の認可拡張まで含むため、実質は UI 置換 + 認可契約整理です。スコープとしては妥当ですが、設計上その二層構造が分離されていません。  
  修正提案: スコープを「UI shell 置換」と「navigation shared prop 契約追加」に分けて明記してください。これで過大化を防げます。
- [Suggestion] Quota、BrandLogo、help 外部リンクを scope 外に置く判断は適切です。

**7. 型安全性**
- [Warning] `HandleInertiaRequests` に prop を足して `shared-props.ts` を同期、という書き方だけだと、配列 shape の場当たり拡張になりやすく、PHPStan level 10 と TS の整合が崩れやすいです。  
  修正提案: `currentOrganization` への場当たり追加ではなく、専用の typed contract を切ってください。`NavigationItemDTO` 相当のサーバ構造か、少なくとも `authNavigation` の厳密な array shape を定義し、TS 側もそれに一対一対応させるのが安全です。
- [Suggestion] org slug 連結をクライアントでやるより、`href` をサーバ解決済みで渡した方が nullability と route drift を減らせます。

この設計は方向性自体は良いですが、「参照 UI を移植する」話と「AI-CUE の認可・導線契約をどう表現するか」がまだ分離しきれていません。そこを詰めれば APPROVED に寄せられます。