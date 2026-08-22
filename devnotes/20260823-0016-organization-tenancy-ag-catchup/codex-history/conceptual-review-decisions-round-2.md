# 対応マトリクス: conceptual-review Round 2

## [Critical] 成功条件 8 の検証コマンドが AGENTS.md の必須一覧に足りない (packages 系 3 本)
- 判断: **対応する**
- 根拠: 指摘のとおり。`pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` が
  抜けていた。AGENTS.md は `VERIFICATION_COMMANDS` マーカーで囲った一覧を正本とし、
  `tests/js/architecture/verification-commands-doc-sync.test.ts` が package.json との同期を
  deny-by-default で強制している。
- 対応内容: 成功条件 8 を「AGENTS.md の `VERIFICATION_COMMANDS` マーカー内に列挙された検証コマンドが
  全 green」へ書き換え、**正本を参照する形**にした。本設計時点の 10 本を参考として写したうえで、
  「ここに写した一覧が正本と食い違ったら正本が正しい」と明記した (2 か所に書くと必ず食い違うため)。

## [Critical] 通常の unique index だけでは PostgreSQL が大小無視の一意性を保証しない
- 判断: **対応する**
- 根拠: 指摘が正しい。値オブジェクトと Architecture 検査は**アプリ経路**の保証であって
  DB の制約ではない。直接 SQL・未検出の書き込み経路・将来の一括処理が `Foo` と `foo` を
  同居させられる以上、「保存層の小文字強制と DB unique index の二重担保」は成立していなかった。
- 対応内容: **`CHECK (slug = lower(slug))` + 通常 `UNIQUE` index** の併用を採用した
  (提案された 3 案のうち、「列の値は常に小文字」という設計意図が制約から読める形を選んだ。
  `UNIQUE (lower(slug))` 単独は、大文字混じりの値が入ったまま一意性だけ守られる状態を許す)。
  併せて次の 3 点を明記した — migration 前検査 (既存行の小文字化 + 正規化後の衝突が無いことを
  制約付与の前に確認し、衝突があれば fail-closed で止める) / CHECK が実際に効くことを
  直接 SQL の Feature テストで固定 / 並行改名の一意制約違反を実 DB で固定し違反の種類を識別する。

## [Warning] migration 欄の「小文字式 unique index」が A 節と矛盾
- 判断: **対応する**
- 根拠: そのとおりの不整合。
- 対応内容: 主な変更コンポーネント表の migration 欄と成功条件 4 を、採用した
  `CHECK (slug = lower(slug))` + 通常 `UNIQUE` に統一した。

## [Critical] `relation_scoped` に信頼の起点が無い
- 判断: **対応する**
- 根拠: 指摘が正しい。親が識別名・表示名・任意文字列で引かれていれば、relation を辿った先も
  同じ穴になる。「relation 経由だから安全」は親の provenance を問わない限り成立しない。
- 対応内容: `relation_scoped` を**再帰的な provenance 契約**として定義し直した:
  「親資源が `primary_key_binding` または `actor_derived` によって確定されており、かつその親から
  tenant-scoped relation のみを辿って組織を確定すること」。
  **親の確定方法が解決できなければ fail-closed** で落とすことも明記した
  (AGENTS.md §走査器の共通規約 (b) の「未解決を解決済みと同じ値へ混ぜない」)。

## [Critical] 「全数目録化」の母集団の抽出方法が未定義 (目録だけを見る検査は全数性を持たない)
- 判断: **対応する**
- 根拠: 指摘が正しい。目録に登録された入口だけを見る形は、新しい controller / command /
  Filament 構成要素 / MCP tool が目録にも走査候補にも入らないまま緑になる。
  これは AGENTS.md §走査器の共通規約 (b) の「『違反が 0 件』と『母集団が 0 件』を区別する」に
  真正面から抵触する。docblock に限界を書くことで全数性の穴が塞がらないという指摘も正しい
  (同規約が「走査器の限界を書き足すことは、既にある見逃しを規約適合へ変えない」と明記している)。
- 対応内容: 「母集団は目録ではなく機械で作る」を節の冒頭に立て、走査根 4 本を表で定義した
  (api/ai の route collection 全 action / 登録済み Artisan command 全数 /
  対象 panel の Filament 構成要素全数 / 登録済み MCP tool・handler 全数)。
  抽出した母集団の**全件**を許可 3 種別か `not_organization_scoped` へ**完全一致で分類**し、
  未登録・余剰登録・重複登録・空の走査根を落とすことを明記した。
  docblock の「保証しないもの」も、母集団の外 (実行時に組み立てた文字列・vendor 内部・
  リポジトリ外) だけを指すよう書き直した。

## [Warning] `primary_key_binding` の範囲が広すぎる (payload の tenant キーを許してしまう)
- 判断: **対応する**
- 根拠: そのとおり。「route / 引数の主キー」と書くと request body の `organization_id` まで読める。
  それは AGENTS.md 不変条件 1 (tenant キー不信) 違反である。
  また「主キーで指せること」と「actor がその組織を操作できること」が別物、という指摘も正しい。
- 対応内容: 許可 3 種別の表を「許可する形 / 明示的に禁じる形」の 2 列にし、入力面ごとに限定した。
  `primary_key_binding` は **route binding の内部主キーだけ**で、request body / query string の
  tenant キー受け取りは禁止。`actor_derived` は認証済み credential の帰属だけ。
  加えて「`primary_key_binding` は『操作してよい組織か』を保証しない。認可は `Gate::authorize` と
  `ControllerAuthorizationGateTest` の担当で、本検査はそこを肩代わりしない」を明記した。

## [Warning] 「現場端末の誤組織事故が構造的に消える」が広すぎる
- 判断: **対応する**
- 根拠: 選択画面での押し間違いまで消えるようには読ませてはいけない (誇張しない)。
- 対応内容: 「**保持列と自己修復に起因する**誤組織表示が構造的に消える」へ限定し、
  「消えるのはこの原因だけで、選択画面での押し間違いの余地は残る」と明記した。

## [Warning] 旧 URL 棚卸しの 6 系統は「全数」と呼ぶには狭い
- 判断: **対応する**
- 根拠: そのとおり。PHP 全層の URL 生成・永続化された URL・文書類が抜けており、
  リポジトリ外の状態は原理的に検査できない。
- 対応内容: 「6 系統の一覧」を閉じた母集団と呼ぶのをやめ、**走査根ベース**
  (git 追跡下の PHP 全数 + `resources/js/` + `docs/` + `.claude/`) で抽出する形へ改めた。
  表には PHP の URL 生成全層 (Controller / Service / Job / Event / Listener / Resource /
  Blade / config の `route()` と直書き)・生成時点で永続化される URL・文書類を追加した。
  さらに **保証外 3 点**を明記した — 利用者のブックマークと外部登録済み URL /
  デプロイ時点で queue に積まれている・送信済みのメール本文 / ブラウザ履歴・bfcache・
  開いたままの旧画面。「旧 URL は 1 つも残らない」とは書かない。

## [Warning] `currentOrganization` の「明示型」が抽象的
- 判断: **対応する**
- 根拠: level 10 の契約としては具体型の指定が要る。
- 対応内容: `CurrentOrganizationData|null` 相当の**専用の不変 DTO 1 本**に固定し、
  配列化は Inertia へ渡す**最終の 1 か所だけ**で行う、と具体化した。

## [Suggestion] 使命整合 / URL 共有の限定 / 型境界 / スコープ切り分けの追認
- 判断: **見送る** (肯定的評価であり変更を要さない)
