import { vi } from "vitest";

/**
 * 反応的な useForm フェイク (.svelte.ts なので $state が使える)。
 *
 * fakeUseForm は errors が非反応な plain object のため「clearErrors で赤枠/文言が消える」
 * 再描画を観測できない。本フェイクは errors を $state で持ち、clearErrors がキーを削除すると
 * バインド先 (FormField の error prop) が再評価される = ユーザー体験と同じ挙動を検証できる。
 */
export function reactiveUseForm<TData extends Record<string, unknown>>(
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
  };

  return form;
}
