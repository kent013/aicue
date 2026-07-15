import { vi } from "vitest";

/**
 * 反応的な useForm フェイク (.svelte.ts なので $state が使える)。
 *
 * fakeUseForm は errors が非反応な plain object のため「clearErrors で赤枠/文言が消える」
 * 再描画を観測できない。本フェイクは errors を $state で持ち、clearErrors がキーを削除すると
 * バインド先 (FormField の error prop) が再評価される = ユーザー体験と同じ挙動を検証できる。
 *
 * processing も $state + getter で保持し、setProcessing(bool) で onStart→onFinish 遷移
 * (loading={form.processing}) を検証できる。reset()・respondWithErrors() は Inertia が
 * 成功/失敗レスポンス受領後に form を更新する挙動 (reset / form.errors 反映) を模倣する。
 */
export function reactiveUseForm<
  TData extends Record<string, unknown> & { processing?: never; errors?: never },
>(
  initial: TData,
  initialErrors: Record<string, string> = {},
): TData & {
  errors: Record<string, string>;
  processing: boolean;
  clearErrors: (...keys: string[]) => void;
  reset: ReturnType<typeof vi.fn>;
  transform: (fn: (data: TData) => unknown) => {
    post: ReturnType<typeof vi.fn>;
    put: ReturnType<typeof vi.fn>;
    patch: ReturnType<typeof vi.fn>;
  };
  post: ReturnType<typeof vi.fn>;
  put: ReturnType<typeof vi.fn>;
  patch: ReturnType<typeof vi.fn>;
  /** テスト用: processing ($state) を切り替える。onStart→onFinish 遷移の観測に使う。 */
  setProcessing: (value: boolean) => void;
  /**
   * テスト用: Inertia がレスポンス受領後に form.errors を更新する挙動を模倣する。
   * リアクティブな errors ($state) へ Object.assign で反映し、FormField を再評価させる。
   */
  respondWithErrors: (next: Record<string, string>) => void;
} {
  const errors = $state<Record<string, string>>({ ...initialErrors });
  // 反応的: テストから true にすると pending 文言 (「変更中…」) を再描画で観測できる。
  let processing = $state(false);
  const post = vi.fn();
  const put = vi.fn();
  const patch = vi.fn();

  const form = {
    ...initial,
    get errors() {
      return errors;
    },
    get processing() {
      return processing;
    },
    set processing(value: boolean) {
      processing = value;
    },
    clearErrors: vi.fn((...keys: string[]) => {
      if (keys.length === 0) {
        for (const key of Object.keys(errors)) delete errors[key];
        return;
      }
      for (const key of keys) delete errors[key];
    }),
    reset: vi.fn(),
    transform() {
      // 戻り値に put/patch も含め、将来 transform().put(...) 連鎖テストでも不整合を出さない
      // (既存 consumer は post のみ参照で後方互換)。
      return { post, put, patch };
    },
    post,
    put,
    patch,
    setProcessing(value: boolean) {
      processing = value;
    },
    respondWithErrors(next: Record<string, string>) {
      Object.assign(errors, next);
    },
  };

  return form;
}
