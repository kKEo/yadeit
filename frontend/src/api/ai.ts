import { request, workspacePath } from './client'
import type {
  AiItemDraft, AiItemSummary, AiPageSummary, AiStatus, AiSubItem, AiTriageSuggestion, ItemResponse,
} from './types'

const aiPath = (slug: string) => `${workspacePath(slug)}/ai`
const itemAi = (slug: string, key: string) => `${workspacePath(slug)}/items/${encodeURIComponent(key)}/ai`

async function post<T>(path: string, body: object = {}): Promise<T> {
  return (await request<ItemResponse<T>>('POST', path, { body })).data
}

export async function getAiStatus(slug: string): Promise<AiStatus> {
  return (await request<ItemResponse<AiStatus>>('GET', aiPath(slug))).data
}

/** `api_key: null` goes back to the instance's key. The key is write-only. */
export async function configureAi(
  slug: string, req: { enabled?: boolean; api_key?: string | null; monthly_token_limit?: number | null },
): Promise<AiStatus> {
  return (await request<ItemResponse<AiStatus>>('PUT', aiPath(slug), { body: req })).data
}

// Every call below returns a suggestion and changes nothing on the server.

export const draftItem = (slug: string, project: string, text: string) => post<AiItemDraft>(`${aiPath(slug)}/draft-item`, { project, text })
export const splitItem = async (slug: string, key: string) => (await post<{ sub_items: AiSubItem[] }>(`${itemAi(slug, key)}/split`)).sub_items
export const summariseItem = (slug: string, key: string) => post<AiItemSummary>(`${itemAi(slug, key)}/summary`)
export const summarisePage = (slug: string, id: number) => post<AiPageSummary>(`${workspacePath(slug)}/pages/${id}/ai/summary`)
export const triageFeedback = (slug: string, id: number) => post<AiTriageSuggestion>(`${workspacePath(slug)}/feedback/${id}/ai/triage`)
