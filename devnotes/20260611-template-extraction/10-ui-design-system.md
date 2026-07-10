# UI・デザインシステム調査(多視点)とテンプレート方針

> 視点を分けた並列調査の統合結果: ①コンポーネント実装規約 ②トークン/Tailwind 機構
> ③DS 機械統制テスト ④画面・UX パターン ⑤DS 思想の差。
> 対象: 両アプリの DESIGN.md / resources/js / resources/css / tests/js / eslint.config.js /
> docs/design-system*.md / docs/frontend.md。

## 0. 最重要の発見: 「機構は同型、テーマは別物」

両アプリの UI 層は次の **共通機構** の上に、**まったく異なるテーマ** を載せている:

| 層 | 共通機構(テンプレ化対象) | テーマ(アプリ毎に差し替え) |
|---|---|---|
| 設計正本 | `DESIGN.md` が canonical + 各トークンに `tailwind:` 行 | aigenba=Ruri×Hi×Gofun(日本伝統色・影禁止・明朝/ゴシック混植・rounded 3段・weight 400/500のみ) / spirux=CreateSpace(マゼンタ系・glassmorphism・グラデーション・影あり・weight 900まで) |
| 実装写像 | Tailwind v4 CSS-first(`@theme`)+ token 経由の class | トークンの値・数(aigenba 14色 / spirux 7色+gradient+shadow) |
| 構造 | Atomic Design(atoms/molecules/(organisms)/features/templates + pages) | — |
| 統制 | vitest 製 architecture テスト(ds-purity)+ eslint better-tailwindcss | 禁止パターンの中身(影・gradient 禁止は aigenba テーマのルール) |
| 同期契約 | 「DESIGN.md と実装を同一 PR で同期、片方だけは merge 不可」 | — |

**テンプレートの取るべき形は「機構一式 + 差し替え可能な既定テーマ 1 つ」**。
両アプリの divergence registry もこの差を logic-driven(デザイン要件起因)の正当差分として扱っている。

## 1. 視点①: コンポーネント実装規約

### 共通している規約(そのままテンプレ規約化)
- Svelte 5 runes($props/$bindable/$derived)+ TypeScript、class 結合は `[base, variant, size, extra].filter(Boolean).join(' ')` で外部 class 後勝ち
- Button の **href/onclick discriminated union**(anchor モードでは type/disabled を `:never` で型禁止)
- variant→class は `Record<Variant, string> as const satisfies` で網羅保証
- `@testing-library/svelte` + vitest で atom 単体テスト、`@ts-expect-error` による型制約テスト

### 差分と採否の所見
| 論点 | aigenba | spirux | テンプレ所見 |
|---|---|---|---|
| 型定義の置き場 | `Button.types.ts` 分離(test と共有) | inline | **分離**(型テストが書ける) |
| iconOnly | なし | iconOnly 時 `ariaLabel` 型必須 + dev warning | **spirux 採用**(a11y 型強制) |
| Inertia 統合 | なし(`<a>` のみ) | `href` + `inertia` で Inertia Link | **spirux 採用** |
| anchor loading | aria-disabled/busy + tabindex=-1 + preventDefault | なし | **aigenba 採用** |
| rel 自動補完 | `_blank` → noopener noreferrer 自動 | 手動 | **aigenba 採用** |
| Input の責務 | label/error/icon 全部入り atom | 最小 atom + **FormField molecule**(id/describedBy/invalid を snippet で自動配線) | **spirux 採用**(関心分離 + a11y 配線が自動) |
| aria-describedby merge | caller 値と error ID を統合 | FormField が担う | FormField に統合 |
| testid | 明示 `testId` prop | restProps 透過 | 両対応(明示 prop + 透過) |
| focus リング | box-shadow inline(token 参照) | `focus-visible:ring-3 ring-secondary/35` 統一 | **spirux 方式で統一**(全 interactive 共通規約) |

### コンポーネント在庫の差
spirux の汎用 UI 部品が圧倒的に充実:
**Table/TableCell(生 `<table>` 禁止)・Pagination(窓+省略)・Tabs(WAI-ARIA roving tabindex)・
Segmented(radio group)・Disclosure・Toggle・FormField・SearchInput・NavItem・DangerZone・
Divider・UsageBar・Toast/ToastContainer・Modal(bits-ui)・TextLink・Avatar・Spinner**。
aigenba 側は ConfirmDialog(focus trap 自作)・ProgressBar・AvatarBadge・StatCard(3モード)・
PageHeader 系・CodeSnippet(dark allowlist)等。
→ **コンポーネントセットのドナーは spirux**(ただしスタイルはテーマ依存なので §5 の token 化が前提)。
bits-ui(Dialog/Popover/Tooltip)への依存も spirux 流に揃える。

