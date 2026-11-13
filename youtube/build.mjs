// The block's browser files, as written, into dist/: the stylesheet and the script that plays a video when its poster is
// clicked. They need no bundler; dist/ is what a release carries and what the site serves (Plugin::asset).
import { copyFileSync, mkdirSync, readdirSync, rmSync } from 'node:fs'

const from = new URL('./assets/', import.meta.url)
const to = new URL('./dist/', import.meta.url)

rmSync(to, { recursive: true, force: true })
mkdirSync(to)

for (const name of readdirSync(from)) {
  // A module is served as .js: every web server knows that type.
  copyFileSync(new URL(name, from), new URL(name.replace(/\.mjs$/, '.js'), to))
}
