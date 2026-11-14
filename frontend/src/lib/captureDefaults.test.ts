import { describe, expect, it } from 'vitest'
import type { Project } from '@/api/types'
import { makeProject } from '@/test/helpers'
import { captureDefaults, defaultProject } from './captureDefaults'

const projects: Project[] = [
  { id: 1, key: 'API', name: 'API', description: null, archived: false, cycle_weeks: null, stale_after_days: null, created_at: '' },
  { id: 2, key: 'WEB', name: 'Website', description: null, archived: false, cycle_weeks: null, stale_after_days: null, created_at: '' },
]

describe('quick capture defaults', () => {
  it('picks the filtered project, else the last used, else the first', () => {
    expect(defaultProject({ project: 'web' }, projects, 'API')).toBe('WEB')
    expect(defaultProject({}, projects, 'WEB')).toBe('WEB')
    expect(defaultProject({}, projects, 'GONE')).toBe('API')
    expect(defaultProject({ project: 'GONE' }, projects, null)).toBe('API')
    expect(defaultProject({}, [], null)).toBeNull()
  })

  it('inherits state, priority, assignee and label from the filters', () => {
    const project = makeProject()
    expect(captureDefaults({ state: 'done', priority: 'high', assignee: 'me', label: 'backend' }, project, 7)).toEqual({
      state: '6', priority: 'high', assignee: '7', labels: ['backend'],
    })
    // A state group becomes the project's first state in that group; a member id is kept.
    expect(captureDefaults({ state_group: 'started', assignee: '12' }, project, 7)).toMatchObject({ state: '4', assignee: '12' })
  })

  it('does not inherit what cannot describe a new item', () => {
    expect(captureDefaults({ assignee: 'none', label: 'unknown', state: 'Nope', q: 'x', open: '1' }, makeProject(), 7)).toEqual({
      state: null, priority: 'none', assignee: null, labels: [],
    })
  })
})
