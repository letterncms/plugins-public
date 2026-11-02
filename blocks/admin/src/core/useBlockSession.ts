import { useEffect, useRef, useState } from 'react'
import { EditorStore } from './store'
import { newId, type BlockNode } from './types'
import { uniqueIds } from './uniqueIds'

/** A block field keeps one history across saves; only explicit external replacements reset its tree. */
export function useBlockSession(value: unknown, onChange: (nodes: BlockNode[]) => void, readOnly: boolean) {
  const change = useRef(onChange)
  change.current = onChange
  const [store] = useState(() => new EditorStore(uniqueIds(Array.isArray(value) ? value : [], newId), (nodes) => change.current(nodes), readOnly))
  const seen = useRef(value)
  store.setReadOnly(readOnly)
  useEffect(() => {
    if (seen.current === value) return
    seen.current = value
    const incoming = uniqueIds(Array.isArray(value) ? value : [], newId)
    // A save returns a fresh JSON object; acknowledging it must retain local undo history.
    if (JSON.stringify(incoming) !== JSON.stringify(store.get().nodes)) store.reset(incoming)
  }, [store, value])
  return store
}
