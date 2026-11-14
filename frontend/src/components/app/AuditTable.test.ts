import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import type { AuditEntry } from '@/api/types'
import i18n from '@/i18n'
import AuditTable from './AuditTable.vue'

function entry(overrides: Partial<AuditEntry> = {}): AuditEntry {
  return {
    id: 1, action: 'member.role_changed', subject: 'user=9', details: { role: 'admin' },
    actor: { id: 7, name: 'Ada', type: 'human' }, token: null, created_at: '2026-01-01T10:00:00Z', ...overrides,
  }
}

describe('AuditTable', () => {
  it('puts actions into words and keeps unknown codes readable', () => {
    const wrapper = mount(AuditTable, {
      props: { entries: [
        entry(),
        entry({ id: 2, action: 'something.new', subject: null, details: { labels: ['a', 'b'], note: null }, actor: { id: 20, name: 'Coder', type: 'agent' }, token: 'CI' }),
        entry({ id: 3, action: 'account.sign_in_failed', actor: null, details: { email: 'x@example.com' } }),
      ] },
      global: { plugins: [i18n] },
    })
    const rows = wrapper.findAll('[data-testid="audit-row"]')

    expect(rows[0].text()).toContain("Changed a member's role")
    expect(rows[0].text()).toContain('user=9')
    expect(rows[0].text()).toContain('role: admin')
    expect(rows[1].text()).toContain('something.new')
    expect(rows[1].text()).toContain('labels: a, b · note: —')
    expect(rows[1].text()).toContain('with token “CI”')
    expect(rows[2].text()).toContain('Not signed in')
    expect(wrapper.text()).not.toContain('Address')
  })

  it('adds the workspace and the address for the operator', () => {
    const wrapper = mount(AuditTable, {
      props: { entries: [entry({ workspace: 'acme', ip: '203.0.113.5' })], operator: true },
      global: { plugins: [i18n] },
    })

    expect(wrapper.text()).toContain('acme')
    expect(wrapper.text()).toContain('203.0.113.5')
  })
})
