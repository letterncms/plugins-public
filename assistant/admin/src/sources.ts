import { getManifest, registry, run, t } from '@letterncms/sdk'

/** What Claude puts under @ and / in a thread: only where it answers this person. */
const answering = () => (getManifest().config?.workspace?.agent as { name?: string } | null | undefined)?.name === 'assistant'
const matches = (query: string, ...words: (string | undefined)[]) => words.some((word) => word?.toLowerCase().includes(query.toLowerCase()))

let skills: Promise<{ name: string; label: string; description: string; icon: string }[]> | null = null
let resources: Promise<{ uri: string; name: string }[]> | null = null

registry.composer.set('assistant-skills', {
  id: 'assistant-skills', trigger: '/', label: t('Skills'), order: 10,
  search: async (query) => {
    if (!answering()) return []
    skills ??= run('assistant/skills')
    return (await skills).filter((skill) => matches(query, skill.name, skill.label)).map((skill) => ({
      id: `skill:${skill.name}`, kind: 'skill', label: skill.label, detail: skill.description, icon: skill.icon, skill: skill.name,
    }))
  },
})

registry.composer.set('assistant-resources', {
  id: 'assistant-resources', trigger: '@', label: t('Site'), order: 40,
  search: async (query) => {
    if (!answering()) return []
    resources ??= run('assistant/resources')
    return (await resources).filter((resource) => matches(query, resource.name)).slice(0, 5).map((resource) => ({
      id: `resource:${resource.uri}`, kind: 'resource', label: resource.name, icon: 'book', read: () => run('assistant/resource', { uri: resource.uri }),
    }))
  },
})
