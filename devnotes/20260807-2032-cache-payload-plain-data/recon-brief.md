# 実査ブリーフ: cache-payload-plain-data

> lctl 台帳 (feature id: `cache-payload-plain-data`) の正典設計と aicue の実コードを突き合わせた調査結果。
> 2026-08-07 の実査。設計フェーズの入力であり、設計そのものではない。

## 序列 (候補 8 件中)

- 順位: #5
- 想定 TODO タイトル: キャッシュ素データ規約の明文化と gate
- テーマ / 優先度 / モード: test / Medium / standalone
- value=5 effort=4 self_contained=True
- 前回セッションで見送った理由: app/ は既に標準形 (Cache::put は FxRateService の 1 か所で配列化済み、読み戻しは is_array 検査 + fromArray + 失敗時 forget) で、アプリの振る舞いは 1 行も変わらないため退行リスクがほぼゼロ。一方で閉じる穴も現時点では無く、価値は予防と文書訂正に限られる (value 5)。ただし docs/app-integration-guide.md §7-6 の「必要になったときだけ最小 allowlist」が canonical v1 の『許可一覧を使わない』と正面から矛盾しているのは実害のある誤情報で、これだけは早めに直す価値がある。effort 4 と小さく、上位の作業が詰まったときの差し込み候補。

## 設計で最初に決めるべき論点

