import { describe, expect, it } from 'vitest'
import i18n from '@/i18n'
import { describeActivity } from '@/lib/activity'
import { patchRequest } from '@/queries/itemMutations'

const t = i18n.global.t as (key: string, named?: Record<string, unknown>) => string
const entry = (event: string, changes: Record<string, unknown>) => ({
  id: 1, event, changes, actor: { id: 20, name: 'Coder' }, created_at: '2026-01-01T00:00:00Z',
})

describe('delegation', () => {
  it('sends a delegate by id and can take the work back', () => {
    expect(patchRequest({ delegate: { id: 20, name: 'Coder' } })).toEqual({ delegate: 20 })
    expect(patchRequest({ delegate: null })).toEqual({ delegate: null })
    expect(patchRequest({ needs_input: false })).toEqual({ needs_input: false })
  })

  it('describes the agent workflow in the history', () => {
    expect(describeActivity(entry('claimed', { delegate: { from: null, to: 'Coder' } }), t)).toEqual(['Coder took the item'])
    expect(describeActivity(entry('input_requested', { comment_id: 3 }), t)).toEqual(['Coder asked a question and is waiting for an answer'])
    expect(describeActivity(entry('submitted_for_review', {}), t)).toEqual(['Coder submitted the work for review'])
    expect(describeActivity(entry('updated', { needs_input: { from: true, to: false } }), t)).toEqual(['Coder marked the question as answered'])
    expect(describeActivity(entry('updated', { delegate: { from: null, to: 'Coder' } }), t)).toEqual(['Coder changed the agent from nothing to Coder'])
    expect(describeActivity(entry('decayed', { state: { from: 'Todo', to: 'Backlog' }, idle_days: 30 }), t)).toEqual(['Untouched for 30 days: returned to the backlog'])
    expect(describeActivity(entry('rolled_over', { cycle: { from: 'Cycle 1', to: 'Cycle 2' } }), t)).toEqual(['Not finished in Cycle 1: moved to Cycle 2'])
  })
})
