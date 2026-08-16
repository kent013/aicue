## 施策別判定

### A. `ManualProgress` enum 新設

**APPROVE**

写像の正本、逆写像の導出、網羅 `match`、型定義、Unit テスト配置はいずれも妥当です。

### B. 一覧クエリ VO の置換

**APPROVE**

`ManualProgress` 型を保持した解析、旧 `status` 非互換、Inertia props、redirect 用クエリの契約が整合しています。

### C. 一覧 WHERE と paginator

**APPROVE**

Round 1 の Critical に対する反論は成立しています。現行 props に paginator の URL や links が含まれないため、`withQueryString()` が旧キーをクライアントへ漏らす経路はありません。

そのうえで `appends($listQuery->toQueryParams())` に寄せる変更も妥当です。`page` は paginator の page name として追加対象から除外されるため、ページ番号との衝突もありません。

### D. 一覧行 DTO

**APPROVE**

表示語彙だけを `progress` に置換し、完成動画の利用可否判定には引き続き `Published` を使う責務分離が適切です。

### E. TS 型・ラベル・トーン

**APPROVE**

説明を表示ラベルとトーンの用途に限定したことで、5値を使う業務判定との矛盾は解消されています。

### F. 一覧 UI

**APPROVE**

`ManualProgress | ""` による state の型保持、testId の改名、参照確認、DS・Atomic Designへの影響はいずれも問題ありません。

### G. 撮影 PWA

**REQUEST_CHANGES**

[Warning] `captureProgressOf()` の実装と説明・テスト期待値が一致していません。

提示コードでは次の入力は `"capturing"` になります。

```ts
{
    cuts_total: 0,
    cuts_adopted: 0,
    cuts_with_takes: 1,
}
```

理由は、最初の条件を通過した後、次の条件が成立するためです。

```ts
if (summary.cuts_with_takes > 0) return "capturing";
```

しかし設計書は同じ入力を「未撮影」とすると宣言しています。

修正案は、どちらかに統一してください。記載されたドメイン判断を採用するなら、実装を次のようにします。

```ts
export function captureProgressOf(
    summary: Pick<CaptureManualSummary, "cuts_total" | "cuts_adopted" | "cuts_with_takes">,
): CaptureProgress {
    if (summary.cuts_total === 0) return "not_captured";
    if (summary.cuts_adopted === summary.cuts_total) return "captured";
    if (summary.cuts_with_takes > 0) return "capturing";

    return "not_captured";
}
```

ただし、これは現行の三項式と完全には同一ではありません。したがって「判定は変えない」という説明も削除し、「構造的不整合時の扱いを明示化した」と記載する必要があります。

現行挙動を維持するなら、コメントとテスト期待値を `"capturing"` に変更してください。構造上発生しない入力なので、こちらの方が変更範囲は小さくなります。

Captureのcategory正規化を据え置く反論は成立しています。非数値が `category_id = 0` 相当になって該当なしへ倒れるだけで、認可・テナント境界には影響しません。表示語彙タスクでVO化まで行わない判断は妥当です。ただしPC側の「全件表示」を「安全側」と表現するのは曖昧なので、「いずれも認可境界には影響しない既存仕様」と書く方が正確です。

### H. enumとTS unionの同期

**APPROVE**

値集合の同期と抽出不能時のfailが揃っており、Architectureテストとして十分です。

### I. テスト更新

**REQUEST_CHANGES**

[Warning] Gの矛盾により、予定している次のテストは提示実装では失敗します。

```text
cuts_total=0 && cuts_with_takes>0 → 未撮影
```

修正案はGで示したとおり、実装を変更するか期待値を `"capturing"` に変更してください。

[Suggestion] `manuals.data.0.progress = not_started` は既定の作成日時降順に依存しやすいため、この契約テストでは対象タイトルを同時に確認するか、1件だけのfixtureにすると意図が明確です。

### J. ドキュメント更新

**APPROVE**

語彙の統一と正本への参照は妥当です。

### 完了条件

**REQUEST_CHANGES**

[Warning] AGENTS.mdは「検証コマンド一覧を全greenでコミット」と定めていますが、完了条件からpackage系の3コマンドが省かれています。

修正案として、次も完了条件へ追加してください。

```text
pnpm typecheck:packages
pnpm build:packages
pnpm test:packages
```

変更との直接関係が薄くても、リポジトリ規約上の完了条件です。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の主要指摘は適切に解消されています。残る必須修正は、`captureProgressOf()` の不整合入力に対する実装・説明・テストの統一と、AGENTS.md所定の検証コマンド追加です。