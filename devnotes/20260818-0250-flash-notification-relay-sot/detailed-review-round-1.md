## レビュー方針

仮説は「中継クラス自体の方向性は正しいが、現行の検査では“単一出典を迂回した実装”も緑になり得る」です。以下の反例が成立しないことを成功条件として確認しました。

- middleware が `KINDS` / `VISIT_KEY` を参照して直接 payload を組み立てる
- Layout が `readFlash()` を使わず `page.props.flash` を直接読む
- `flash` に truthy な非文字列が混入する

結論として、方向性は妥当ですが、設計どおり実装するとこれらを防げないため変更が必要です。Critical はありません。

## 横断事項

[Warning] 正典照合の実行順が不明確です。

現在は実装順が「1 → 2 → 3 → 4 → 5」ですが、未確認の公開 API をテストで先に固定することになります。

修正案:

1. Step 0 として正典取得・比較を実施
2. 差異があれば詳細設計を更新、または divergence を登録
3. その後に 1 → 2 → 3 → 4 → 5
4. lctl が引き続き取得不能なら実装完了ではなく blocked とする

照合した正典の世代・取得日時・3点の比較結果も、実装記録または `codex-history` に残すべきです。

---

## 施策1: PHPレーンのドリフト検査

判定: REQUEST_CHANGES

[Warning] 「middleware が中継へ委譲していること」を検査できていません。

次の実装は提案された検査をすべて通過できます。

```php
foreach (FlashNotificationRelay::KINDS as $kind) {
    $flash[$kind] = $request->session()->get($kind);
}

$flash[FlashNotificationRelay::VISIT_KEY] = Str::uuid()->toString();
```

文字列リテラルも種別の直書きもありませんが、`payload()` を迂回した第二の組み立て経路です。Featureテストも出力が同じなので通ります。

修正案:

- middleware が `FlashNotificationRelay::SHARED_PROP_KEY` を使うこと
- `FlashNotificationRelay::payload($request)` の呼び出しがちょうど1回あること
- middleware に `session()->get()` や UUID 発行による flash 組み立てが残らないこと

をArchitectureテストで固定してください。可能ならコメントを拾う生文字列検索ではなく、トークン化または整形後の対象式を検査します。

[Warning] 中核ヘルパーが `/* … */` のため、詳細設計としてPHPStan適合性とfail-closed性を確認できません。

修正案として、少なくとも以下を設計に確定してください。

- `git ls-files` 等の失敗を例外にする
- 読み込み失敗を例外にする
- 対象ファイル0件を例外にする
- 戻り値を `list<string>` として確定する
- コメント中の `visitKey` を文字列リテラルとして数えない
- 同一ファイルの複数出現を重複パスにしない

---

## 施策2: TSレーンのドリフト検査

判定: REQUEST_CHANGES

[Warning] `extractKinds()` にdegenerate PASSの自己検証がありません。

`extractStringConstant()` だけでなく、主要契約である語彙抽出にも負のコントロールが必要です。

修正案:

```ts
expect(() => extractKinds("final class X {}")).toThrow(
    /degenerate PASS/,
);

expect(() =>
    extractKinds("public const array KINDS = [];"),
).toThrow(/degenerate PASS/);
```

また、正規表現がコメント内の引用符まで値として取得しないよう、許容する定義形式を明確に固定してください。

[Suggestion] PHPソースの書式依存検査なので、Pint後の実際の定義形式をfixtureとして正例テストに持たせると、抽出器自身の変更を安全にレビューできます。

---

## 施策3: 接続のFeatureテスト

判定: REQUEST_CHANGES

[Warning] `renderedFlash()` が `mixed` を返した後、`array_keys($flash)` や `$flash[$kind]` を使用しており、PHPStan level 10で安全とは言い切れません。

Pestの `expect(...)->toBeArray()` が後続のローカル変数を常に型narrowingする前提にしない方が安全です。

修正案:

