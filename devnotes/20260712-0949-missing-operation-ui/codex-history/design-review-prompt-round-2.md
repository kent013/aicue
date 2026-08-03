# 詳細設計レビュー Round 2: 指摘対応の再レビュー依頼

Round 1 の指摘 (Warning 2 / Suggestion 4) への対応を報告します。全体判定の更新をお願いします。

## 対応マトリクス

| 指摘 | 判断 | 対応 |
|------|------|------|
| [W] 施策2: 失敗レスポンスモックが実装変更に脆い | 対応 | 失敗レスポンスに `json: () => Promise.resolve({})` を追加し、意図をコメントで明記 |
| [W] 施策2: 自己参照キャスト | 対応 | `interface InertiaVisitOptions` を明示定義しキャスト・返り値型を置換 |
| [S] 施策1: async IIFE の切り出し | 対応 | `handleRegenerateSuccess(): Promise<void>` に切り出し |
| [S] 施策2: processing 連動ケース追加 | 対応 | 「POST 実行中は確認ボタンが aria-busy」ケースを追加 (Button atom は loading 中 `aria-busy` を立てることをコードで確認済み: `<button ... aria-busy={loading || undefined}>`) |
| [S] 施策3: 候補 0 人メッセージの定数化 | 対応 | `NO_TRANSFER_CANDIDATES` 定数を導入し案内文/押下時エラーで共有。トースト文言は各 1 箇所使用のため定数化しない (単一使用の文字列に間接層を挟まない方針を設計判断メモに記載) |
| [S] 施策4: myId 前提ケースの将来追加 | 見送り | page のモジュールモック導入は既存 6 ケースの前提 (実 page) を変えるリスクがあり、`transferCandidates.length === 0` の分岐カバレッジは members: [] で同一。実環境近似は bug-hunt 再走行の守備範囲とする |

## 修正後の詳細設計 (差分該当箇所)

### 施策 1: onSuccess の切り出し (変更後)

```svelte
/** POST 成功後の後処理 (旧コードは既に失効している前提)。 */
async function handleRegenerateSuccess(): Promise<void> {
    regenerateDialogOpen = false;
    // 旧コードは失効済み。誤保管を防ぐため画面から即クリアする
    recoveryCodes = [];
    if (await loadRecoveryCodes()) {
        addToast(
            "success",
            "リカバリコードを再生成しました。新しいコードを保管してください。",
        );
        await tick();
        recoveryCodesPanel?.focus();
        return;
    }
    addToast(
        "error",
        "新しいコードの取得に失敗しました。以前のコードは既に無効です。「リカバリコードを表示」から再取得してください。",
    );
}

function regenerateRecoveryCodes(): void {
    router.post(
        "/user/two-factor-recovery-codes",
        {},
        {
            preserveScroll: true,
            onStart: () => {
                regenerating = true;
            },
            onSuccess: () => {
                void handleRegenerateSuccess();
            },
            onError: () => {
                regenerateDialogOpen = false;
                addToast("error", "リカバリコードの再生成に失敗しました。");
            },
            onFinish: () => {
                regenerating = false;
            },
        },
    );
}
```

### 施策 2: 型定義・失敗モック・processing ケース (変更後)

```typescript
/** router.post の第3引数 (visit options) の検証対象部分。自己参照キャストを避けて明示定義する */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** Inertia visit options (第3引数) を取り出す */
function lastVisitOptions(): InertiaVisitOptions {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}
```

```typescript
    it("POST 成功 → GET 失敗では旧コードを残さず error トースト + 再試行導線に戻る", async () => {
        // fetchJson は response.ok で throw するが、実装変更 (先に json() を読む等) にも
        // 壊れないよう失敗レスポンスにも json を持たせる
        fetchMock.mockResolvedValue({
            ok: false,
            status: 500,
            json: () => Promise.resolve({}),
        });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(addToastMock).toHaveBeenCalledWith(
                "error",
                expect.stringContaining("以前のコードは既に無効です"),
            );
        });
        expect(screen.queryByTestId("recovery-codes")).toBeNull();
        expect(screen.getByTestId("show-recovery-codes-button")).toBeInTheDocument();
    });

    it("POST 実行中 (onStart〜onFinish) は確認ボタンが processing (aria-busy) になる", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
        await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));

        const options = lastVisitOptions();
        options.onStart?.();
        await waitFor(() => {
            // Button atom は loading 中 aria-busy を立てる (二重送信抑止の回帰固定)
            expect(screen.getByRole("button", { name: "再生成する" })).toHaveAttribute(
                "aria-busy",
                "true",
            );
        });

        options.onFinish?.();
        await waitFor(() => {
            expect(screen.getByRole("button", { name: "再生成する" })).not.toHaveAttribute(
                "aria-busy",
            );
        });
    });
```

### 施策 3: 文言定数化 (変更後)

```svelte
/** 候補 0 人時の共通文言 (案内文と押下時エラーで揺れないよう単一定義。テストも本文言を検証) */
const NO_TRANSFER_CANDIDATES = "移譲先にできるメンバーがいません。";

function openTransferDialog(event: SubmitEvent): void {
    event.preventDefault();
    if (transferCandidates.length === 0) {
        transferForm.setError(
            "user_id",
            `${NO_TRANSFER_CANDIDATES}先にメンバーを招待してください。`,
        );
        return;
    }
    const isValidTarget = transferCandidates.some(
        (member) => String(member.id) === transferForm.user_id,
    );
    if (!isValidTarget) {
        transferForm.setError("user_id", "移譲先のメンバーを選択してください。");
        return;
    }
    transferDialogOpen = true;
}
```

案内文側も同一定数を使用:

```svelte
{#if transferCandidates.length === 0}
    <p class="text-caption text-text-secondary" data-testid="transfer-no-candidates">
        {NO_TRANSFER_CANDIDATES}先に
        {#if usersUrl !== null}
            <TextLink href={usersUrl}>管理メニュー &gt; ユーザー管理</TextLink>
            からメンバーを招待してください。
        {:else}
            メンバーを招待できる管理者に依頼してください。
        {/if}
    </p>
{/if}
```

---

上記対応で全体判定を更新してください (APPROVED / CHANGES_REQUESTED)。
