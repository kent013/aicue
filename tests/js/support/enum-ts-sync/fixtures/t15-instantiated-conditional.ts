type Pick2<T> = T extends "a" | "b" ? T : never;

export type X = Pick2<"a" | "b" | "c">;
