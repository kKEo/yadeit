import { request, workspacePath } from './client'
import type { Feedback, FeedbackStatusFilter, ItemKind, ItemResponse } from './types'

const feedbackPath = (slug: string) => `${workspacePath(slug)}/feedback`

export interface FeedbackList { data: Feedback[]; next_cursor: string | null; waiting: number | null }

export async function listFeedback(slug: string, params: { status?: FeedbackStatusFilter; project?: string } = {}): Promise<FeedbackList> {
  const query = new URLSearchParams({ limit: '100' })
  if (params.status) query.set('status', params.status)
  if (params.project) query.set('project', params.project)
  return request<FeedbackList>('GET', `${feedbackPath(slug)}?${query}`)
}

export async function sendFeedback(slug: string, req: { project: string; title: string; body?: string | null }): Promise<Feedback> {
  return (await request<ItemResponse<Feedback>>('POST', feedbackPath(slug), { body: req })).data
}

type Decision =
  | { action: 'accept'; body: { item?: string; kind?: ItemKind } }
  | { action: 'decline'; body: { reason?: string | null } }
  | { action: 'duplicate'; body: { of: number } }
  | { action: 'snooze'; body: { until: string | null } }
  | { action: 'reopen'; body?: undefined }

/** One triage decision; the answer is the feedback as it stands afterwards. */
export async function decide(slug: string, id: number, decision: Decision): Promise<Feedback> {
  return (await request<ItemResponse<Feedback>>('POST', `${feedbackPath(slug)}/${id}/${decision.action}`, { body: decision.body ?? {} })).data
}

export async function setPublicForm(slug: string, projectKey: string, enabled: boolean): Promise<string | null> {
  const res = await request<ItemResponse<{ feedback_token: string | null }>>(
    'PUT', `${workspacePath(slug)}/projects/${encodeURIComponent(projectKey)}/feedback-form`, { body: { enabled } },
  )
  return res.data.feedback_token
}

// ── The public form: no sign-in ──────────────────────────────────────────────

export async function getPublicForm(token: string): Promise<{ project: string }> {
  return (await request<ItemResponse<{ project: string }>>('GET', `/public/feedback/${encodeURIComponent(token)}`, { expectUnauthorized: true })).data
}

export async function sendPublicFeedback(
  token: string, req: { title: string; body?: string | null; name?: string | null; email?: string | null; website?: string },
): Promise<void> {
  await request<unknown>('POST', `/public/feedback/${encodeURIComponent(token)}`, { body: req, expectUnauthorized: true })
}
