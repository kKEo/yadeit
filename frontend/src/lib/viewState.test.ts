import { describe, expect, it } from 'vitest'
import { itemListQuery } from '@/api/items'
import type { SavedView, ViewState } from '@/api/types'
import { makeItem } from '@/test/helpers'
import {
  DEFAULT_VIEW, filterChips, listParams, matchesFilters, parseViewState, sameViewState, viewPayload, viewStateQuery, viewToState, withFilter,
} from './viewState'

const state: ViewState = {
  filters: { project: 'WEB', state_group: 'started', priority: 'high', assignee: 'me', label: 'backend', q: 'login', open: '1' },
  layout: 'board', group_by: 'priority', sort: 'due_date',
}

describe('view state ⇄ URL', () => {
  it('round-trips through the query string', () => {
    const query = viewStateQuery(state)
    expect(query).toEqual({
      project: 'WEB', state_group: 'started', priority: 'high', assignee: 'me', label: 'backend', q: 'login', open: '1',
      layout: 'board', group: 'priority', sort: 'due_date',
    })
    expect(parseViewState(query)).toEqual(state)
  })

  it('leaves defaults out of the URL and reads an empty URL as the default view', () => {
    expect(viewStateQuery(DEFAULT_VIEW)).toEqual({})
    expect(parseViewState({})).toEqual(DEFAULT_VIEW)
  })

  it('drops unknown and invalid values instead of passing them to the API', () => {
    const parsed = parseViewState({ priority: 'extreme', kind: 'bug', layout: 'grid', group: 'colour', sort: 'title; drop', open: 'maybe', item: 'WEB-1' })
    expect(parsed).toEqual({ ...DEFAULT_VIEW, filters: { kind: 'bug' } })
  })

  it('compares states by meaning, not by key order', () => {
    expect(sameViewState({ ...DEFAULT_VIEW, filters: { kind: 'bug', open: '1' } }, { ...DEFAULT_VIEW, filters: { open: '1', kind: 'bug' } })).toBe(true)
    expect(sameViewState(DEFAULT_VIEW, { ...DEFAULT_VIEW, layout: 'board' })).toBe(false)
  })
})

describe('filter chips', () => {
  it('lists one chip per active filter; the search text is not a chip', () => {
    expect(filterChips(state.filters).map((chip) => `${chip.name}=${chip.value}`)).toEqual([
      'project=WEB', 'state_group=started', 'priority=high', 'assignee=me', 'label=backend', 'open=1',
    ])
  })

  it('produce the item list query', () => {
    expect(itemListQuery(listParams(state, 50))).toBe(
      '?project=WEB&state_group=started&priority=high&assignee=me&label=backend&q=login&open=1&sort=due_date&limit=50',
    )
    expect(itemListQuery(listParams(DEFAULT_VIEW))).toBe('?sort=-updated_at')
  })

  it('removing a chip removes it from the query', () => {
    const filters = withFilter(state.filters, 'assignee', null)
    expect(itemListQuery(listParams({ ...state, filters }))).not.toContain('assignee')
    expect(filterChips(filters).some((chip) => chip.name === 'assignee')).toBe(false)
  })

  it('keeps state filters consistent: a state replaces the group, another project drops the state', () => {
    expect(withFilter({ project: 'WEB', state_group: 'started' }, 'state', 'In review')).toEqual({ project: 'WEB', state: 'In review' })
    expect(withFilter({ project: 'WEB', state: 'In review' }, 'project', 'API')).toEqual({ project: 'API' })
  })
})

describe('saved views', () => {
  const view: SavedView = {
    id: 4, name: 'My bugs', is_shared: false, owner_id: 7,
    filters: { kind: 'bug', assignee: 'me' }, layout: 'board', group_by: 'state', sort: '-priority',
  }

  it('apply: a saved view becomes the URL state', () => {
    expect(viewStateQuery(viewToState(view))).toEqual({ kind: 'bug', assignee: 'me', layout: 'board', sort: '-priority' })
  })

  it('save: the state is stored under the API\'s names', () => {
    expect(viewPayload(state)).toEqual({ filters: state.filters, layout: 'board', group_by: 'priority', sort: 'due_date' })
    expect(viewToState({ ...view, ...viewPayload(state) })).toEqual(state)
  })
})

describe('matchesFilters', () => {
  const item = makeItem({ assignee: { id: 7, name: 'Ada' }, labels: [{ id: 1, name: 'backend', color: '#000000' }] })

  it('mirrors the API rules for the common filters', () => {
    expect(matchesFilters(item, {}, 7)).toBe(true)
    expect(matchesFilters(item, { assignee: 'me', project: 'web', label: 'backend', open: '1' }, 7)).toBe(true)
    expect(matchesFilters(item, { assignee: 'me' }, 8)).toBe(false)
    expect(matchesFilters(item, { assignee: 'none' }, 7)).toBe(false)
    expect(matchesFilters(item, { state_group: 'started' }, 7)).toBe(false)
    expect(matchesFilters(item, { q: 'LOGIN' }, 7)).toBe(true)
    expect(matchesFilters({ ...item, completed_at: '2026-01-03T00:00:00Z' }, { open: '1' }, 7)).toBe(false)
  })
})
