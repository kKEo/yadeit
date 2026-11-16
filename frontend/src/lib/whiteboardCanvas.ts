import { createElement } from 'react'
import { createRoot } from 'react-dom/client'
import { Excalidraw, exportToBlob, exportToSvg } from '@excalidraw/excalidraw'
import type { BinaryFiles, ExcalidrawImperativeAPI, ExcalidrawInitialDataState } from '@excalidraw/excalidraw/types'
import '@excalidraw/excalidraw/index.css'
import type { WhiteboardScene } from '@/api/types'
import { blobToDataUrl } from '@/lib/dataUrl'

/**
 * The drawing surface of the whiteboard editor. Excalidraw is a React
 * component, so this is the one module that knows React: it mounts the canvas
 * into an element and hands back what the Vue side needs. Import it lazily;
 * it is by far the heaviest part of the app.
 */
/** A picture placed on the board, as the editor holds it. */
export interface WhiteboardPicture { id: string; mimeType: string; dataURL: string }

export interface WhiteboardSnapshot {
  scene: WhiteboardScene
  preview_svg: string
  /** The pictures the drawing uses right now. */
  pictures: WhiteboardPicture[]
}

export interface WhiteboardCanvas {
  /** What is drawn now and a picture of it, or null when the board is empty. */
  snapshot(): Promise<WhiteboardSnapshot | null>
  destroy(): void
}

export interface WhiteboardCanvasOptions {
  scene: WhiteboardScene | null
  /** The pictures of that scene, already loaded. */
  pictures: WhiteboardPicture[]
  /** An Excalidraw language code, e.g. "en" or "pl-PL". */
  language: string
  theme: 'light' | 'dark'
  /** Called with whether the drawing differs from what the canvas was opened with. */
  onDirty: (dirty: boolean) => void
}

const DEFAULT_BACKGROUND = '#ffffff'
/** A preview larger than this is stored as one flattened picture instead of a drawing with its pictures inside. */
const PREVIEW_LIMIT = 1_500_000
const FLAT_PREVIEW_SIDE = 2000


export function mountWhiteboard(container: HTMLElement, options: WhiteboardCanvasOptions): WhiteboardCanvas {
  let api: ExcalidrawImperativeAPI | null = null
  let opened: number | null = null

  const initialData: ExcalidrawInitialDataState = {
    elements: (options.scene?.elements ?? []) as ExcalidrawInitialDataState['elements'],
    appState: { viewBackgroundColor: options.scene?.appState?.viewBackgroundColor ?? DEFAULT_BACKGROUND },
    files: Object.fromEntries(options.pictures.map((picture) => [picture.id, { ...picture, created: Date.now() }])) as unknown as BinaryFiles,
    scrollToContent: true,
  }

  const root = createRoot(container)
  root.render(createElement(Excalidraw, {
    initialData,
    langCode: options.language,
    theme: options.theme,
    autoFocus: true,
    aiEnabled: false,
    // Saving and loading is ours, not the editor's.
    UIOptions: {
      canvasActions: { loadScene: false, saveToActiveFile: false, saveAsImage: false, export: false, toggleTheme: false },
    },
    excalidrawAPI: (value: ExcalidrawImperativeAPI) => { api = value },
    onChange: (elements) => {
      // Every edit raises an element's version, so their sum changes exactly when the drawing does.
      const state = elements.reduce((sum, element) => sum + element.version, 0)
      if (opened === null) opened = state
      options.onDirty(state !== opened)
    },
  }))

  return {
    async snapshot() {
      if (!api) return null
      const elements = api.getSceneElements()
      if (elements.length === 0) return null
      const viewBackgroundColor = api.getAppState().viewBackgroundColor || DEFAULT_BACKGROUND
      const files = api.getFiles()
      const used = new Set(elements.flatMap((element) => (element.type === 'image' && element.fileId ? [String(element.fileId)] : [])))
      const pictures = [...used].flatMap((id) => {
        const file = files[id]
        return file ? [{ id, mimeType: file.mimeType, dataURL: file.dataURL }] : []
      })

      // Always the light picture with its background: it is shown on both themes, outside the editor.
      const appState = { exportBackground: true, exportWithDarkMode: false, viewBackgroundColor }
      let preview = new XMLSerializer().serializeToString(await exportToSvg({ elements, files, exportPadding: 16, appState }))
      if (preview.length > PREVIEW_LIMIT) {
        // Large photographs make the drawing itself too heavy to keep twice; a flattened picture has a known size.
        const flat = await exportToBlob({ elements, files, exportPadding: 16, appState, mimeType: 'image/webp', quality: 0.85, maxWidthOrHeight: FLAT_PREVIEW_SIDE })
        const { width, height } = await createImageBitmap(flat)
        preview = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ${width} ${height}" width="${width}" height="${height}">`
          + `<image width="${width}" height="${height}" href="${await blobToDataUrl(flat)}"/></svg>`
      }

      return {
        scene: { elements: JSON.parse(JSON.stringify(elements)) as unknown[], appState: { viewBackgroundColor } },
        preview_svg: preview,
        pictures,
      }
    },
    destroy() {
      root.unmount()
    },
  }
}
