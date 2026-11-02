import { registry, run, t } from '@letterncms/sdk'
import { panel } from './store'

/** What the assistant itself puts under @ and /. Other modules register theirs the same way. */
const matches = (query: string, ...words: (string | undefined)[]) => words.some((word) => word?.toLowerCase().includes(query.toLowerCase()))

let skills: Promise<{ name: string; label: string; description: string; icon: string }[]> | null = null
let resources: Promise<{ uri: string; name: string }[]> | null = null

registry.composer.set('assistant-skills', {
  id: 'assistant-skills', trigger: '/', label: t('Skills'), order: 10,
  search: async (query) => {
    skills ??= run('assistant/skills')
    return (await skills).filter((skill) => matches(query, skill.name, skill.label)).map((skill) => ({
      id: `skill:${skill.name}`, kind: 'skill', label: skill.label, detail: skill.description, icon: skill.icon, skill: skill.name,
    }))
  },
})

registry.composer.set('assistant-actions', {
  id: 'assistant-actions', trigger: '/', label: t('Chat'), order: 90,
  search: (query) => [
    { id: 'new', label: t('New Chat'), icon: 'plus', perform: () => panel.select(null) },
    { id: 'chats', label: t('All Chats'), icon: 'history', perform: () => panel.set({ history: true }) },
  ].filter((action) => matches(query, action.label)),
})

registry.composer.set('assistant-resources', {
  id: 'assistant-resources', trigger: '@', label: t('Site'), order: 40,
  search: async (query) => {
    resources ??= run('assistant/resources')
    return (await resources).filter((resource) => matches(query, resource.name)).slice(0, 5).map((resource) => ({
      id: `resource:${resource.uri}`, kind: 'resource', label: resource.name, icon: 'book', read: () => run('assistant/resource', { uri: resource.uri }),
    }))
  },
})
