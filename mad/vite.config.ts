import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

// Deployed at bobcooleyphoto.com/mad/, so assets must resolve
// relative to that subpath rather than the domain root.
export default defineConfig({
  base: "/mad/",
  plugins: [react()],
  build: {
    outDir: "dist",
  },
});
