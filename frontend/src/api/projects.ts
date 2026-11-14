import { request, workspacePath } from './client'
import type { CreateProjectRequest, ItemResponse, LabelRef, Project, ProjectDetail, ProjectState, StateGroup } from './types'

const projectsPath = (slug: string) => `${workspacePath(slug)}/projects`
const projectPath = (slug: string, key: string) => `${projectsPath(slug)}/${encodeURIComponent(key)}`

export async function listProjects(slug: string): Promise<Project[]> {
  return (await request<ItemResponse<Project[]>>('GET', projectsPath(slug))).data
}

export async function getProject(slug: string, key: string): Promise<ProjectDetail> {
  return (await request<ItemResponse<ProjectDetail>>('GET', projectPath(slug, key))).data
}

export async function createProject(slug: string, req: CreateProjectRequest): Promise<ProjectDetail> {
  return (await request<ItemResponse<ProjectDetail>>('POST', projectsPath(slug), { body: req })).data
}

export async function updateProject(
  slug: string, key: string, req: { name?: string; description?: string | null; archived?: boolean },
): Promise<ProjectDetail> {
  return (await request<ItemResponse<ProjectDetail>>('PATCH', projectPath(slug, key), { body: req })).data
}

export async function deleteProject(slug: string, key: string): Promise<void> {
  await request<void>('DELETE', projectPath(slug, key))
}

// ── States ───────────────────────────────────────────────────────────────────

export interface StateInput { name: string; group: StateGroup; color?: string; position?: number; is_default?: boolean }
export type ProjectStateFull = ProjectState & { position: number }

export async function listStates(slug: string, key: string): Promise<ProjectStateFull[]> {
  return (await request<ItemResponse<ProjectStateFull[]>>('GET', `${projectPath(slug, key)}/states`)).data
}

export async function createState(slug: string, key: string, req: StateInput): Promise<ProjectStateFull> {
  return (await request<ItemResponse<ProjectStateFull>>('POST', `${projectPath(slug, key)}/states`, { body: req })).data
}

export async function updateState(slug: string, key: string, id: number, req: Partial<StateInput>): Promise<ProjectStateFull> {
  return (await request<ItemResponse<ProjectStateFull>>('PATCH', `${projectPath(slug, key)}/states/${id}`, { body: req })).data
}

export async function deleteState(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${projectPath(slug, key)}/states/${id}`)
}

// ── Labels ───────────────────────────────────────────────────────────────────

export interface LabelInput { name: string; color?: string }

export async function listLabels(slug: string, key: string): Promise<LabelRef[]> {
  return (await request<ItemResponse<LabelRef[]>>('GET', `${projectPath(slug, key)}/labels`)).data
}

export async function createLabel(slug: string, key: string, req: LabelInput): Promise<LabelRef> {
  return (await request<ItemResponse<LabelRef>>('POST', `${projectPath(slug, key)}/labels`, { body: req })).data
}

export async function updateLabel(slug: string, key: string, id: number, req: Partial<LabelInput>): Promise<LabelRef> {
  return (await request<ItemResponse<LabelRef>>('PATCH', `${projectPath(slug, key)}/labels/${id}`, { body: req })).data
}

export async function deleteLabel(slug: string, key: string, id: number): Promise<void> {
  await request<void>('DELETE', `${projectPath(slug, key)}/labels/${id}`)
}
