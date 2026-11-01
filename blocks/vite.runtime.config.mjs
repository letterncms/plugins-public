// The site runtime (runtime/src) → dist/runtime, with a manifest the PHP side reads to
// find the entry and preload chunks. Not part of the admin bundle.
import { defineConfig } from 'vite'

export default defineConfig({
  publicDir: false,
  build: {
    outDir: 'dist/runtime',
    emptyOutDir: true,
    target: 'es2022',
    manifest: 'manifest.json',
    modulePreload: { polyfill: false },
    rollupOptions: {
      input: 'runtime/src/index.ts',
      output: { entryFileNames: 'cms-[hash].js', chunkFileNames: '[name]-[hash].js' },
    },
  },
})
