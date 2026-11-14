import { request, workspacePath } from './client'
import type { DocPage, DocPageSummary, ItemResponse, ListResponse, PublicPage, SavePageRequest } from './types'

const pagesPath = (slug: string) => `${workspacePath(slug)}/pages`

export async function listPages(slug: string, params: { project?: string; q?: string } = {}): Promise<DocPageSummary[]> {
  const query = new URLSearchParams({ limit: '100' })
  if (params.project) query.set('project', params.project)
  if (params.q) query.set('q', params.q)
  return (await request<ListResponse<DocPageSummary>>('GET', `${pagesPath(slug)}?${query}`)).data
}

export async function getPage(slug: string, id: number): Promise<DocPage> {
  return (await request<ItemResponse<DocPage>>('GET', `${pagesPath(slug)}/${id}`)).data
}

export async function createPage(slug: string, req: SavePageRequest & { title: string }): Promise<DocPage> {
  return (await request<ItemResponse<DocPage>>('POST', pagesPath(slug), { body: req })).data
}

export async function updatePage(slug: string, id: number, req: SavePageRequest): Promise<DocPage> {
  return (await request<ItemResponse<DocPage>>('PATCH', `${pagesPath(slug)}/${id}`, { body: req })).data
}

export async function deletePage(slug: string, id: number): Promise<void> {
  await request<void>('DELETE', `${pagesPath(slug)}/${id}`)
}

/** A page shared by link; works signed out. */
export async function getPublicPage(token: string): Promise<PublicPage> {
  return (await request<ItemResponse<PublicPage>>('GET', `/public/pages/${encodeURIComponent(token)}`, { expectUnauthorized: true })).data
}
