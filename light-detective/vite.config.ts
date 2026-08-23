import { defineConfig } from "vite";

// Deployed at bobcooleyphoto.com/light-detective/, so assets must resolve
// relative to that subpath rather than the domain root.
export default defineConfig({
  base: "/light-detective/",
  build: {
    outDir: "dist",
  },
});
