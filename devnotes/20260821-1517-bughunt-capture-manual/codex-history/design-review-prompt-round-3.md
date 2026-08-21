## Round 3: Round 2 指摘への対応を報告します

施策1-3 は APPROVE をいただきました。施策4/5 の Warning に対応しました。対応マトリクスと、変更した該当セクションの新テキストを提示します。全体判定をお願いします。

### 施策4 (before-event 空振り防止) の改訂後テキスト
```
### 回帰テスト (Vitest。ハーネス走行に依存しない決定的テスト)
jsdom では実 Inertia のフルロードは再現できないため、**アプリ配線の回帰**を Vitest で固定する。
**個別メソッド (`router.visit`/`router.get`) の呼出有無ではなく、`before` event に現れた visit の
url/method を判定する** (`<Link>` / form helper / `router.post` 等の別経路を見逃さない。Codex Round1 [Warning]):
- **空振り防止 (Codex Round2 [Warning])**: 「イベント 0 件で green」を避ける。mock router の
  **全 visit 入口 (`reload`/`visit`/`get`/`post` と、mock する場合の `<Link>` クリック) を共通の
  before-event emitter へ通す**ように配線し、以下を満たす:
  - [ ] 通常フローで**現 URL への reload イベントが最低 1 件観測される**ことを assert (母集団非空を固定)。
  - [ ] 通常フローで観測された visit の destination が現 URL への部分リロードのみで、
    許可されない destination を含まないことを assert。
  - [ ] **負のコントロール**: 禁止 destination を合成入力として emitter に流し、判定器が確実に
    検出する (「違反ゼロ」と「母集団ゼロ」を区別。空振り検査)。
- [ ] **通常フロー**と**復帰性テスト**を分ける (Codex Round1 [Suggestion])。復帰性は施策5 の
  「ハードロードで失う状態」テストで扱う。

### 証拠の正本 (Codex Round1 [Suggestion])
```

### 施策5 helper (URL 解析失敗の deny) の改訂後テキスト
```
### helper (`resources/js/lib/capture/navigation-guard.ts` 新規, 条件付き)
URL 判定は**文字列 prefix でなく `URL` 正規化**で行う (Codex Round1 [Warning])。visit の url 型は
`string | URL` (Inertia 公式) なので**独自に string へ狭めず、導入済みバージョンの event 型をそのまま使う**。
```ts
/**
 * 撮影 PWA (Capture/Show) マウント中だけ、撮影画面が自ら起こし得ない
 * /app/ 外への programmatic Inertia visit を拒否する狭い「UX 回帰防止」ガード。
 * これはセキュリティ境界ではない (テナント/認可は middleware + Gate が担う)。
 * ハードビジット (window.location / 409+X-Inertia-Location / popstace) は before で
 * 止められないため保証対象外 (docblock に明記)。
 */

/** /app/ 内 URL か。origin 一致 + 正規化 pathname で判定 (prefix 文字列一致にしない)。
 *  ★ new URL() 失敗 (malformed) は throw させず in-app でない = 拒否側に倒す (Codex Round2 [Warning]) */
export function isInAppUrl(value: string | URL): boolean {
    let url: URL;
    try {
        url = new URL(value, window.location.href);
    } catch {
        return false; // 解析不能は in-app ではない (許可リスト方式なので拒否側へ)
    }
    if (url.origin !== window.location.origin) return false; // //evil/app, https://evil/app を弾く
    return url.pathname === "/app" || url.pathname.startsWith("/app/"); // /app.evil を弾く
}

/** url+method の canonical キー。**例外を外へ漏らさず失敗は null で返す** (before handler が止まらない) */
export function canonicalize(value: string | URL, method: string): string | null {
    try {
        const url = new URL(value, window.location.href);
        return `${method.toLowerCase()} ${url.origin}${url.pathname}${url.search}`;
    } catch {
        return null;
    }
}

// register(guard): router.on("before", handler) を張り、unsubscribe を返す。
// handler は event.detail.visit の公式型 (url: string|URL, method) をそのまま読む。
```
- **負例テスト**: `https://evil.example/app/...` / `//evil.example/app/...` / `/app.evil/...` を
  in-app と誤判定しないこと。**malformed URL / 異常 scheme / dot-segment (`/app/../x`) の正規化**も
  テストに含め、解析失敗は拒否側に倒れることを固定する (Codex Round2 [Warning])。
- page 側は `onMount` で register、cleanup で解除 + `pendingIntent` 破棄。
```

### 施策5 visitExplicitly (同期発火依存) の改訂後テキスト
```
- **`visitExplicitly` は「`router.visit()` が返る前に before が同期発火し intent を消費する」ことに依存する。**
  単純な mock だけでは導入済み Inertia 版の実契約を固定できない (Codex Round2 [Warning])。よって:
  - **第一候補 (最小案)**: 外向きの明示遷移は**素の native anchor (`<a href>` 通常遷移)** にして、
    トークン機構自体を不要にする。PC 詳細リンク (`マニュアル詳細へ`) を撮影 PWA から**削除**できれば
    さらに小さくなる (Codex 推奨)。運用契約 (`docs/architecture.md §撮影 PWA の運用契約`) と Phase A の
    結果を踏まえ、実装時に「リンク削除」/「native anchor」/「wrapper 経由」を確定する。
  - **wrapper を残す場合**: before が同期発火して intent を消費した後に `router.visit()` が戻る契約を
    テストで固定し、mock は**実イベント順を再現**する。**非同期発火させた場合に誤許可しない**ことも
    負例で確認する。
