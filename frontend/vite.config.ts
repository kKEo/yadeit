/// <reference types="vitest/config" />
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

const BACKEND = 'http://localhost:8000'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: BACKEND, changeOrigin: true },
      '/sanctum': { target: BACKEND, changeOrigin: true },
      // The MCP endpoint and the OAuth pages apps connect through live on the backend.
      '/oauth': { target: BACKEND, changeOrigin: true },
      '/.well-known': { target: BACKEND, changeOrigin: true },
      '/mcp': { target: BACKEND, changeOrigin: true },
    },
  },
  build: {
    outDir: 'dist',
    sourcemap: false,
  },
  test: {
    environment: 'jsdom',
    globals: true,
  },
})