```php
/** @return array<string, mixed> */
function renderedFlash(TestResponse $response): array
{
    $page = $response->viewData('page');

    if (! is_array($page)) {
        throw new RuntimeException('Inertia page が配列ではありません');
    }

    $props = $page['props'] ?? null;
    if (! is_array($props)) {
        throw new RuntimeException('Inertia props が配列ではありません');
    }

    $flash = $props[FlashNotificationRelay::SHARED_PROP_KEY] ?? null;
    if (! is_array($flash)) {
        throw new RuntimeException('flash prop が配列ではありません');
    }

    return $flash;
}
```

[Warning] 非文字列の正規化を `KINDS[0]` だけで検査しています。

修正案として、全 `KINDS` のdatasetまたはloopで、配列・整数・真偽値・オブジェクトの少なくとも代表値を `null` に正規化することを確認してください。

[Warning] `withSession()` は値をsessionへ置くだけで、Laravelのflash lifecycle自体は検査していません。

中継の責務をsession値の読み出しに限定するなら、テスト名と説明を「session値」に修正してください。「一時メッセージ」を保証したいなら `session()->flash()` を使ったケースを追加します。

未使用の `Route` / `Inertia` importも削除対象です。

---

## 施策4: 中継クラスとmiddleware委譲

判定: APPROVE

クラス設計そのものは妥当です。

- `mixed` を `is_string()` でnarrowingしている
- `VISIT_KEY` の追加により戻り値のnon-empty性が成立する
- Inertia共有propなのでDTO/JsonResourceが不要という判断は適切
- 即値評価とUUID発行時点を維持しており、既存挙動との整合性がある
- tenant、認可、外部取得、LLM、変更系routeには影響しない

ただし、施策1で実際の委譲を固定し、Step 0の正典照合後に公開API名を確定することが前提です。

---

## 施策5: 画面側の一本化

判定: REQUEST_CHANGES

[Warning] `readFlash()` のruntime検査と戻り値型が一致していません。

`typeof value === "object"` は配列も許容します。また、次の値はtruthyなので `if (message)` では落ちず、`addToast()` に非文字列が渡ります。

```ts
{
    flash: {
        visitKey: {},
        success: ["壊れた値"],
    },
}
```

したがって「非文字列を真偽判定で落とす」というリスク説明は事実と異なります。

修正案:

- 配列を拒否する
- `visitKey` が `string | null | undefined` か検査する
- 各通知値を `string | null | undefined` に正規化する
- `consumeFlash()` でも `typeof key === "string"` と `typeof message === "string"` を確認する

[Warning] `readFlash()` の利用強制がありません。

Layoutが再び `page.props.flash` を直接読むようになっても、PHP⇔TS定数同期テストは緑です。これでは共有prop名の定数が再び飾りになります。

修正案としてTS Architectureテストに以下を追加してください。

- `flash-to-toast.ts` 以外に `page.props.flash` / `shared.flash` が存在しない
- 対象Layoutが `readFlash()` 経由で消費する
- 走査不能・対象0件をfailにする

[Warning] 変更ファイル一覧とテスト計画が矛盾しています。

「既存 `flash-to-toast.test.ts` は更新不要」としながら、`readFlash()` の新規テストを追加するとしています。

修正案:

- `tests/js/lib/flash-to-toast.test.ts` を施策5の変更ファイルへ追加
- 非object、配列、欠落、非文字列のkind、非文字列のvisit keyを検査
- 「既存テストの更新不要」の記述を削除

Atomic Design上はtemplateから`lib/`への依存であり、提示された変更に階層違反はありません。Inertia Propsを使う判断も適切です。

## 全体判定

**CHANGES_REQUESTED**

中継クラスの実装方針は良好ですが、現状の検査では「定数を参照しながら中継を迂回する実装」と「Layoutの直接読み」を防げません。また、`readFlash()` のruntime保証とPHPStan向けFeatureテストの型narrowingに修正が必要です。正典照合をStep 0へ移したうえで、上記の反例を赤にできれば再レビュー可能です。