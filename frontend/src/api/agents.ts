import { request, workspacePath } from './client'
import type { Agent, AgentToken, CreateAgentTokenRequest, ItemResponse, NewAgentToken } from './types'

const agentsPath = (slug: string) => `${workspacePath(slug)}/agents`

export async function listAgents(slug: string): Promise<Agent[]> {
  return (await request<ItemResponse<Agent[]>>('GET', agentsPath(slug))).data
}

export async function createAgent(slug: string, req: { name: string; description?: string | null }): Promise<Agent> {
  return (await request<ItemResponse<Agent>>('POST', agentsPath(slug), { body: req })).data
}

export async function deleteAgent(slug: string, id: number): Promise<void> {
  await request<void>('DELETE', `${agentsPath(slug)}/${id}`)
}

export async function listAgentTokens(slug: string, agentId: number): Promise<AgentToken[]> {
  return (await request<ItemResponse<AgentToken[]>>('GET', `${agentsPath(slug)}/${agentId}/tokens`)).data
}

/** The secret in the result is shown by the API only this once. */
export async function createAgentToken(slug: string, agentId: number, req: CreateAgentTokenRequest): Promise<NewAgentToken> {
  return (await request<ItemResponse<NewAgentToken>>('POST', `${agentsPath(slug)}/${agentId}/tokens`, { body: req })).data
}

export async function revokeAgentToken(slug: string, agentId: number, tokenId: number): Promise<void> {
  await request<void>('DELETE', `${agentsPath(slug)}/${agentId}/tokens/${tokenId}`)
}
