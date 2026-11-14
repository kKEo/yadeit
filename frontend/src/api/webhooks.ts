import { request, workspacePath } from './client'
import type { ItemResponse, NewWebhook, SaveWebhookRequest, Webhook, WebhookDelivery } from './types'

const hooksPath = (slug: string) => `${workspacePath(slug)}/webhooks`

/** The webhooks of the workspace and the event names that can be subscribed to. */
export async function listWebhooks(slug: string): Promise<{ data: Webhook[]; events: string[] }> {
  return request<{ data: Webhook[]; events: string[] }>('GET', hooksPath(slug))
}

/** The signing secret in the result is shown by the API only this once. */
export async function createWebhook(slug: string, req: SaveWebhookRequest & { name: string; url: string }): Promise<NewWebhook> {
  return (await request<ItemResponse<NewWebhook>>('POST', hooksPath(slug), { body: req })).data
}

export async function updateWebhook(slug: string, id: number, req: SaveWebhookRequest): Promise<Webhook> {
  return (await request<ItemResponse<Webhook>>('PATCH', `${hooksPath(slug)}/${id}`, { body: req })).data
}

export async function deleteWebhook(slug: string, id: number): Promise<void> {
  await request<void>('DELETE', `${hooksPath(slug)}/${id}`)
}

export async function listWebhookDeliveries(slug: string, id: number): Promise<WebhookDelivery[]> {
  return (await request<ItemResponse<WebhookDelivery[]>>('GET', `${hooksPath(slug)}/${id}/deliveries`)).data
}

export async function pingWebhook(slug: string, id: number): Promise<void> {
  await request<unknown>('POST', `${hooksPath(slug)}/${id}/ping`)
}
