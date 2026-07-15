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
  reset: ReturnType<typeof vi.fn>;
  clearErrors: (...keys: string[]) => void;
  transform: (fn: (data: TData) => unknown) => { post: ReturnType<typeof vi.fn> };
  post: ReturnType<typeof vi.fn>;
  /** テスト用: processing ($state) を切り替える。onStart→onFinish 遷移の観測に使う。 */
  setProcessing: (value: boolean) => void;
  /**
   * テスト用: Inertia がレスポンス受領後に form.errors を更新する挙動を模倣する。
   * リアクティブな errors ($state) へ Object.assign で反映し、FormField を再評価させる。
   */
  respondWithErrors: (next: Record<string, string>) => void;
} {
  const errors = $state<Record<string, string>>({ ...initialErrors });
  let processing = $state(false);
  const post = vi.fn();

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
    reset: vi.fn(),
    clearErrors: vi.fn((...keys: string[]) => {
      if (keys.length === 0) {
        for (const key of Object.keys(errors)) delete errors[key];
        return;
      }
      for (const key of keys) delete errors[key];
    }),
    transform() {
      return { post };
    },
    post,
    setProcessing(value: boolean) {
      processing = value;
    },
    respondWithErrors(next: Record<string, string>) {
      Object.assign(errors, next);
    },
  };

  return form;
}
