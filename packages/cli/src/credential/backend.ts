import type { ItemKind } from "./key-derivation.js";

/**
 * Backend contract shared by KeychainStore and FileStore.
 * Write / read / delete operate on a single (origin, profile, kind, id)
 * tuple. `CredentialStore` sits on top and maintains a meta:"index" item so
 * that backend-agnostic listing / clear is possible.
 */
export interface BackendStore {
    write(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
        value: string,
    ): void;
    read(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): string | null;
    delete(
        canonicalOrigin: string,
        profileName: string,
        itemKind: ItemKind,
        itemId: string,
    ): void;
}