gate の母集団定義を先に決める。Cache facade だけに限ると cache() ヘルパと Repository を DI した書き方が素通りして空振り green になり、逆に ->put( を受け手を見ずに拾うと session()->put (10 箇所以上) と disk()->put を巻き込む。Cache::lock の 9 箇所 (payload を持たない) を明示除外することと、CarbonOverflowArithmeticGateTest が持つ負のコントロール fixture と空振り検知アサーションを同梱することをセットで決める。

## 台帳が確定させた標準形

標準形 v1 (裁定 2026-08-06)。キャッシュに入れてよいのは素のデータ (配列・文字列・数値・真偽値) だけで、オブジェクトをそのまま入れない。読み出したらアプリのコードが明示的に組み立て直し、その際に整合性を検査する。config/cache.php の serializable_classes は 5 リポジトリすべてで false のまま維持し例外を作らない。クラスを名指しで許す許可一覧は使わず既存も削除する。配列への変換と復元の往復が壊れないことを単体テストで固定する (キャッシュ経路を通す必要はない)。「オブジェクトをキャッシュに入れていないこと」の機械検査を標準形に必須として含める — 検査の実装形 (静的検査か実行時検出か) は各リポジトリの実装時に委ねられている。aicue に求められるのは明文化と機械検査の 2 点で、実装の書き換えを伴うのは aigenba のみ。

## aicue の現状 (実在確認済み)

実査で台帳の観測どおりだった点: (1) /workspace/config/cache.php 128 行が `'serializable_classes' => false,` で許可一覧は無い ('default' は 18 行で env('CACHE_STORE','database'))。(2) アプリコードのキャッシュ書き込みは /workspace/app/Services/FxRateService.php:49 の `Cache::put($cacheKey, $fresh->toArray(), ...)` 1 か所だけで、既に配列化して保存している。読み戻しは同 33-37 行で `Cache::get` → `is_array()` 検査 → `FxSnapshotDto::fromArray()`、38-44 行の catch で警告ログ + `Cache::forget()` = 標準形そのもの。(3) /workspace/app/DataTransferObjects/FxSnapshotDto.php は Arrayable 実装で toArray() (27-35 行) / fromArray() (43-61 行、Webmozart\Assert で keyExists・numeric・greaterThan(0)・stringNotEmpty を検査) を両方持つ。(4) tests/ 配下に `Cache::put` / `Cache::forget` / `Cache::add` / `cache()->put` は 1 件も無い (`grep -rn "Cache::" tests/` の結果は 0 件)。その他のキャッシュ API 利用は AutoRechargeService / SubscriptionService / TicketCheckoutService / ReconcileAutoRechargeAttempts の `Cache::lock()` 計 9 か所のみ (payload を持たない)。台帳と食い違った点: (5) 明文化は「全く無い」わけではなく、/workspace/docs/app-integration-guide.md 213-214 行の §7 不変条件 6 に「任意 class の逆シリアライズを許さない (cache serializable_classes は既定 false。object cache が必要になったときだけ最小 allowlist)」という記述が既に存在する。ただし後半の「最小 allowlist」は canonical v1 の「許可一覧は使わない・例外を作らない」と正面から矛盾する。/workspace/AGENTS.md 側にはこの不変条件の本文が無く、72 行の採番注意書きに「guide 6 = 逆シリアライズ」と参照があるだけ。(6) tests/Architecture/ 全 70 ファイルにキャッシュ payload の機械検査は無い (ファイル名・本文とも該当なし)。serializable_classes の値を pin するテストも無い (`grep -rn serializable tests/` は 0 件)。(7) FxSnapshotDto / FxRateService の単体テストが aicue には 1 本も存在しない (`grep -rln "FxSnapshot|FxRateService" tests/` が 0 件。tests/Unit/DataTransferObjects/ 配下は Billing のみ)。fx_snapshot に触れるテストは tests/Feature/Listeners/RecordLlmCallCostTest.php の 107 行 / 151 行だけで、DTO の往復性は固定されていない。

## ギャップ

1. 「キャッシュに入れてよいのは素のデータだけ」という規約が AGENTS.md に明文化されていない (現在の記述は docs/app-integration-guide.md §7-6 のみ)。
2. docs/app-integration-guide.md 214 行の「object cache が必要になったときだけ最小 allowlist」が canonical v1 の『許可一覧を使わない・例外を作らない』と矛盾しており、訂正が必要。
3. 「オブジェクトをキャッシュに入れていないこと」を守る機械検査が tests/Architecture/ に 1 本も無く、違反は本番で読み出しが失敗するまで気付けない。
4. config/cache.php の serializable_classes => false を固定する検査が無く、値が緩められても誰も落ちない (SsrfPinBoundaryTest 相当の pin が未整備)。
5. FxSnapshotDto の toArray()/fromArray() の往復と不正値の拒否を固定する単体テストが aicue に存在しない (aigenba / spirux には家系実例がある)。
6. キャッシュ書き込み経路が将来増えたときに素のデータであることを申告させる deny-by-default の inventory 機構が無い。

## 想定スコープ

新規: (1) tests/Architecture/CachePayloadPlainDataGateTest.php — CarbonOverflowArithmeticGateTest.php (362 行) の PhpToken 走査 + 正負コントロールの作法をそのまま踏襲し、app/ 配下 (必要なら tests/ も) の Cache facade / cache() ヘルパ / Repository 型プロパティ経由の書き込み呼び出し (put / add / forever / remember / rememberForever / put の配列形) を全列挙し、allowlist (現状 app/Services/FxRateService.php の 1 件のみ) 外を fail させる deny-by-default gate。あわせて config('cache.stores.*.serializable_classes') 相当の pin (SsrfPinBoundaryTest 流に config('cache.serializable_classes') が false であること) と、走査の空振り検知 (files > 0 / 走査したメソッド呼び出し数 > 0) を同ファイルに置く。想定 300-400 行。(2) tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php — toArray→fromArray の往復一致、rate 欠損 / 非数値 / 0 以下 / pair・source・fetched_at の空文字が例外になることを固定 (家系実例 aigenba:tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php と同旨、30-60 行)。inventory を enum + 根拠文字列の形にするなら app/Enums/Security/ への新規 enum (DirectFetchJustification.php 126 行が見本) と tests/Support/Security/ への inventory クラス (DirectFetchInventory.php 330 行が見本) が追加になるが、対象が 1 経路しかない現状では gate ファイル内の const allowlist + 根拠コメントで足りる (オーバーエンジニアリング禁止の思考原則 2 に照らす)。変更: (3) /workspace/AGENTS.md のセキュリティ不変条件へ項目を 1 つ追記 (既存 1-10 の採番は 71-75 行の注意書きにより renumber 禁止なので末尾 11 として足す) または実装規約側へ追記。(4) /workspace/docs/app-integration-guide.md 213-214 行の §7 不変条件 6 の後半 (「object cache が必要になったときだけ最小 allowlist」) を canonical v1 に合わせて書き換え、gate のファイル名を明記。§7 の番号は動かさない。(5) 必要なら docs/architecture.md への短い参照追記。アプリコードの変更はゼロ (app/ は既に標準形)。

## リスク

アプリの振る舞いは 1 行も変わらない (app/ 配下は既に標準形で、追加するのは検査と文書のみ) ため実行時退行のリスクは無い。実装上のリスクは gate の誤検出に集中する: (a) `->put(` を受け手を見ずに拾うと session()->put (RecentAuthState.php:30-32 / RequireRecentAuth.php:60,79,85 / InvitationAcceptanceController.php:65 / SocialAuthController.php:63 / ActivatePersonalController.php:140 / EmailVerificationContinuation.php:33 / OnboardingReturnResolver.php:92 / IntendedPlanResolver.php:123,149,162) と FakeObjectStore.php:180 の disk()->put を巻き込む。(b) `Cache::lock()` は payload を持たないのに Cache facade 経由なので、9 か所 (AutoRechargeService 6 / SubscriptionService 2 / TicketCheckoutService 1 / ReconcileAutoRechargeAttempts 1) を明示的に対象外にしないと全部 fail する。(c) 逆に受け手を Cache facade に限定しすぎると `cache()` ヘルパや Repository を DI した書き方が素通りする = 空振り green になる。CarbonOverflowArithmeticGateTest が持つ「負のコントロール fixture」「空振り検知アサーション」を必ず同梱して両方向を塞ぐこと。文書側のリスクは AGENTS.md / app-integration-guide.md の §7 採番で、71-75 行が既存参照を壊すため renumber を禁じている — 既存 6 の本文を書き換えるのは可だが番号をずらしてはいけない。実行時間の増加は app/ 全 PHP の token 走査 1 パス分で、既存の同型 gate と同程度 (秒未満〜数秒)。

## 実装者への申し送り (台帳と実コードの食い違いを含む)

台帳と実コードの食い違いを 2 点報告する。(1) aicue の inbox 要約と projects.aicue の note は「やることは明文化と機械検査の 2 点」としているが、明文化はゼロではなく docs/app-integration-guide.md 213-214 行に §7 不変条件 6 として既に存在する。しかもその本文の後半「object cache が必要になったときだけ最小 allowlist」は canonical v1 の裁定 (許可一覧は使わない・例外を作らない) と矛盾している。つまり aicue の作業は「新規に書く」ではなく「既存の記述を canonical v1 に合わせて訂正し、AGENTS.md 側にも不変条件として立てる」である。この矛盾記述の存在は台帳に記録されていない。(2) 台帳 gates 欄は往復検査の家系実例として aigenba / spirux のテストを挙げているが、aicue には FxSnapshotDto の単体テストが 1 本も無い (tests/ 全体で FxSnapshotDto / FxRateService への参照が 0 件)。projects.aicue の note が「家系で最も違反の余地が小さい」とだけ書いているため、往復検査の欠落が aicue でも未達である事実が読み取りにくい。metamovics の note (tests/Architecture/ 全列挙に該当なしと明記) と同じ粒度で aicue にも書かれるべきだった。実装順の申し送り: 検査を先に書いて red を確認する (AGENTS.md 思考原則 5 テストファースト)。gate は allowlist を app/Services/FxRateService.php の 1 件だけにして始めれば、現状 green になる予防 gate として成立する。実装は worktree (.claude/worktrees/tasks/<id>) で行うこと。検証は composer test / composer phpstan / vendor/bin/pint --test で足りる (フロント差分ゼロのため pnpm 系は無変更確認のみ)。lctl への status_reported は push 済み commit を refs に付けて行うこと。
