import path from "path"
import react from "@vitejs/plugin-react"
import { defineConfig } from "vite"

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
  build: {
    outDir: "assets/compiled",
    manifest: true,
    rollupOptions: {
      input: {
        main: path.resolve(__dirname, "src/main.tsx"),
        recovery: path.resolve(__dirname, "src/recovery.tsx"),
      },
      output: {
        entryFileNames: `assets/[name].js`,
        chunkFileNames: `assets/[name]-[hash].js`,
        assetFileNames: `assets/[name].[ext]`
      }
    },
  },
})