```

### 施策5 リスク欄 (認証失効の整合) の改訂後テキスト
```
- [ ] `明示トークン一致の /app/ 外 visit は 1 回だけ通し、2 回目はキャンセル (single-use)`。
- [ ] `method/url がトークンと不一致なら通さない`。
- [ ] `reloadManual 相当 (現 URL 部分リロード) を巻き込まない`。
- [ ] `URL 正規化の負例` — `https://evil.example/app/x` / `//evil.example/app/x` / `/app.evil/x` を
  in-app と誤判定しない (origin + 正規化 pathname 判定)。
- [ ] `visitExplicitly の finally で pendingIntent が必ず破棄される` (visit が例外/中断でも stale しない)。

**Vitest (`tests/js/pages/CaptureShow.test.ts`)**:
- [ ] `マウントで before ガードが register され、unmount で解除される`。
- [ ] 明示リンク押下由来 / 戻る進む / offline→online 復帰で `reloadManual`・正規遷移が阻害されない
  (Codex Round1 [Warning] 回帰)。

### リスク
```

### 状態保証表 (ヘッダ重複除去) の改訂後テキスト
```
### ハードロードで失う状態の保証 (Codex Round2 [Warning]。状態ごとに分ける)
| 状態 | 保証方針 | 対応テスト (Codex Round1 [Warning] — 各行にテスト) |
|------|----------|------|
| キュー保存**前**の `<input type=file>` 選択 | 自動復元不可 → **再選択を明確に案内** | 再選択案内の表示要素を実装対象に追加し、Vitest で表示を固定 |
| キュー保存**後**のアップロード | IDB から `resumeUploads` で再開 | onMount で `resumeUploads` が呼ばれ**二重 enqueue しない** Vitest |
| サーバ保存済み・未採用 take | 詳細 GET 再取得で再出現し採用へ戻せる | 再 GET の props/resource に未採用 take が再出現する Pest Feature |
| UI のみ (`selectedCutId` / 全画面ラッチ) | 安全な初期状態へ戻す | 再 mount で安全初期値になる component Vitest |

「復帰導線」と「元状態の自動復元」を同義にしない (文言・テストで区別)。
```

---

# 対応マトリクス: design-review Round 2

## 施策1 (APPROVE) [Suggestion] dataset payload のドット記法考慮
- 判断: 対応する。現保護キーは全てトップレベルである前提を dataset コメント/テスト名に明示。

## 施策2 (APPROVE) [Suggestion] form.reset 時の selectedFileName 掃除
- 判断: 対応する。成功時は別画面遷移で不要だが、同一画面に残る送信経路が入るなら同時消去する旨を明記。

## 施策3 (APPROVE) [Suggestion] x3
- SourceDocumentUpload を変更ファイル一覧から除去 → 対応 (一覧から外し Factory を代わりに明記)。
- 日時 locale/timezone 契約 → 対応 (uploadedAt は ISO8601 固定、表示整形は Svelte、SSR 未配線ゆえ
  hydration ずれ無し、将来 SSR 時は明示指定と実装メモ)。
- Assert::notNull 後の型確定 → 対応 (CarbonInterface で isInstanceOf 絞り込み、型を緩めない)。

## 施策4 (REQUEST_CHANGES) [Warning] before-event テストの空振り
- 判断: 対応する
- 根拠: mock router.reload/Link が実際に before を発火する保証が無いと 0 件で green になる。
- 対応: mock router の全 visit 入口を共通 before-event emitter へ通す配線。通常フローで現 URL reload が
  最低 1 件観測されること (母集団非空) を assert。禁止 destination を合成入力で流す負のコントロールを追加。
  「違反ゼロ」と「母集団ゼロ」を区別。

## 施策5 (REQUEST_CHANGES) [Warning] x3 + [Suggestion]
- new URL() 失敗時の扱い未定義 → 対応: isInAppUrl は try/catch で解析不能を拒否側 (false) に。
  canonicalize も例外を漏らさず null 返し。malformed / 異常 scheme / dot-segment テスト追加。
- visitExplicitly の同期 before 発火依存 → 対応: 第一候補は native anchor でトークン機構を不要化
  (PC 詳細リンク削除も検討)。wrapper を残す場合は同期発火 → intent 消費 → router.visit 戻りの契約を
  テストで固定し、非同期発火時に誤許可しない負例を置く。
- リスク欄の「認証失効は正規遷移として通す」が本文の限定契約と矛盾 → 対応: リスク欄を限定契約に統一
  (ハードビジットは対象外で妨げない / client-side programmatic は認証失効を推測せず明示 intent のみ許可)。
- 状態保証表のヘッダ二重 → 対応: 重複ヘッダ行を削除。
