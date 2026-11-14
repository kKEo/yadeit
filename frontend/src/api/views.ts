import { request, workspacePath } from './client'
import type { ItemResponse, SavedView, SaveViewRequest } from './types'

const viewsPath = (slug: string) => `${workspacePath(slug)}/views`

export async function listViews(slug: string): Promise<SavedView[]> {
  return (await request<ItemResponse<SavedView[]>>('GET', viewsPath(slug))).data
}

export async function createView(slug: string, req: SaveViewRequest & { name: string }): Promise<SavedView> {
  return (await request<ItemResponse<SavedView>>('POST', viewsPath(slug), { body: req })).data
}

export async function updateView(slug: string, id: number, req: SaveViewRequest): Promise<SavedView> {
  return (await request<ItemResponse<SavedView>>('PATCH', `${viewsPath(slug)}/${id}`, { body: req })).data
}

export async function deleteView(slug: string, id: number): Promise<void> {
  await request<void>('DELETE', `${viewsPath(slug)}/${id}`)
}
