import { useMutation, useQuery, useQueryCache, type QueryCache } from '@pinia/colada'
import { toValue, type MaybeRefOrGetter } from 'vue'
import { listMembers } from '@/api/client'
import * as api from '@/api/projects'
import type { CreateProjectRequest, ProjectDetail } from '@/api/types'
import { keys, useSlug } from './keys'

export function useProjects() {
  const slug = useSlug()
  return useQuery(() => ({
    key: keys.projectList(slug.value),
    query: () => api.listProjects(slug.value),
    enabled: slug.value !== '',
  }))
}

/** One project with its states (in workflow order) and labels. */
export function useProject(projectKey: MaybeRefOrGetter<string | null | undefined>) {
  const slug = useSlug()
  return useQuery(() => {
    const key = toValue(projectKey) ?? ''
    const workspace = slug.value
    return {
      key: keys.project(workspace, key),
      query: () => api.getProject(workspace, key),
      enabled: key !== '' && workspace !== '',
    }
  })
}

/** The project from the cache, or fetched once; for code that needs states outside a component's query. */
export async function ensureProject(cache: QueryCache, slug: string, key: string): Promise<ProjectDetail> {
  const cached = cache.getQueryData<ProjectDetail>(keys.project(slug, key))
  if (cached) return cached
  const project = await api.getProject(slug, key)
  cache.setQueryData(keys.project(slug, key), project)
  return project
}

export function useCreateProject() {
  const cache = useQueryCache()
  const slug = useSlug()
  return useMutation({
    mutation: (req: CreateProjectRequest) => api.createProject(slug.value, req),
    onSuccess(project) {
      cache.setQueryData(keys.project(slug.value, project.key), project)
      void cache.invalidateQueries({ key: keys.projectList(slug.value) })
    },
  })
}

/** People who can be assigned: everyone in the workspace. */
export function useMembers() {
  const slug = useSlug()
  return useQuery(() => ({
    key: keys.members(slug.value),
    query: () => listMembers(slug.value),
    enabled: slug.value !== '',
  }))
}
