import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Welcome from "@/pages/Welcome.svelte";

describe("Welcome", () => {
    it("アプリ名を表示する", () => {
        render(Welcome, { props: { appName: "My App" } });

        expect(screen.getByRole("heading", { name: "My App" })).toBeInTheDocument();
    });
});