## 2. 視点②: トークン/Tailwind 機構

- **aigenba**: `tokens.css` に `@theme`(14色+radius 3段)+ `@utility text-*`(typography ramp 6種を
  family/size/weight/lh/ls 一括定義)。`tailwind.config.js` はほぼ空(v4 CSS-first)。
  DESIGN.md の `tailwind:` 行 = **そのまま使える utility 名**。
- **spirux**: `app.css` の `@theme` に色 7+gradient+shadow+stroke-width。typography ramp は
  **@utility 化されておらず**、DESIGN.md の `tailwind:` 行が「組み立てるべき raw class 列」
  (`font-sans text-7xl font-black leading-[1.15]...`)。→ typo・揺れリスクがあり、機械検証もない。
- **eslint**: 両者 better-tailwindcss(no-conflicting/no-duplicate/whitespace/class-order=error、
  no-unknown-classes=warn)。ほぼ同一設定でテンプレ化容易。

**テンプレ方針: aigenba 方式を機構として採用**
1. `DESIGN.md`(canonical、frontmatter にトークン値)
2. `resources/css/tokens.css`(@theme + @utility ramp = 実装写像)
3. `tests/js/styles/inventory.ts` + `tokens.test.ts` + `canonical-source-parity.test.ts`
   (DESIGN.md⇔tokens.css の set equality + 値一致を機械検証。テーマ差し替え時は
   inventory がテーマから導出されるため構造は再利用可)
4. `docs/design-system.md`(同期契約 + 新規 domain 色トークン追加の 4 条件 = token board reopen protocol)

aigenba の P6 実証データ(「3 度の token 追加提案がすべて却下=最小色構成+opacity 修飾+atom 化で
53 page を DS-pure 化できた」)は、テンプレの「トークンを安易に増やさない」規約の根拠として docs に引用する。

## 3. 視点③: DS 機械統制テスト

| 統制 | aigenba | spirux |
|---|---|---|
| ds-purity | **層別 7 テスト**(atoms/molecules/features/templates/pages)。BASE_PATTERNS(raw 色 utility・hex・shadow・gradient・scale・rounded-xl 以上)+ TYPOGRAPHY_PATTERNS(raw text-size・weight・arbitrary・inline style)。file-scoped allowlist は 7 フィールド構造化(reason/owner_phase/remove_condition/reason_classes/lifecycle=permanent\|transitional) | **3 ルールのみ**(raw hex utility / arbitrary z-index / 静的 inline style)。T674 で aigenba と「不変条件は同じ・強度は正当差分」と整理済み |
| shape ramp | AST(svelte/compiler)で素の rounded/任意値/方向別を検出 | regex で off-ramp(sm/xl)+任意値のみ |
| comment 純度 | コメント・MD 内の禁止 class literal も検出(再発防止) | なし |
| token parity | tokens.test + canonical-source-parity(前述) | なし |
| SVG | — | Lucide 以外の inline SVG 禁止(allowlist 3 件) |

**テンプレ所見**: 統制の**構造**(層別テスト+構造化 allowlist+parity)は aigenba がドナー。
ただし BASE_PATTERNS の中身(影・gradient 禁止)は **aigenba テーマのルール**なので、
テンプレでは「テーマ非依存の普遍ルール(raw hex 禁止・token 経由・arbitrary 禁止・inline style 禁止・
z-index ramp・Lucide 以外 SVG 禁止)」と「テーマ由来ルール(影/gradient/rounded 段数/weight 段数)」を
**分離して定義**し、後者はテーマファイルから導出する形にする。spirux の svg-inline-allowlist は普遍側に取り込む。
出荷時 allowlist は 0 件(サンプルリソースも DS-pure)。

## 4. 視点④: 画面・UX パターン

### 汎用画面のドナー
- 汎用画面の量は aigenba 約 44(マルチテナント・Teams・APIKeys・Legal 含む)>> spirux 約 21。
- **組織・Team・Project・APIKey・Billing・Guest/LP/Legal 系 = aigenba ドナー**(Q1 で aigenba 階層を採用済みなので整合)
- **Auth 一式・Settings(Index/Security)・Dashboard・Error = spirux ドナー**(シンプルで完全)
- フォームは全画面 **FormField パターンに統一**(aigenba 由来画面は移植時に refactor)

### レイアウト: ハイブリッド
spirux `Layout.svelte`(サイドバー+NavItem 統一+通知ベル+詳細折りたたみ)をベースに、
aigenba AppLayout の **組織切替 UX**(cookie `last_organization_slug` 永続化・5 件以上で検索・
サイドバー折りたたみ localStorage)を融合。権限によるメニュー出し分け(管理ロール判定)は aigenba 方式。
AuthLayout / GuestLayout / PageContainer / PageContent は aigenba から。

