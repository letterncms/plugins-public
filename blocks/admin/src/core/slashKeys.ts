/**
 * What Enter does around the slash menu, as plain decisions (tested in tests/js).
 * Typing "/image" + Enter can be faster than the menu: the menu may not be on screen
 * yet, or its components may still be loading. Enter must still pick the first match,
 * and still start a new line when nothing matches.
 */

/** In the open menu: pick the highlighted item, wait for the list to load, or leave Enter to the text. */
export function menuEnter(itemCount: number, active: number, loading: boolean): 'choose' | 'wait' | 'pass' {
  if (active >= 0 && active < itemCount) return 'choose'
  return loading ? 'wait' : 'pass'
}

/** A remembered Enter, once the list changes: pick the first match, keep waiting, or hand Enter back to the text. */
export function settleEnter(itemCount: number, loading: boolean): 'choose' | 'wait' | 'release' {
  if (itemCount > 0) return 'choose'
  return loading ? 'wait' : 'release'
}

/** In the text: Enter while "/query" is typed and the menu isn't on screen yet is the menu's, once it is. */
export function textDefersEnter(slashOpen: boolean, menuOnScreen: boolean): boolean {
  return slashOpen && !menuOnScreen
}
