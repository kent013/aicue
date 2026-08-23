# 実装時に確定した事項 (T247)

詳細設計からの**確定変更**と、設計時に決めていなかった判断を記録する。
設計書 (`detailed-design.md`) は変更せず、差分をここに集約する。

## 1. 施策 10 の走査方式を「ファイル種別ごとの抽出器」から「2 つの抽出方式 + 割り当て表」へ

設計は種別ごとに抽出方法を書き分ける表を持っていたが、母集団が git 追跡下**全ファイル**である以上、
種別は増え続ける。抽出方式を **2 つだけ** (`LegacyUrlExtractionMode`) に固定し、
**どの拡張子をどちらへ割り当てるか**を `LegacyUrlScanRoots::SCANNED_EXTENSIONS` が宣言する形にした。
割り当ての無い拡張子は**未分類 = 赤**なので、種別が増えたときの fail-closed は保たれる。

- `SourceLiteral` (PHP / TypeScript / JavaScript / Svelte / Python): **文字列リテラルだけ**を見る。
  コメントの言及は参照ではない (撤去を説明する docblock を違反にしない)。
- `PlainText` (Markdown / JSON / TOML / YAML / TSV / テキスト / Blade): 全文を見る。終端集合を宣言する。

抽出は `Tests\Support\SourceLiterals` に集約し、`CurrentOrganizationRemovalScanner` の
列名検出も同じ入口を使うようにした (コメントの言及で赤くなっていた実測を解消)。

## 2. `/app` は「配下つきのときだけ旧 URL」— 許可目録を使わない

設計は「分岐入口以外の `/app`」を許可目録で名指しする想定だったが、
**裸の `/app` は正規の分岐入口**であり、旧 URL なのは配下を持つ形 (`/app/projects/…`) だけである。
規則で表せるものを目録にすると、目録が旧 URL 文字列を持つことになり再帰する。
そこで `LegacyUrlScanner::captureRoot()` だけ「配下つき」を要求する規則にした。
結果として PWA の `start_url` / robots の宣言 / 入口の Feature テストは**登録なしで通る**。

## 3. 許可目録の区分を 3 つに限定した

`LegacyUrlAllowanceKind` は `FilesystemPath` / `AbsenceAssertion` / `OrganizationRelativePath` の 3 つ。
「なんとなく直せない」を入れる口を作らないため限定列挙にし、
区分を足す操作そのものがレビューに見えるようにした。登録は**パス + 規則 ID + 件数 + 30 文字以上の理由**で、
件数は増減のどちらでも赤になる。

## 4. `routes/` を走査対象から外した (理由付き)

route 定義の URI は group の prefix からの**相対セグメント**であり、組織 prefix の中では
根だけの記述が正しい姿になる。実 route 表が 1 本残らず組織 URL 配下にあることは
`OrganizationScopedRouteCoverageTest` が**解決済みの route 表**で固定するので、
ここを字面で走査しても新しい保証は増えない。

## 5. 撤去 route 名の検出を `LegacyOrganizationlessUrlAbsenceTest` へ一本化した

`CurrentOrganizationRemovalTest` の「検出 4 (撤去した route 名)」を撤去し、
追跡下ファイル**全数**を母集団に持つ施策 10 側へ移した (同じ事実を 2 か所で検査しない)。
`CurrentOrganizationRemovalTest` は 3 形 (列名リテラル / relation / 撤去した Service の FQCN) に絞り、
列名検出は `database/migrations/` を母集団から外した (撤去した列の名前は移行履歴に必ず残るため)。

## 6. 流量制限 `render-trigger` のキーを識別名の文字列にした

`ThrottleRequests` は framework の既定 priority で `SubstituteBindings` **より前**に走るため、
limiter からは組織モデルが束縛されていない。束縛の後ろへ動かすと束縛の DB 参照が
流量制限の外に出るので、**route parameter の識別名の文字列**をキーに使う。
改名は 30 日 5 回が上限で窓は 1 分なので、改名の前後で bucket が分かれても上限の意味は保たれる。

## 7. 課金ゲートの defense-in-depth を「所属」で判定するようにした

`RequireActiveSubscription::resolveOrganization()` は binder の回帰検出に
`Gate::allows('view')` を使っていたが、**所属はあるが役割が無い**利用者 (並行受諾レースの帰結) の
403 が 404 に化けて層 2 と層 3 の境目が消えていた。判定を **binder と同じ契約 (organization_user の所属)** に
揃え、役割不在は従来どおり controller / policy の 403 で成立させる。

## 8. 認証済みで guest 専用 route を開いたときの着地を分岐入口へ固定した

framework の既定は「`dashboard` という名前の route があればそこへ」だが、本アプリの `dashboard` は
組織 URL 配下 (`{organization}` 必須) なので既定のままだと引数不足で 500 になる。
`RedirectIfAuthenticated::redirectUsing()` で**組織文脈を持たない分岐入口** (`app.entry`) へ倒した。

## 9. 「切り替えてから解約」の一手を概念ごと撤去した

`AccountDeletionBlockerAction::SwitchOrganizationThenOpenBilling` は保持列と切替 endpoint の撤去に伴って
意味を失った (どの組織の課金画面へも URL で直接行ける)。enum の case と DTO の
`isCurrentOrganization` 引数を撤去し、テストの期待値を `OpenBilling` 1 手へ揃えた。

## 10. 乖離台帳の採番を繰り上げた

main が先に D40 (T250) / D41 (T245) / D42 (T244) を使っていたため、本ブランチの 2 件を
**D43 / D44** へ繰り上げた。件数 pin は実ファイルの実数から数え直して
`DIVERGENCE_ENTRY_COUNT = 41` / `ADOPTION_DEBT_COUNT = 154`。
`docs/app-integration-guide.md` は main の D42 が既に登録しているため D44 の対象パスからは外し、
代わりに `docs/default-team-pattern.md` と `tests/Architecture/AccountDeletionPathGateTest.php` を
D44 へ足して採用時債務から削った (3 択の (3))。
