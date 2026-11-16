/// <reference types="vitest/config" />
import { defineConfig, type Plugin } from 'vite'
import vue from '@vitejs/plugin-vue'
import { cpSync, createReadStream, statSync } from 'node:fs'
import { join, normalize } from 'node:path'
import { fileURLToPath, URL } from 'node:url'

const BACKEND = 'http://localhost:8000'

const WHITEBOARD_FONTS = fileURLToPath(new URL('./node_modules/@excalidraw/excalidraw/dist/prod/fonts', import.meta.url))

/**
 * The whiteboard editor (Excalidraw) fetches its fonts at run time, by default
 * from an outside CDN. They are served from the app itself instead, under
 * /excalidraw/fonts: straight from the package while developing, copied into the build.
 */
function whiteboardFonts(): Plugin[] {
  return [
    {
      name: 'whiteboard-fonts:serve',
      apply: 'serve',
      configureServer(server) {
        server.middlewares.use('/excalidraw/fonts', (req, res, next) => {
          const file = normalize(join(WHITEBOARD_FONTS, decodeURIComponent((req.url ?? '').split('?')[0])))
          if (!file.startsWith(WHITEBOARD_FONTS) || !statSync(file, { throwIfNoEntry: false })?.isFile()) return next()
          res.setHeader('Content-Type', 'font/woff2')
          createReadStream(file).pipe(res)
        })
      },
    },
    {
      name: 'whiteboard-fonts:build',
      apply: 'build',
      closeBundle() {
        cpSync(WHITEBOARD_FONTS, fileURLToPath(new URL('./dist/excalidraw/fonts', import.meta.url)), { recursive: true })
      },
    },
  ]
}

export default defineConfig({
  plugins: [vue(), whiteboardFonts()],
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