### 共通 UX 機構
- **flash→toast**: spirux の `flash-to-toast.ts`(visitKey で de-dup)を採用(aigenba の effect 方式より堅牢)
- ConfirmDialog: spirux(Modal=bits-ui ラップ、processing 中 ESC/overlay 抑止)+ confirmVariant 2 値
  (primary|danger)の aigenba 規約(「irreversible なら danger」の意味的割り当てルールごと移植)
- DangerZone / EmptyState(CTA discriminated union)/ Skeleton・Spinner 規約 = spirux
- Inertia 共有データ: 両者の和集合を雛形化(auth.user + flash(visitKey 付き) + organizations +
  sidebarProjects + 権限フラグ群 + unreadNotificationsCount)

## 5. 視点⑤: DS 思想の差とテンプレの既定テーマ問題

aigenba(影なし・最小色・和の余白・明朝/ゴシック・「色は意味で割り当てる」ルール群)と
spirux(カラフル・glassmorphism・gradient・motion あり)は **どちらも完成したテーマ**だが互換性はない。
コンポーネントのスタイルはテーマに染まる(spirux 部品は gray-* 直書き・shadow・rounded-2xl を多用)ため、
**「spirux のコンポーネント API + aigenba のトークン機構」で移植する際、スタイル部分は
テンプレ既定テーマの token に書き直す**作業が発生する。これが UI 抽出の主コストになる。

## 6. テンプレート UI 方針(まとめ)

1. **機構**: DESIGN.md canonical + tokens.css(@theme+@utility ramp)+ parity/purity テスト + 同期契約 = aigenba 方式
2. **コンポーネント API・在庫**: spirux ドナー(+aigenba の型分離・anchor 安全策・ConfirmDialog 意味規約)
3. **スタイル**: 既定テーマの token のみで全部品を実装(raw palette 色・直書き hex を部品から排除 →
   テーマ差し替えが tokens.css の値変更だけで済む状態を目指す)
4. **統制**: 普遍ルールとテーマ由来ルールを分離した ds-purity 構造 + 層別テスト + allowlist 0 出荷
5. **画面**: §4 のドナー対応で汎用画面を移植、フォームは FormField 統一
6. **Atomic Design 階層**: 要決定(下記 Q14)

## 7. 新規の要決定事項(Q13〜Q16)

| # | 論点 | 選択肢 |
|---|---|---|
| Q13 | 既定テーマ | (a) aigenba 型の厳格テーマをニュートラル化して既定に / (b) spirux 型 expressive / (c) 新規ニュートラルテーマを起こす |
| Q14 | Atomic Design 階層 | (a) organisms あり 5 層(spirux 型、portal 系=Modal/Toast/ConfirmDialog を organisms に) / (b) organisms なし 4 層(aigenba 型 Route X、必要になったら組成) |
| Q15 | DS 統制の強度 | (a) aigenba フルセット(層別 7 テスト+typography+comment+parity) / (b) spirux ライト(3 ルール+shape+svg) / (c) 普遍ルールはフル・テーマ由来ルールは既定テーマ分のみ(推奨) |
| Q16 | DESIGN.md 雛形の粒度 | (a) spirux 型の詳細仕様書(1200 行級、全 molecule の class 列挙) / (b) aigenba 型のトークン+原則中心(270 行級、詳細は実装と型に委ねる) |

→ 決定後 `05-decisions.md` に追記する。

## 8. 機構抽出の対象ファイル(実在確認済み)

- aigenba: `DESIGN.md` / `resources/css/tokens.css` / `resources/css/app.css` /
  `docs/design-system.md` / `docs/design-system-migration.md`(導入計画の知見) /
  `tests/js/support/ds-purity.ts` / `tests/js/architecture/{atoms,molecules,features,pages,templates}-ds-purity.test.ts` /
  `tests/js/architecture/{shape-ramp,comment-literal}-purity.test.ts` /
  `tests/js/styles/{inventory.ts,tokens.test.ts,canonical-source-parity.test.ts}` /
  `components/atoms/*.types.ts` 型分離パターン / `templates/{App,Auth,Guest}Layout.svelte` 系
- spirux: `DESIGN.md`(component 仕様の網羅性) / `docs/frontend.md` /
  `components/{atoms,molecules,organisms}/` 一式 / `lib/stores/{toast,flash-to-toast}.ts` /
  `tests/js/architecture/{ds-purity,shape-ramp-purity,svg-inline-allowlist}.test.ts` /
  eslint better-tailwindcss 設定(両者ほぼ同一)
